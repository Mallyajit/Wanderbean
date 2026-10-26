<?php
declare(strict_types=1);

/**
 * Small JSON HTTP client with a file cache, parallel fan-out (curl_multi) and a
 * circuit breaker: after the first transport-level failure (no DNS, no route,
 * no CA store) every later call short-circuits, so an offline machine falls
 * back to sample data immediately instead of waiting on each timeout.
 */
final class HttpClient
{
    private static bool $down = false;
    /** Once any host has answered, a transport error is that host's problem, not ours. */
    private static bool $reachedNetwork = false;
    /** @var list<array{service:string,status:string,cached:bool,ms:int}> */
    private static array $log = [];

    /** curl error codes that mean "this machine cannot reach the internet". */
    private const FATAL_CURL = [5, 6, 7, 35, 60, 77];

    public function __construct(
        private string $cacheDir,
        private string $userAgent,
        private int $timeout = 7,
        private bool $enabled = true,
    ) {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
    }

    public static function transportAvailable(): bool
    {
        return function_exists('curl_multi_init')
            || (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL) && extension_loaded('openssl'));
    }

    /** @return list<array{service:string,status:string,cached:bool,ms:int}> */
    public static function log(): array
    {
        return self::$log;
    }

    public function online(): bool
    {
        return $this->enabled && !self::$down && self::transportAvailable();
    }

    /**
     * @param array{method?:string,headers?:list<string>,body?:mixed,ttl?:int,timeout?:int} $opts
     * @return array<mixed>|null
     */
    public function json(string $service, string $url, array $opts = []): ?array
    {
        return $this->multi([$service => ['url' => $url, 'service' => $service] + $opts])[$service];
    }

    /**
     * Fire several requests in parallel. Each request is
     * {url, method?, headers?, body?, ttl?, timeout?, service?}; results come back
     * under the same keys, null on any failure.
     *
     * @param array<string, array<string, mixed>> $requests
     * @return array<string, array<mixed>|null>
     */
    public function multi(array $requests): array
    {
        $out = [];
        $pending = [];

        foreach ($requests as $key => $r) {
            $service = (string)($r['service'] ?? $key);
            $method = strtoupper((string)($r['method'] ?? 'GET'));
            $body = array_key_exists('body', $r) ? json_encode($r['body'], JSON_UNESCAPED_SLASHES) : null;
            $headers = $r['headers'] ?? [];
            $cacheFile = $this->cacheDir . '/' . md5($method . $r['url'] . $body . implode('|', $headers)) . '.json';
            $ttl = (int)($r['ttl'] ?? 3600);

            if (is_file($cacheFile) && filemtime($cacheFile) > time() - $ttl) {
                $data = json_decode((string)file_get_contents($cacheFile), true);
                if (is_array($data)) {
                    $out[$key] = $data;
                    self::$log[] = ['service' => $service, 'status' => 'ok', 'cached' => true, 'ms' => 0];
                    continue;
                }
            }
            if (!$this->online()) {
                $out[$key] = null;
                self::$log[] = ['service' => $service, 'status' => $this->enabled ? 'offline' : 'skipped', 'cached' => false, 'ms' => 0];
                continue;
            }
            $pending[$key] = [
                'url' => (string)$r['url'],
                'method' => $method,
                'headers' => $headers,
                'body' => $body,
                'timeout' => (int)($r['timeout'] ?? $this->timeout),
                'cache' => $cacheFile,
                'service' => $service,
            ];
        }

        if ($pending) {
            $results = function_exists('curl_multi_init') ? $this->viaCurl($pending) : $this->viaStreams($pending);
            foreach ($results as $key => [$status, $raw, $ms, $error]) {
                $p = $pending[$key];
                $data = ($status >= 200 && $status < 300) ? json_decode((string)$raw, true) : null;
                if (is_array($data)) {
                    @file_put_contents($p['cache'], $raw);
                }
                $out[$key] = is_array($data) ? $data : null;
                if ($status > 0) {
                    self::$reachedNetwork = true;
                }
                self::$log[] = [
                    'service' => $p['service'],
                    'status' => is_array($data) ? 'ok' : ($error !== '' ? $error : 'http ' . $status),
                    'cached' => false,
                    'ms' => $ms,
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $pending
     * @return array<string, array{0:int,1:string,2:int,3:string}>
     */
    private function viaCurl(array $pending): array
    {
        $mh = curl_multi_init();
        $handles = [];
        $codes = [];

        foreach ($pending as $key => $p) {
            $ch = curl_init($p['url']);
            $headers = $p['headers'];
            $headers[] = 'Accept: application/json';
            if ($p['body'] !== null) {
                $headers[] = 'Content-Type: application/json';
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => $p['timeout'],
                CURLOPT_USERAGENT => $this->userAgent,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_ENCODING => '',
            ]);
            if ($p['method'] === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $p['body'] ?? '');
            }
            // Windows PHP builds ship without a CA bundle; borrow the OS store.
            if (PHP_OS_FAMILY === 'Windows' && !ini_get('curl.cainfo') && defined('CURLSSLOPT_NATIVE_CA')) {
                curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
            }
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }

        do {
            $status = curl_multi_exec($mh, $running);
            while ($info = curl_multi_info_read($mh)) {
                $codes[spl_object_id($info['handle'])] = (int)$info['result'];
            }
            if ($running) {
                curl_multi_select($mh, 0.25);
            }
        } while ($running && $status === CURLM_OK);
        while ($info = curl_multi_info_read($mh)) {
            $codes[spl_object_id($info['handle'])] = (int)$info['result'];
        }

        $results = [];
        $fatal = false;
        $answered = false;
        foreach ($handles as $key => $ch) {
            $errno = $codes[spl_object_id($ch)] ?? 0;
            $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $ms = (int)round((float)curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
            $raw = (string)curl_multi_getcontent($ch);
            $fatal = $fatal || ($errno !== 0 && in_array($errno, self::FATAL_CURL, true));
            $answered = $answered || $http > 0;
            $results[$key] = [$http, $raw, $ms, $errno !== 0 ? 'curl ' . $errno : ''];
            curl_multi_remove_handle($mh, $ch);
        }
        curl_multi_close($mh);
        if ($fatal && !$answered && !self::$reachedNetwork) {
            self::$down = true;
        }

        return $results;
    }

    /**
     * Sequential fallback for PHP builds without ext-curl.
     *
     * @param array<string, array<string, mixed>> $pending
     * @return array<string, array{0:int,1:string,2:int,3:string}>
     */
    private function viaStreams(array $pending): array
    {
        $results = [];
        foreach ($pending as $key => $p) {
            if (self::$down) {
                $results[$key] = [0, '', 0, 'offline'];
                continue;
            }
            $headers = array_merge($p['headers'], ['User-Agent: ' . $this->userAgent, 'Accept: application/json']);
            if ($p['body'] !== null) {
                $headers[] = 'Content-Type: application/json';
            }
            $ctx = stream_context_create(['http' => [
                'method' => $p['method'],
                'header' => implode("\r\n", $headers),
                'content' => $p['body'] ?? '',
                'timeout' => $p['timeout'],
                'ignore_errors' => true,
            ]]);
            $start = microtime(true);
            $raw = @file_get_contents($p['url'], false, $ctx);
            $ms = (int)round((microtime(true) - $start) * 1000);
            $responseHeaders = function_exists('http_get_last_response_headers')
                ? (http_get_last_response_headers() ?? [])
                : ($http_response_header ?? []);
            if ($raw === false || !$responseHeaders) {
                self::$down = !self::$reachedNetwork;
                $results[$key] = [0, '', $ms, 'unreachable'];
                continue;
            }
            self::$reachedNetwork = true;
            preg_match('#HTTP/\S+\s+(\d{3})#', (string)$responseHeaders[0], $m);
            $results[$key] = [(int)($m[1] ?? 0), (string)$raw, $ms, ''];
        }
        return $results;
    }
}

<?php
declare(strict_types=1);

/**
 * Universal, keyless destination data from Wikivoyage (MediaWiki API).
 *
 * Nearly every tourist destination has a Wikivoyage guide whose See / Do /
 * Buy / Eat / Drink / Sleep sections are made of structured listing templates
 * ({{see | name= | lat= | long= | hours= | price= | content= ...}}). Eat and
 * Sleep listings sit under Budget / Mid-range / Splurge headings, which give us
 * a price tier even when the price text can't be parsed. Big cities split into
 * district sub-pages ("Kyoto/Higashiyama"), which are fetched in parallel.
 */
final class WikivoyageService
{
    private const API = 'https://en.wikivoyage.org/w/api.php';
    private const TYPES = ['see', 'do', 'buy', 'eat', 'drink', 'sleep', 'listing', 'go'];
    private const SECTION_TYPE = ['see' => 'see', 'do' => 'do', 'buy' => 'buy', 'eat' => 'eat', 'drink' => 'drink', 'sleep' => 'sleep'];

    public function __construct(private HttpClient $http)
    {
    }

    /** Find the guide's page title for a free-text destination. */
    public function resolve(array $candidates): ?string
    {
        $requests = [];
        foreach (array_values(array_unique(array_filter($candidates))) as $i => $name) {
            $requests["t$i"] = [
                'service' => 'Wikivoyage search',
                'url' => self::API . '?action=query&list=search&srlimit=3&srnamespace=0&format=json&formatversion=2&srsearch=' . rawurlencode($name),
                'ttl' => 86400 * 14,
            ];
        }
        foreach ($this->http->multi($requests) as $r) {
            foreach ($r['query']['search'] ?? [] as $hit) {
                $title = (string)$hit['title'];
                // Skip phrasebooks, itineraries and other non-destination guides.
                if (!preg_match('/phrasebook|itinerar|travel topic|^List of/i', $title)) {
                    return $title;
                }
            }
        }
        return null;
    }

    /**
     * @return array{title:string, lat:?float, lng:?float, banner:?string, tagline:string, metro:bool, shared:?string, listings:list<array<string,mixed>>}|null
     */
    public function guide(string $title): ?array
    {
        $main = $this->page($title);
        if (!$main) {
            return null;
        }
        $wikitext = $main;
        $listings = $this->listings($wikitext, $title);

        // Huge cities keep their listings in district sub-pages.
        if (count($listings) < 40 && preg_match_all('/\[\[(' . preg_quote($title, '/') . '\/[^\]|#]+)/u', $wikitext, $m)) {
            $districts = array_slice(array_values(array_unique($m[1])), 0, 8);
            $requests = [];
            foreach ($districts as $i => $d) {
                $requests["d$i"] = $this->pageRequest($d);
            }
            foreach ($this->http->multi($requests) as $key => $r) {
                $text = $r['parse']['wikitext'] ?? null;
                if ($text) {
                    $name = $districts[(int)substr($key, 1)];
                    $listings = array_merge($listings, $this->listings($text, $name));
                }
            }
        }

        $lat = $lng = null;
        if (preg_match('/\{\{geo\|\s*(-?[\d.]+)\s*\|\s*(-?[\d.]+)/i', $wikitext, $g)) {
            [$lat, $lng] = [(float)$g[1], (float)$g[2]];
        }
        $banner = null;
        if (preg_match('/\{\{pagebanner\|\s*([^|}\n]+\.(?:jpe?g|png))/i', $wikitext, $b)) {
            $banner = self::commons(trim($b[1]), 1600);
        }

        $getAround = self::section($wikitext, 'Get around');
        $shared = null;
        if (preg_match('/\b(tuk-?tuks?|auto-?rickshaws?|xe ôm|motorbike taxis?|jeepneys?|songthaews?|cyclos?|dolmuş|marshrutka|colectivos?|matatus?)\b/iu', $getAround, $s)) {
            $shared = ucfirst(strtolower($s[1]));
        }

        return [
            'title' => $title,
            'lat' => $lat,
            'lng' => $lng,
            'banner' => $banner,
            'tagline' => self::lead($wikitext),
            'metro' => (bool)preg_match('/\b(metro|subway|mrt|lrt|underground|u-bahn|s-bahn|light rail|skytrain|tram)\b/i', $getAround),
            'shared' => $shared,
            'listings' => $listings,
        ];
    }

    /** Commons file → direct, resizable image URL (no key, redirects to upload.wikimedia.org). */
    public static function commons(string $file, int $width = 960): string
    {
        $file = str_replace(' ', '_', preg_replace('/^(File|Image):/i', '', trim($file)));
        return 'https://commons.wikimedia.org/wiki/Special:FilePath/' . rawurlencode($file) . '?width=' . $width;
    }

    private function pageRequest(string $title): array
    {
        return [
            'service' => 'Wikivoyage guide',
            'url' => self::API . '?action=parse&prop=wikitext&redirects=1&format=json&formatversion=2&page=' . rawurlencode($title),
            'ttl' => 86400 * 7,
            'timeout' => 10,
        ];
    }

    private function page(string $title): ?string
    {
        $r = $this->http->multi(['p' => $this->pageRequest($title)])['p'];
        return $r['parse']['wikitext'] ?? null;
    }

    /** @return list<array<string, mixed>> */
    private function listings(string $text, string $page): array
    {
        // Section headings with their offsets, so each listing knows where it sits.
        preg_match_all('/^(={2,4})\s*([^=\n]+?)\s*\1\s*$/m', $text, $hm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        $heads = [];
        foreach ($hm as $h) {
            $heads[] = ['level' => strlen($h[1][0]), 'name' => strtolower(trim($h[2][0])), 'at' => $h[0][1]];
        }

        $out = [];
        $re = '/\{\{\s*(' . implode('|', self::TYPES) . ')\s*[\n|]/i';
        $offset = 0;
        while (preg_match($re, $text, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $m[0][1];
            $end = self::matchBraces($text, $start);
            if ($end === null) {
                break;
            }
            $offset = $end;
            $type = strtolower($m[1][0]);
            $params = self::params(substr($text, $start + 2, $end - $start - 4));

            $section = '';
            $sub = '';
            foreach ($heads as $h) {
                if ($h['at'] > $start) {
                    break;
                }
                if ($h['level'] === 2) {
                    $section = $h['name'];
                    $sub = '';
                } else {
                    $sub = $h['name'];
                }
            }
            if ($type === 'listing') {
                $type = strtolower($params['type'] ?? '') ?: (self::SECTION_TYPE[$section] ?? 'see');
            }
            if (empty($params['name'])) {
                continue;
            }
            $params['_type'] = $type;
            $params['_tier'] = self::tierFromHeading($sub);
            $params['_area'] = str_contains($page, '/') ? substr($page, strrpos($page, '/') + 1) : '';
            $out[] = $params;
        }
        return $out;
    }

    /** Index just past the "}}" that closes the template opening at $start. */
    private static function matchBraces(string $s, int $start): ?int
    {
        $depth = 0;
        $len = strlen($s);
        for ($i = $start; $i < $len - 1; $i++) {
            if ($s[$i] === '{' && $s[$i + 1] === '{') {
                $depth++;
                $i++;
            } elseif ($s[$i] === '}' && $s[$i + 1] === '}') {
                $depth--;
                $i++;
                if ($depth === 0) {
                    return $i + 1;
                }
            }
        }
        return null;
    }

    /** Split template params on top-level pipes, then clean wiki markup out of values. */
    private static function params(string $body): array
    {
        $parts = [];
        $buf = '';
        $depth = 0;
        $len = strlen($body);
        for ($i = 0; $i < $len; $i++) {
            $two = substr($body, $i, 2);
            if ($two === '{{' || $two === '[[') {
                $depth++;
                $buf .= $two;
                $i++;
                continue;
            }
            if ($two === '}}' || $two === ']]') {
                $depth--;
                $buf .= $two;
                $i++;
                continue;
            }
            if ($body[$i] === '|' && $depth === 0) {
                $parts[] = $buf;
                $buf = '';
                continue;
            }
            $buf .= $body[$i];
        }
        $parts[] = $buf;

        $out = [];
        foreach (array_slice($parts, 1) as $p) {
            $eq = strpos($p, '=');
            if ($eq === false) {
                continue;
            }
            $key = strtolower(trim(substr($p, 0, $eq)));
            $out[$key] = self::clean(substr($p, $eq + 1));
        }
        return $out;
    }

    public static function clean(string $v): string
    {
        $v = preg_replace('/<ref[^>]*>.*?<\/ref>|<ref[^>]*\/>|<!--.*?-->/s', '', $v);
        $v = preg_replace('/\[\[(?:[^|\]]*\|)?([^\]]+)\]\]/', '$1', $v);
        $v = preg_replace('/\[https?:\/\/\S+\s+([^\]]+)\]/', '$1', $v);
        // Simple inline templates ({{USD|10}}, {{km|3}}): keep their arguments.
        for ($i = 0; $i < 3; $i++) {
            $v = preg_replace_callback('/\{\{([^{}]*)\}\}/', function ($m) {
                $bits = explode('|', $m[1]);
                $name = strtolower(trim(array_shift($bits)));
                $args = implode(' ', array_filter(array_map('trim', $bits), fn($b) => !str_contains($b, '=')));
                return match (true) {
                    in_array($name, ['usd', 'us$'], true) => 'US$' . $args,
                    in_array($name, ['eur', 'euro'], true) => '€' . $args,
                    in_array($name, ['gbp'], true) => '£' . $args,
                    default => $args,
                };
            }, $v);
        }
        $v = str_replace(["'''", "''", '&nbsp;'], ['', '', ' '], $v);
        $v = strip_tags($v);
        return trim(preg_replace('/\s+/u', ' ', $v));
    }

    private static function tierFromHeading(string $h): ?int
    {
        return match (true) {
            (bool)preg_match('/budget|cheap|street/', $h) => 1,
            (bool)preg_match('/mid/', $h) => 2,
            (bool)preg_match('/splurge|luxury|upscale|high/', $h) => 4,
            default => null,
        };
    }

    private static function section(string $text, string $name): string
    {
        if (!preg_match('/^==\s*' . preg_quote($name, '/') . '\s*==\s*$/mi', $text, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $from = $m[0][1];
        $next = preg_match('/^==[^=].*==\s*$/m', $text, $n, PREG_OFFSET_CAPTURE, $from + strlen($m[0][0])) ? $n[0][1] : strlen($text);
        return substr($text, $from, $next - $from);
    }

    /** First sentence of the guide's lead paragraph. */
    private static function lead(string $text): string
    {
        foreach (preg_split('/\n\s*\n/', $text) as $para) {
            $para = trim($para);
            if ($para === '' || $para[0] === '{' || $para[0] === '[' || $para[0] === '=' || $para[0] === '|') {
                continue;
            }
            $clean = self::clean($para);
            $clean = preg_replace('/\s*\([^)]*\)/u', '', $clean); // drop native-script asides
            if (strlen($clean) < 40) {
                continue;
            }
            $sentence = preg_split('/(?<=[.!?])\s+/', $clean)[0];
            return mb_substr_safe($sentence, 220);
        }
        return '';
    }
}

function mb_substr_safe(string $s, int $max): string
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s;
    }
    return strlen($s) > $max ? rtrim(substr($s, 0, $max - 1)) . '…' : $s;
}

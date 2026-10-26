<?php
declare(strict_types=1);

/**
 * Forward geocoding: OpenCage (key) → Geoapify (key) → Nominatim (keyless).
 */
final class GeocodingService
{
    public function __construct(private HttpClient $http, private array $cfg)
    {
    }

    /** @return array{name:string,country:string,countryCode:string,lat:float,lng:float,currency:?string,tz:?string,source:string}|null */
    public function geocode(string $query): ?array
    {
        $q = rawurlencode($query);

        if ($this->cfg['opencage_key'] !== '') {
            $r = $this->http->json('OpenCage geocode', "https://api.opencagedata.com/geocode/v1/json?q={$q}&limit=1&no_annotations=0&key="
                . rawurlencode($this->cfg['opencage_key']), ['ttl' => 86400 * 30]);
            $hit = $r['results'][0] ?? null;
            if ($hit) {
                $c = $hit['components'] ?? [];
                return [
                    'name' => (string)($c['_normalized_city'] ?? $c['city'] ?? $c['town'] ?? $c['state'] ?? $query),
                    'country' => (string)($c['country'] ?? ''),
                    'countryCode' => strtoupper((string)($c['country_code'] ?? '')),
                    'lat' => (float)$hit['geometry']['lat'],
                    'lng' => (float)$hit['geometry']['lng'],
                    'currency' => $hit['annotations']['currency']['iso_code'] ?? null,
                    'tz' => $hit['annotations']['timezone']['name'] ?? null,
                    'source' => 'OpenCage',
                ];
            }
        }

        if ($this->cfg['geoapify_key'] !== '') {
            $r = $this->http->json('Geoapify geocode', "https://api.geoapify.com/v1/geocode/search?text={$q}&limit=1&format=json&apiKey="
                . rawurlencode($this->cfg['geoapify_key']), ['ttl' => 86400 * 30]);
            $hit = $r['results'][0] ?? null;
            if ($hit) {
                return [
                    'name' => (string)($hit['city'] ?? $hit['name'] ?? $hit['state'] ?? $query),
                    'country' => (string)($hit['country'] ?? ''),
                    'countryCode' => strtoupper((string)($hit['country_code'] ?? '')),
                    'lat' => (float)$hit['lat'],
                    'lng' => (float)$hit['lon'],
                    'currency' => null,
                    'tz' => $hit['timezone']['name'] ?? null,
                    'source' => 'Geoapify',
                ];
            }
        }

        $r = $this->http->json('Nominatim geocode', "https://nominatim.openstreetmap.org/search?q={$q}&format=jsonv2&limit=1&addressdetails=1&accept-language=en",
            ['ttl' => 86400 * 30]);
        $hit = $r[0] ?? null;
        if ($hit) {
            $a = $hit['address'] ?? [];
            return [
                'name' => (string)($a['city'] ?? $a['town'] ?? $a['village'] ?? $a['state'] ?? $hit['name'] ?? $query),
                'country' => (string)($a['country'] ?? ''),
                'countryCode' => strtoupper((string)($a['country_code'] ?? '')),
                'lat' => (float)$hit['lat'],
                'lng' => (float)$hit['lon'],
                'currency' => null,
                'tz' => null,
                'source' => 'Nominatim',
            ];
        }
        return null;
    }
}

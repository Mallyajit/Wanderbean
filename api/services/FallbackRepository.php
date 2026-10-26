<?php
declare(strict_types=1);

/**
 * Structured JSON fallback generator. Curated destination datasets live in
 * api/data/fallback/*.json; this repository matches a free-text destination to
 * a dataset and emits it in the normalised place schema, so Wanderbean produces a
 * complete gazette with zero API keys and zero network.
 */
final class FallbackRepository
{
    public function __construct(private string $dir)
    {
    }

    /** @return list<string> */
    public function destinations(): array
    {
        $names = [];
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            $data = json_decode((string)file_get_contents($file), true);
            if (isset($data['city']['name'])) {
                $names[] = $data['city']['name'] . ', ' . $data['city']['country'];
            }
        }
        sort($names);
        return $names;
    }

    /** @return array<string, mixed>|null raw dataset */
    public function find(string $destination): ?array
    {
        $needle = strtolower(trim((string)preg_replace('/[^a-z ]+/i', ' ', $destination)));
        if ($needle === '') {
            return null;
        }
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            $data = json_decode((string)file_get_contents($file), true);
            if (!is_array($data)) {
                continue;
            }
            foreach ($data['city']['aliases'] ?? [] as $alias) {
                if (preg_match('/\b' . preg_quote(strtolower($alias), '/') . '\b/', $needle)) {
                    return $data;
                }
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{ctx: array<string, mixed>, places: array<string, array<string, array<string, mixed>>>}
     */
    public function bundle(array $data): array
    {
        $city = $data['city'];
        $ctx = [
            'name' => $city['name'],
            'country' => $city['country'],
            'countryCode' => $city['countryCode'],
            'currency' => $city['currency'],
            'lat' => (float)$city['lat'],
            'lng' => (float)$city['lng'],
            'tz' => $city['tz'],
            'wiki' => $city['wiki'] ?? $city['name'],
            'tagline' => $city['tagline'] ?? '',
            'dinnerTime' => $city['dinnerTime'] ?? '19:30',
            'transit' => $data['transit'] + ['currency' => $city['currency']],
            'hubs' => $data['hubs'] ?? [],
            'passes' => $data['passes'] ?? [],
            'source' => 'SAMPLE',
        ];

        $places = ['hotels' => [], 'attractions' => [], 'eateries' => [], 'markets' => [], 'activities' => []];
        $map = ['hotels' => 'hotel', 'attractions' => 'attraction', 'eateries' => 'eatery', 'markets' => 'market', 'activities' => 'activity'];

        foreach ($map as $group => $kind) {
            foreach ($data[$group] ?? [] as $raw) {
                $p = $raw + [
                    'kind' => $kind,
                    'area' => '',
                    'rating' => null,
                    'ratingCount' => null,
                    'summary' => '',
                    'tip' => '',
                ];
                $p['ratingSource'] = 'SAMPLE';
                $p['priceEstimated'] = false;
                $p['priceLocal'] = (float)match ($kind) {
                    'hotel' => $raw['price'],
                    'attraction' => $raw['fee'] ?? 0,
                    'eatery' => $raw['cost'],
                    'market' => $raw['allowance'] ?? 0,
                    'activity' => $raw['price'],
                };
                $p['hours'] = Places::weekHours($raw['hours'] ?? Places::DEFAULT_HOURS[$kind], $raw['closed'] ?? []);
                $p['hoursKnown'] = isset($raw['hours']);
                $p['reviews'] = [];
                $p['image'] = null;
                $p['indoor'] = (bool)($raw['indoor'] ?? false);
                $p['golden'] = (bool)($raw['golden'] ?? false);
                $p['early'] = (bool)($raw['early'] ?? false);
                if (in_array($kind, ['attraction', 'activity', 'market'], true)) {
                    $p['tags'] ??= Places::classify($p['name'] . ' ' . $p['summary'] . ' ' . ($raw['crafts'] ?? ''))['tags'];
                }
                if ($kind === 'hotel') {
                    $p['style'] ??= CostModel::styleForTier((int)($raw['tier'] ?? 2));
                }
                $places[$group][$p['id']] = $p;
            }
        }

        return ['ctx' => $ctx, 'places' => $places];
    }
}

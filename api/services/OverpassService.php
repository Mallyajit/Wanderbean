<?php
declare(strict_types=1);

/**
 * Keyless POI discovery from OpenStreetMap via the Overpass API, used for
 * destinations without a curated dataset when no Google key is configured.
 * OSM carries no ratings, so ranking leans on Wikipedia/Wikidata presence.
 */
final class OverpassService
{
    /** Public Overpass instances, tried in order. */
    private const MIRRORS = [
        'https://overpass-api.de/api/interpreter',
        'https://maps.mail.ru/osm/tools/overpass/api/interpreter',
        'https://overpass.private.coffee/api/interpreter',
    ];

    public function __construct(private HttpClient $http)
    {
    }

    /** @return array<string, array<string, array<string, mixed>>>|null */
    public function discover(float $lat, float $lng, string $countryCode): ?array
    {
        $around = static fn(int $m) => sprintf('(around:%d,%.5f,%.5f)', $m, $lat, $lng);
        $q = '[out:json][timeout:20];('
            . 'nwr["tourism"~"^(attraction|museum|viewpoint|gallery|zoo)$"]["name"]' . $around(7000) . ';'
            . 'nwr["historic"~"^(castle|fort|palace|monument|city_gate)$"]["name"]' . $around(7000) . ';'
            . 'nwr["tourism"~"^(hotel|guest_house|hostel)$"]["name"]' . $around(3000) . ';'
            . 'nwr["amenity"~"^(restaurant|cafe|fast_food)$"]["name"]["cuisine"]' . $around(2000) . ';'
            . 'nwr["amenity"="marketplace"]["name"]' . $around(9000) . ';'
            . 'nwr["amenity"~"^(theatre|arts_centre)$"]["name"]' . $around(6000) . ';'
            . ');out center tags 450;';

        $r = null;
        foreach (self::MIRRORS as $endpoint) {
            $r = $this->http->json('Overpass (OSM)', $endpoint . '?data=' . rawurlencode($q), ['ttl' => 86400 * 7, 'timeout' => 20]);
            if (!empty($r['elements'])) {
                break;
            }
        }
        if (empty($r['elements'])) {
            return null;
        }

        $index = CostModel::index($countryCode);
        $b = CostModel::BASKET;
        $out = ['hotels' => [], 'attractions' => [], 'eateries' => [], 'markets' => [], 'activities' => []];

        foreach ($r['elements'] as $el) {
            $t = $el['tags'] ?? [];
            $pLat = $el['lat'] ?? $el['center']['lat'] ?? null;
            $pLng = $el['lon'] ?? $el['center']['lon'] ?? null;
            $name = $t['name:en'] ?? $t['name'] ?? null;
            if ($pLat === null || !$name) {
                continue;
            }
            $wiki = isset($t['wikipedia']) && str_starts_with($t['wikipedia'], 'en:') ? substr($t['wikipedia'], 3) : null;
            $notability = (isset($t['wikidata']) ? 1 : 0) + ($wiki ? 1 : 0);
            $base = [
                'id' => 'osm-' . $el['type'][0] . $el['id'],
                'name' => $name,
                'area' => $t['addr:suburb'] ?? $t['addr:neighbourhood'] ?? $t['addr:street'] ?? '',
                'lat' => (float)$pLat,
                'lng' => (float)$pLng,
                'rating' => null,
                'ratingCount' => null,
                'ratingSource' => 'OSM',
                'priceEstimated' => true,
                'summary' => $t['description'] ?? '',
                'tip' => '',
                'reviews' => [],
                'image' => null,
                'wiki' => $wiki,
                'mapsUri' => "https://www.openstreetmap.org/{$el['type']}/{$el['id']}",
                'notability' => $notability,
                'indoor' => false,
                'golden' => false,
                'early' => false,
                'hoursKnown' => false,
            ];
            $tourism = $t['tourism'] ?? '';
            $amenity = $t['amenity'] ?? '';
            $historic = $t['historic'] ?? '';

            if (in_array($tourism, ['hotel', 'guest_house', 'hostel'], true)) {
                $stars = (int)($t['stars'] ?? 0);
                $tier = $tourism === 'hostel' ? 1 : ($tourism === 'guest_house' ? 2 : ($stars >= 5 ? 5 : ($stars === 4 ? 4 : 3)));
                $out['hotels'][$base['id']] = $base + [
                    'kind' => 'hotel', 'tier' => $tier, 'style' => CostModel::styleForTier($tier),
                    'priceUsd' => $b['hotel'][$tier] * $index, 'hours' => Places::weekHours(null),
                ];
            } elseif (in_array($amenity, ['restaurant', 'cafe', 'fast_food'], true)) {
                $diet = [];
                foreach (['vegetarian', 'vegan', 'halal'] as $d) {
                    if (in_array($t['diet:' . $d] ?? '', ['yes', 'only'], true)) {
                        $diet[] = $d;
                    }
                }
                $meals = match ($amenity) {
                    'cafe' => ['breakfast', 'lunch'],
                    'fast_food' => ['lunch', 'dinner'],
                    default => ['lunch', 'dinner'],
                };
                $tier = $amenity === 'restaurant' ? 2 : 1;
                $out['eateries'][$base['id']] = $base + [
                    'kind' => 'eatery', 'meals' => $meals, 'diet' => $diet, 'tier' => $tier,
                    'dish' => isset($t['cuisine']) ? ucwords(str_replace(['_', ';'], [' ', ', '], $t['cuisine'])) : '',
                    'priceUsd' => $b['meal'][$amenity === 'cafe' ? 'breakfast' : 'lunch'] * $b['mealTier'][$tier] * $index,
                    'hours' => Places::weekHours(Places::DEFAULT_HOURS['eatery']),
                ];
            } elseif ($amenity === 'marketplace') {
                $out['markets'][$base['id']] = $base + [
                    'kind' => 'market', 'priceUsd' => $b['market'] * $index, 'night' => (bool)preg_match('/night/i', $name),
                    'crafts' => '', 'hours' => Places::weekHours(Places::DEFAULT_HOURS['market']),
                ];
            } elseif (in_array($amenity, ['theatre', 'arts_centre'], true)) {
                $out['activities'][$base['id']] = $base + [
                    'kind' => 'activity', 'slot' => 'evening', 'duration' => 105, 'value' => 2.5 + $notability,
                    'priceUsd' => $b['activity'] * max($index, 0.45), 'hours' => Places::weekHours(['17:00', '23:00']),
                    'addonOnly' => $notability === 0,
                ];
            } else {
                $indoor = in_array($tourism, ['museum', 'gallery'], true);
                $out['attractions'][$base['id']] = $base + [
                    'kind' => 'attraction',
                    'indoor' => $indoor,
                    'golden' => $tourism === 'viewpoint' || in_array($historic, ['castle', 'fort'], true),
                    'early' => $notability >= 2,
                    'must' => $notability >= 2,
                    'duration' => $indoor ? 100 : ($tourism === 'viewpoint' ? 40 : 70),
                    'priceUsd' => ($indoor || in_array($historic, ['castle', 'fort', 'palace'], true) || $tourism === 'zoo') ? $b['attraction'] * $index : 0.0,
                    'hours' => Places::weekHours($tourism === 'viewpoint' ? ['05:30', '22:00'] : Places::DEFAULT_HOURS['attraction']),
                    // OSM has no ratings: notable (Wikipedia-linked) places rank as if well reviewed.
                    'rating' => null,
                    'ratingCount' => [0 => 150, 1 => 3000, 2 => 30000][$notability],
                ];
            }
        }

        // Unrated hotels/eateries: keep the 40 closest to the centre to stay tractable.
        foreach (['eateries', 'hotels'] as $group) {
            uasort($out[$group], fn($x, $y) => Geo::km($lat, $lng, $x['lat'], $x['lng']) <=> Geo::km($lat, $lng, $y['lat'], $y['lng']));
            $out[$group] = array_slice($out[$group], 0, 40, true);
        }
        return $out['attractions'] ? $out : null;
    }
}

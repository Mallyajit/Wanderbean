<?php
declare(strict_types=1);

/**
 * Google Places API (New): places:searchText for discovery and ranking, then
 * Place Details for the verified reviews and opening hours of every stop that
 * makes it into the final itinerary.
 */
final class PlacesService
{
    private const BASE = 'https://places.googleapis.com/v1/';

    private const SEARCH_MASK = 'places.id,places.displayName,places.shortFormattedAddress,places.addressComponents,'
        . 'places.location,places.rating,places.userRatingCount,places.priceLevel,places.priceRange,'
        . 'places.regularOpeningHours,places.types,places.primaryType,places.photos,places.editorialSummary,'
        . 'places.googleMapsUri,places.servesBreakfast,places.servesLunch,places.servesDinner,places.servesVegetarianFood';

    private const DETAILS_MASK = 'id,rating,userRatingCount,priceLevel,regularOpeningHours,reviews,googleMapsUri,editorialSummary';

    private const INDOOR = ['museum', 'art_gallery', 'aquarium', 'shopping_mall', 'library', 'planetarium', 'movie_theater', 'performing_arts_theater', 'cultural_center'];
    private const FREE = ['park', 'place_of_worship', 'church', 'hindu_temple', 'mosque', 'synagogue', 'natural_feature', 'plaza', 'beach', 'hiking_area', 'national_park'];
    private const PAID_BIG = ['amusement_park', 'zoo', 'aquarium', 'water_park', 'theme_park'];
    private const PRICE_TIER = ['PRICE_LEVEL_FREE' => 0, 'PRICE_LEVEL_INEXPENSIVE' => 1, 'PRICE_LEVEL_MODERATE' => 2, 'PRICE_LEVEL_EXPENSIVE' => 3, 'PRICE_LEVEL_VERY_EXPENSIVE' => 4];

    public function __construct(private HttpClient $http, private string $key)
    {
    }

    public function enabled(): bool
    {
        return $this->key !== '';
    }

    /**
     * Discover every place category for a city in one parallel fan-out.
     *
     * @param array<string, mixed> $geo name, lat, lng, countryCode, currency
     * @return array<string, array<string, array<string, mixed>>>|null places grouped like FallbackRepository::bundle()
     */
    public function discover(array $geo): ?array
    {
        $city = $geo['name'];
        $queries = [
            'attractions' => ["top tourist attractions in {$city}", 20],
            'eat_local' => ["famous local food restaurants in {$city}", 20],
            'eat_breakfast' => ["best breakfast cafes in {$city}", 12],
            'eat_street' => ["best street food in {$city}", 10],
            'hotels_value' => ["best value budget hotels and guesthouses in {$city}", 20],
            'hotels_boutique' => ["boutique heritage hotels in {$city}", 10],
            'markets' => ["local markets bazaars and flea markets in {$city}", 10],
            'activities' => ["cultural experiences cooking classes and evening shows in {$city}", 10],
        ];

        $requests = [];
        foreach ($queries as $key => [$text, $max]) {
            $body = ['textQuery' => $text, 'maxResultCount' => $max, 'languageCode' => 'en'];
            if (isset($geo['lat'], $geo['lng'])) {
                $body['locationBias'] = ['circle' => ['center' => ['latitude' => $geo['lat'], 'longitude' => $geo['lng']], 'radius' => 20000.0]];
            }
            $requests[$key] = [
                'service' => 'Google Places searchText',
                'url' => self::BASE . 'places:searchText',
                'method' => 'POST',
                'headers' => ['X-Goog-Api-Key: ' . $this->key, 'X-Goog-FieldMask: ' . self::SEARCH_MASK],
                'body' => $body,
                'ttl' => 86400 * 3,
                'timeout' => 10,
            ];
        }

        $res = $this->http->multi($requests);
        if (empty($res['attractions']['places'])) {
            return null;
        }

        $cc = (string)($geo['countryCode'] ?? '');
        $currency = (string)($geo['currency'] ?? CostModel::currencyFor($cc));
        $index = CostModel::index($cc);
        $out = ['hotels' => [], 'attractions' => [], 'eateries' => [], 'markets' => [], 'activities' => []];

        foreach ($res['attractions']['places'] ?? [] as $g) {
            $p = $this->attraction($g, $index);
            $out['attractions'][$p['id']] = $p;
        }
        foreach (['eat_local' => null, 'eat_breakfast' => 'breakfast', 'eat_street' => 'street'] as $key => $hint) {
            foreach ($res[$key]['places'] ?? [] as $g) {
                $p = $this->eatery($g, $index, $currency, $hint);
                $out['eateries'][$p['id']] ??= $p;
            }
        }
        foreach (['hotels_value', 'hotels_boutique'] as $key) {
            foreach ($res[$key]['places'] ?? [] as $g) {
                $p = $this->hotel($g, $index);
                $out['hotels'][$p['id']] ??= $p;
            }
        }
        foreach ($res['markets']['places'] ?? [] as $g) {
            $p = $this->base($g, 'market');
            $p['priceUsd'] = CostModel::BASKET['market'] * $index;
            $p['night'] = (bool)preg_match('/night/i', $p['name']);
            $p['crafts'] = $p['summary'];
            $out['markets'][$p['id']] = $p;
        }
        foreach ($res['activities']['places'] ?? [] as $g) {
            $p = $this->activity($g, $index);
            $out['activities'][$p['id']] = $p;
        }
        // A place can surface in several searches; the more specific bucket wins.
        foreach (['eateries', 'hotels', 'markets', 'activities'] as $group) {
            $out['attractions'] = array_diff_key($out['attractions'], $out[$group]);
        }
        return $out;
    }

    /**
     * Place Details for the final stops: reviews (with author attribution),
     * hours and a canonical Maps link.
     *
     * @param list<string> $ids
     * @return array<string, array<string, mixed>>
     */
    public function details(array $ids): array
    {
        $requests = [];
        foreach (array_unique($ids) as $id) {
            $requests[$id] = [
                'service' => 'Google Place Details',
                'url' => self::BASE . 'places/' . rawurlencode($id) . '?languageCode=en',
                'headers' => ['X-Goog-Api-Key: ' . $this->key, 'X-Goog-FieldMask: ' . self::DETAILS_MASK],
                'ttl' => 86400 * 3,
            ];
        }
        $out = [];
        foreach ($this->http->multi($requests) as $id => $r) {
            if (!$r) {
                continue;
            }
            $reviews = [];
            foreach (array_slice($r['reviews'] ?? [], 0, 3) as $rv) {
                $reviews[] = [
                    'author' => $rv['authorAttribution']['displayName'] ?? 'Google user',
                    'authorUri' => $rv['authorAttribution']['uri'] ?? null,
                    'photo' => $rv['authorAttribution']['photoUri'] ?? null,
                    'rating' => $rv['rating'] ?? null,
                    'relative' => $rv['relativePublishTimeDescription'] ?? '',
                    'text' => $rv['text']['text'] ?? $rv['originalText']['text'] ?? '',
                ];
            }
            $out[$id] = [
                'reviews' => $reviews,
                'hoursText' => $r['regularOpeningHours']['weekdayDescriptions'] ?? [],
                'mapsUri' => $r['googleMapsUri'] ?? null,
                'rating' => $r['rating'] ?? null,
                'ratingCount' => $r['userRatingCount'] ?? null,
            ];
        }
        return $out;
    }

    /** Photo proxy URL so the API key never reaches the browser. */
    public static function photoUrl(?array $photos): ?string
    {
        $name = $photos[0]['name'] ?? null;
        return $name ? 'api/photo.php?ref=' . rawurlencode($name) : null;
    }

    /** @return array<string, mixed> */
    private function base(array $g, string $kind): array
    {
        $area = '';
        foreach ($g['addressComponents'] ?? [] as $c) {
            if (array_intersect($c['types'] ?? [], ['neighborhood', 'sublocality_level_1', 'sublocality'])) {
                $area = $c['longText'] ?? '';
                break;
            }
        }
        if ($area === '' && !empty($g['shortFormattedAddress'])) {
            $parts = array_map('trim', explode(',', $g['shortFormattedAddress']));
            $area = count($parts) > 1 ? $parts[count($parts) - 2] : $parts[0];
        }
        $hours = Places::fromGoogleHours($g['regularOpeningHours'] ?? null);
        return [
            'id' => 'g-' . substr(md5((string)$g['id']), 0, 10),
            'googleId' => $g['id'],
            'kind' => $kind,
            'name' => $g['displayName']['text'] ?? 'Unnamed place',
            'area' => $area,
            'lat' => (float)($g['location']['latitude'] ?? 0),
            'lng' => (float)($g['location']['longitude'] ?? 0),
            'rating' => isset($g['rating']) ? (float)$g['rating'] : null,
            'ratingCount' => isset($g['userRatingCount']) ? (int)$g['userRatingCount'] : null,
            'ratingSource' => 'GOOGLE',
            'priceEstimated' => true,
            'priceLevel' => $g['priceLevel'] ?? null,
            'hours' => $hours ?? Places::weekHours(Places::DEFAULT_HOURS[$kind]),
            'hoursKnown' => $hours !== null,
            'hoursText' => $g['regularOpeningHours']['weekdayDescriptions'] ?? [],
            'types' => $g['types'] ?? [],
            'summary' => $g['editorialSummary']['text'] ?? '',
            'tip' => '',
            'reviews' => [],
            'image' => self::photoUrl($g['photos'] ?? null),
            'mapsUri' => $g['googleMapsUri'] ?? null,
            'indoor' => false,
            'golden' => false,
            'early' => false,
        ];
    }

    private function attraction(array $g, float $index): array
    {
        $p = $this->base($g, 'attraction');
        $types = $p['types'];
        $p['indoor'] = (bool)array_intersect($types, self::INDOOR);
        $p['golden'] = !$p['indoor'] && (in_array('observation_deck', $types, true)
            || (bool)preg_match('/\b(view ?point|lookout|miradouro|beach|lake|bridge|hill|sunset|pier|promenade|fort|castle|tower|ghat)\b/i', $p['name']));
        $p['early'] = ($p['ratingCount'] ?? 0) >= 20000;
        $p['must'] = ($p['ratingCount'] ?? 0) >= 50000;
        $p['priceUsd'] = match (true) {
            (bool)array_intersect($types, self::PAID_BIG) => CostModel::BASKET['bigAttraction'] * $index,
            (bool)array_intersect($types, self::FREE) && !array_intersect($types, ['museum']) => 0.0,
            (bool)array_intersect($types, ['museum', 'art_gallery', 'historical_landmark', 'castle', 'tourist_attraction']) => CostModel::BASKET['attraction'] * $index,
            default => 0.0,
        };
        $p['duration'] = match (true) {
            (bool)array_intersect($types, self::PAID_BIG) => 180,
            (bool)array_intersect($types, ['museum', 'art_gallery']) => 110,
            (bool)array_intersect($types, ['place_of_worship', 'church', 'hindu_temple', 'mosque']) => 45,
            (bool)array_intersect($types, ['park', 'garden']) => 60,
            default => 75,
        };
        if ($p['priceUsd'] > 0 && $p['duration'] >= 75) {
            $p['guide'] = ['label' => 'Licensed local guide', 'priceUsd' => CostModel::BASKET['guide'] * max($index, 0.5)];
        }
        return $p;
    }

    private function eatery(array $g, float $index, string $currency, ?string $hint): array
    {
        $p = $this->base($g, 'eatery');
        $types = $p['types'];
        $tier = self::PRICE_TIER[$p['priceLevel'] ?? ''] ?? ($hint === 'street' ? 1 : 2);
        $meals = [];
        if (($g['servesBreakfast'] ?? false) || $hint === 'breakfast' || array_intersect($types, ['cafe', 'bakery', 'coffee_shop', 'breakfast_restaurant'])) {
            $meals[] = 'breakfast';
        }
        if (($g['servesLunch'] ?? true) || $hint === 'street') {
            $meals[] = 'lunch';
        }
        if (($g['servesDinner'] ?? $hint !== 'breakfast')) {
            $meals[] = 'dinner';
        }
        $p['meals'] = array_values(array_unique($meals ?: ['lunch', 'dinner']));
        $p['tier'] = max(1, $tier);
        $diet = [];
        if (($g['servesVegetarianFood'] ?? false) || in_array('vegetarian_restaurant', $types, true)) {
            $diet[] = 'vegetarian';
        }
        if (in_array('vegan_restaurant', $types, true)) {
            array_push($diet, 'vegan', 'vegetarian');
        }
        if (preg_match('/halal/i', $p['name'])) {
            $diet[] = 'halal';
        }
        $p['diet'] = array_values(array_unique($diet));
        $p['dish'] = '';

        $range = $g['priceRange'] ?? null;
        $start = $range['startPrice'] ?? null;
        if ($start && ($start['currencyCode'] ?? '') === $currency) {
            $end = $range['endPrice']['units'] ?? $start['units'];
            $p['priceLocal'] = ((float)$start['units'] + (float)$end) / 2;
            unset($p['priceUsd']);
        } else {
            $slot = in_array('breakfast', $p['meals'], true) && count($p['meals']) === 1 ? 'breakfast' : ($hint === 'street' ? 'street' : 'lunch');
            $p['priceUsd'] = CostModel::BASKET['meal'][$slot] * CostModel::BASKET['mealTier'][$tier] * $index * ($slot === 'lunch' ? 1.2 : 1.0);
        }
        return $p;
    }

    private function hotel(array $g, float $index): array
    {
        $p = $this->base($g, 'hotel');
        $name = $p['name'];
        $tier = match (true) {
            (bool)preg_match('/hostel|backpacker|dorm/i', $name) => 1,
            (bool)preg_match('/resort|palace|luxury|ritz|four seasons|taj|oberoi|aman|mandarin|peninsula|st\.? regis|park hyatt|leela|rosewood/i', $name) => 5,
            (bool)preg_match('/boutique|heritage|haveli|ryokan|suites|design|manor/i', $name) => 4,
            isset(self::PRICE_TIER[$p['priceLevel'] ?? '']) => max(2, min(5, self::PRICE_TIER[$p['priceLevel']] + 1)),
            ($p['rating'] ?? 0) >= 4.5 => 3,
            default => 2,
        };
        $p['tier'] = $tier;
        $p['style'] = CostModel::styleForTier($tier);
        $p['priceUsd'] = CostModel::BASKET['hotel'][$tier] * $index * (($p['rating'] ?? 4.0) >= 4.6 ? 1.12 : 1.0);
        return $p;
    }

    private function activity(array $g, float $index): array
    {
        $p = $this->base($g, 'activity');
        $name = $p['name'] . ' ' . $p['summary'];
        $p['slot'] = match (true) {
            (bool)preg_match('/food (walk|tour)|street food tour|dinner/i', $name) => 'dinner',
            (bool)preg_match('/show|theatre|theater|fado|dance|music|concert|night|cabaret/i', $name) => 'evening',
            default => 'day',
        };
        $p['includesDinner'] = $p['slot'] === 'dinner';
        $p['duration'] = $p['slot'] === 'day' ? 120 : 105;
        $p['priceUsd'] = CostModel::BASKET['activity'] * max($index, 0.45);
        $p['value'] = round(3 + (($p['rating'] ?? 4.3) - 4.3) * 3, 1);
        return $p;
    }
}

<?php
declare(strict_types=1);

/**
 * Builds a full place set for ANY destination from open data:
 * Wikivoyage listings (sights, activities, markets, food, stays) ranked by
 * Wikipedia popularity, topped up with Wikipedia GeoSearch sights, with
 * Photon geocoding for listings that only carry a street address.
 */
final class GuideSource
{
    private string $currency = 'USD';
    private const DAYS = ['su' => 0, 'mo' => 1, 'tu' => 2, 'we' => 3, 'th' => 4, 'fr' => 5, 'sa' => 6];

    public function __construct(
        private HttpClient $http,
        private WikivoyageService $voyage,
        private WikipediaService $wiki,
    ) {
    }

    /**
     * @param array<string, mixed> $geo name, lat, lng, countryCode
     * @return array{ctx: array<string, mixed>, places: array<string, array<string, array<string, mixed>>>}|null
     */
    public function discover(array $geo, string $typed): ?array
    {
        $title = $this->voyage->resolve([trim(explode(',', $typed)[0]), $geo['name']]);
        $guide = $title ? $this->voyage->guide($title) : null;

        $lat = (float)$geo['lat'];
        $lng = (float)$geo['lng'];
        // Trust the guide's own coordinates only when it agrees with the geocoder.
        if ($guide && $guide['lat'] !== null && Geo::km($lat, $lng, $guide['lat'], $guide['lng']) < 30) {
            [$lat, $lng] = [$guide['lat'], $guide['lng']];
        }
        $center = ['lat' => $lat, 'lng' => $lng];

        $cc = (string)$geo['countryCode'];
        $currency = (string)($geo['currency'] ?? CostModel::currencyFor($cc));
        $this->currency = $currency;
        $index = CostModel::index($cc);
        $b = CostModel::BASKET;

        $listings = $guide['listings'] ?? [];
        $this->geocodeMissing($listings, $center, $geo['name']);

        $places = ['hotels' => [], 'attractions' => [], 'eateries' => [], 'markets' => [], 'activities' => []];
        $wikiTitles = [];
        foreach ($listings as $l) {
            if (!empty($l['wikipedia'])) {
                $wikiTitles[] = $l['wikipedia'];
            }
        }
        $nearby = $this->wiki->nearby($lat, $lng);
        $details = $this->wiki->details($wikiTitles);

        foreach ($listings as $l) {
            if (!isset($l['_lat'])) {
                continue;
            }
            if (Geo::km($lat, $lng, $l['_lat'], $l['_lng']) > 35) {
                continue; // day-trip destinations listed under "Go next" etc.
            }
            $text = $l['name'] . ' ' . ($l['alt'] ?? '') . ' ' . ($l['content'] ?? '');
            $base = $this->base($l, $currency, $details);
            $type = $l['_type'];

            if ($type === 'see' || ($type === 'do' && preg_match('/\b(park|garden|beach|lake|temple|museum|viewpoint|square|bridge)\b/i', $l['name']) && ($base['priceLocal'] ?? 0) == 0)) {
                if (preg_match('/\b(market|bazaar|souk|souq)\b/i', $l['name'])) {
                    $places['markets'][$base['id']] = $this->market($base, $text, $index);
                    continue;
                }
                $places['attractions'][$base['id']] = $this->attraction($base, $l, $text, $index);
            } elseif ($type === 'do' || $type === 'go') {
                if (preg_match('/\b(market|bazaar|souk|souq)\b/i', $l['name'])) {
                    $places['markets'][$base['id']] = $this->market($base, $text, $index);
                } elseif ($type === 'do') {
                    $places['activities'][$base['id']] = $this->activity($base, $text, $index);
                }
            } elseif ($type === 'buy') {
                if (preg_match('/\b(market|bazaar|souk|souq|night|flea|street|arcade|alley|lane)\b/i', $text)
                    && !preg_match('/\b(mall|avm|shopping cent(er|re)|outlet|department store|supermarket)\b/i', $l['name'])) {
                    $places['markets'][$base['id']] = $this->market($base, $text, $index);
                }
            } elseif ($type === 'eat' || ($type === 'drink' && preg_match('/\b(caf[eé]|coffee|tea ?house|bakery|juice)\b/iu', $text))) {
                $places['eateries'][$base['id']] = $this->eatery($base, $l, $text, $index, $type === 'drink');
            } elseif ($type === 'sleep') {
                $places['hotels'][$base['id']] = $this->hotel($base, $l, $text, $index);
            }
        }

        // Guide listings without a wikipedia= link borrow popularity and a photo
        // from the nearby article with the same name (or one within 150 m).
        foreach ($places['attractions'] as &$a) {
            if (!empty($a['popularity'])) {
                continue;
            }
            foreach ($nearby as $w) {
                $same = self::norm($w['title']) === self::norm($a['name'])
                    || str_contains(self::norm($w['title']), self::norm($a['name']))
                    || str_contains(self::norm($a['name']), self::norm($w['title']));
                if ($same && Geo::km($a['lat'], $a['lng'], $w['lat'], $w['lng']) < 1.5
                    || Geo::km($a['lat'], $a['lng'], $w['lat'], $w['lng']) < 0.15) {
                    $a['popularity'] = $w['views'];
                    $a['wiki'] ??= $w['title'];
                    $a['image'] ??= $w['image'];
                    break;
                }
            }
        }
        unset($a);

        // Top up sights from Wikipedia GeoSearch: notable places the guide skipped.
        $known = [];
        foreach ($places['attractions'] as $a) {
            $known[self::norm($a['name'])] = true;
            if (!empty($a['wiki'])) {
                $known[self::norm($a['wiki'])] = true;
            }
        }
        $added = 0;
        foreach ($nearby as $w) {
            if ($added >= 25 || $w['views'] < 300) {
                break;
            }
            if (isset($known[self::norm($w['title'])])) {
                continue;
            }
            $c = Places::classify($w['title'] . ' ' . $w['description']);
            $id = 'wp-' . Places::slug($w['title']);
            $places['attractions'][$id] = [
                'id' => $id,
                'kind' => 'attraction',
                'name' => $w['title'],
                'area' => '',
                'lat' => $w['lat'],
                'lng' => $w['lng'],
                'rating' => null,
                'ratingCount' => null,
                'popularity' => $w['views'],
                'ratingSource' => 'WIKIPEDIA',
                'priceEstimated' => true,
                'priceUsd' => $c['indoor'] ? $b['attraction'] * $index : 0.0,
                'hours' => Places::weekHours(Places::DEFAULT_HOURS['attraction']),
                'hoursKnown' => false,
                'summary' => ucfirst($w['description']),
                'tip' => '',
                'reviews' => [],
                'image' => $w['image'],
                'wiki' => $w['title'],
                'mapsUri' => 'https://en.wikipedia.org/wiki/' . rawurlencode(str_replace(' ', '_', $w['title'])),
                'indoor' => $c['indoor'],
                'golden' => $c['golden'],
                'early' => false,
                'tags' => $c['tags'],
                'duration' => $c['duration'],
            ];
            $known[self::norm($w['title'])] = true;
            $added++;
        }

        // The most-read sights draw crowds: visit them first thing.
        $views = array_filter(array_map(fn($a) => $a['popularity'] ?? 0, $places['attractions']));
        rsort($views);
        $crowd = $views[min(4, count($views) - 1)] ?? PHP_INT_MAX;
        foreach ($places['attractions'] as &$a) {
            $a['early'] = ($a['popularity'] ?? 0) >= max($crowd, 3000);
            $a['must'] = ($a['popularity'] ?? 0) >= ($views[1] ?? PHP_INT_MAX);
        }
        unset($a);

        if (count($places['attractions']) < 3) {
            return null;
        }

        $transit = CostModel::genericTransit();
        $transit['bus']['fare'] *= $index;
        $transit['taxi']['base'] *= max($index, 0.3);
        $transit['taxi']['perKm'] *= max($index, 0.3);
        $transit['carDay']['price'] *= max($index, 0.35);
        if (!empty($guide['metro'])) {
            $transit['metro'] = ['label' => 'Metro / rail', 'fare' => $b['metro'] * max($index, 0.3), 'speed' => 30, 'access' => 12, 'radiusKm' => 6];
        }
        if (!empty($guide['shared'])) {
            $transit['shared'] = ['label' => $guide['shared'], 'fare' => $b['bus'] * 1.6 * $index, 'speed' => 16, 'maxKm' => 6];
        }

        return [
            'ctx' => [
                'name' => $guide ? preg_replace('/ \(.*\)$/', '', $guide['title']) : $geo['name'],
                'country' => $geo['country'],
                'countryCode' => $cc,
                'currency' => $currency,
                'lat' => $lat,
                'lng' => $lng,
                'tz' => $geo['tz'] ?? null,
                'wiki' => $geo['name'],
                'image' => $guide['banner'] ?? null,
                'imagePage' => $title ? 'https://en.wikivoyage.org/wiki/' . rawurlencode(str_replace(' ', '_', $title)) : null,
                'tagline' => $guide['tagline'] ?? '',
                'dinnerTime' => self::dinnerTime($cc),
                'transit' => $transit,
                'hubs' => [],
                'passes' => [],
                'source' => 'WIKIVOYAGE',
            ],
            'places' => $places,
        ];
    }

    // ─── Listing → place ────────────────────────────────────────────────────

    private function base(array $l, string $currency, array $details): array
    {
        $wiki = $l['wikipedia'] ?? null;
        $d = $wiki ? ($details[$wiki] ?? null) : null;
        $content = (string)($l['content'] ?? '');
        $sentences = preg_split('/(?<=[.!?])\s+/', $content, 3);
        $price = self::price((string)($l['price'] ?? ''), $currency);
        $hours = self::hours((string)($l['hours'] ?? ''));

        $p = [
            'id' => 'wv-' . substr(md5($l['name'] . $l['_lat'] . $l['_lng']), 0, 10),
            'name' => self::short($l['name'], 60),
            'area' => self::area($l),
            'lat' => (float)$l['_lat'],
            'lng' => (float)$l['_lng'],
            'rating' => null,
            'ratingCount' => null,
            'popularity' => $d['views'] ?? null,
            'ratingSource' => 'WIKIVOYAGE',
            'priceEstimated' => $price === null,
            'priceText' => (string)($l['price'] ?? ''),
            'hours' => $hours['week'],
            'hoursKnown' => $hours['known'],
            'hoursText' => !empty($l['hours']) ? [(string)$l['hours']] : [],
            'summary' => self::short((string)($sentences[0] ?? ''), 200),
            'tip' => self::short((string)($sentences[1] ?? ''), 200),
            'reviews' => [],
            'image' => !empty($l['image']) ? WikivoyageService::commons($l['image']) : ($d['image'] ?? null),
            'wiki' => $wiki,
            'mapsUri' => !empty($l['url']) && str_starts_with((string)$l['url'], 'http') ? $l['url'] : null,
            'indoor' => false,
            'golden' => false,
            'early' => false,
        ];
        if ($price !== null) {
            if (isset($price['usd'])) {
                $p['priceUsd'] = $price['usd'];
            } else {
                $p['priceLocal'] = $price['local'];
            }
        }
        return $p;
    }

    private function attraction(array $p, array $l, string $text, float $index): array
    {
        $c = Places::classify($text);
        $p['kind'] = 'attraction';
        $p['tags'] = $c['tags'];
        $p['indoor'] = $c['indoor'];
        $p['golden'] = $c['golden'];
        $p['duration'] = $c['duration'];
        if (!isset($p['priceLocal']) && !isset($p['priceUsd'])) {
            $p['priceUsd'] = $c['indoor'] ? CostModel::BASKET['attraction'] * $index : 0.0;
        }
        if ($c['duration'] >= 70 && ($p['priceLocal'] ?? $p['priceUsd'] ?? 0) > 0) {
            $p['guide'] = ['label' => 'Local guide (est.)', 'priceUsd' => CostModel::BASKET['guide'] * max($index, 0.5)];
        }
        return $p;
    }

    private function market(array $p, string $text, float $index): array
    {
        $p['kind'] = 'market';
        $p['night'] = (bool)preg_match('/\bnight\b/i', $text);
        if ($p['night'] && !$p['hoursKnown']) {
            $p['hours'] = Places::weekHours(['17:30', '23:00']);
        } elseif (!$p['hoursKnown']) {
            $p['hours'] = Places::weekHours(Places::DEFAULT_HOURS['market']);
        }
        $p['crafts'] = $p['summary'];
        unset($p['priceLocal']);
        $p['priceUsd'] = CostModel::BASKET['market'] * $index;
        $p['priceEstimated'] = true;
        $p['tags'] = ['shopping', 'food'];
        $p['duration'] = 75;
        return $p;
    }

    private function activity(array $p, string $text, float $index): array
    {
        $slot = match (true) {
            (bool)preg_match('/\b(food (tour|walk)|street food tour|dinner cruise|cooking dinner)\b/i', $text) => 'dinner',
            (bool)preg_match('/\b(show|theat(re|er)|puppet|concert|cabaret|opera|ballet|night|jazz|live music|performance|cinema)\b/i', $text) => 'evening',
            (bool)preg_match('/\b(sunrise|balloon|dawn|yoga|tai chi)\b/i', $text) => 'morning',
            default => 'day',
        };
        $p['kind'] = 'activity';
        $p['slot'] = $slot;
        $p['includesDinner'] = $slot === 'dinner';
        $p['duration'] = $slot === 'day' ? 120 : 105;
        if (!isset($p['priceLocal']) && !isset($p['priceUsd'])) {
            $p['priceUsd'] = CostModel::BASKET['activity'] * max($index, 0.45);
        }
        $c = Places::classify($text);
        $p['tags'] = $c['tags'];
        $p['value'] = round(3 + min(1.5, log10(($p['popularity'] ?? 0) + 1) / 3), 1);
        // Guides list every gym, spa and class under "Do": only evening shows are
        // scheduled automatically; everything else is offered as an add-on.
        $p['addonOnly'] = $slot !== 'evening';
        $p['short'] = self::short($p['name'], 24);
        $p['pitch'] = $p['name'];
        return $p;
    }

    private function eatery(array $p, array $l, string $text, float $index, bool $cafe): array
    {
        $tier = $l['_tier'] ?? ($cafe ? 1 : 2);
        $meals = $cafe ? ['breakfast'] : ['lunch', 'dinner'];
        if (!$cafe && preg_match('/\b(breakfast|brunch|caf[eé]|coffee|bakery|pastr|pho|congee|dim sum|idli|dosa|croissant)\w*/iu', $text)) {
            $meals[] = 'breakfast';
        }
        if ($tier <= 1 || preg_match('/\b(street|stall|hawker|food court|cart|kiosk)\b/i', $text)) {
            $meals[] = 'street';
        }
        $diet = [];
        if (preg_match('/\b(vegetarian|pure veg|veg\b|vegan|plant-based)/i', $text)) {
            $diet[] = 'vegetarian';
        }
        if (preg_match('/\b(vegan|plant-based)\b/i', $text)) {
            $diet[] = 'vegan';
        }
        if (preg_match('/\bhalal\b/i', $text)) {
            $diet[] = 'halal';
        }
        $p['kind'] = 'eatery';
        $p['meals'] = array_values(array_unique($meals));
        $p['diet'] = array_values(array_unique($diet));
        $p['tier'] = $tier;
        $p['dish'] = '';
        if (!$p['hoursKnown']) {
            $p['hours'] = Places::weekHours($cafe ? ['07:30', '20:00'] : Places::DEFAULT_HOURS['eatery']);
        }
        // Menu prices in guides are a mix of mains and set meals: trust them only in a sane band.
        $estimate = CostModel::BASKET['meal'][$cafe ? 'breakfast' : 'lunch'] * CostModel::BASKET['mealTier'][$tier] * $index * 1.15;
        return $this->sane($p, $estimate, 0.25, 4);
    }

    private function hotel(array $p, array $l, string $text, float $index): array
    {
        $hostel = (bool)preg_match('/\b(hostel|dorm|backpacker)/i', $text);
        $tier = match ($l['_tier'] ?? null) {
            1 => $hostel ? 1 : 2,
            2 => 3,
            4 => preg_match('/\b(boutique|heritage|design|ryokan|riad|haveli)\b/i', $text) ? 4 : 5,
            default => $hostel ? 1 : 3,
        };
        $p['kind'] = 'hotel';
        $p['tier'] = $tier;
        $p['style'] = CostModel::styleForTier($tier);
        $p['amenities'] = array_values(array_filter([
            preg_match('/\bpool\b/i', $text) ? 'Pool' : null,
            preg_match('/\bbreakfast\b/i', $text) ? 'Breakfast' : null,
            preg_match('/\bwi-?fi\b/i', $text) ? 'Wi-Fi' : null,
            preg_match('/\b(air[- ]?con|a\/c)/i', $text) ? 'Air-con' : null,
            preg_match('/\brooftop\b/i', $text) ? 'Rooftop' : null,
        ]));
        $p['hours'] = Places::weekHours(null);
        // Dorm-bed prices undercut a room; only trust parsed prices in a sane band.
        return $this->sane($p, CostModel::BASKET['hotel'][$tier] * $index, 0.3, 4);
    }

    /** Keep a parsed price only if it lands within [lo, hi] × the regional estimate (USD). */
    private function sane(array $p, float $estimateUsd, float $lo, float $hi): array
    {
        $usd = $p['priceUsd'] ?? (isset($p['priceLocal']) ? $p['priceLocal'] / (CostModel::USD_RATES[$this->currency] ?? 1) : null);
        if ($usd === null || $usd < $estimateUsd * $lo || $usd > $estimateUsd * $hi) {
            unset($p['priceLocal']);
            $p['priceUsd'] = $estimateUsd;
            $p['priceEstimated'] = true;
        }
        return $p;
    }

    // ─── Parsing helpers ────────────────────────────────────────────────────

    /**
     * Price text → amount. Returns ['local' => x], ['usd' => x] or null.
     * "Adult 40,000 dong" → local 40000; "USD12-25" → usd 12; "Free" → local 0.
     */
    public static function price(string $text, string $local): ?array
    {
        $t = trim($text);
        if ($t === '') {
            return null;
        }
        if (preg_match('/^\s*(free|no charge|gratis)\b/i', $t) || (preg_match('/\bfree\b/i', $t) && !preg_match('/\d/', $t))) {
            return ['local' => 0.0];
        }
        // First number that is a price — not "10-course", "2 people" or "30%"; a
        // trailing "k" on a range ("180-200k") applies to both ends.
        $num = '(\d{1,3}(?:[,.]\d{3})+|\d+(?:[.,]\d{1,2})?)';
        $skip = '(?!\s*-?\s*(?:course|%|people|persons?|pax|min|hours?|years?|days?|nights?|am\b|pm\b))';
        if (!preg_match('/' . $num . '(?:\s*[-–]\s*' . $num . ')?\s*(k\b|thousand)?' . $skip . '(?![\d,.])/iu', $t, $m)) {
            return null;
        }
        $raw = $m[1];
        $amount = preg_match('/^\d{1,3}([,.]\d{3})+$/', $raw)
            ? (float)preg_replace('/[,.]/', '', $raw)
            : (float)str_replace(',', '.', $raw);
        if (!empty($m[3])) {
            $amount *= 1000;
        }

        $dollarLocal = in_array($local, ['USD', 'AUD', 'CAD', 'NZD', 'SGD', 'HKD', 'MXN', 'TWD', 'ARS', 'CLP', 'COP'], true);
        $foreign = match (true) {
            $local !== 'USD' && (bool)preg_match('/US\$|(?<![A-Z])USD|\bUS dollars?/i', $t) => 'USD',
            !$dollarLocal && str_contains($t, '$') => 'USD',
            $local !== 'EUR' && (bool)preg_match('/€|\bEUR\b|\beuros?\b/i', $t) => 'EUR',
            $local !== 'GBP' && (bool)preg_match('/£|\bGBP\b/i', $t) => 'GBP',
            default => null,
        };
        if ($foreign === null) {
            // Guides for big-denomination currencies often drop the thousands ("Pho 50 dong").
            if ($amount > 0 && $amount < 1000 && (CostModel::USD_RATES[$local] ?? 1) >= 1000) {
                $amount *= 1000;
            }
            return ['local' => $amount];
        }
        $usd = $amount / (CostModel::USD_RATES[$foreign] ?? 1);
        return ['usd' => $usd];
    }

    /**
     * Opening-hours text → week array. Handles "Daily 08:30-17:00",
     * "9AM-5PM", "Tu-Su 10:00-18:00", "closed Mondays", "24 hours".
     *
     * @return array{week: list<array{0:int,1:int}|null>, known: bool}
     */
    public static function hours(string $text): array
    {
        $t = strtolower($text);
        if ($t === '') {
            return ['week' => Places::weekHours(Places::DEFAULT_HOURS['attraction']), 'known' => false];
        }
        if (preg_match('/24\s*(h|hours|\/7)|always open/', $t)) {
            return ['week' => Places::weekHours(['00:00', '23:59']), 'known' => true];
        }
        $re = '/(\d{1,2})(?:[:.h](\d{2}))?\s*(am|pm|a\.m\.|p\.m\.)?\s*(?:-|–|—|to|until)\s*(\d{1,2})(?:[:.h](\d{2}))?\s*(am|pm|a\.m\.|p\.m\.)?/';
        if (!preg_match_all($re, $t, $ranges, PREG_SET_ORDER)) {
            return ['week' => Places::weekHours(Places::DEFAULT_HOURS['attraction']), 'known' => false];
        }
        $toMin = static function (string $h, ?string $mm, ?string $ap): int {
            $h = (int)$h;
            $ap = $ap ? $ap[0] : '';
            if ($ap === 'p' && $h < 12) {
                $h += 12;
            } elseif ($ap === 'a' && $h === 12) {
                $h = 0;
            }
            return min(23 * 60 + 59, $h * 60 + (int)($mm ?: 0));
        };
        // Split shifts ("8-11:30, 14-16:30") collapse to their envelope.
        $open = 24 * 60;
        $close = 0;
        foreach ($ranges as $m) {
            $o = $toMin($m[1], $m[2] ?? null, ($m[3] ?? '') ?: ($m[6] ?? null));
            $c = $toMin($m[4], $m[5] ?? null, ($m[6] ?? '') ?: null);
            if ($c <= $o && empty($m[6]) && (int)$m[4] < 12) {
                $c += 12 * 60; // "9-5" means 17:00
            }
            if ($c <= $o) {
                $c = 23 * 60 + 59;
            }
            $open = min($open, $o);
            $close = max($close, min($c, 23 * 60 + 59));
        }

        $closed = [];
        if (preg_match_all('/closed\s+(?:on\s+)?(su|mo|tu|we|th|fr|sa)[a-z]*/', $t, $cm)) {
            foreach ($cm[1] as $d) {
                $closed[] = self::DAYS[$d];
            }
        }
        // "Tu-Su 10:00-18:00" style ranges: everything outside is closed.
        if (preg_match('/^\s*(su|mo|tu|we|th|fr|sa)[a-z]*\s*[-–]\s*(su|mo|tu|we|th|fr|sa)[a-z]*/', $t, $rm)) {
            $from = self::DAYS[$rm[1]];
            $to = self::DAYS[$rm[2]];
            $openDays = [];
            for ($d = $from; ; $d = ($d + 1) % 7) {
                $openDays[] = $d;
                if ($d === $to || count($openDays) > 7) {
                    break;
                }
            }
            $closed = array_merge($closed, array_diff(range(0, 6), $openDays));
        }
        return ['week' => Places::weekHours([Geo::fmt($open), Geo::fmt($close)], array_values(array_unique($closed))), 'known' => true];
    }

    /** Fill in coordinates for the stays and kitchens a guide lists by address only. */
    private function geocodeMissing(array &$listings, array $center, string $city): void
    {
        $have = ['sleep' => 0, 'eat' => 0];
        foreach ($listings as $i => $l) {
            $lat = (float)($l['lat'] ?? 0);
            $lng = (float)($l['long'] ?? 0);
            if ($lat != 0.0 && $lng != 0.0) {
                $listings[$i]['_lat'] = $lat;
                $listings[$i]['_lng'] = $lng;
                if (isset($have[$l['_type']])) {
                    $have[$l['_type']]++;
                }
            }
        }
        $want = ['sleep' => max(0, 8 - $have['sleep']), 'eat' => max(0, 10 - $have['eat'])];
        $requests = [];
        foreach ($listings as $i => $l) {
            $type = $l['_type'];
            if (isset($l['_lat']) || !isset($want[$type]) || $want[$type] <= 0 || empty($l['address'])) {
                continue;
            }
            $want[$type]--;
            $q = $l['name'] . ', ' . $l['address'] . ', ' . $city;
            $requests["g$i"] = [
                'service' => 'Photon geocode',
                'url' => sprintf('https://photon.komoot.io/api/?limit=1&lat=%.4f&lon=%.4f&q=%s', $center['lat'], $center['lng'], rawurlencode($q)),
                'ttl' => 86400 * 30,
                'timeout' => 6,
            ];
        }
        foreach ($this->http->multi($requests) as $key => $r) {
            $f = $r['features'][0]['geometry']['coordinates'] ?? null;
            if (!$f) {
                continue;
            }
            $i = (int)substr($key, 1);
            if (Geo::km($center['lat'], $center['lng'], (float)$f[1], (float)$f[0]) < 15) {
                $listings[$i]['_lat'] = (float)$f[1];
                $listings[$i]['_lng'] = (float)$f[0];
            }
        }
    }

    private static function area(array $l): string
    {
        if (!empty($l['_area'])) {
            return $l['_area'];
        }
        // A neighbourhood name if the address ends with one; street numbers are not areas.
        $parts = array_values(array_filter(array_map('trim', explode(',', (string)($l['address'] ?? '')))));
        $last = $parts ? end($parts) : '';
        return count($parts) > 1 && !preg_match('/\d|\b(street|road|st|ave?|avenue|calle|rue|via|strasse|ul)\b/i', $last) ? self::short($last, 32) : '';
    }

    private static function short(string $s, int $max): string
    {
        return mb_substr_safe(trim($s), $max);
    }

    private static function norm(string $s): string
    {
        return strtolower((string)preg_replace('/[^a-z0-9]+/i', '', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s));
    }

    /** Typical dinner hour by region — Spain dines late, Japan early. */
    private static function dinnerTime(string $cc): string
    {
        return match (strtoupper($cc)) {
            'ES', 'AR', 'PT', 'GR', 'IT' => '20:30',
            'JP', 'KR', 'DE', 'NL', 'SE', 'NO', 'DK', 'FI', 'GB', 'US', 'CA', 'AU', 'NZ' => '18:45',
            default => '19:30',
        };
    }
}

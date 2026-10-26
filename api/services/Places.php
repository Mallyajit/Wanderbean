<?php
declare(strict_types=1);

/**
 * Shared helpers for the normalised place schema every source adapter emits.
 *
 * A place: id, kind (hotel|attraction|eatery|market|activity), name, area, lat,
 * lng, rating, ratingCount, ratingSource, priceLocal|priceUsd, hours (7 entries
 * indexed Sun..Sat, each [openMin, closeMin] or null when closed), duration,
 * indoor, golden, early, meals, diet, tier, style, summary, tip, reviews, image,
 * wiki, photoRef, googleId, mapsUri, plus kind-specific fields.
 */
final class Places
{
    public const DEFAULT_HOURS = [
        'attraction' => ['09:00', '18:00'],
        'eatery' => ['08:00', '22:30'],
        'market' => ['10:30', '21:00'],
        'activity' => ['09:00', '22:30'],
        'hotel' => ['00:00', '23:59'],
    ];

    /**
     * @param array{0:string,1:string}|null $pair
     * @param list<int> $closed weekdays (0 = Sunday)
     * @return list<array{0:int,1:int}|null>
     */
    public static function weekHours(?array $pair, array $closed = []): array
    {
        $pair ??= ['00:00', '23:59'];
        $o = Geo::toMin($pair[0]);
        $c = Geo::toMin($pair[1]);
        if ($c <= $o) {
            $c = 24 * 60 - 1; // closes after midnight
        }
        $week = [];
        for ($d = 0; $d < 7; $d++) {
            $week[] = in_array($d, $closed, true) ? null : [$o, $c];
        }
        return $week;
    }

    /**
     * Google Places (New) regularOpeningHours → week array. Returns null when
     * the place has no published hours.
     *
     * @param array<string, mixed>|null $roh
     * @return list<array{0:int,1:int}|null>|null
     */
    public static function fromGoogleHours(?array $roh): ?array
    {
        $periods = $roh['periods'] ?? null;
        if (!$periods) {
            return null;
        }
        $week = array_fill(0, 7, null);
        foreach ($periods as $p) {
            $open = $p['open'] ?? null;
            if (!$open) {
                continue;
            }
            $d = (int)($open['day'] ?? 0);
            $o = (int)($open['hour'] ?? 0) * 60 + (int)($open['minute'] ?? 0);
            if (!isset($p['close'])) {
                return self::weekHours(['00:00', '23:59']); // open 24 hours
            }
            $close = $p['close'];
            $c = (int)($close['hour'] ?? 0) * 60 + (int)($close['minute'] ?? 0);
            if ((int)($close['day'] ?? $d) !== $d || $c <= $o) {
                $c = 24 * 60 - 1;
            }
            // Split shifts (lunch & dinner service): keep the widest envelope.
            $week[$d] = isset($week[$d]) ? [min($week[$d][0], $o), max($week[$d][1], $c)] : [$o, $c];
        }
        return $week;
    }

    public static function hoursLabel(?array $window): string
    {
        if (!$window) {
            return 'CLOSED TODAY';
        }
        if ($window[0] === 0 && $window[1] >= 24 * 60 - 1) {
            return 'OPEN 24 HOURS';
        }
        return Geo::fmt($window[0]) . '–' . Geo::fmt($window[1]);
    }

    public static function slug(string $s): string
    {
        $s = strtolower(trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-'));
        return $s !== '' ? $s : substr(md5($s), 0, 8);
    }

    /**
     * Popularity-weighted quality score used for ranking. Sources without
     * ratings (Wikivoyage, Wikipedia) fall back to monthly article views.
     */
    public static function score(array $p): float
    {
        $rating = $p['rating'] ?? 4.1;
        $count = $p['ratingCount'] ?? (isset($p['popularity']) ? $p['popularity'] * 2 : 200);
        return $rating + log10($count + 10) * 0.35 + (!empty($p['must']) ? 1.0 : 0.0);
    }

    /** Interest tag => keyword pattern, matched against a place's name and description. */
    public const INTERESTS = [
        'history' => '/\b(fort|castle|palace|mahal|haveli|qila|ruin|historic|monument|mausoleum|citadel|old town|old city|heritage|memorial|tomb|gate|walls?|archaeolog|ancient|imperial|colonial)\w*/i',
        'museums' => '/\b(museum|gallery|exhibition|collection)\w*/i',
        'nature' => '/\b(park|garden|lake|beach|mountain|hill|forest|waterfall|zoo|island|river|bay|valley|canyon|cave|botanic|bamboo|reserve|trail)\w*/i',
        'religion' => '/\b(temple|church|mosque|pagoda|cathedral|shrine|monastery|synagogue|basilica|chapel|abbey|stupa|gurdwara|mandir|wat)\b/i',
        'views' => '/\b(viewpoint|view point|lookout|observation|tower|rooftop|skyline|miradouro|panoram|summit|bridge|sunset)\w*/i',
        'art' => '/\b(art|artist|street art|mural|theatre|theater|opera|design|craft|architecture)\w*/i',
        'food' => '/\b(food|market|cooking|culinary|tasting|street food|coffee|tea|wine|brewery|sake|chocolate)\w*/i',
        'shopping' => '/\b(market|bazaar|souk|souq|mall|shopping|arcade|boutique|flea)\w*/i',
        'nightlife' => '/\b(night|bar|club|show|music|cabaret|jazz|live|fado|puppet|cinema|performance)\w*/i',
    ];

    /**
     * Keyword classifier shared by every source: interest tags, whether the
     * stop is under cover, whether it suits golden hour, and a visit length.
     *
     * @return array{tags: list<string>, indoor: bool, golden: bool, duration: int}
     */
    public static function classify(string $text): array
    {
        $tags = [];
        foreach (self::INTERESTS as $tag => $re) {
            if (preg_match($re, $text)) {
                $tags[] = $tag;
            }
        }
        $indoor = (bool)preg_match('/\b(museum|gallery|aquarium|mausoleum|theatre|theater|opera|library|planetarium|exhibition|cathedral|basilica|mall|arcade)\w*/i', $text);
        $golden = !$indoor && (bool)preg_match('/\b(viewpoint|view point|lookout|miradouro|observation|hill|lake|beach|bridge|tower|fort|castle|sunset|pier|promenade|rooftop|bay|summit|ghat|esplanade|waterfront)\w*/i', $text);
        $duration = match (true) {
            (bool)preg_match('/\b(zoo|aquarium|theme park|amusement|national park|island)\b/i', $text) => 150,
            (bool)preg_match('/\b(museum|gallery|palace|castle|fort|citadel)\w*/i', $text) => 100,
            (bool)preg_match('/\b(park|garden|old town|old city|quarter|market|bazaar)\w*/i', $text) => 70,
            (bool)preg_match('/\b(temple|church|mosque|pagoda|cathedral|shrine|monastery|basilica)\w*/i', $text) => 45,
            (bool)preg_match('/\b(viewpoint|lookout|tower|bridge|gate|square|monument|statue|fountain)\w*/i', $text) => 35,
            default => 60,
        };
        return ['tags' => $tags, 'indoor' => $indoor, 'golden' => $golden, 'duration' => $duration];
    }

    /** Score bump for places matching the traveller's chosen interests. */
    public static function interestBonus(array $p, array $interests): float
    {
        if (!$interests) {
            return 0.0;
        }
        $hits = count(array_intersect($p['tags'] ?? [], $interests));
        return min(2, $hits) * 0.6;
    }
}

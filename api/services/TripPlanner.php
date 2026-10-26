<?php
declare(strict_types=1);

final class PlannerException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}

/**
 * Orchestrates the full pipeline:
 *   source places (Google → curated sample → OSM) → FX normalisation →
 *   weather & daylight → basecamp selection at the attraction centroid →
 *   balanced clustering into days (weather-aware, closure-aware) → timetable
 *   build → road-matrix refinement → budget fit → reviews, photos, nearby
 *   lists → add-ons and budget-boost tiers.
 */
final class TripPlanner
{
    public const CURRENCIES = ['INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥', 'AUD' => 'A$', 'SGD' => 'S$', 'AED' => 'AED ', 'CAD' => 'C$'];
    public const MODES = ['walk', 'metro', 'bus', 'shared', 'taxi'];
    public const DIETS = ['vegetarian', 'vegan', 'halal'];
    private const PACE = [
        'relaxed' => ['perDay' => 3, 'dayStart' => '09:00', 'timeFactor' => 0.6, 'acts' => 1],
        'balanced' => ['perDay' => 4, 'dayStart' => '08:15', 'timeFactor' => 1.0, 'acts' => 1],
        'packed' => ['perDay' => 5, 'dayStart' => '07:30', 'timeFactor' => 1.5, 'acts' => 2],
    ];

    /** Stay style → the hotel tier the traveller is hoping for. */
    private const STAY_TIER = ['hostel' => 1, 'budget' => 2, 'comfort' => 3, 'boutique' => 4];

    private HttpClient $http;
    /** @var array<string, string> */
    private array $warnings = [];
    /** @var array<string, array<string, array<string, mixed>>> normalised places, prices in home currency */
    private array $places = [];

    public function __construct(private array $cfg)
    {
    }

    /** @param array<string, mixed> $in */
    public function plan(array $in): array
    {
        $q = $this->validate($in);
        $this->http = new HttpClient($this->cfg['cache_dir'], $this->cfg['user_agent'], (int)$this->cfg['http_timeout'], !$q['offline']);
        $repo = new FallbackRepository(__DIR__ . '/../data/fallback');
        $google = new PlacesService($this->http, (string)$this->cfg['google_places_key']);

        // ── 1. Places ───────────────────────────────────────────────────────
        [$ctx, $places] = $this->source($q, $repo, $google);

        // ── 2. Currency normalisation ───────────────────────────────────────
        $fx = (new CurrencyService($this->http))->rates($q['currency'], [$ctx['currency'], 'USD']);
        $localToHome = 1 / $fx['rates'][$ctx['currency']];
        $usdToHome = 1 / $fx['rates']['USD'];
        $this->normalise($ctx, $places, $localToHome, $usdToHome);
        $money = fn(float $v): string => self::CURRENCIES[$q['currency']] . number_format($v, 0);

        // ── 3. Dates, weather, daylight ─────────────────────────────────────
        $dates = [];
        for ($d = new DateTimeImmutable($q['start']); $d <= new DateTimeImmutable($q['end']); $d = $d->modify('+1 day')) {
            $dates[] = $d->format('Y-m-d');
        }
        $weather = (new WeatherService($this->http))->forecast($ctx['lat'], $ctx['lng'], $dates, $ctx['tz'] ?? null);
        $ctx['tz'] ??= $weather['tz'];
        $days = [];
        foreach ($dates as $date) {
            $w = $weather['days'][$date];
            $days[] = $w + [
                'date' => $date,
                'weekday' => (int)(new DateTimeImmutable($date))->format('w'),
                'sunriseMin' => Geo::toMin($w['sunrise']),
                'sunsetMin' => Geo::toMin($w['sunset']),
            ];
        }
        if (array_filter($days, fn($d) => $d['source'] !== 'OPEN-METEO')) {
            $this->warnings['forecast'] = 'Some dates are beyond the 16-day forecast window: sunset times are computed, weather-based reshuffling applies once forecasts open.';
        }

        // ── 4. Preferences & budget envelope ────────────────────────────────
        $nDays = count($days);
        $nights = $nDays - 1;
        $pace = self::PACE[$q['pace']];
        $prefs = [
            'travellers' => $q['travellers'],
            'rooms' => (int)ceil($q['travellers'] / 2),
            'nights' => $nights,
            'days' => $nDays,
            'budget' => $q['budget'],
            'dayStart' => Geo::toMin($pace['dayStart']),
            'foodCapPP' => $q['budget'] * $q['foodPct'] / $nDays / $q['travellers'],
            'diet' => $q['diet'],
        ];
        $lodgingBudget = $q['budget'] * $q['lodgingPct'];
        $nightlyCap = $nights > 0 ? $lodgingBudget / $nights : 0.0;
        $minuteValue = $q['budget'] / $nDays / (14 * 60) * $pace['timeFactor'];

        // ── 5. Attraction shortlist ─────────────────────────────────────────
        $A = $places['attractions'];
        $A = array_filter($A, fn($a) => Geo::between($a, $ctx) <= 40);
        $rank = fn($a) => Places::score($a) + Places::interestBonus($a, $q['interests']);
        uasort($A, fn($a, $b) => $rank($b) <=> $rank($a));
        $quota = $nDays * $pace['perDay'];
        $selected = array_slice($A, 0, $quota, true);
        $unscheduled = [];
        // Overflow sights stay on the bench: the builder borrows them to fill thin afternoons.
        $spares = array_map('strval', array_keys(array_slice($A, $quota, 20, true)));

        // ── 6. Basecamp at the attraction centroid / transit hub ────────────
        $centroid = Geo::centroid($selected, array_map(fn($a) => Places::score($a), $selected));
        [$hotelId, $hotelRank] = $nights > 0
            ? $this->pickHotel($places['hotels'], $centroid, $ctx['hubs'], $nightlyCap / $prefs['rooms'], $q['stayTier'])
            : [null, []]; // day trip: no stay

        // ── 7. Photography (Wikipedia) for non-Google sources ───────────────
        $this->attachImages($ctx, $places);
        $this->places = $places;

        // ── 8. Day clusters ─────────────────────────────────────────────────
        $origin = $hotelId ? $places['hotels'][$hotelId] : ($ctx['hubs'][0] ?? $ctx);
        $dayAttractions = $this->clusterDays($selected, $days, $origin, $pace['perDay'], $unscheduled);
        $state = [
            'hotelId' => $hotelId,
            'dayAttractions' => $dayAttractions,
            'dayMarket' => $this->assignMarkets($places['markets'], $dayAttractions, $selected, $days, $origin),
            'dayActivities' => [],
            'mealOverrides' => [],
            'legOverrides' => [],
            'browseOnly' => [],
            'dropped' => [],
            'spares' => $spares,
        ];
        foreach ($places['activities'] as &$act) {
            $act['value'] = ($act['value'] ?? 3) + Places::interestBonus($act, $q['interests']);
        }
        unset($act);
        $state['dayActivities'] = $this->assignActivities($places['activities'], $state, $selected, $days, $origin, $pace, $unscheduled);

        // ── 9. Build, refine with road matrices, fit the budget ─────────────
        $transit = new TransitPlanner($ctx['transit'], $q['modes'], $q['travellers'], $minuteValue, ['lat' => $ctx['lat'], 'lng' => $ctx['lng']]);
        $builder = new ItineraryBuilder($ctx, $places, $days, $prefs, $transit);
        $build = $builder->build($state);
        $routeSource = $this->refineRoutes($build, $transit);
        if ($routeSource) {
            $build = $builder->build($state);
        }

        $engine = new BudgetEngine($places, $prefs, $ctx, $money);
        $ledger = $engine->ledger($build, $state);
        $initialTotal = $ledger['total'];
        $fitLog = [];
        for ($i = 0; $i < 120 && $ledger['total'] > $q['budget']; $i++) {
            $moves = $engine->downgrades($build, $state, $hotelRank);
            if (!$moves) {
                break;
            }
            usort($moves, fn($a, $b) => ($b['saving'] / $b['loss']) <=> ($a['saving'] / $a['loss']));
            $state = ($moves[0]['apply'])($state);
            $fitLog[] = $moves[0]['label'];
            $build = $builder->build($state);
            $ledger = $engine->ledger($build, $state);
        }
        if ($ledger['total'] > $q['budget']) {
            $this->warnings['budget'] = sprintf(
                'Even the leanest version of this trip needs about %s — %s more than your budget. Shown as the minimum viable plan.',
                $money($ledger['total']),
                $money($ledger['total'] - $q['budget'])
            );
        } elseif ($fitLog) {
            $this->warnings['fit'] = sprintf('The first draft came to %s, so %d cost-saving swaps were made to fit your %s budget. Every swap can be undone from the add-ons.',
                $money($initialTotal), count($fitLog), $money($q['budget']));
        }
        foreach (array_slice(array_diff($spares, $build['sparesUsed']), 0, 6) as $pid) {
            $unscheduled[] = ['name' => $places['attractions'][$pid]['name'], 'reason' => "Didn't make the cut at a {$q['pace']} pace"];
        }
        foreach ($state['dropped'] as $pid => $reason) {
            $p = $places['attractions'][$pid] ?? $places['activities'][$pid] ?? null;
            if ($p) {
                $unscheduled[] = ['name' => $p['name'], 'reason' => $reason];
            }
        }
        foreach ($builder->warnings as $k => $w) {
            $this->warnings[$k] = $w;
        }

        // ── 10. Reviews & details for the final cut ─────────────────────────
        $hotelAlts = $this->hotelAlternatives($hotelRank, $hotelId, $places['hotels']);
        $details = [];
        if ($ctx['source'] === 'GOOGLE') {
            $ids = [];
            foreach ($build['days'] as $d) {
                foreach ($d['nodes'] as $n) {
                    if (!empty($n['googleId'])) {
                        $ids[] = $n['googleId'];
                    }
                }
            }
            foreach (array_merge([$hotelId], $hotelAlts) as $hid) {
                if ($hid && !empty($places['hotels'][$hid]['googleId'])) {
                    $ids[] = $places['hotels'][$hid]['googleId'];
                }
            }
            $details = $google->details(array_slice(array_values(array_unique($ids)), 0, 40));
        }

        // ── 11. Add-ons & tiers ─────────────────────────────────────────────
        $addons = $engine->addons($build, $state, $hotelAlts);
        $tiers = $engine->tiers($addons, $q['budget'], $ledger['buffer'], 1 / $usdToHome, $q['currency']);

        // ── 12. Assemble ────────────────────────────────────────────────────
        $outDays = [];
        foreach ($build['days'] as $di => $d) {
            $nodes = [];
            foreach ($d['nodes'] as $n) {
                $nodes[] = $this->presentNode($n, $details, $localToHome);
            }
            foreach ($d['skipped'] as $s) {
                $unscheduled[] = ['name' => $s['name'], 'reason' => $s['reason'] . ' (Day ' . sprintf('%02d', $di + 1) . ')'];
            }
            $w = $days[$di];
            $outDays[] = [
                'index' => $di,
                'date' => $d['date'],
                'weekday' => $d['weekday'],
                'theme' => $d['theme'],
                'weather' => [
                    'code' => $w['code'], 'label' => $w['label'], 'tmax' => $w['tmax'], 'tmin' => $w['tmin'], 'rain' => $w['rain'],
                    'wet' => $w['wet'], 'hot' => $w['hot'], 'source' => $w['source'],
                ],
                'sunrise' => $w['sunrise'],
                'sunset' => $w['sunset'],
                'nodes' => $nodes,
            ];
        }

        $hotelNode = $hotelId ? $this->presentHotel($places['hotels'][$hotelId], $centroid, $ctx, $prefs, $details, $localToHome) : null;
        $alts = [];
        foreach ($hotelAlts as $hid) {
            $alts[] = $this->presentHotel($places['hotels'][$hid], $centroid, $ctx, $prefs, $details, $localToHome) + ['addonId' => "hotel:$hid"];
        }

        return [
            'ok' => true,
            'meta' => [
                'destination' => $ctx['name'],
                'country' => $ctx['country'],
                'countryCode' => $ctx['countryCode'] ?? '',
                'interests' => $q['interests'],
                'currency' => $q['currency'],
                'symbol' => self::CURRENCIES[$q['currency']],
                'localCurrency' => $ctx['currency'],
                'rate' => $localToHome,
                'usdRate' => 1 / $usdToHome,
                'rateSource' => $fx['source'],
                'rateDate' => $fx['date'],
                'dataSource' => $ctx['source'],
                'routeSource' => $routeSource ?? 'HAVERSINE ESTIMATE',
                'weatherSource' => $weather['source'],
                'tz' => $ctx['tz'],
                'startDate' => $q['start'],
                'endDate' => $q['end'],
                'days' => $nDays,
                'nights' => $nights,
                'travellers' => $q['travellers'],
                'rooms' => $prefs['rooms'],
                'pace' => $q['pace'],
                'diet' => $q['diet'],
                'modes' => $q['modes'],
                'warnings' => array_values($this->warnings),
                'sources' => $this->sourceSummary(),
                'generatedAt' => date('c'),
            ],
            'city' => [
                'name' => $ctx['name'],
                'country' => $ctx['country'],
                'tagline' => $ctx['tagline'] ?? '',
                'image' => $ctx['image'] ?? null,
                'imagePage' => $ctx['imagePage'] ?? null,
                'lat' => $ctx['lat'],
                'lng' => $ctx['lng'],
            ],
            'budget' => [
                'total' => $q['budget'],
                'lodgingPct' => $q['lodgingPct'],
                'foodPct' => $q['foodPct'],
                'lodgingAllocation' => round($lodgingBudget, 2),
                'nightlyCap' => round($nightlyCap, 2),
                'foodCapPerPersonDay' => round($prefs['foodCapPP'], 2),
                'ledger' => $ledger['categories'],
                'spent' => $ledger['total'],
                'buffer' => $ledger['buffer'],
                'fitLog' => $fitLog,
            ],
            'hotel' => $hotelNode,
            'hotelAlternatives' => $alts,
            'packages' => $build['packages'],
            'days' => $outDays,
            'addons' => $addons,
            'tiers' => $tiers,
            'unscheduled' => $unscheduled,
        ];
    }

    // ─── Input ──────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function validate(array $in): array
    {
        $dest = trim((string)($in['destination'] ?? ''));
        if (mb_strlen_safe($dest) < 2 || mb_strlen_safe($dest) > 80) {
            throw new PlannerException('Enter a destination city (2–80 characters).');
        }
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($in['startDate'] ?? ''));
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($in['endDate'] ?? ''));
        if (!$start || !$end) {
            throw new PlannerException('Choose a start and end date.');
        }
        if ($end < $start) {
            throw new PlannerException('The end date must be on or after the start date.');
        }
        $span = (int)$start->diff($end)->days + 1;
        if ($span > $this->cfg['max_days']) {
            throw new PlannerException("Wanderbean plans up to {$this->cfg['max_days']} days at a time — shorten the range.");
        }
        $budget = (float)($in['budget'] ?? 0);
        if ($budget <= 0 || $budget > 1e9) {
            throw new PlannerException('Enter a total budget greater than zero.');
        }
        $currency = strtoupper((string)($in['currency'] ?? 'INR'));
        if (!isset(self::CURRENCIES[$currency])) {
            throw new PlannerException('Unsupported currency.');
        }
        $pace = (string)($in['pace'] ?? 'balanced');
        $modes = array_values(array_intersect(self::MODES, (array)($in['modes'] ?? self::MODES)));

        return [
            'destination' => $dest,
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'budget' => $budget,
            'currency' => $currency,
            'travellers' => max(1, min(12, (int)($in['travellers'] ?? 1))),
            'pace' => isset(self::PACE[$pace]) ? $pace : 'balanced',
            'diet' => array_values(array_intersect(self::DIETS, (array)($in['diet'] ?? []))),
            'modes' => $modes ?: self::MODES,
            'lodgingPct' => max(0.2, min(0.6, (float)($in['lodgingPct'] ?? $this->cfg['lodging_pct']))),
            'foodPct' => max(0.1, min(0.4, (float)($in['foodPct'] ?? $this->cfg['food_pct']))),
            'offline' => filter_var($in['offline'] ?? false, FILTER_VALIDATE_BOOL),
            'interests' => array_values(array_intersect(array_keys(Places::INTERESTS), (array)($in['interests'] ?? []))),
            'stayTier' => self::STAY_TIER[(string)($in['stayStyle'] ?? '')] ?? null,
        ];
    }

    // ─── Sourcing ───────────────────────────────────────────────────────────

    /** @return array{0: array<string, mixed>, 1: array<string, array<string, array<string, mixed>>>} */
    private function source(array $q, FallbackRepository $repo, PlacesService $google): array
    {
        $dataset = $repo->find($q['destination']);
        $geocoder = new GeocodingService($this->http, $this->cfg);

        if ($google->enabled() && $this->http->online()) {
            $geo = $dataset
                ? ['name' => $dataset['city']['name'], 'country' => $dataset['city']['country'], 'countryCode' => $dataset['city']['countryCode'],
                   'lat' => (float)$dataset['city']['lat'], 'lng' => (float)$dataset['city']['lng'], 'currency' => $dataset['city']['currency'], 'tz' => $dataset['city']['tz']]
                : $geocoder->geocode($q['destination']);
            if ($geo) {
                $found = $google->discover($geo);
                if ($found) {
                    if ($dataset) {
                        // Keep the curated transit profile and hubs; passes reference curated ids, so drop them.
                        $ctx = $repo->bundle($dataset)['ctx'];
                        $ctx['passes'] = [];
                        $ctx['source'] = 'GOOGLE';
                    } else {
                        $ctx = $this->genericCtx($geo, 'GOOGLE');
                    }
                    return [$ctx, $this->ensureGroups($found)];
                }
            }
            $this->warnings['google'] = 'Google Places did not respond — using the next best source.';
        }

        if ($dataset) {
            $b = $repo->bundle($dataset);
            return [$b['ctx'], $b['places']];
        }

        $demos = implode(', ', array_map(fn($d) => explode(',', $d)[0], $repo->destinations()));
        if (!$this->http->online()) {
            throw new PlannerException($q['offline']
                ? "Offline mode is on, and “{$q['destination']}” has no offline guide. Switch live data back on in Preferences, or try: {$demos}."
                : "“{$q['destination']}” needs live data, but this server can't reach the internet over HTTPS (enable PHP's curl and openssl extensions). Offline guides: {$demos}.");
        }

        $geo = $geocoder->geocode($q['destination']);
        if (!$geo) {
            throw new PlannerException("Couldn't locate “{$q['destination']}”. Try “City, Country”, or one of the offline guides: {$demos}.");
        }
        $geo['currency'] ??= CostModel::currencyFor($geo['countryCode']);

        // Universal open-data guide: Wikivoyage listings + Wikipedia popularity.
        $guide = (new GuideSource($this->http, new WikivoyageService($this->http), new WikipediaService($this->http)))->discover($geo, $q['destination']);
        if ($guide) {
            $this->warnings['guide'] = 'Built from Wikivoyage and Wikipedia: popularity comes from article views, and prices not printed in the guide are estimated from a regional cost index. Add a Google Places key for star ratings and reviews.';
            return [$guide['ctx'], $this->ensureGroups($guide['places'])];
        }

        $osm = (new OverpassService($this->http))->discover($geo['lat'], $geo['lng'], $geo['countryCode']);
        if ($osm && count($osm['attractions']) >= 3 && count($osm['eateries']) >= 3) {
            $this->warnings['osm'] = 'Built from OpenStreetMap: no public ratings, and prices are estimates from a regional cost index. Add a Google Places key for verified reviews.';
            return [$this->genericCtx($geo, 'OSM'), $this->ensureGroups($osm)];
        }
        throw new PlannerException($osm === null
            ? "Found {$geo['name']}, but OpenStreetMap's Overpass servers are busy right now. Try again in a minute, add a Google Places key, or use a curated edition: {$demos}."
            : "Found {$geo['name']}, but OpenStreetMap lists too few named sights around it. Try a larger city, or one of the curated editions: {$demos}.");
    }

    private function genericCtx(array $geo, string $source): array
    {
        $cc = $geo['countryCode'];
        $index = CostModel::index($cc);
        $t = CostModel::genericTransit();
        $t['bus']['fare'] *= $index;
        $t['taxi']['base'] *= max($index, 0.3);
        $t['taxi']['perKm'] *= max($index, 0.3);
        $t['carDay']['price'] *= max($index, 0.35);
        return [
            'name' => $geo['name'],
            'country' => $geo['country'],
            'countryCode' => $cc,
            'currency' => $geo['currency'] ?? CostModel::currencyFor($cc),
            'lat' => $geo['lat'],
            'lng' => $geo['lng'],
            'tz' => $geo['tz'] ?? null,
            'wiki' => $geo['name'],
            'tagline' => '',
            'dinnerTime' => '19:30',
            'transit' => $t,
            'hubs' => [],
            'passes' => [],
            'source' => $source,
        ];
    }

    private function ensureGroups(array $p): array
    {
        foreach (['hotels', 'attractions', 'eateries', 'markets', 'activities'] as $g) {
            $p[$g] ??= [];
        }
        return $p;
    }

    private function normalise(array &$ctx, array &$places, float $l2h, float $u2h): void
    {
        foreach ($places as &$group) {
            foreach ($group as &$p) {
                if (isset($p['priceLocal'])) {
                    $p['price'] = $p['priceLocal'] * $l2h;
                } else {
                    $p['price'] = ($p['priceUsd'] ?? 0) * $u2h;
                    $p['priceLocal'] = $p['price'] / $l2h;
                }
                if (isset($p['guide'])) {
                    $p['guide']['price'] = isset($p['guide']['priceUsd']) ? $p['guide']['priceUsd'] * $u2h : $p['guide']['price'] * $l2h;
                }
                $p['meals'] ??= [];
                $p['diet'] ??= [];
                $p['duration'] ??= 75;
            }
            unset($p);
        }
        unset($group);

        $f = ($ctx['transit']['currency'] ?? $ctx['currency']) === 'USD' && $ctx['currency'] !== 'USD' ? $u2h : $l2h;
        $t = &$ctx['transit'];
        foreach (['metro', 'bus', 'shared'] as $m) {
            if (isset($t[$m]['fare'])) {
                $t[$m]['fare'] *= $f;
            }
        }
        $t['taxi']['base'] *= $f;
        $t['taxi']['perKm'] *= $f;
        foreach (['dayPass', 'carDay'] as $k) {
            if (isset($t[$k]['price'])) {
                $t[$k]['price'] *= $f;
            }
        }
        unset($t);
        foreach ($ctx['passes'] as &$pass) {
            $pass['price'] *= $l2h;
        }
        unset($pass);
    }

    private function attachImages(array &$ctx, array &$places): void
    {
        $titles = [$ctx['wiki'] ?? $ctx['name']];
        foreach ($places as $group) {
            foreach ($group as $p) {
                if (!empty($p['wiki']) && empty($p['image'])) {
                    $titles[] = $p['wiki'];
                }
            }
        }
        $found = (new ImageService($this->http))->lookup(array_slice($titles, 0, 40));
        $city = $found[$ctx['wiki'] ?? $ctx['name']] ?? null;
        if ($city) {
            $ctx['image'] = $city['image'];
            $ctx['imagePage'] = $city['page'];
        }
        foreach ($places as &$group) {
            foreach ($group as &$p) {
                if (!empty($p['wiki']) && empty($p['image']) && isset($found[$p['wiki']])) {
                    $p['image'] = $found[$p['wiki']]['image'];
                }
            }
            unset($p);
        }
        unset($group);
    }

    // ─── Basecamp ───────────────────────────────────────────────────────────

    /**
     * Rank stays by rating, popularity and distance to the attraction centroid
     * (and nearest transit hub); take the best one within the nightly cap.
     *
     * @return array{0: ?string, 1: list<string>}
     */
    private function pickHotel(array $hotels, array $centroid, array $hubs, float $capPerRoom, ?int $wantTier = null): array
    {
        if (!$hotels) {
            return [null, []];
        }
        // Comfort tier counts, but so does leaving money on the table: a stay that
        // eats the whole lodging cap has to earn it on rating and location.
        $score = [];
        foreach ($hotels as $id => $h) {
            $hubKm = $hubs ? min(array_map(fn($x) => Geo::between($h, $x), $hubs)) : 0.0;
            $score[$id] = ($h['rating'] ?? 4.0) * 2
                + log10(($h['ratingCount'] ?? 100) + 10) * 0.4
                - Geo::between($h, $centroid) * 0.45
                - $hubKm * 0.25
                + ($h['tier'] ?? 2) * 0.55
                - ($wantTier !== null ? abs(($h['tier'] ?? 2) - $wantTier) * 0.9 : 0.0)
                - ($capPerRoom > 0 ? $h['price'] / $capPerRoom * 0.8 : 0.0);
        }
        arsort($score);
        $rank = array_map('strval', array_keys($score));
        foreach ($rank as $id) {
            if ($hotels[$id]['price'] <= $capPerRoom) {
                return [$id, $rank];
            }
        }
        // Nothing under the cap: start from the cheapest and let the fit loop work.
        $cheapest = $rank;
        usort($cheapest, fn($a, $b) => $hotels[$a]['price'] <=> $hotels[$b]['price']);
        $this->warnings['lodging'] = 'No stay fits the lodging share of your budget; Wanderbean started from the cheapest well-located option.';
        return [$cheapest[0], $rank];
    }

    /** @return list<string> */
    private function hotelAlternatives(array $rank, ?string $chosen, array $hotels): array
    {
        if (!$chosen) {
            return [];
        }
        $cur = $hotels[$chosen]['price'];
        $others = array_values(array_filter($rank, fn($id) => $id !== $chosen));
        $cheaper = array_values(array_filter($others, fn($id) => $hotels[$id]['price'] < $cur));
        $pricier = array_values(array_filter($others, fn($id) => $hotels[$id]['price'] >= $cur));
        // Spread upgrades across tiers so the boosts can offer a real step up.
        usort($pricier, fn($a, $b) => $hotels[$a]['price'] <=> $hotels[$b]['price']);
        $pick = array_merge(array_slice($pricier, 0, 4), array_slice($cheaper, 0, 2));
        return array_slice(array_values(array_unique($pick)), 0, 6);
    }

    // ─── Days ───────────────────────────────────────────────────────────────

    /**
     * Balanced geographic clusters → days. Rainy days receive the most indoor
     * cluster, closures are swapped across days, and each day is ordered as a
     * closed tour from basecamp with crowd-magnet sites pulled to the front.
     *
     * @return array<int, list<string>>
     */
    private function clusterDays(array $selected, array $days, array $origin, int $perDay, array &$unscheduled): array
    {
        $n = count($days);
        $clusters = Geo::balancedClusters($selected, $n);
        $stats = [];
        foreach ($clusters as $ci => $ids) {
            $indoor = count(array_filter($ids, fn($id) => $selected[$id]['indoor']));
            $stats[$ci] = [
                'indoor' => $indoor / max(1, count($ids)),
                'score' => array_sum(array_map(fn($id) => Places::score($selected[$id]), $ids)) / max(1, count($ids)),
            ];
        }

        // Clusters → days by minimum cost: closures dominate, then rain on
        // outdoor-heavy clusters, then a gentle pull of the best cluster to Day 1.
        $cost = [];
        $order = array_keys($clusters);
        usort($order, fn($a, $b) => $stats[$b]['score'] <=> $stats[$a]['score']);
        $rankOf = array_flip($order);
        foreach ($clusters as $ci => $ids) {
            foreach ($days as $di => $day) {
                $closed = count(array_filter($ids, fn($id) => $selected[$id]['hours'][$day['weekday']] === null));
                $cost[$ci][$di] = $closed * 10
                    + ($day['wet'] ? (1 - $stats[$ci]['indoor']) * 3 : 0)
                    + abs($rankOf[$ci] - $di) * 0.15;
            }
        }
        $assign = self::assignClusters($cost, count($clusters), $n);

        $plan = [];
        for ($di = 0; $di < $n; $di++) {
            $plan[$di] = isset($assign[$di]) ? $clusters[$assign[$di]] : [];
        }

        // Any closure left: swap with the open stop from another day that adds
        // the least travel, or move it to a day with room.
        for ($di = 0; $di < $n; $di++) {
            foreach ($plan[$di] as $k => $id) {
                if ($selected[$id]['hours'][$days[$di]['weekday']] !== null) {
                    continue;
                }
                $here = Geo::centroid(array_map(fn($x) => $selected[$x], $plan[$di]));
                $best = null;
                for ($dj = 0; $dj < $n; $dj++) {
                    if ($dj === $di || $selected[$id]['hours'][$days[$dj]['weekday']] === null) {
                        continue;
                    }
                    $there = $plan[$dj] ? Geo::centroid(array_map(fn($x) => $selected[$x], $plan[$dj])) : $origin;
                    if (count($plan[$dj]) < $perDay) {
                        $c = Geo::between($selected[$id], $there);
                        if (!$best || $c < $best[0]) {
                            $best = [$c, $dj, null];
                        }
                    }
                    foreach ($plan[$dj] as $m => $other) {
                        if ($selected[$other]['hours'][$days[$di]['weekday']] === null) {
                            continue;
                        }
                        $c = Geo::between($selected[$id], $there) + Geo::between($selected[$other], $here);
                        if (!$best || $c < $best[0]) {
                            $best = [$c, $dj, $m];
                        }
                    }
                }
                if (!$best) {
                    unset($plan[$di][$k]);
                    $unscheduled[] = ['name' => $selected[$id]['name'], 'reason' => 'Closed on every day of this trip'];
                    continue;
                }
                [, $dj, $m] = $best;
                if ($m === null) {
                    $plan[$dj][] = $id;
                    unset($plan[$di][$k]);
                } else {
                    $plan[$di][$k] = $plan[$dj][$m];
                    $plan[$dj][$m] = $id;
                }
            }
            $plan[$di] = array_values($plan[$di]);
        }

        foreach ($plan as $di => $ids) {
            if (!$ids) {
                continue;
            }
            $pts = array_intersect_key($selected, array_flip($ids));
            // Crowd magnets open the day; the rest is toured from there back to basecamp.
            $early = array_keys(array_filter($pts, fn($a) => $a['early'] && !$a['golden']));
            usort($early, fn($a, $b) => Places::score($selected[$b]) <=> Places::score($selected[$a]));
            if ($early) {
                $first = $early[0];
                $order = array_merge([$first], Geo::tour($selected[$first], array_diff_key($pts, [$first => true]), $origin));
            } else {
                $order = Geo::tour($origin, $pts);
            }
            if ($days[$di]['hot'] && count($order) > 2) {
                // Shade at midday: indoor stops after the first outdoor one.
                $head = array_shift($order);
                $indoor = array_values(array_filter($order, fn($id) => $selected[$id]['indoor']));
                $outdoor = array_values(array_filter($order, fn($id) => !$selected[$id]['indoor']));
                $order = array_merge([$head], array_slice($outdoor, 0, 1), $indoor, array_slice($outdoor, 1));
            }
            $plan[$di] = array_map('strval', $order);
        }
        return $plan;
    }

    /**
     * Minimum-cost assignment of clusters to days: exact over permutations for
     * short trips, greedy cheapest-pair for long ones.
     *
     * @param array<int, array<int, float>> $cost [cluster][day]
     * @return array<int, int> day => cluster
     */
    private static function assignClusters(array $cost, int $k, int $n): array
    {
        $clusters = array_keys($cost);
        if ($n <= 7) {
            $best = null;
            $bestCost = INF;
            $walk = function (array $used, array $map, float $acc, int $di) use (&$walk, &$best, &$bestCost, $cost, $clusters, $n): void {
                if ($acc >= $bestCost) {
                    return;
                }
                if ($di === $n || count($map) === count($clusters)) {
                    $best = $map;
                    $bestCost = $acc;
                    return;
                }
                foreach ($clusters as $ci) {
                    if (!isset($used[$ci])) {
                        $walk($used + [$ci => true], $map + [$di => $ci], $acc + $cost[$ci][$di], $di + 1);
                    }
                }
                // A day may also stay cluster-free when there are fewer clusters than days.
                if ($n - $di > count($clusters) - count($used)) {
                    $walk($used, $map, $acc, $di + 1);
                }
            };
            $walk([], [], 0.0, 0);
            return $best ?? [];
        }
        $pairs = [];
        foreach ($cost as $ci => $row) {
            foreach ($row as $di => $c) {
                $pairs[] = [$c, $ci, $di];
            }
        }
        usort($pairs, fn($a, $b) => $a[0] <=> $b[0]);
        $map = [];
        $taken = [];
        foreach ($pairs as [, $ci, $di]) {
            if (!isset($map[$di]) && !isset($taken[$ci])) {
                $map[$di] = $ci;
                $taken[$ci] = true;
            }
        }
        return $map;
    }

    /** @return array<int, ?string> */
    private function assignMarkets(array $markets, array $dayAttractions, array $selected, array $days, array $origin): array
    {
        $used = [];
        $out = [];
        foreach ($days as $di => $day) {
            $pts = array_map(fn($id) => $selected[$id], $dayAttractions[$di] ?? []);
            $center = $pts ? Geo::centroid($pts) : $origin;
            $best = null;
            $bestD = 10.0;
            foreach ($markets as $id => $m) {
                if (isset($used[$id]) || $m['hours'][$day['weekday']] === null) {
                    continue;
                }
                if (!empty($m['monthdays']) && !in_array((int)substr($day['date'], 8, 2), $m['monthdays'], true)) {
                    continue;
                }
                $d = Geo::between($center, $m) - (!empty($m['monthdays']) ? 3.0 : 0.0); // special-date markets are worth a detour
                if ($d < $bestD) {
                    $bestD = $d;
                    $best = (string)$id;
                }
            }
            if ($best) {
                $used[$best] = true;
            }
            $out[$di] = $best;
        }
        return $out;
    }

    /** @return array<int, list<string>> */
    private function assignActivities(array $activities, array &$state, array $selected, array $days, array $origin, array $pace, array &$unscheduled): array
    {
        $out = array_fill(0, count($days), []);
        $acts = array_filter($activities, fn($a) => empty($a['addonOnly']) && ($a['slot'] ?? 'evening') !== 'morning');
        uasort($acts, fn($a, $b) => ($b['value'] ?? 3) <=> ($a['value'] ?? 3));

        foreach ($acts as $id => $act) {
            $bestDay = null;
            $bestD = 25.0;
            $type = $act['slot'] === 'day' ? 'day' : 'evening';
            foreach ($days as $di => $day) {
                if (count($out[$di]) >= $pace['acts'] || $act['hours'][$day['weekday']] === null) {
                    continue;
                }
                $clash = array_filter($out[$di], fn($x) => (($activities[$x]['slot'] === 'day') ? 'day' : 'evening') === $type);
                if ($clash) {
                    continue;
                }
                $pts = array_map(fn($x) => $selected[$x], $state['dayAttractions'][$di] ?? []);
                $center = $pts ? Geo::centroid($pts) : $origin;
                $d = Geo::between($center, $act);
                if ($d < $bestD) {
                    $bestD = $d;
                    $bestDay = $di;
                }
            }
            if ($bestDay === null) {
                continue;
            }
            $out[$bestDay][] = (string)$id;
            // A daytime workshop takes the slot of the day's weakest sight.
            if ($type === 'day' && count($state['dayAttractions'][$bestDay] ?? []) >= $pace['perDay']) {
                $ids = $state['dayAttractions'][$bestDay];
                usort($ids, fn($a, $b) => Places::score($selected[$a]) <=> Places::score($selected[$b]));
                $drop = $ids[0];
                $state['dayAttractions'][$bestDay] = array_values(array_diff($state['dayAttractions'][$bestDay], [$drop]));
                $unscheduled[] = ['name' => $selected[$drop]['name'], 'reason' => "Made room for {$act['name']}"];
            }
        }
        return $out;
    }

    /** One road-matrix call per day; returns the matrix source or null. */
    private function refineRoutes(array $build, TransitPlanner $transit): ?string
    {
        $sets = [];
        foreach ($build['days'] as $di => $d) {
            $pts = [['lat' => $build['base']['lat'], 'lng' => $build['base']['lng']]];
            foreach ($d['nodes'] as $n) {
                if ($n['type'] !== 'pause') {
                    $pts[] = ['lat' => $n['lat'], 'lng' => $n['lng']];
                }
            }
            $sets["d$di"] = $pts;
        }
        $routing = new RoutingService($this->http, (string)$this->cfg['openrouteservice_key']);
        $source = null;
        foreach ($routing->matrices($sets) as $key => $m) {
            if (!$m) {
                continue;
            }
            $source = $m['source'];
            $pts = $sets[$key];
            foreach ($pts as $i => $a) {
                foreach ($pts as $j => $b) {
                    $s = $m['durations'][$i][$j] ?? null;
                    $dist = $m['distances'][$i][$j] ?? null;
                    if ($i !== $j && $s !== null && $dist !== null) {
                        $transit->remember($a, $b, (float)$s, (float)$dist);
                    }
                }
            }
        }
        return $source;
    }

    // ─── Presentation ───────────────────────────────────────────────────────

    private function presentNode(array $n, array $details, float $l2h): array
    {
        $place = null;
        foreach (['attractions', 'eateries', 'markets', 'activities', 'hotels'] as $g) {
            if (isset($n['placeId'], $this->places[$g][$n['placeId']])) {
                $place = $this->places[$g][$n['placeId']];
                break;
            }
        }
        $d = (!empty($n['googleId']) && isset($details[$n['googleId']])) ? $details[$n['googleId']] : null;
        $leg = $n['transit'] ?? null;
        if ($leg) {
            $leg = [
                'mode' => $leg['mode'],
                'label' => $leg['label'],
                'minutes' => $leg['minutes'],
                'km' => $leg['km'],
                'cost' => round($leg['cost'], 2),
                'covered' => $leg['covered'],
                'routed' => $leg['routed'],
                'from' => $leg['from'],
                'fromLat' => $leg['fromLat'],
                'fromLng' => $leg['fromLng'],
                'options' => array_map(fn($o) => array_intersect_key($o, array_flip(['mode', 'label', 'minutes', 'km', 'cost'])), $leg['options']),
            ];
        }
        $nearby = in_array($n['type'], ['pause', 'return'], true) ? null : $this->nearby($n);

        return [
            'id' => $n['id'],
            'day' => $n['day'],
            'type' => $n['type'],
            'slot' => $n['slot'] ?? null,
            'placeId' => $n['placeId'] ?? null,
            'start' => $n['start'],
            'end' => $n['end'],
            'title' => $n['title'],
            'area' => $n['area'],
            'lat' => $n['lat'],
            'lng' => $n['lng'],
            'cost' => round($n['cost'], 2),
            'costLocal' => round($n['cost'] / $l2h, 2),
            'passValue' => isset($n['passValue']) ? round($n['passValue'], 2) : null,
            'category' => $n['category'],
            'priceEstimated' => $place['priceEstimated'] ?? false,
            'rating' => $d['rating'] ?? $n['rating'] ?? null,
            'ratingCount' => $d['ratingCount'] ?? $n['ratingCount'] ?? null,
            'ratingSource' => $n['ratingSource'] ?? null,
            'summary' => $n['summary'] !== '' ? $n['summary'] : ($place['crafts'] ?? ''),
            'tip' => $n['tip'] ?? '',
            'dish' => $n['dish'] ?? '',
            'why' => $n['why'] ?? '',
            'hours' => $n['hours'] ?? null,
            'hoursText' => $d['hoursText'] ?? $place['hoursText'] ?? [],
            'image' => $n['image'] ?? null,
            'mapsUri' => $d['mapsUri'] ?? $n['mapsUri'] ?? null,
            'reviews' => $d['reviews'] ?? [],
            'tags' => $n['tags'] ?? [],
            'interests' => $place['tags'] ?? [],
            'popularity' => $place['popularity'] ?? null,
            'priceText' => $place['priceText'] ?? '',
            'indoor' => $n['indoor'] ?? false,
            'transit' => $leg,
            'nearby' => $nearby,
        ];
    }

    private function presentHotel(array $h, array $centroid, array $ctx, array $prefs, array $details, float $l2h): array
    {
        $hub = null;
        foreach ($ctx['hubs'] as $x) {
            $km = Geo::between($h, $x);
            if (!$hub || $km < $hub['km']) {
                $hub = ['name' => $x['name'], 'km' => round($km, 2)];
            }
        }
        $d = (!empty($h['googleId']) && isset($details[$h['googleId']])) ? $details[$h['googleId']] : null;
        $perNight = $h['price'] * $prefs['rooms'];
        return [
            'id' => 'hotel',
            'type' => 'hotel',
            'placeId' => $h['id'],
            'title' => $h['name'],
            'area' => $h['area'],
            'lat' => $h['lat'],
            'lng' => $h['lng'],
            'style' => $h['style'],
            'tier' => $h['tier'] ?? 2,
            'rating' => $d['rating'] ?? $h['rating'] ?? null,
            'ratingCount' => $d['ratingCount'] ?? $h['ratingCount'] ?? null,
            'ratingSource' => $h['ratingSource'],
            'summary' => $h['summary'] ?? '',
            'tip' => $h['tip'] ?? '',
            'amenities' => $h['amenities'] ?? [],
            'image' => $h['image'] ?? null,
            'mapsUri' => $d['mapsUri'] ?? $h['mapsUri'] ?? null,
            'reviews' => $d['reviews'] ?? [],
            'hoursText' => [],
            'pricePerNight' => round($perNight, 2),
            'pricePerNightLocal' => round($perNight / $l2h, 2),
            'priceEstimated' => $h['priceEstimated'] ?? false,
            'nights' => $prefs['nights'],
            'rooms' => $prefs['rooms'],
            'cost' => round($perNight * $prefs['nights'], 2),
            'category' => 'accommodation',
            'centroidKm' => round(Geo::between($h, $centroid), 2),
            'hub' => $hub,
            'nearby' => $this->nearby(['lat' => $h['lat'], 'lng' => $h['lng'], 'placeId' => $h['id']]),
        ];
    }

    /** Chain level 03: closest kitchens and markets to a stop. */
    private function nearby(array $n): array
    {
        $eat = [];
        foreach ($this->places['eateries'] as $id => $e) {
            if ($id === ($n['placeId'] ?? null)) {
                continue;
            }
            $km = Geo::between($n, $e);
            if ($km <= 1.6) {
                $eat[] = ['id' => $id, 'name' => $e['name'], 'dish' => $e['dish'] ?? '', 'km' => round($km, 2), 'cost' => round($e['price'], 2),
                    'rating' => $e['rating'] ?? null, 'meals' => $e['meals'], 'diet' => $e['diet']];
            }
        }
        usort($eat, fn($a, $b) => $a['km'] <=> $b['km']);
        $mk = [];
        foreach ($this->places['markets'] as $id => $m) {
            if ($id === ($n['placeId'] ?? null)) {
                continue;
            }
            $km = Geo::between($n, $m);
            if ($km <= 3.0) {
                $mk[] = ['id' => $id, 'name' => $m['name'], 'crafts' => $m['crafts'] ?? '', 'km' => round($km, 2), 'rating' => $m['rating'] ?? null,
                    'night' => !empty($m['night'])];
            }
        }
        usort($mk, fn($a, $b) => $a['km'] <=> $b['km']);
        return ['cuisine' => array_slice($eat, 0, 4), 'markets' => array_slice($mk, 0, 2)];
    }

    /** @return list<array{service: string, calls: int, ok: int, cached: int, status: string}> */
    private function sourceSummary(): array
    {
        $by = [];
        foreach (HttpClient::log() as $e) {
            $s = &$by[$e['service']];
            $s ??= ['service' => $e['service'], 'calls' => 0, 'ok' => 0, 'cached' => 0, 'status' => $e['status']];
            $s['calls']++;
            if ($e['status'] === 'ok') {
                $s['ok']++;
                $s['status'] = 'ok';
            }
            if ($e['cached']) {
                $s['cached']++;
            }
            unset($s);
        }
        return array_values($by);
    }
}

function mb_strlen_safe(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s) : (int)preg_match_all('/./us', $s);
}

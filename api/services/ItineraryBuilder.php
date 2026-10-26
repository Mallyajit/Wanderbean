<?php
declare(strict_types=1);

/**
 * Turns a plan "state" (which hotel, which attractions on which day, which
 * market/activities, plus any meal or transit overrides) into a concrete,
 * chronological timetable. Building is deterministic and network-free, so the
 * budget engine can mutate the state and rebuild as often as it needs.
 *
 * Day shape: breakfast near basecamp → attractions in tour order (crowd-magnet
 * sites first, lunch slotted around midday, opening hours respected) →
 * afternoon market → golden-hour viewpoint timed to sunset → dinner (or a
 * dinner experience) → evening activity / night market → back to basecamp.
 */
final class ItineraryBuilder
{
    private const MEAL_SHARE = ['breakfast' => 0.2, 'lunch' => 0.35, 'dinner' => 0.45];
    private const MEAL_MINUTES = ['breakfast' => 45, 'lunch' => 60, 'dinner' => 75];
    private const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    /** @var array<string, true> eateries already used this trip */
    private array $used = [];
    /** @var array<string, true> overflow sights pulled in to fill gaps */
    private array $spareUsed = [];
    /** @var list<string> */
    public array $warnings = [];

    /**
     * @param array<string, mixed> $ctx city context; transit fares in home currency
     * @param array<string, array<string, array<string, mixed>>> $places grouped places, 'price' in home currency
     * @param list<array<string, mixed>> $days
     * @param array<string, mixed> $prefs travellers, dayStart, foodCapPP, diet
     */
    public function __construct(
        private array $ctx,
        private array $places,
        private array $days,
        private array $prefs,
        private TransitPlanner $transit,
    ) {
    }

    /** @param array<string, mixed> $state */
    public function build(array $state): array
    {
        $this->used = [];
        $this->spareUsed = [];
        $this->warnings = [];
        $base = $this->base($state);
        $days = [];
        foreach ($this->days as $di => $day) {
            $days[] = $this->buildDay($di, $day, $state, $base);
        }
        $packages = $this->applyPasses($days);
        return ['days' => $days, 'packages' => $packages, 'base' => $base, 'sparesUsed' => array_keys($this->spareUsed)];
    }

    /** @return array<string, mixed> */
    public function base(array $state): array
    {
        if (!empty($state['hotelId'])) {
            return $this->places['hotels'][$state['hotelId']];
        }
        $hub = $this->ctx['hubs'][0] ?? ['name' => $this->ctx['name'] . ' centre', 'lat' => $this->ctx['lat'], 'lng' => $this->ctx['lng']];
        return $hub + ['id' => 'hub', 'area' => 'Transit hub'];
    }

    private function buildDay(int $di, array $day, array $state, array $base): array
    {
        $wd = $day['weekday'];
        $c = [
            'di' => $di,
            'day' => $day,
            'wd' => $wd,
            't' => $this->prefs['dayStart'],
            'loc' => $base,
            'nodes' => [],
            'skipped' => [],
            'state' => $state,
        ];

        $stops = [];
        $golden = null;
        foreach ($state['dayAttractions'][$di] ?? [] as $id) {
            $a = $this->places['attractions'][$id];
            $win = $a['hours'][$wd];
            if (!$golden && $a['golden'] && $win && $win[1] >= $day['sunsetMin']) {
                $golden = $a;
                continue;
            }
            $stops[] = $a;
        }
        $evening = [];
        foreach ($state['dayActivities'][$di] ?? [] as $id) {
            $act = $this->places['activities'][$id];
            if ($act['slot'] === 'day') {
                $stops[] = $act;
            } else {
                $evening[] = $act;
            }
        }

        // Breakfast between basecamp and the first stop — no earlier than it
        // needs to be, so nobody stands outside a locked gate.
        $first = $stops[0] ?? $golden ?? null;
        $firstOpen = $first ? ($first['hours'][$wd][0] ?? 0) : 0;
        $this->meal($c, 'breakfast', max($this->prefs['dayStart'], $firstOpen - 75), $first);

        $sunset = $day['sunsetMin'];
        $lunched = false;
        foreach ($stops as $s) {
            $duration = (int)$s['duration'];
            if (!$lunched && ($c['t'] >= 12 * 60 || ($c['t'] >= 11 * 60 + 15 && $c['t'] + $duration + 25 > 13 * 60 + 45))) {
                $this->meal($c, 'lunch', 12 * 60 + 30);
                $lunched = true;
            }
            // Protect the golden-hour slot. If a stop would run into it, the
            // weaker of the two gives way.
            if ($golden && $c['t'] + 20 + $duration > $sunset - 50) {
                if (Places::score($s) > Places::score($golden) + 0.5) {
                    $c['skipped'][] = ['id' => $golden['id'], 'name' => $golden['name'], 'reason' => 'Gave its sunset slot to ' . $s['name']];
                    $golden = null;
                } else {
                    $c['skipped'][] = ['id' => $s['id'], 'name' => $s['name'], 'reason' => 'Would run into the sunset slot'];
                    continue;
                }
            }
            $this->visit($c, $s);
        }
        if (!$lunched) {
            $this->meal($c, 'lunch', 12 * 60 + 30);
        }

        // A thin afternoon borrows the best unused sight close by.
        $this->fillGap($c, $golden ? $sunset - 60 : Geo::toMin($this->ctx['dinnerTime'] ?? '19:30') - 20);

        $market = !empty($state['dayMarket'][$di]) ? $this->places['markets'][$state['dayMarket'][$di]] : null;
        $marketDone = false;
        if ($market && empty($market['night']) && (!$golden || $c['t'] + 100 <= $sunset - 45)) {
            $marketDone = $this->market($c, $market);
        }
        if ($golden) {
            $this->golden($c, $golden);
        }
        if ($market && !$marketDone && empty($market['night'])) {
            $marketDone = $this->market($c, $market);
        }

        // Evening: dinner and any evening experience, ordered by their start times.
        $dinnerAt = Geo::toMin($this->ctx['dinnerTime'] ?? '19:30');
        $events = [];
        $dinnerAct = null;
        foreach ($evening as $act) {
            $fixed = isset($act['start']) ? Geo::toMin($act['start']) : null;
            if (!empty($act['includesDinner']) && !$dinnerAct) {
                $dinnerAct = $act;
                $events[] = [max($dinnerAt - 60, $fixed ?? 0), 'act', $act];
            } else {
                $events[] = [$fixed ?? $dinnerAt + 90, 'act', $act];
                // A fixed-time show that would collide with dinner goes first; dinner follows it.
                if ($fixed !== null && $fixed < $dinnerAt + self::MEAL_MINUTES['dinner'] + 20) {
                    $dinnerAt = max($dinnerAt, $fixed + (int)$act['duration'] + 10);
                }
            }
        }
        if (!$dinnerAct) {
            $events[] = [$dinnerAt, 'dinner', null];
        }
        usort($events, fn($x, $y) => $x[0] <=> $y[0]);
        foreach ($events as [$at, $what, $act]) {
            if ($what === 'dinner') {
                $this->meal($c, 'dinner', $at);
            } elseif ($c['t'] <= 22 * 60) {
                $this->activity($c, $act, $at);
            } else {
                $c['skipped'][] = ['id' => $act['id'], 'name' => $act['name'], 'reason' => 'Ran past 22:00 — kept as an add-on'];
            }
        }
        if ($market && !$marketDone) {
            $this->market($c, $market);
        }

        // Home.
        [$leg, $arrive] = $this->travel($c, "d{$di}-return", $base);
        $c['nodes'][] = $this->node($c, "d{$di}-return", 'return', $base, $leg, $arrive, $arrive, [
            'title' => ($base['id'] === 'hub' ? 'Wrap at ' : 'Back at ') . $base['name'],
            'summary' => 'Day closes at basecamp. ' . ($di === count($this->days) - 1 ? 'Check-out is usually 11:00 — most desks hold bags for the afternoon.' : 'Lay out tomorrow\'s early start.'),
            'category' => null,
        ]);

        $areas = [];
        foreach ($c['nodes'] as $n) {
            if (in_array($n['type'], ['attraction', 'golden'], true) && $n['area'] !== '' && !in_array($n['area'], $areas, true)) {
                $areas[] = $n['area'];
            }
        }

        return [
            'index' => $di,
            'date' => $day['date'],
            'weekday' => self::WEEKDAYS[$wd],
            'theme' => $areas ? implode(' & ', array_slice($areas, 0, 2)) : 'Markets, Food & Slow Wandering',
            'nodes' => $c['nodes'],
            'skipped' => $c['skipped'],
        ];
    }

    // ─── Stops ──────────────────────────────────────────────────────────────

    private function visit(array &$c, array $s): void
    {
        $id = "d{$c['di']}-" . $s['id'];
        $win = $s['hours'][$c['wd']];
        if (!$win) {
            $c['skipped'][] = ['id' => $s['id'], 'name' => $s['name'], 'reason' => 'Closed on ' . self::WEEKDAYS[$c['wd']] . 's'];
            return;
        }
        $duration = (int)$s['duration'];
        [$leg, $arrive] = $this->travel($c, $id, $s);
        $start = max($arrive, $win[0]);
        if ($start + min($duration, 60) > $win[1]) {
            $c['skipped'][] = ['id' => $s['id'], 'name' => $s['name'], 'reason' => 'Closes at ' . Geo::fmt($win[1]) . ' — no time for a proper visit'];
            return;
        }
        $end = min($start + $duration, $win[1]);
        $this->pauseIfIdle($c, $arrive, $start, $leg);

        $isActivity = $s['kind'] === 'activity';
        $why = [];
        if (!empty($s['early']) && count($c['nodes']) <= 2) {
            $why[] = 'Gates open ' . Geo::fmt($win[0]) . ' — the first hour is the quietest before tour groups arrive.';
        } elseif (!empty($s['early'])) {
            $why[] = 'Crowd magnet: weekday mornings are calmer than afternoons.';
        }
        if ($c['day']['hot'] && $s['indoor'] && $start >= 12 * 60 && $start < 16 * 60) {
            $why[] = 'Indoor stop placed in the midday heat (' . $c['day']['tmax'] . '°).';
        } elseif ($c['day']['wet'] && $s['indoor']) {
            $why[] = 'Under cover on a ' . $c['day']['rain'] . '% rain day.';
        } elseif ($c['day']['wet'] && !$s['indoor']) {
            $why[] = 'Outdoor — pack a shell; rain chance ' . $c['day']['rain'] . '%.';
        }
        if ($arrive < $win[0]) {
            $why[] = 'Arrive as it opens at ' . Geo::fmt($win[0]) . '.';
        }

        $c['nodes'][] = $this->node($c, $id, $isActivity ? 'activity' : 'attraction', $s, $leg, $start, $end, [
            'cost' => $s['price'] * $this->prefs['travellers'],
            'category' => $isActivity ? 'activities' : 'entry',
            'hours' => Places::hoursLabel($win),
            'why' => implode(' ', $why),
        ]);
        $c['t'] = $end;
        $c['loc'] = $s;
    }

    private function golden(array &$c, array $g): void
    {
        $id = "d{$c['di']}-" . $g['id'];
        $win = $g['hours'][$c['wd']];
        $sunset = $c['day']['sunsetMin'];
        [$leg, $arrive] = $this->travel($c, $id, $g);
        $target = min($sunset - 45, $win[1] - 40);
        $start = max($arrive, $win[0], $target);
        $end = min(max($start + 40, $sunset + 15), $win[1]);
        if ($end - $start < 25 || $start > $sunset) {
            $c['skipped'][] = ['id' => $g['id'], 'name' => $g['name'], 'reason' => 'Sunset slot ran out of daylight'];
            return;
        }
        $this->pauseIfIdle($c, $arrive, $start, $leg);
        $why = 'Golden hour: sunset at ' . $c['day']['sunset'] . '. Arrive ~45 minutes before for warm, raking light.';
        if ($c['day']['wet']) {
            $why .= ' Rain risk ' . $c['day']['rain'] . '% — the indoor swap in add-ons is a safe fallback.';
        }
        $c['nodes'][] = $this->node($c, $id, 'attraction', $g, $leg, $start, $end, [
            'cost' => $g['price'] * $this->prefs['travellers'],
            'category' => 'entry',
            'hours' => Places::hoursLabel($win),
            'why' => $why,
            'tags' => ['GOLDEN HOUR'],
        ]);
        $c['t'] = $end;
        $c['loc'] = $g;
    }

    private function market(array &$c, array $m): bool
    {
        $id = "d{$c['di']}-" . $m['id'];
        $win = $m['hours'][$c['wd']];
        if (!$win) {
            return false;
        }
        [$leg, $arrive] = $this->travel($c, $id, $m);
        $start = max($arrive, $win[0]);
        if ($start + 45 > $win[1]) {
            return false;
        }
        $end = min($start + 75, $win[1]);
        $this->pauseIfIdle($c, $arrive, $start, $leg);
        $browse = !empty($c['state']['browseOnly'][$m['id']]);
        $c['nodes'][] = $this->node($c, $id, 'market', $m, $leg, $start, $end, [
            'cost' => $browse ? 0.0 : $m['price'], // shopping allowance is per party
            'category' => 'activities',
            'hours' => Places::hoursLabel($win),
            'why' => !empty($m['night']) ? 'Night market — stalls light up after dark.' : 'Late-afternoon slot: cooler, and vendors are settled in for the evening trade.',
            'tags' => $browse ? ['BROWSE ONLY'] : ['SHOPPING ALLOWANCE'],
        ]);
        $c['t'] = $end;
        $c['loc'] = $m;
        return true;
    }

    private function activity(array &$c, array $act, int $notBefore): void
    {
        $id = "d{$c['di']}-" . $act['id'];
        $win = $act['hours'][$c['wd']];
        if (!$win) {
            $c['skipped'][] = ['id' => $act['id'], 'name' => $act['name'], 'reason' => 'Not running on ' . self::WEEKDAYS[$c['wd']] . 's'];
            return;
        }
        [$leg, $arrive] = $this->travel($c, $id, $act);
        $start = max($arrive, $notBefore, $win[0]);
        $end = min($start + (int)$act['duration'], 24 * 60 - 1);
        $this->pauseIfIdle($c, $arrive, $start, $leg);
        $c['nodes'][] = $this->node($c, $id, 'activity', $act, $leg, $start, $end, [
            'cost' => $act['price'] * $this->prefs['travellers'],
            'category' => 'activities',
            'slot' => !empty($act['includesDinner']) ? 'dinner' : 'evening',
            'hours' => isset($act['start']) ? 'STARTS ' . $act['start'] : Places::hoursLabel($win),
            'why' => !empty($act['includesDinner']) ? 'Dinner is included — no separate meal booked tonight.' : 'Evening slot, after the day\'s sightseeing.',
        ]);
        $c['t'] = $end;
        $c['loc'] = $act;
    }

    /** Visit spare (shortlist-overflow) sights near the current spot until $until. */
    private function fillGap(array &$c, int $until): void
    {
        for ($n = 0; $n < 3 && $c['t'] + 90 < $until; $n++) {
            $best = null;
            $bestKm = 3.5;
            foreach ($c['state']['spares'] ?? [] as $id) {
                $s = $this->places['attractions'][$id] ?? null;
                if (!$s || isset($this->spareUsed[$id])) {
                    continue;
                }
                $win = $s['hours'][$c['wd']];
                $km = Geo::between($c['loc'], $s);
                if (!$win || $km >= $bestKm) {
                    continue;
                }
                $eta = $c['t'] + 8 + (int)($km * 6);
                if (max($eta, $win[0]) + min((int)$s['duration'], 60) > min($win[1], $until)) {
                    continue;
                }
                $best = $s;
                $bestKm = $km;
            }
            if (!$best) {
                return;
            }
            $this->spareUsed[$best['id']] = true;
            $this->visit($c, $best);
        }
    }

    // ─── Meals ──────────────────────────────────────────────────────────────

    private function meal(array &$c, string $slot, int $notBefore, ?array $toward = null): void
    {
        $id = "d{$c['di']}-{$slot}";
        [$e, $alts] = $this->pickEatery($c, $slot, max($notBefore, $c['t']), $toward, $id);
        if (!$e) {
            // No listed kitchen in reach: budget a typical local meal near here rather than skip it.
            $area = ($c['loc']['area'] ?? '') ?: $this->ctx['name'];
            $e = [
                'id' => "local-{$slot}",
                'name' => 'Local ' . $slot . ' spot near ' . ($c['loc']['name'] ?? $area),
                'area' => $area,
                'lat' => $c['loc']['lat'],
                'lng' => $c['loc']['lng'],
                'price' => $this->prefs['foodCapPP'] * self::MEAL_SHARE[$slot] * 0.8,
                'rating' => null,
                'ratingSource' => null,
                'summary' => 'No kitchen listed close by — pick whatever is busy with locals. Budgeted at a typical local price.',
                'hours' => Places::weekHours(null),
                'dish' => '',
            ];
            $alts = [];
        }
        [$leg, $arrive] = $this->travel($c, $id, $e);
        $start = max($arrive, $notBefore);
        $this->pauseIfIdle($c, $arrive, $start, $leg);
        $end = $start + self::MEAL_MINUTES[$slot];
        $c['nodes'][] = $this->node($c, $id, 'meal', $e, $leg, $start, $end, [
            'slot' => $slot,
            'cost' => $e['price'] * $this->prefs['travellers'],
            'category' => 'food',
            'alternatives' => $alts,
            'dish' => $e['dish'] ?? '',
            'hours' => Places::hoursLabel($e['hours'][$c['wd']]),
            'why' => match ($slot) {
                'breakfast' => 'On the way from basecamp to the first stop.',
                'lunch' => 'Nearest well-rated kitchen to the morning cluster.',
                default => 'Within easy reach of the day\'s last stop.',
            },
        ]);
        $this->used[$e['id']] = true;
        $c['t'] = $end;
        $c['loc'] = $e;
    }

    /** @return array{0: ?array, 1: list<string>} */
    public function pickEatery(array $c, string $slot, int $at, ?array $toward, string $nodeId): array
    {
        $cap = max(0.01, $this->prefs['foodCapPP'] * self::MEAL_SHARE[$slot]);
        $anchor = $c['loc'];
        // Widen the search step by step. A long detour for a diet-tagged kitchen
        // is worse than a nearby one that can adapt a dish, so relax the diet
        // filter before relaxing the distance.
        $scored = $this->rankEateries($c, $slot, $at, $anchor, $toward, $cap, true, 4.0);
        if (!$scored && $this->prefs['diet']) {
            $scored = $this->rankEateries($c, $slot, $at, $anchor, $toward, $cap, false, 4.0);
            if ($scored) {
                $this->warnings['diet'] = 'Some meal slots had no kitchen tagged for your dietary filter within reach; those use the best nearby option — ask for adaptations.';
            }
        }
        if (!$scored) {
            $scored = $this->rankEateries($c, $slot, $at, $anchor, $toward, $cap, !empty($this->prefs['diet']), 12.0)
                ?: $this->rankEateries($c, $slot, $at, $anchor, $toward, $cap, false, 12.0);
        }
        if (!$scored) {
            return [null, []];
        }
        arsort($scored);
        $ids = array_keys($scored);
        $override = $c['state']['mealOverrides'][$nodeId] ?? null;
        $chosen = ($override && isset($scored[$override])) ? $override : $ids[0];

        // Alternatives: the best three plus the cheapest and priciest nearby.
        $others = array_values(array_filter($ids, fn($id) => $id !== $chosen));
        $byPrice = $others;
        usort($byPrice, fn($a, $b) => $this->places['eateries'][$a]['price'] <=> $this->places['eateries'][$b]['price']);
        $alts = array_values(array_unique(array_merge(array_slice($others, 0, 3), array_slice($byPrice, 0, 1), array_slice($byPrice, -1))));

        return [$this->places['eateries'][$chosen], $alts];
    }

    /** @return array<string, float> */
    private function rankEateries(array $c, string $slot, int $at, array $anchor, ?array $toward, float $cap, bool $useDiet, float $maxDetour): array
    {
        $scored = [];
        foreach ($this->places['eateries'] as $e) {
            $serves = in_array($slot, $e['meals'], true) || ($slot !== 'breakfast' && in_array('street', $e['meals'], true));
            if (!$serves || ($useDiet && !$this->dietOk($e))) {
                continue;
            }
            $w = $e['hours'][$c['wd']];
            if (!$w || $w[0] > $at + 30 || $w[1] < $at + 45) {
                continue;
            }
            $detour = Geo::between($anchor, $e);
            if ($toward) {
                $detour += Geo::between($e, $toward) - Geo::between($anchor, $toward);
            }
            if ($detour > $maxDetour) {
                continue;
            }
            $over = max(0.0, $e['price'] / $cap - 1);
            // Within the cap, a more serious kitchen is a small plus; above it, a steep minus.
            $tier = $over > 0 ? 0.0 : ($e['tier'] ?? 2) * 0.25;
            $scored[$e['id']] = ($e['rating'] ?? 4.0) * 1.2 + $tier - $detour * 0.7 - $over * 3 - (isset($this->used[$e['id']]) ? 2.0 : 0.0);
        }
        return $scored;
    }

    private function dietOk(array $e): bool
    {
        foreach ($this->prefs['diet'] as $d) {
            if (!in_array($d, $e['diet'] ?? [], true)) {
                return false;
            }
        }
        return true;
    }

    // ─── Movement ───────────────────────────────────────────────────────────

    /** @return array{0: array<string, mixed>, 1: int} */
    private function travel(array $c, string $nodeId, array $dest): array
    {
        $opts = $this->transit->options($c['loc'], $dest, $c['t']);
        $leg = $this->transit->choose($opts, $c['state']['legOverrides'][$nodeId] ?? null);
        $leg['from'] = $c['loc']['name'] ?? '';
        $leg['fromLat'] = $c['loc']['lat'];
        $leg['fromLng'] = $c['loc']['lng'];
        $leg['options'] = $this->transit->allowed($opts);
        $leg['covered'] = null;
        return [$leg, $c['t'] + $leg['minutes']];
    }

    /** Make long idle gaps explicit rather than leaving silent holes in the day. */
    private function pauseIfIdle(array &$c, int $arrive, int $start, array $leg): void
    {
        $idle = $start - $arrive;
        if ($idle < 75 || $c['t'] <= $this->prefs['dayStart']) {
            return;
        }
        $from = $c['t'];
        $to = $start - $leg['minutes'];
        $area = ($c['loc']['area'] ?? '') ?: $this->ctx['name'];
        $c['nodes'][] = [
            'id' => "d{$c['di']}-pause-" . count($c['nodes']),
            'day' => $c['di'],
            'type' => 'pause',
            'start' => Geo::fmt($from),
            'end' => Geo::fmt($to),
            'startMin' => $from,
            'endMin' => $to,
            'title' => 'Slow hour in ' . $area,
            'area' => $area,
            'lat' => $c['loc']['lat'],
            'lng' => $c['loc']['lng'],
            'summary' => 'Unscheduled on purpose: linger over coffee, rest at basecamp, or wander the lanes. Nothing to book.',
            'cost' => 0.0,
            'category' => null,
            'transit' => null,
            'tags' => ['FREE TIME'],
            'rating' => null,
        ];
    }

    private function node(array $c, string $id, string $type, array $place, ?array $leg, int $start, int $end, array $extra = []): array
    {
        return $extra + [
            'id' => $id,
            'day' => $c['di'],
            'type' => $type,
            'placeId' => $place['id'] ?? null,
            'start' => Geo::fmt($start),
            'end' => Geo::fmt($end),
            'startMin' => $start,
            'endMin' => $end,
            'title' => $place['name'],
            'area' => $place['area'] ?? '',
            'lat' => $place['lat'],
            'lng' => $place['lng'],
            'rating' => $place['rating'] ?? null,
            'ratingCount' => $place['ratingCount'] ?? null,
            'ratingSource' => $place['ratingSource'] ?? null,
            'summary' => $place['summary'] ?? '',
            'tip' => $place['tip'] ?? '',
            'image' => $place['image'] ?? null,
            'mapsUri' => $place['mapsUri'] ?? null,
            'googleId' => $place['googleId'] ?? null,
            'indoor' => $place['indoor'] ?? false,
            'transit' => $leg,
            'tags' => [],
            'cost' => 0.0,
            'category' => null,
        ];
    }

    // ─── Passes ─────────────────────────────────────────────────────────────

    /**
     * City cards (entry bundles, sometimes with transit) and transit day
     * passes are applied only when they beat paying per entry / per ride.
     *
     * @return list<array<string, mixed>>
     */
    private function applyPasses(array &$days): array
    {
        $packages = [];
        $trav = $this->prefs['travellers'];
        $nDays = count($days);
        $transitCoveredDays = [];

        $best = null;
        foreach ($this->ctx['passes'] ?? [] as $pass) {
            $span = min((int)$pass['validityDays'], $nDays);
            for ($s = 0; $s + $span <= $nDays; $s++) {
                $value = 0.0;
                $hits = [];
                for ($di = $s; $di < $s + $span; $di++) {
                    foreach ($days[$di]['nodes'] as $ni => $n) {
                        if ($n['type'] === 'attraction' && in_array($n['placeId'], $pass['covers'], true) && $n['cost'] > 0) {
                            $value += $n['cost'];
                            $hits[] = [$di, $ni, 'stop'];
                        }
                        if (!empty($pass['coversTransit']) && in_array($n['transit']['mode'] ?? '', ['metro', 'bus'], true) && $n['transit']['cost'] > 0) {
                            $value += $n['transit']['cost'];
                            $hits[] = [$di, $ni, 'leg'];
                        }
                    }
                }
                $price = $pass['price'] * $trav;
                if (!$best || $value - $price > $best['saving']) {
                    $best = ['pass' => $pass, 'value' => $value, 'price' => $price, 'saving' => $value - $price, 'hits' => $hits, 'from' => $s, 'span' => $span];
                }
            }
        }
        if ($best) {
            $p = $best['pass'];
            $applied = $best['saving'] > 0;
            if ($applied) {
                foreach ($best['hits'] as [$di, $ni, $what]) {
                    if ($what === 'stop') {
                        $days[$di]['nodes'][$ni]['passValue'] = $days[$di]['nodes'][$ni]['cost'];
                        $days[$di]['nodes'][$ni]['cost'] = 0.0;
                        $days[$di]['nodes'][$ni]['tags'][] = 'PASS';
                    } else {
                        $days[$di]['nodes'][$ni]['transit']['covered'] = $p['label'];
                        $days[$di]['nodes'][$ni]['transit']['cost'] = 0.0;
                    }
                }
                if (!empty($p['coversTransit'])) {
                    $transitCoveredDays = range($best['from'], $best['from'] + $best['span'] - 1);
                }
            }
            $packages[] = [
                'id' => $p['id'],
                'kind' => 'citycard',
                'label' => $p['label'],
                'detail' => $p['detail'] ?? '',
                'price' => $best['price'],
                'value' => $best['value'],
                'saving' => $best['saving'],
                'applied' => $applied,
                'category' => 'entry',
                'days' => range($best['from'], $best['from'] + $best['span'] - 1),
                'note' => $applied
                    ? sprintf('Covers %d scheduled entries%s.', count(array_filter($best['hits'], fn($h) => $h[2] === 'stop')), !empty($p['coversTransit']) ? ' plus metro & bus rides' : '')
                    : 'Paying per entry is cheaper for this itinerary.',
            ];
        }

        $pass = $this->ctx['transit']['dayPass'] ?? null;
        if ($pass) {
            $bestMiss = 0.0;
            $any = false;
            foreach ($days as $di => &$day) {
                if (in_array($di, $transitCoveredDays, true)) {
                    continue;
                }
                $fares = 0.0;
                $legs = [];
                foreach ($day['nodes'] as $ni => $n) {
                    if (in_array($n['transit']['mode'] ?? '', $pass['covers'], true) && $n['transit']['cost'] > 0) {
                        $fares += $n['transit']['cost'];
                        $legs[] = $ni;
                    }
                }
                $price = $pass['price'] * $trav;
                if ($fares > $price) {
                    foreach ($legs as $ni) {
                        $day['nodes'][$ni]['transit']['covered'] = $pass['label'];
                        $day['nodes'][$ni]['transit']['cost'] = 0.0;
                    }
                    $any = true;
                    $packages[] = [
                        'id' => 'daypass-d' . $di,
                        'kind' => 'daypass',
                        'label' => $pass['label'] . ' — Day ' . sprintf('%02d', $di + 1),
                        'detail' => $pass['detail'] ?? '',
                        'price' => $price,
                        'value' => $fares,
                        'saving' => $fares - $price,
                        'applied' => true,
                        'category' => 'transit',
                        'days' => [$di],
                        'note' => sprintf('%d rides would cost more than the pass.', count($legs)),
                    ];
                } else {
                    $bestMiss = max($bestMiss, $fares);
                }
            }
            unset($day);
            if (!$any) {
                $packages[] = [
                    'id' => 'daypass',
                    'kind' => 'daypass',
                    'label' => $pass['label'],
                    'detail' => $pass['detail'] ?? '',
                    'price' => $pass['price'] * $trav,
                    'value' => $bestMiss,
                    'saving' => $bestMiss - $pass['price'] * $trav,
                    'applied' => false,
                    'category' => 'transit',
                    'days' => [],
                    'note' => 'Single fares come in under the pass price on every day of this plan.',
                ];
            }
        }

        return $packages;
    }
}

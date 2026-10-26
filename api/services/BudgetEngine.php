<?php
declare(strict_types=1);

/**
 * The money side of the planner:
 *  - ledger():     Accommodation + Transit + Entry + Food + Markets/Activities
 *  - downgrades(): candidate savings moves when a plan breaks the budget,
 *                  each scored saving ÷ quality-loss for a greedy fit loop
 *  - addons():     every swap / upgrade / extra the UI can toggle, with its
 *                  exact marginal cost in home currency
 *  - tiers():      +10 / +15 / +25 % budget boosts, each filled with the
 *                  highest-value combination of upgrades that fits
 */
final class BudgetEngine
{
    public const CATEGORIES = ['accommodation', 'transit', 'entry', 'food', 'activities'];

    /** @var callable(float): string */
    private $money;

    /**
     * @param array<string, array<string, array<string, mixed>>> $places
     * @param array<string, mixed> $prefs travellers, rooms, nights, budget, guideByDefault
     * @param array<string, mixed> $ctx
     */
    public function __construct(private array $places, private array $prefs, private array $ctx, callable $money)
    {
        $this->money = $money;
    }

    /** @return array{categories: array<string, float>, total: float, budget: float, buffer: float} */
    public function ledger(array $build, array $state): array
    {
        $l = array_fill_keys(self::CATEGORIES, 0.0);
        $hotel = !empty($state['hotelId']) ? $this->places['hotels'][$state['hotelId']] : null;
        $l['accommodation'] = $hotel ? $hotel['price'] * $this->prefs['nights'] * $this->prefs['rooms'] : 0.0;
        foreach ($build['days'] as $d) {
            foreach ($d['nodes'] as $n) {
                if (!empty($n['category'])) {
                    $l[$n['category']] += $n['cost'];
                }
                if (!empty($n['transit'])) {
                    $l['transit'] += $n['transit']['cost'];
                }
            }
        }
        foreach ($build['packages'] as $p) {
            if ($p['applied']) {
                $l[$p['category']] += $p['price'];
            }
        }
        $total = array_sum($l);
        return [
            'categories' => array_map(fn($v) => round($v, 2), $l),
            'total' => round($total, 2),
            'budget' => (float)$this->prefs['budget'],
            'buffer' => round($this->prefs['budget'] - $total, 2),
        ];
    }

    /**
     * @param list<string> $hotelRank hotel ids, best first
     * @return list<array{saving: float, loss: float, label: string, apply: callable}>
     */
    public function downgrades(array $build, array $state, array $hotelRank): array
    {
        $moves = [];
        $trav = $this->prefs['travellers'];
        $H = $this->places['hotels'];

        if (!empty($state['hotelId'])) {
            $cur = $H[$state['hotelId']];
            $rank = array_flip($hotelRank);
            foreach ($H as $h) {
                if ($h['price'] >= $cur['price']) {
                    continue;
                }
                $saving = ($cur['price'] - $h['price']) * $this->prefs['nights'] * $this->prefs['rooms'];
                $loss = 0.25 + max(0, (($cur['rating'] ?? 4.2) - ($h['rating'] ?? 4.0)) * 2) + max(0, ($rank[$h['id']] ?? 9) - ($rank[$cur['id']] ?? 0)) * 0.15;
                $id = $h['id'];
                $moves[] = ['saving' => $saving, 'loss' => $loss, 'label' => 'Basecamp → ' . $h['name'], 'apply' => fn(array $s) => ['hotelId' => $id] + $s];
            }
        }

        foreach ($build['days'] as $di => $d) {
            $attractionCount = count(array_filter($d['nodes'], fn($n) => $n['type'] === 'attraction'));
            foreach ($d['nodes'] as $n) {
                $nid = $n['id'];
                $pid = $n['placeId'] ?? null;

                if ($n['type'] === 'meal') {
                    foreach ($n['alternatives'] as $eid) {
                        $e = $this->places['eateries'][$eid];
                        $alt = $e['price'] * $trav;
                        if ($alt < $n['cost'] - 0.01) {
                            $loss = 0.15 + max(0, (($n['rating'] ?? 4.0) - ($e['rating'] ?? 4.0)) * 1.5);
                            $moves[] = ['saving' => $n['cost'] - $alt, 'loss' => $loss, 'label' => "Meal swap → {$e['name']}",
                                'apply' => function (array $s) use ($nid, $eid) { $s['mealOverrides'][$nid] = $eid; return $s; }];
                        }
                    }
                }

                $leg = $n['transit'] ?? null;
                if ($leg && $leg['cost'] > 0 && !$leg['covered']) {
                    foreach ($leg['options'] as $o) {
                        if ($o['cost'] < $leg['cost'] - 0.01) {
                            $mode = $o['mode'];
                            $loss = 0.05 + max(0, $o['minutes'] - $leg['minutes']) / 35;
                            $moves[] = ['saving' => $leg['cost'] - $o['cost'], 'loss' => $loss, 'label' => "Leg → {$o['label']}",
                                'apply' => function (array $s) use ($nid, $mode) { $s['legOverrides'][$nid] = $mode; return $s; }];
                        }
                    }
                }

                if ($n['type'] === 'market' && $n['cost'] > 0) {
                    $moves[] = ['saving' => $n['cost'], 'loss' => 0.6, 'label' => 'Browse-only market',
                        'apply' => function (array $s) use ($pid) { $s['browseOnly'][$pid] = true; return $s; }];
                }

                if ($n['type'] === 'activity' && $n['cost'] > 0) {
                    $value = (float)($this->places['activities'][$pid]['value'] ?? 3);
                    $moves[] = ['saving' => $n['cost'], 'loss' => 0.4 * $value, 'label' => "Drop {$n['title']}",
                        'apply' => function (array $s) use ($di, $pid) {
                            $s['dayActivities'][$di] = array_values(array_diff($s['dayActivities'][$di] ?? [], [$pid]));
                            $s['dropped'][$pid] = 'Dropped to fit the budget — available as an add-on';
                            return $s;
                        }];
                }

                if ($n['type'] === 'attraction' && $n['cost'] > 0 && $attractionCount > 2) {
                    $moves[] = ['saving' => $n['cost'], 'loss' => 0.55 * Places::score($this->places['attractions'][$pid]), 'label' => "Drop {$n['title']}",
                        'apply' => function (array $s) use ($di, $pid) {
                            $s['dayAttractions'][$di] = array_values(array_diff($s['dayAttractions'][$di], [$pid]));
                            $s['dropped'][$pid] = 'Dropped to fit the budget';
                            return $s;
                        }];
                }
            }
        }
        return $moves;
    }

    /**
     * @param list<string> $hotelAlts hotel ids offered as swaps
     * @return array<string, array<string, mixed>>
     */
    public function addons(array $build, array $state, array $hotelAlts): array
    {
        $out = [];
        $trav = $this->prefs['travellers'];
        $money = $this->money;

        // Basecamp swaps.
        $cur = !empty($state['hotelId']) ? $this->places['hotels'][$state['hotelId']] : null;
        if ($cur) {
            foreach ($hotelAlts as $hid) {
                $h = $this->places['hotels'][$hid];
                $delta = ($h['price'] - $cur['price']) * $this->prefs['nights'] * $this->prefs['rooms'];
                $out["hotel:$hid"] = [
                    'id' => "hotel:$hid",
                    'group' => 'hotel',
                    'nodeId' => 'hotel',
                    'category' => 'accommodation',
                    'affects' => 'stop',
                    'kind' => $delta > 0 ? 'upgrade' : 'saving',
                    'delta' => round($delta, 2),
                    'label' => ($delta > 0 ? 'Upgrade basecamp to ' : 'Swap basecamp to ') . $h['name'],
                    'detail' => ucfirst($h['style']) . ' · ' . $h['area'] . ' · ' . $money($h['price'] * $this->prefs['rooms']) . ' / night',
                    'value' => round(max(0.0, (($h['rating'] ?? 4.2) - ($cur['rating'] ?? 4.2)) * 2 + (($h['tier'] ?? 2) - ($cur['tier'] ?? 2)) * 1.6 + 0.8), 2),
                    'from' => $cur['style'],
                    'to' => $h['style'],
                    'patch' => $this->placePatch($h) + ['style' => $h['style'], 'pricePerNight' => $h['price'] * $this->prefs['rooms']],
                ];
            }
        }

        $mealNodes = [];
        foreach ($build['days'] as $di => $d) {
            $dayTransit = 0.0;
            foreach ($d['nodes'] as $n) {
                $nid = $n['id'];
                $leg = $n['transit'] ?? null;
                $dayTransit += $leg['cost'] ?? 0;

                if ($n['type'] === 'meal') {
                    $mealNodes[] = $n;
                    $curTier = $this->places['eateries'][$n['placeId']]['tier'] ?? 2;
                    foreach ($n['alternatives'] as $eid) {
                        $e = $this->places['eateries'][$eid];
                        $delta = $e['price'] * $trav - $n['cost'];
                        $km = Geo::between($n, $e);
                        $out["meal:$nid:$eid"] = [
                            'id' => "meal:$nid:$eid",
                            'group' => "meal:$nid",
                            'nodeId' => $nid,
                            'category' => 'food',
                            'affects' => 'stop',
                            'kind' => $delta > 0 ? 'upgrade' : 'saving',
                            'delta' => round($delta, 2),
                            'placeId' => $eid,
                            'label' => ($delta > 0 ? 'Trade up to ' : 'Swap to ') . $e['name'],
                            'detail' => trim(($e['dish'] ?? '') . ' · ' . sprintf('%.1f KM AWAY', $km), ' ·'),
                            'value' => round(max(0.0, (($e['rating'] ?? 4.0) - ($n['rating'] ?? 4.0)) * 2 + (($e['tier'] ?? 2) - $curTier) * 0.9 + 0.2), 2),
                            'patch' => $this->placePatch($e) + ['dish' => $e['dish'] ?? ''],
                        ];
                    }
                }

                if ($leg && count($leg['options']) > 1) {
                    foreach ($leg['options'] as $o) {
                        if ($o['mode'] === $leg['mode']) {
                            continue;
                        }
                        $altCost = ($leg['covered'] && in_array($o['mode'], ['metro', 'bus'], true)) ? 0.0 : $o['cost'];
                        $delta = $altCost - $leg['cost'];
                        $out["leg:$nid:{$o['mode']}"] = [
                            'id' => "leg:$nid:{$o['mode']}",
                            'group' => "leg:$nid",
                            'nodeId' => $nid,
                            'category' => 'transit',
                            'affects' => 'leg',
                            'kind' => $delta > 0 ? 'upgrade' : 'saving',
                            'delta' => round($delta, 2),
                            'label' => 'Switch to ' . $o['label'],
                            'detail' => sprintf('%d MIN · %.1f KM', $o['minutes'], $o['km']),
                            'value' => round(max(0.0, ($leg['minutes'] - $o['minutes']) / 20), 2),
                            'patch' => ['transit' => ['mode' => $o['mode'], 'label' => $o['label'], 'minutes' => $o['minutes'], 'km' => $o['km'], 'cost' => $altCost]],
                        ];
                    }
                }

                if ($n['type'] === 'attraction') {
                    $guide = $this->places['attractions'][$n['placeId']]['guide'] ?? null;
                    if ($guide) {
                        $out["guide:$nid"] = [
                            'id' => "guide:$nid",
                            'group' => "guide:$nid",
                            'nodeId' => $nid,
                            'category' => 'entry',
                            'affects' => 'stop',
                            'kind' => 'extra',
                            'delta' => round($guide['price'], 2),
                            'label' => 'Add ' . $guide['label'],
                            'detail' => 'Context you can\'t get from placards · per group',
                            'value' => 1.3,
                            'patch' => ['tag' => 'GUIDED'],
                        ];
                    }
                }

                if ($n['type'] === 'market' && $n['cost'] == 0 && !empty($state['browseOnly'][$n['placeId']])) {
                    $m = $this->places['markets'][$n['placeId']];
                    $out["market:$nid"] = [
                        'id' => "market:$nid",
                        'group' => "market:$nid",
                        'nodeId' => $nid,
                        'category' => 'activities',
                        'affects' => 'stop',
                        'kind' => 'extra',
                        'delta' => round($m['price'], 2),
                        'label' => 'Restore shopping allowance',
                        'detail' => $m['crafts'] ?? '',
                        'value' => 0.8,
                        'patch' => ['tag' => 'SHOPPING ALLOWANCE'],
                    ];
                }
            }

            $car = $this->ctx['transit']['carDay'] ?? null;
            $first = $d['nodes'][0] ?? null;
            if ($car && $first && $dayTransit > 0 && $car['price'] > $dayTransit) {
                $out["car:d$di"] = [
                    'id' => "car:d$di",
                    'group' => "car:d$di",
                    'nodeId' => $first['id'],
                    'category' => 'transit',
                    'affects' => 'stop',
                    'kind' => 'extra',
                    'delta' => round($car['price'] - $dayTransit, 2),
                    'label' => $car['label'] . ' — Day ' . sprintf('%02d', $di + 1),
                    'detail' => 'Replaces every leg today · door-to-door, no haggling',
                    'value' => 1.1,
                    'patch' => ['tag' => 'CAR & DRIVER'],
                ];
            }
        }

        // Experiences not in the timetable become "unlock" add-ons.
        $scheduled = [];
        foreach ($build['days'] as $d) {
            foreach ($d['nodes'] as $n) {
                $scheduled[$n['placeId'] ?? ''] = true;
            }
        }
        foreach ($this->places['activities'] as $act) {
            if (isset($scheduled[$act['id']]) || !$mealNodes) {
                continue;
            }
            $price = $act['price'] * $trav;
            $slot = $act['slot'] ?? 'evening';
            $targetSlot = match ($slot) { 'morning' => 'breakfast', 'day' => 'lunch', default => 'dinner' };
            $candidates = array_values(array_filter($mealNodes, fn($n) => $n['slot'] === $targetSlot)) ?: $mealNodes;
            usort($candidates, fn($a, $b) => Geo::between($a, $act) <=> Geo::between($b, $act));
            $target = $candidates[0];
            $replacesDinner = $slot === 'dinner' && $target['slot'] === 'dinner';
            $delta = $replacesDinner ? $price - $target['cost'] : $price;
            $out["exp:{$act['id']}"] = [
                'id' => "exp:{$act['id']}",
                'group' => $replacesDinner ? "meal:{$target['id']}" : "exp:{$act['id']}",
                'nodeId' => $target['id'],
                'category' => $replacesDinner ? 'food' : 'activities',
                'affects' => 'stop',
                'kind' => 'upgrade',
                'delta' => round($delta, 2),
                'placeId' => $act['id'],
                'label' => 'Unlock ' . $act['name'],
                'pitch' => $act['pitch'] ?? lcfirst($act['name']),
                'detail' => $act['summary'] ?? '',
                'value' => (float)($act['value'] ?? 3),
                'patch' => $replacesDinner
                    ? $this->placePatch($act) + ['tag' => 'EXPERIENCE']
                    : ['tag' => '+ ' . strtoupper($act['short'] ?? $act['name'])],
            ];
        }

        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $addons
     * @return list<array<string, mixed>>
     */
    public function tiers(array $addons, float $budget, float $buffer, float $homeToUsd, string $currency): array
    {
        $upgrades = array_values(array_filter($addons, fn($a) =>
            $a['delta'] > 0 && $a['value'] > 0.25 && !str_starts_with($a['group'], 'leg:')));
        $stays = array_values(array_filter($upgrades, fn($a) => $a['group'] === 'hotel'));

        // Keep the extras pool small enough for an exact subset search, but make
        // sure the headline-worthy experiences are always in it.
        $extras = array_values(array_filter($upgrades, fn($a) => $a['group'] !== 'hotel'));
        usort($extras, fn($a, $b) => ($b['value'] / $b['delta']) <=> ($a['value'] / $a['delta']));
        $experiences = array_values(array_filter($extras, fn($a) => str_starts_with($a['id'], 'exp:')));
        usort($experiences, fn($a, $b) => $b['value'] <=> $a['value']);
        $merged = [];
        foreach (array_merge(array_slice($experiences, 0, 6), array_slice($extras, 0, 12)) as $a) {
            $merged[$a['id']] = $a;
        }
        $pool = array_slice(array_values($merged), 0, 14);

        // A tier may also draw on unallocated money (never the last 5%), but at
        // most matching the boost itself, so each tier stays a distinct step.
        $usableBuffer = max(0.0, $buffer - 0.05 * $budget);
        $tiers = [];
        $prevStay = 0.0;
        foreach ([10, 15, 25] as $pct) {
            $increase = self::nice($budget * $pct / 100);
            $cap = $increase + min($usableBuffer, $increase);

            // Anchor each tier on the best stay upgrade that still leaves a
            // quarter of the tier for experiences; higher tiers never step down.
            $anchor = null;
            foreach ($stays as $s) {
                if ($s['delta'] <= $cap * 0.75 && $s['delta'] >= $prevStay && (!$anchor || $s['value'] > $anchor['value'])) {
                    $anchor = $s;
                }
            }
            $chosen = array_map(fn($i) => $pool[$i], self::bestSubset($pool, $cap - ($anchor['delta'] ?? 0.0)));
            usort($chosen, fn($a, $b) => $b['value'] <=> $a['value']);
            if ($anchor) {
                array_unshift($chosen, $anchor);
                $prevStay = $anchor['delta'];
            }
            $spend = array_sum(array_column($chosen, 'delta'));
            $ids = array_column($chosen, 'id');
            $prev = $tiers ? end($tiers) : null;
            $same = $prev && !array_diff($ids, $prev['addonIds']) && !array_diff($prev['addonIds'], $ids);
            $tiers[] = [
                'pct' => $pct,
                'increase' => $increase,
                'increaseUsd' => $currency === 'USD' ? null : self::nice($increase * $homeToUsd),
                'spend' => round($spend, 2),
                'bufferUsed' => round(max(0.0, $spend - $increase), 2),
                'held' => round($cap - $spend, 2),
                'addonIds' => $ids,
                'headline' => $same
                    ? sprintf('Increase budget by %s for everything in the +%d%% tier, with %s more headroom for unplanned finds.',
                        ($this->money)($increase), $prev['pct'], ($this->money)($increase - $prev['increase']))
                    : $this->headline($chosen, $increase, $increase * $homeToUsd, $currency),
            ];
        }
        return $tiers;
    }

    /**
     * Exact multiple-choice knapsack over ≤ 15 items: at most one add-on per
     * group, total delta within $cap, maximise value (ties → bigger spend).
     *
     * @return list<int>
     */
    private static function bestSubset(array $pool, float $cap): array
    {
        $n = count($pool);
        $best = [];
        $bestValue = 0.0;
        $bestSpend = 0.0;
        for ($mask = 1, $limit = 1 << $n; $mask < $limit; $mask++) {
            $spend = 0.0;
            $value = 0.0;
            $groups = [];
            $ok = true;
            for ($i = 0; $i < $n; $i++) {
                if (!($mask & (1 << $i))) {
                    continue;
                }
                $a = $pool[$i];
                if (isset($groups[$a['group']])) {
                    $ok = false;
                    break;
                }
                $groups[$a['group']] = true;
                $spend += $a['delta'];
                if ($spend > $cap) {
                    $ok = false;
                    break;
                }
                $value += $a['value'];
            }
            if ($ok && ($value > $bestValue + 1e-9 || (abs($value - $bestValue) < 1e-9 && $spend > $bestSpend))) {
                $bestValue = $value;
                $bestSpend = $spend;
                $best = [];
                for ($i = 0; $i < $n; $i++) {
                    if ($mask & (1 << $i)) {
                        $best[] = $i;
                    }
                }
            }
        }
        return $best;
    }

    private function headline(array $chosen, float $increase, float $usd, string $currency): string
    {
        $money = $this->money;
        $amount = $money($increase) . ($currency !== 'USD' ? ' / $' . number_format(self::nice($usd)) : '');
        if (!$chosen) {
            return "Increase budget by {$amount} to widen your safety buffer — no single upgrade fits this tier.";
        }
        // Lead with the stay and the experiences; summarise the small stuff.
        $lead = [];
        $minor = 0;
        foreach ($chosen as $a) {
            if ($a['group'] === 'hotel') {
                $to = (string)preg_replace('/^transit-hub /', '', $a['to']);
                array_unshift($lead, $to === (string)preg_replace('/^transit-hub /', '', $a['from'])
                    ? 'move to ' . $a['patch']['title']
                    : 'upgrade from ' . self::article($a['from']) . ' to ' . self::article($to));
            } elseif (str_starts_with($a['id'], 'exp:')) {
                $lead[] = 'unlock ' . $a['pitch'];
            } else {
                $minor++;
            }
        }
        if (!$lead) {
            $first = $chosen[0];
            $lead[] = match (true) {
                str_starts_with($first['id'], 'meal:') => 'trade up to ' . $first['patch']['title'],
                str_starts_with($first['id'], 'car:') => 'add a private car & driver for a day',
                str_starts_with($first['id'], 'guide:') => 'add a licensed guide',
                default => lcfirst($first['label']),
            };
            $minor--;
        }
        $minor += max(0, count($lead) - 2);
        $lead = array_slice($lead, 0, 2);
        $text = implode(' and ', $lead);
        if ($minor > 0) {
            $text .= ', plus ' . $minor . ' smaller upgrade' . ($minor > 1 ? 's' : '');
        }
        return "Increase budget by {$amount} to {$text}.";
    }

    private static function article(string $phrase): string
    {
        return (preg_match('/^[aeiou]/i', $phrase) ? 'an ' : 'a ') . $phrase;
    }

    public static function nice(float $x): float
    {
        $step = match (true) {
            $x >= 100000 => 5000,
            $x >= 10000 => 500,
            $x >= 1000 => 50,
            $x >= 100 => 5,
            default => 1,
        };
        return max($step, round($x / $step) * $step);
    }

    /** @return array<string, mixed> */
    private function placePatch(array $p): array
    {
        return [
            'title' => $p['name'],
            'area' => $p['area'] ?? '',
            'summary' => $p['summary'] ?? '',
            'rating' => $p['rating'] ?? null,
            'ratingCount' => $p['ratingCount'] ?? null,
            'image' => $p['image'] ?? null,
            'placeId' => $p['id'],
            'lat' => $p['lat'],
            'lng' => $p['lng'],
        ];
    }
}

<?php
declare(strict_types=1);

/**
 * Multi-modal leg costing. For each hop it prices every available mode
 * (walk, metro, bus, shared auto/tuk, taxi) in home currency for the whole
 * party, then picks the lowest generalised cost: fare + time × value-of-time.
 * Road durations come from the OSRM/ORS matrix when known, Haversine otherwise.
 */
final class TransitPlanner
{
    /** @var array<string, array{0:float,1:float}> "a>b" => [seconds, metres] */
    private array $routes = [];

    /**
     * @param array<string, mixed> $profile city transit profile, fares already in home currency
     * @param list<string> $modes modes the traveller allows
     */
    public function __construct(
        private array $profile,
        private array $modes,
        private int $travellers,
        private float $minuteValue,
        private array $center,
    ) {
    }

    public function remember(array $a, array $b, float $seconds, float $metres): void
    {
        $this->routes[self::key($a, $b)] = [$seconds, $metres];
    }

    public function routedLegs(): int
    {
        return count($this->routes);
    }

    private static function key(array $a, array $b): string
    {
        return sprintf('%.4f,%.4f>%.4f,%.4f', $a['lat'], $a['lng'], $b['lat'], $b['lng']);
    }

    /**
     * @return list<array{mode:string,label:string,minutes:int,km:float,cost:float,score:float,routed:bool}>
     */
    public function options(array $a, array $b, ?int $departMin = null): array
    {
        $p = $this->profile;
        $crow = Geo::between($a, $b);
        if ($crow < 0.12) {
            return [['mode' => 'walk', 'label' => 'Next door', 'minutes' => 2, 'km' => round($crow, 2), 'cost' => 0.0, 'score' => 0.0, 'routed' => false]];
        }

        $route = $this->routes[self::key($a, $b)] ?? null;
        $roadKm = $route ? max($route[1] / 1000, $crow) : $crow * 1.32;
        $taxiSpeed = (float)($p['taxi']['speed'] ?? 20);
        $driveMin = $route ? $route[0] / 60 * 1.15 : $roadKm / $taxiSpeed * 60; // demo-server durations skew optimistic in dense cores
        $opts = [];
        // After 22:00 city buses thin out and shared autos vanish: cabs only.
        $night = $departMin !== null && $departMin >= 22 * 60;

        $walkKm = $crow * 1.25;
        if ($walkKm <= (float)($p['walkMaxKm'] ?? 2.0)) {
            $opts[] = ['mode' => 'walk', 'label' => 'Walk', 'minutes' => $walkKm / 4.6 * 60, 'km' => $walkKm, 'cost' => 0.0];
        }

        $metro = $p['metro'] ?? null;
        if ($metro && !$night && $crow >= 1.5) {
            $ride = $this->metroRide($metro, $a, $b);
            if ($ride) {
                $opts[] = ['mode' => 'metro', 'label' => $metro['label'], 'minutes' => $ride, 'km' => $roadKm, 'cost' => $metro['fare'] * $this->travellers];
            }
        }

        $bus = $p['bus'] ?? null;
        if ($bus && !$night && $crow >= 1.0) {
            $opts[] = [
                'mode' => 'bus', 'label' => $bus['label'],
                'minutes' => $bus['wait'] + 5 + $driveMin * ($bus['factor'] ?? 1.6),
                'km' => $roadKm, 'cost' => $bus['fare'] * $this->travellers,
            ];
        }

        $shared = $p['shared'] ?? null;
        if ($shared && !$night && $crow >= 0.7 && $roadKm <= $shared['maxKm']) {
            $opts[] = [
                'mode' => 'shared', 'label' => $shared['label'],
                'minutes' => 5 + $roadKm / $shared['speed'] * 60,
                'km' => $roadKm, 'cost' => $shared['fare'] * $this->travellers,
            ];
        }

        $taxi = $p['taxi'];
        $opts[] = [
            'mode' => 'taxi', 'label' => $taxi['label'],
            'minutes' => 6 + $driveMin,
            'km' => $roadKm, 'cost' => ($taxi['base'] + $taxi['perKm'] * $roadKm) * (int)ceil($this->travellers / 4),
        ];

        // A bus that takes nearly twice as long as a cab over a long hop is not a
        // real option (no direct line); drop it rather than let a tight budget pick it.
        $taxiMin = end($opts)['minutes'];
        $opts = array_values(array_filter($opts, fn($o) => $o['mode'] !== 'bus' || $o['minutes'] <= 75 || $o['minutes'] <= 1.8 * $taxiMin));

        foreach ($opts as &$o) {
            $o['minutes'] = max(2, (int)round($o['minutes']));
            $o['km'] = round($o['km'], 2);
            $o['cost'] = round($o['cost'], 2);
            // Walking through an old quarter is part of the trip, so its minutes weigh less.
            $o['score'] = $o['cost'] + $this->minuteValue * $o['minutes'] * ($o['mode'] === 'walk' ? 0.55 : 1.0);
            $o['routed'] = $route !== null && $o['mode'] !== 'walk';
        }
        unset($o);
        return $opts;
    }

    /**
     * Door-to-door rail minutes, or null when either end is beyond a short walk
     * of a station (or, without a station list, outside the network radius).
     */
    private function metroRide(array $metro, array $a, array $b): ?float
    {
        $stations = $metro['stations'] ?? [];
        if (!$stations) {
            $inside = Geo::between($a, $this->center) <= $metro['radiusKm'] && Geo::between($b, $this->center) <= $metro['radiusKm'];
            return $inside ? $metro['access'] + Geo::between($a, $b) * 1.25 / $metro['speed'] * 60 : null;
        }
        $nearest = static function (array $p) use ($stations): array {
            $best = [$stations[0], INF];
            foreach ($stations as $s) {
                $d = Geo::km($p['lat'], $p['lng'], $s[1], $s[2]);
                if ($d < $best[1]) {
                    $best = [$s, $d];
                }
            }
            return $best;
        };
        [$sa, $da] = $nearest($a);
        [$sb, $db] = $nearest($b);
        if ($da > 0.9 || $db > 0.9 || $sa[0] === $sb[0]) {
            return null;
        }
        $rideKm = Geo::km($sa[1], $sa[2], $sb[1], $sb[2]) * 1.2;
        return ($da + $db) * 1.25 / 4.6 * 60 + $metro['access'] / 2 + $rideKm / $metro['speed'] * 60;
    }

    /** @param list<array<string, mixed>> $opts */
    public function choose(array $opts, ?string $forced = null): array
    {
        if ($forced !== null) {
            foreach ($opts as $o) {
                if ($o['mode'] === $forced) {
                    return $o;
                }
            }
        }
        $allowed = array_values(array_filter($opts, fn($o) => in_array($o['mode'], $this->modes, true) || $o['label'] === 'Next door'));
        $pool = $allowed ?: array_values(array_filter($opts, fn($o) => $o['mode'] === 'taxi'));
        usort($pool, fn($x, $y) => $x['score'] <=> $y['score']);
        return $pool[0] ?? $opts[0];
    }

    /** @param list<array<string, mixed>> $opts */
    public function allowed(array $opts): array
    {
        return array_values(array_filter($opts, fn($o) => in_array($o['mode'], $this->modes, true) || $o['mode'] === 'taxi'));
    }
}

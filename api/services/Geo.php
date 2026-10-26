<?php
declare(strict_types=1);

/**
 * Spherical geometry and the clustering / routing heuristics the planner uses.
 * Points are arrays with 'lat' and 'lng' keys.
 */
final class Geo
{
    public static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * 6371.0 * asin(min(1.0, sqrt($a)));
    }

    /** @param array{lat:float,lng:float} $a @param array{lat:float,lng:float} $b */
    public static function between(array $a, array $b): float
    {
        return self::km((float)$a['lat'], (float)$a['lng'], (float)$b['lat'], (float)$b['lng']);
    }

    /**
     * Weighted geometric centroid.
     *
     * @param array<array-key, array{lat:float,lng:float}> $points
     * @param array<array-key, float> $weights
     * @return array{lat:float,lng:float}
     */
    public static function centroid(array $points, array $weights = []): array
    {
        $lat = $lng = $sum = 0.0;
        foreach ($points as $k => $p) {
            $w = $weights[$k] ?? 1.0;
            $lat += $p['lat'] * $w;
            $lng += $p['lng'] * $w;
            $sum += $w;
        }
        return $sum > 0 ? ['lat' => $lat / $sum, 'lng' => $lng / $sum] : ['lat' => 0.0, 'lng' => 0.0];
    }

    /**
     * Capacity-balanced k-means. Seeds deterministically with the first point
     * (callers pass points best-ranked first) plus farthest-point picks, then
     * alternates a globally greedy capacity-limited assignment with centroid
     * updates. Returns clusters as lists of the input keys.
     *
     * @param array<array-key, array{lat:float,lng:float}> $points
     * @return list<list<array-key>>
     */
    public static function balancedClusters(array $points, int $k): array
    {
        $keys = array_keys($points);
        $n = count($keys);
        if ($n === 0) {
            return [];
        }
        $k = max(1, min($k, $n));
        if ($k === 1) {
            return [$keys];
        }
        $cap = (int)ceil($n / $k);

        $centers = [$points[$keys[0]]];
        while (count($centers) < $k) {
            $best = $keys[0];
            $bestD = -1.0;
            foreach ($keys as $key) {
                $d = INF;
                foreach ($centers as $c) {
                    $d = min($d, self::between($c, $points[$key]));
                }
                if ($d > $bestD) {
                    $bestD = $d;
                    $best = $key;
                }
            }
            $centers[] = $points[$best];
        }

        $assign = [];
        for ($iter = 0; $iter < 20; $iter++) {
            $pairs = [];
            foreach ($keys as $key) {
                foreach ($centers as $ci => $c) {
                    $pairs[] = [self::between($c, $points[$key]), $key, $ci];
                }
            }
            usort($pairs, fn($a, $b) => $a[0] <=> $b[0]);

            $next = [];
            $load = array_fill(0, $k, 0);
            foreach ($pairs as [, $key, $ci]) {
                if (isset($next[$key]) || $load[$ci] >= $cap) {
                    continue;
                }
                $next[$key] = $ci;
                $load[$ci]++;
            }
            if ($next == $assign) {
                break;
            }
            $assign = $next;
            foreach (array_keys($centers) as $ci) {
                $members = [];
                foreach ($keys as $key) {
                    if ($assign[$key] === $ci) {
                        $members[] = $points[$key];
                    }
                }
                if ($members) {
                    $centers[$ci] = self::centroid($members);
                }
            }
        }

        $clusters = array_fill(0, $k, []);
        foreach ($keys as $key) {
            $clusters[$assign[$key]][] = $key;
        }
        return array_values(array_filter($clusters));
    }

    /**
     * Tour from $start through every point to $end: nearest-neighbour
     * construction followed by 2-opt improvement. $dist defaults to Haversine
     * but can be swapped for a road-duration matrix lookup.
     *
     * @param array{lat:float,lng:float} $start
     * @param array<array-key, array{lat:float,lng:float}> $points
     * @param array{lat:float,lng:float}|null $end where the path finishes (defaults to $start)
     * @return list<array-key>
     */
    public static function tour(array $start, array $points, ?array $end = null, ?callable $dist = null): array
    {
        $dist ??= static fn(array $a, array $b): float => self::between($a, $b);
        $end ??= $start;
        $left = $points;
        $order = [];
        $cur = $start;
        while ($left) {
            $bestKey = array_key_first($left);
            $bestD = INF;
            foreach ($left as $key => $p) {
                $d = $dist($cur, $p);
                if ($d < $bestD) {
                    $bestD = $d;
                    $bestKey = $key;
                }
            }
            $order[] = $bestKey;
            $cur = $left[$bestKey];
            unset($left[$bestKey]);
        }

        $n = count($order);
        for ($pass = 0, $improved = true; $improved && $pass < 40; $pass++) {
            $improved = false;
            for ($i = 0; $i < $n - 1; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $i === 0 ? $start : $points[$order[$i - 1]];
                    $b = $points[$order[$i]];
                    $c = $points[$order[$j]];
                    $d = $j + 1 < $n ? $points[$order[$j + 1]] : $end;
                    if ($dist($a, $c) + $dist($b, $d) + 1e-9 < $dist($a, $b) + $dist($c, $d)) {
                        $order = array_merge(
                            array_slice($order, 0, $i),
                            array_reverse(array_slice($order, $i, $j - $i + 1)),
                            array_slice($order, $j + 1),
                        );
                        $improved = true;
                    }
                }
            }
        }
        return $order;
    }

    public static function toMin(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm) + [1 => 0]);
        return $h * 60 + $m;
    }

    public static function fmt(int $minutes): string
    {
        $minutes = max(0, min($minutes, 24 * 60 - 1));
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}

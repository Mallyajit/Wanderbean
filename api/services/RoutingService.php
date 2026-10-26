<?php
declare(strict_types=1);

/**
 * Road duration/distance matrices: OpenRouteService (key) or the public OSRM
 * demo server. One matrix call per itinerary day.
 */
final class RoutingService
{
    public function __construct(private HttpClient $http, private string $orsKey = '')
    {
    }

    /**
     * @param array<string, list<array{lat:float,lng:float}>> $sets keyed batches of points
     * @return array<string, array{durations: list<list<?float>>, distances: list<list<?float>>, source: string}|null>
     */
    public function matrices(array $sets): array
    {
        $requests = [];
        foreach ($sets as $key => $points) {
            $points = array_slice($points, 0, 25);
            if (count($points) < 2) {
                continue;
            }
            if ($this->orsKey !== '') {
                $requests[$key] = [
                    'service' => 'OpenRouteService matrix',
                    'url' => 'https://api.openrouteservice.org/v2/matrix/driving-car',
                    'method' => 'POST',
                    'headers' => ['Authorization: ' . $this->orsKey],
                    'body' => [
                        'locations' => array_map(fn($p) => [round($p['lng'], 5), round($p['lat'], 5)], $points),
                        'metrics' => ['duration', 'distance'],
                    ],
                    'ttl' => 86400 * 7,
                ];
            } else {
                $coords = implode(';', array_map(fn($p) => sprintf('%.5f,%.5f', $p['lng'], $p['lat']), $points));
                $requests[$key] = [
                    'service' => 'OSRM table',
                    'url' => "https://router.project-osrm.org/table/v1/driving/{$coords}?annotations=duration,distance",
                    'ttl' => 86400 * 7,
                    'timeout' => 8,
                ];
            }
        }

        $out = [];
        foreach ($this->http->multi($requests) as $key => $r) {
            if (!$r || !isset($r['durations'])) {
                $out[$key] = null;
                continue;
            }
            $out[$key] = [
                'durations' => $r['durations'],
                'distances' => $r['distances'] ?? [],
                'source' => $this->orsKey !== '' ? 'OPENROUTESERVICE' : 'OSRM',
            ];
        }
        return $out;
    }
}

<?php
declare(strict_types=1);

/**
 * GET api/route.php?c=lng,lat;lng,lat;...
 * Road geometry for a day's stops from the public OSRM server, as GeoJSON
 * [lng, lat] pairs, so the map can draw the real route rather than straight
 * lines. Falls back to the straight polyline when OSRM is unreachable.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

require __DIR__ . '/services/HttpClient.php';
$config = require __DIR__ . '/config.php';

$raw = (string)($_GET['c'] ?? '');
$points = [];
foreach (array_slice(explode(';', $raw), 0, 25) as $pair) {
    if (!preg_match('/^(-?\d{1,3}(?:\.\d+)?),(-?\d{1,2}(?:\.\d+)?)$/', trim($pair), $m)) {
        continue;
    }
    $points[] = [round((float)$m[1], 5), round((float)$m[2], 5)];
}
if (count($points) < 2) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Pass at least two lng,lat points.']);
    exit;
}

$coords = implode(';', array_map(fn($p) => $p[0] . ',' . $p[1], $points));
$http = new HttpClient($config['cache_dir'], $config['user_agent'], 8);
$r = $http->json('OSRM route', "https://router.project-osrm.org/route/v1/driving/{$coords}?overview=full&geometries=geojson", ['ttl' => 86400 * 14]);

$route = $r['routes'][0] ?? null;
echo json_encode($route ? [
    'ok' => true,
    'source' => 'OSRM',
    'coordinates' => $route['geometry']['coordinates'],
    'distance' => $route['distance'],
    'duration' => $route['duration'],
] : [
    'ok' => true,
    'source' => 'STRAIGHT',
    'coordinates' => $points,
]);

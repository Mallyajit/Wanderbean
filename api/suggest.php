<?php
declare(strict_types=1);

/**
 * GET api/suggest.php?q=hano
 * Destination autocomplete from Photon (OpenStreetMap, keyless). Results are
 * cached; curated offline guides are always offered first when they match.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

require __DIR__ . '/services/HttpClient.php';
require __DIR__ . '/services/FallbackRepository.php';
$config = require __DIR__ . '/config.php';

$q = trim((string)($_GET['q'] ?? ''));
if (strlen($q) < 2 || strlen($q) > 80) {
    echo json_encode(['ok' => true, 'results' => []]);
    exit;
}

$results = [];
foreach ((new FallbackRepository(__DIR__ . '/data/fallback'))->destinations() as $d) {
    if (stripos($d, $q) === 0) {
        $results[] = ['label' => $d, 'name' => explode(',', $d)[0], 'country' => trim(explode(',', $d)[1] ?? ''), 'offline' => true];
    }
}

$http = new HttpClient($config['cache_dir'], $config['user_agent'], 5);
$r = $http->json('Photon suggest', 'https://photon.komoot.io/api/?limit=8&lang=en&q=' . rawurlencode($q), ['ttl' => 86400 * 7]);
$seen = array_flip(array_column($results, 'label'));
foreach ($r['features'] ?? [] as $f) {
    $p = $f['properties'] ?? [];
    $type = (string)($p['type'] ?? '');
    $isPlace = ($p['osm_key'] ?? '') === 'place' || in_array($type, ['city', 'town', 'village', 'state', 'county', 'district', 'locality', 'island'], true);
    if (!$isPlace || empty($p['name'])) {
        continue;
    }
    $label = $p['name'] . (!empty($p['country']) ? ', ' . $p['country'] : '');
    if (isset($seen[$label])) {
        continue;
    }
    $seen[$label] = true;
    $results[] = [
        'label' => $label,
        'name' => $p['name'],
        'country' => $p['country'] ?? '',
        'region' => $p['state'] ?? '',
        'lat' => $f['geometry']['coordinates'][1] ?? null,
        'lng' => $f['geometry']['coordinates'][0] ?? null,
        'offline' => false,
    ];
    if (count($results) >= 6) {
        break;
    }
}

echo json_encode(['ok' => true, 'results' => $results], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

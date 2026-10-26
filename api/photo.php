<?php
declare(strict_types=1);

/**
 * Google Place Photo proxy: resolves a photo resource name to its short-lived
 * googleusercontent URL server-side and redirects, so the API key never ships
 * to the browser.  GET api/photo.php?ref=places/{id}/photos/{photo}
 */

require __DIR__ . '/services/HttpClient.php';
$config = require __DIR__ . '/config.php';

$ref = (string)($_GET['ref'] ?? '');
if ($config['google_places_key'] === '' || !preg_match('#^places/[A-Za-z0-9_-]+/photos/[A-Za-z0-9_-]+$#', $ref)) {
    http_response_code(404);
    exit;
}

$http = new HttpClient($config['cache_dir'], $config['user_agent'], 6);
$r = $http->json('Google Place Photo', 'https://places.googleapis.com/v1/' . $ref . '/media?maxWidthPx=1200&skipHttpRedirect=true', [
    'headers' => ['X-Goog-Api-Key: ' . $config['google_places_key']],
    'ttl' => 6 * 3600,
]);

if (empty($r['photoUri'])) {
    http_response_code(404);
    exit;
}
header('Cache-Control: public, max-age=3600');
header('Location: ' . $r['photoUri'], true, 302);

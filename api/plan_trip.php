<?php
declare(strict_types=1);

/**
 * POST api/plan_trip.php
 *
 * Body (JSON): {
 *   destination, startDate (Y-m-d), endDate (Y-m-d), budget, currency,
 *   travellers?, pace? (relaxed|balanced|packed), diet? [vegetarian|vegan|halal],
 *   modes? [walk|metro|bus|shared|taxi], lodgingPct?, foodPct?, offline?
 * }
 *
 * GET api/plan_trip.php?meta=1 returns the curated destinations and which
 * integrations are configured, for the UI's status line.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

set_time_limit(90);
ini_set('display_errors', '0');

spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . '/services/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});
require_once __DIR__ . '/services/TripPlanner.php'; // also defines PlannerException

$config = require __DIR__ . '/config.php';

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && isset($_GET['meta'])) {
    $repo = new FallbackRepository(__DIR__ . '/data/fallback');
    respond([
        'ok' => true,
        'destinations' => $repo->destinations(),
        'integrations' => [
            'googlePlaces' => $config['google_places_key'] !== '',
            'geoapify' => $config['geoapify_key'] !== '',
            'opencage' => $config['opencage_key'] !== '',
            'openrouteservice' => $config['openrouteservice_key'] !== '',
            'network' => HttpClient::transportAvailable(),
        ],
        'currencies' => TripPlanner::CURRENCIES,
    ]);
}

if ($method !== 'POST') {
    respond(['ok' => false, 'error' => 'Use POST with a JSON body.'], 405);
}

$raw = file_get_contents('php://input') ?: '';
$input = json_decode($raw, true);
if (!is_array($input)) {
    respond(['ok' => false, 'error' => 'Request body must be JSON.'], 400);
}

try {
    $planner = new TripPlanner($config);
    respond($planner->plan($input));
} catch (PlannerException $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    error_log('[Wanderbean] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    respond(['ok' => false, 'error' => 'The planner hit an unexpected error. Check the PHP error log for details.'], 500);
}

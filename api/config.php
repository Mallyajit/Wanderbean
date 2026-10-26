<?php
declare(strict_types=1);

/*
 * Wanderbean runtime configuration.
 *
 * Keys are read from environment variables first. For local development you can
 * instead create api/config.local.php returning an array with the same keys —
 * it is merged over these defaults and should never be committed.
 *
 * Every key is optional. With none configured, Wanderbean plans Jaipur, Kyoto
 * and Lisbon from its bundled offline guides, and any other destination from
 * keyless public APIs (Wikivoyage, Wikipedia, Nominatim, Photon, Open-Meteo,
 * Frankfurter, OSRM, Overpass) when the machine has network access.
 */

$config = [
    'google_places_key'   => getenv('GOOGLE_PLACES_API_KEY') ?: '',
    'geoapify_key'        => getenv('GEOAPIFY_API_KEY') ?: '',
    'opencage_key'        => getenv('OPENCAGE_API_KEY') ?: '',
    'openrouteservice_key'=> getenv('ORS_API_KEY') ?: '',

    // Nominatim and Wikipedia require an identifying User-Agent.
    'user_agent'          => 'Wanderbean-Trip-Planner/1.0 (local development)',
    'http_timeout'        => 7,
    'cache_dir'           => __DIR__ . '/cache',

    // Budget split defaults (fractions of the total budget).
    'lodging_pct'         => 0.38,
    'food_pct'            => 0.22,
    'max_days'            => 14,
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_replace($config, (array)require $local);
}

return $config;

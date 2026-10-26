<?php
declare(strict_types=1);

$config = require __DIR__ . '/api/config.php';
require __DIR__ . '/api/services/HttpClient.php';
require __DIR__ . '/api/services/FallbackRepository.php';

$start = (new DateTimeImmutable('today'))->modify('+3 days');

$boot = [
    'defaults' => [
        'destination' => '',
        'startDate' => $start->format('Y-m-d'),
        'endDate' => $start->modify('+2 days')->format('Y-m-d'),
        'budget' => 30000,
        'currency' => 'INR',
        'travellers' => 2,
        'pace' => 'balanced',
        'interests' => [],
        'diet' => [],
        'foodStyle' => 'mix',
        'stayStyle' => 'budget',
        'modes' => ['walk', 'metro', 'bus', 'shared', 'taxi'],
        'offline' => false,
    ],
    'offlineGuides' => (new FallbackRepository(__DIR__ . '/api/data/fallback'))->destinations(),
    'integrations' => [
        'googlePlaces' => $config['google_places_key'] !== '',
        'network' => HttpClient::transportAvailable(),
    ],
    'maxDays' => $config['max_days'],
];
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Wanderbean — trips brewed to your budget</title>
    <meta name="description" content="Pick a destination, dates and a budget. Wanderbean brews a day-by-day plan that stays inside it — with a calendar, a map and every stop explained.">
    <meta name="theme-color" content="#e5e7eb">
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Cpath d='M14 24h30v14a15 15 0 0 1-30 0z' fill='%23ff5e5b' stroke='%23000' stroke-width='4' stroke-linejoin='round'/%3E%3Cpath d='M44 28h4a7 7 0 0 1 0 14h-5' fill='none' stroke='%23000' stroke-width='4' stroke-linecap='round'/%3E%3C/svg%3E">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bowlby+One&family=DM+Sans:opsz,wght@9..40,400;9..40,600&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <a class="visually-hidden" href="#view">Skip to content</a>
    <header class="app-header" id="app-header"></header>
    <main id="view" tabindex="-1"></main>
    <div id="sheet-root"></div>
    <div class="toasts" id="toasts" role="status" aria-live="polite"></div>

    <script>window.WB_BOOT = <?= json_encode($boot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js" defer></script>
    <script type="module" src="assets/js/main.js"></script>
</body>
</html>

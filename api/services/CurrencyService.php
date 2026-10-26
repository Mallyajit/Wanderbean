<?php
declare(strict_types=1);

/**
 * FX normalisation: Frankfurter (ECB) → ExchangeRate-API open endpoint →
 * static table. Rates are returned as "units of target per 1 unit of base".
 */
final class CurrencyService
{
    public function __construct(private HttpClient $http)
    {
    }

    /**
     * @param list<string> $targets
     * @return array{rates: array<string, float>, source: string, date: ?string}
     */
    public function rates(string $base, array $targets): array
    {
        $targets = array_values(array_unique(array_filter($targets, fn($t) => $t !== $base)));
        $rates = [$base => 1.0];
        if (!$targets) {
            return ['rates' => $rates, 'source' => 'IDENTITY', 'date' => null];
        }

        $r = $this->http->json('Frankfurter FX', 'https://api.frankfurter.dev/v1/latest?base=' . $base . '&symbols=' . implode(',', $targets), ['ttl' => 6 * 3600]);
        if (isset($r['rates']) && count(array_intersect_key($r['rates'], array_flip($targets))) === count($targets)) {
            foreach ($targets as $t) {
                $rates[$t] = (float)$r['rates'][$t];
            }
            return ['rates' => $rates, 'source' => 'FRANKFURTER / ECB', 'date' => $r['date'] ?? null];
        }

        $r = $this->http->json('ExchangeRate-API FX', 'https://open.er-api.com/v6/latest/' . $base, ['ttl' => 6 * 3600]);
        if (($r['result'] ?? '') === 'success') {
            $ok = true;
            foreach ($targets as $t) {
                if (!isset($r['rates'][$t])) {
                    $ok = false;
                    break;
                }
                $rates[$t] = (float)$r['rates'][$t];
            }
            if ($ok) {
                return ['rates' => $rates, 'source' => 'EXCHANGERATE-API', 'date' => isset($r['time_last_update_unix']) ? date('Y-m-d', (int)$r['time_last_update_unix']) : null];
            }
        }

        foreach ($targets as $t) {
            $rates[$t] = CostModel::staticRate($base, $t);
        }
        return ['rates' => $rates, 'source' => 'STATIC TABLE', 'date' => null];
    }
}

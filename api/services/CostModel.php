<?php
declare(strict_types=1);

/**
 * Price estimation for places that come without explicit prices (Google
 * priceLevel only, or OpenStreetMap). A USD reference basket is scaled by a
 * per-country cost index, then converted with live (or static) FX.
 */
final class CostModel
{
    /** ISO country => [local currency, cost index relative to the USD basket]. */
    public const COUNTRIES = [
        'IN' => ['INR', 0.28], 'JP' => ['JPY', 0.90], 'PT' => ['EUR', 0.70], 'ES' => ['EUR', 0.75],
        'FR' => ['EUR', 1.05], 'IT' => ['EUR', 0.95], 'DE' => ['EUR', 0.95], 'NL' => ['EUR', 1.05],
        'AT' => ['EUR', 0.95], 'BE' => ['EUR', 0.95], 'IE' => ['EUR', 1.10], 'GR' => ['EUR', 0.70],
        'GB' => ['GBP', 1.10], 'US' => ['USD', 1.00], 'CA' => ['CAD', 0.95], 'AU' => ['AUD', 0.95],
        'NZ' => ['NZD', 0.90], 'TH' => ['THB', 0.40], 'VN' => ['VND', 0.32], 'ID' => ['IDR', 0.35],
        'MY' => ['MYR', 0.40], 'SG' => ['SGD', 1.00], 'AE' => ['AED', 0.90], 'TR' => ['TRY', 0.45],
        'MX' => ['MXN', 0.50], 'BR' => ['BRL', 0.50], 'LK' => ['LKR', 0.30], 'NP' => ['NPR', 0.25],
        'CN' => ['CNY', 0.55], 'KR' => ['KRW', 0.80], 'HK' => ['HKD', 0.95], 'CH' => ['CHF', 1.45],
        'EG' => ['EGP', 0.30], 'MA' => ['MAD', 0.40], 'ZA' => ['ZAR', 0.50], 'CZ' => ['CZK', 0.65],
        'HU' => ['HUF', 0.55], 'PL' => ['PLN', 0.55], 'DK' => ['DKK', 1.20], 'SE' => ['SEK', 1.00],
        'NO' => ['NOK', 1.20], 'PH' => ['PHP', 0.38], 'KH' => ['USD', 0.35], 'BT' => ['BTN', 0.35],
        'MV' => ['MVR', 0.90], 'QA' => ['QAR', 0.95], 'SA' => ['SAR', 0.80], 'IL' => ['ILS', 1.10],
        'AR' => ['ARS', 0.45], 'CL' => ['CLP', 0.60], 'CO' => ['COP', 0.40], 'PE' => ['PEN', 0.45], 'BO' => ['BOB', 0.35],
        'EC' => ['USD', 0.50], 'CR' => ['CRC', 0.70], 'CU' => ['CUP', 0.50], 'GT' => ['GTQ', 0.45], 'DO' => ['DOP', 0.50],
        'JM' => ['JMD', 0.70], 'PA' => ['USD', 0.65], 'UY' => ['UYU', 0.65], 'KE' => ['KES', 0.45], 'TZ' => ['TZS', 0.45],
        'UG' => ['UGX', 0.40], 'ET' => ['ETB', 0.35], 'GH' => ['GHS', 0.45], 'NG' => ['NGN', 0.40], 'SN' => ['XOF', 0.50],
        'TN' => ['TND', 0.35], 'JO' => ['JOD', 0.75], 'OM' => ['OMR', 0.85], 'BH' => ['BHD', 0.90], 'KW' => ['KWD', 0.90],
        'PK' => ['PKR', 0.25], 'BD' => ['BDT', 0.28], 'MM' => ['MMK', 0.30], 'LA' => ['LAK', 0.30], 'MN' => ['MNT', 0.40],
        'KZ' => ['KZT', 0.40], 'UZ' => ['UZS', 0.30], 'GE' => ['GEL', 0.45], 'AM' => ['AMD', 0.45], 'AZ' => ['AZN', 0.45],
        'RU' => ['RUB', 0.50], 'UA' => ['UAH', 0.40], 'RO' => ['RON', 0.55], 'BG' => ['BGN', 0.50], 'HR' => ['EUR', 0.75],
        'SI' => ['EUR', 0.80], 'SK' => ['EUR', 0.70], 'EE' => ['EUR', 0.80], 'LV' => ['EUR', 0.70], 'LT' => ['EUR', 0.70],
        'FI' => ['EUR', 1.10], 'LU' => ['EUR', 1.10], 'MT' => ['EUR', 0.80], 'CY' => ['EUR', 0.80], 'IS' => ['ISK', 1.35],
        'RS' => ['RSD', 0.50], 'BA' => ['BAM', 0.45], 'AL' => ['ALL', 0.45], 'ME' => ['EUR', 0.55], 'MK' => ['MKD', 0.40],
        'TW' => ['TWD', 0.65], 'MO' => ['MOP', 0.90], 'FJ' => ['FJD', 0.80], 'MU' => ['MUR', 0.55], 'SC' => ['SCR', 1.00],
    ];

    /** Units of each currency per 1 USD — used only when FX APIs are unreachable. */
    public const USD_RATES = [
        'USD' => 1, 'INR' => 84.5, 'JPY' => 150, 'EUR' => 0.92, 'GBP' => 0.78, 'AUD' => 1.52,
        'CAD' => 1.37, 'NZD' => 1.66, 'SGD' => 1.33, 'AED' => 3.67, 'THB' => 35, 'VND' => 25300,
        'IDR' => 15800, 'MYR' => 4.6, 'TRY' => 34, 'MXN' => 18.5, 'BRL' => 5.5, 'LKR' => 300,
        'NPR' => 135, 'CNY' => 7.2, 'KRW' => 1380, 'HKD' => 7.8, 'CHF' => 0.88, 'EGP' => 49,
        'MAD' => 9.9, 'ZAR' => 18, 'CZK' => 23, 'HUF' => 365, 'PLN' => 4.0, 'DKK' => 6.9,
        'SEK' => 10.6, 'NOK' => 10.9, 'PHP' => 57, 'BTN' => 84.5, 'MVR' => 15.4, 'QAR' => 3.64,
        'SAR' => 3.75, 'ILS' => 3.7,
        'ARS' => 1000, 'CLP' => 940, 'COP' => 4000, 'PEN' => 3.75, 'BOB' => 6.9, 'CRC' => 515, 'CUP' => 24, 'GTQ' => 7.75,
        'DOP' => 60, 'JMD' => 157, 'UYU' => 40, 'KES' => 129, 'TZS' => 2650, 'UGX' => 3700, 'ETB' => 120, 'GHS' => 15,
        'NGN' => 1550, 'XOF' => 600, 'TND' => 3.1, 'JOD' => 0.71, 'OMR' => 0.385, 'BHD' => 0.376, 'KWD' => 0.307, 'PKR' => 278,
        'BDT' => 120, 'MMK' => 2100, 'LAK' => 21800, 'MNT' => 3400, 'KZT' => 480, 'UZS' => 12700, 'GEL' => 2.7, 'AMD' => 390,
        'AZN' => 1.7, 'RUB' => 92, 'UAH' => 41, 'RON' => 4.6, 'BGN' => 1.8, 'ISK' => 138, 'RSD' => 108, 'BAM' => 1.8,
        'ALL' => 92, 'MKD' => 57, 'TWD' => 32, 'MOP' => 8, 'FJD' => 2.25, 'MUR' => 46, 'SCR' => 14,
    ];

    /** USD reference basket at cost index 1.0. */
    public const BASKET = [
        'hotel' => [1 => 18, 2 => 45, 3 => 95, 4 => 190, 5 => 420],
        'meal' => ['breakfast' => 6, 'lunch' => 12, 'dinner' => 20, 'street' => 4],
        'mealTier' => [0 => 0.5, 1 => 0.6, 2 => 1.0, 3 => 1.9, 4 => 3.6, 5 => 7.0],
        'attraction' => 12,
        'bigAttraction' => 35,
        'market' => 25,
        'activity' => 40,
        'guide' => 30,
        'carDay' => 90,
        'taxiBase' => 3.0,
        'taxiKm' => 1.4,
        'bus' => 1.5,
        'metro' => 2.0,
    ];

    public static function currencyFor(string $countryCode): string
    {
        return self::COUNTRIES[strtoupper($countryCode)][0] ?? 'USD';
    }

    public static function index(string $countryCode): float
    {
        return self::COUNTRIES[strtoupper($countryCode)][1] ?? 0.8;
    }

    /** Units of $to per 1 unit of $from using the static table. */
    public static function staticRate(string $from, string $to): float
    {
        $f = self::USD_RATES[$from] ?? null;
        $t = self::USD_RATES[$to] ?? null;
        return ($f && $t) ? $t / $f : 1.0;
    }

    public static function styleForTier(int $tier, bool $nearHub = false): string
    {
        $style = [
            1 => 'hostel bunk',
            2 => 'budget guesthouse',
            3 => 'mid-range hotel',
            4 => 'boutique stay',
            5 => 'luxury hotel',
        ][max(1, min(5, $tier))];
        return $nearHub && $tier <= 3 ? 'transit-hub ' . $style : $style;
    }

    /**
     * Estimated transit profile in USD for cities without a curated one.
     *
     * @return array<string, mixed>
     */
    public static function genericTransit(): array
    {
        $b = self::BASKET;
        return [
            'currency' => 'USD',
            'walkMaxKm' => 2.0,
            'bus' => ['label' => 'Local bus', 'fare' => $b['bus'], 'wait' => 10, 'factor' => 1.6],
            'taxi' => ['label' => 'Taxi / ride-hail', 'base' => $b['taxiBase'], 'perKm' => $b['taxiKm'], 'speed' => 20],
            'carDay' => ['label' => 'Private car & driver (8 hrs)', 'price' => $b['carDay']],
        ];
    }
}

<?php
declare(strict_types=1);

/**
 * Open-Meteo daily forecast (16-day horizon) with offline sunrise/sunset from
 * PHP's own solar calculator for dates outside the forecast window.
 */
final class WeatherService
{
    public function __construct(private HttpClient $http)
    {
    }

    /**
     * @param list<string> $dates Y-m-d local dates
     * @return array{tz:string, source:string, days: array<string, array<string, mixed>>}
     */
    public function forecast(float $lat, float $lng, array $dates, ?string $tzHint): array
    {
        $url = sprintf(
            'https://api.open-meteo.com/v1/forecast?latitude=%.4f&longitude=%.4f&daily=%s&timezone=auto&forecast_days=16',
            $lat,
            $lng,
            'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,sunrise,sunset'
        );
        $r = $this->http->json('Open-Meteo forecast', $url, ['ttl' => 3 * 3600]);

        $tz = $r['timezone'] ?? $tzHint ?? null;
        if (!$tz || !in_array($tz, timezone_identifiers_list(), true)) {
            $tz = $tzHint && in_array($tzHint, timezone_identifiers_list(), true) ? $tzHint : 'UTC';
        }
        $offset = isset($r['utc_offset_seconds']) ? (int)$r['utc_offset_seconds'] : null;

        $byDate = [];
        foreach ($r['daily']['time'] ?? [] as $i => $date) {
            $byDate[$date] = $i;
        }

        $days = [];
        foreach ($dates as $date) {
            [$sunrise, $sunset] = $this->sun($date, $lat, $lng, $tz, $offset);
            $day = [
                'sunrise' => $sunrise,
                'sunset' => $sunset,
                'code' => null,
                'label' => 'Forecast opens 16 days out',
                'tmax' => null,
                'tmin' => null,
                'rain' => null,
                'source' => 'SOLAR CALC',
            ];
            if (isset($byDate[$date])) {
                $i = $byDate[$date];
                $d = $r['daily'];
                $code = (int)($d['weather_code'][$i] ?? 0);
                $day = [
                    'sunrise' => substr((string)($d['sunrise'][$i] ?? ''), 11, 5) ?: $sunrise,
                    'sunset' => substr((string)($d['sunset'][$i] ?? ''), 11, 5) ?: $sunset,
                    'code' => $code,
                    'label' => self::label($code),
                    'tmax' => isset($d['temperature_2m_max'][$i]) ? (int)round($d['temperature_2m_max'][$i]) : null,
                    'tmin' => isset($d['temperature_2m_min'][$i]) ? (int)round($d['temperature_2m_min'][$i]) : null,
                    'rain' => isset($d['precipitation_probability_max'][$i]) ? (int)$d['precipitation_probability_max'][$i] : null,
                    'source' => 'OPEN-METEO',
                ];
            }
            $day['wet'] = ($day['rain'] ?? 0) >= 55 || in_array($day['code'], [63, 65, 66, 67, 81, 82, 95, 96, 99], true);
            $day['hot'] = ($day['tmax'] ?? 0) >= 34;
            $days[$date] = $day;
        }

        return ['tz' => $tz, 'source' => $r ? 'OPEN-METEO' : 'SOLAR CALC', 'days' => $days];
    }

    /** @return array{0:string,1:string} */
    private function sun(string $date, float $lat, float $lng, string $tz, ?int $offset): array
    {
        $zone = new DateTimeZone($tz === 'UTC' && $offset === null ? 'UTC' : $tz);
        $noon = new DateTimeImmutable($date . ' 12:00', $zone);
        $info = date_sun_info($noon->getTimestamp(), $lat, $lng);
        $fmt = static function ($ts) use ($zone, $lng, $tz): string {
            if (!is_int($ts)) {
                return '';
            }
            $dt = (new DateTimeImmutable('@' . $ts))->setTimezone($zone);
            if ($tz === 'UTC') {
                // No zone known at all: approximate solar time from longitude.
                $dt = $dt->modify(sprintf('%+d minutes', (int)round($lng / 15 * 60)));
            }
            return $dt->format('H:i');
        };
        return [$fmt($info['sunrise']) ?: '06:30', $fmt($info['sunset']) ?: '18:15'];
    }

    public static function label(int $code): string
    {
        return match (true) {
            $code === 0 => 'Clear',
            $code <= 2 => 'Partly cloudy',
            $code === 3 => 'Overcast',
            $code <= 48 => 'Fog',
            $code <= 57 => 'Drizzle',
            $code <= 67 => 'Rain',
            $code <= 77 => 'Snow',
            $code <= 82 => 'Showers',
            default => 'Thunderstorms',
        };
    }
}

<?php

namespace App\Services\Weather;

class FeelsLikeCalculator
{
    /**
     * Compute apparent ("Feels Like") temperature in °C.
     *
     * Applies the appropriate formula based on conditions:
     *
     * - **Wind Chill** (Environment Canada / NWS formula):
     *   When temperature ≤ 10 °C and wind speed > 4.8 km/h.
     *   `13.12 + 0.6215·T − 11.37·W^0.16 + 0.3965·T·W^0.16`
     *
     * - **Humidex** (Canadian Meteorological Service formula):
     *   When temperature ≥ 20 °C and dewpoint is available.
     *   `T + 0.5555 × (6.11 × exp(5417.753 × (1/273.16 − 1/(273.15 + Td))) − 10)`
     *
     * Falls back to the actual temperature when neither condition is met but
     * temperature is available (e.g., 10 °C < T < 20 °C, or missing wind/dewpoint).
     * Returns null only when temperature itself is null.
     *
     * @param  float|null  $temperature  Air temperature in °C.
     * @param  float|null  $windKph  Wind speed in km/h (required for Wind Chill).
     * @param  float|null  $dewpoint  Dewpoint in °C (required for Humidex).
     * @return float|null Apparent temperature rounded to 1 decimal place, or null.
     */
    public static function compute(?float $temperature, ?float $windKph, ?float $dewpoint): ?float
    {
        if ($temperature === null) {
            return null;
        }

        if ($temperature <= 10.0 && $windKph !== null && $windKph > 4.8) {
            $windExponent = $windKph ** 0.16;

            $windChill = 13.12
                + 0.6215 * $temperature
                - 11.37 * $windExponent
                + 0.3965 * $temperature * $windExponent;

            return round($windChill, 1);
        }

        if ($temperature >= 20.0 && $dewpoint !== null) {
            $humidex = $temperature
                + 0.5555 * (6.11 * exp(5417.753 * (1 / 273.16 - 1 / (273.15 + $dewpoint))) - 10);

            return round($humidex, 1);
        }

        // Fall back to actual temperature when no adjustment formula applies
        return round($temperature, 1);
    }
}

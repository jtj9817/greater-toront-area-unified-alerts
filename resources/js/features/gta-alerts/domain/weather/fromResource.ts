import { WeatherResourceSchema } from './resource';
import type { WeatherData } from './types';

/**
 * Maps a raw WeatherResource (snake_case from API) to a WeatherData domain
 * object (camelCase). Returns null and logs a warning if the resource is
 * malformed.
 */
export function fromWeatherResource(resource: unknown): WeatherData | null {
    const result = WeatherResourceSchema.safeParse(resource);

    if (!result.success) {
        console.warn(
            '[WeatherData] Invalid weather resource:',
            result.error.issues,
        );
        return null;
    }

    const r = result.data;

    return {
        fsa: r.fsa,
        provider: r.provider,
        temperature: r.temperature,
        humidity: r.humidity,
        windSpeed: r.wind_speed,
        windDirection: r.wind_direction,
        condition: r.condition,
        alertLevel: r.alert_level,
        alertText: r.alert_text,
        fetchedAt: r.fetched_at,
    };
}

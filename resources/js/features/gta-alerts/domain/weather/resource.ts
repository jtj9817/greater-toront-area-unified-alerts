import { z } from 'zod/v4';

/**
 * Zod schema for the raw WeatherResource as returned by GET /api/weather.
 * Backend sends snake_case; fromResource.ts converts to camelCase.
 */
export const WeatherResourceSchema = z.object({
    fsa: z.string(),
    provider: z.string(),
    temperature: z.nullable(z.number()),
    humidity: z.nullable(z.number()),
    wind_speed: z.nullable(z.string()),
    wind_direction: z.nullable(z.string()),
    condition: z.nullable(z.string()),
    alert_level: z.nullable(z.enum(['yellow', 'orange', 'red'])),
    alert_text: z.nullable(z.string()),
    fetched_at: z.string(),
});

export type WeatherResource = z.input<typeof WeatherResourceSchema>;
export type WeatherResourceParsed = z.infer<typeof WeatherResourceSchema>;

/**
 * Zod schema for a single postal code entry from GET /api/postal-codes.
 */
export const PostalCodeResourceSchema = z.object({
    fsa: z.string(),
    municipality: z.string(),
    neighbourhood: z.nullable(z.string()),
    lat: z.number(),
    lng: z.number(),
});

export type PostalCodeResource = z.input<typeof PostalCodeResourceSchema>;
export type PostalCodeResourceParsed = z.infer<typeof PostalCodeResourceSchema>;

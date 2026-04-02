import { z } from 'zod/v4';

/**
 * Zod schema for the location object within a UnifiedAlertResource.
 */
export const AlertLocationResourceSchema = z.object({
    name: z.nullable(z.string()),
    lat: z.nullable(z.number()),
    lng: z.nullable(z.number()),
});

/**
 * Zod schema for the raw UnifiedAlertResource as received from the API.
 * This validates the transport shape before source-specific parsing.
 */
export const UnifiedAlertResourceSchema = z.object({
    id: z.string(),
    source: z.enum(['fire', 'police', 'transit', 'go_transit', 'miway', 'yrt']),
    external_id: z.string(),
    is_active: z.boolean(),
    timestamp: z.string(),
    title: z.string(),
    location: z.nullable(AlertLocationResourceSchema),
    meta: z.record(z.string(), z.unknown()),
});

export type UnifiedAlertResourceParsed = z.infer<
    typeof UnifiedAlertResourceSchema
>;

export type UnifiedAlertResource = z.input<typeof UnifiedAlertResourceSchema>;

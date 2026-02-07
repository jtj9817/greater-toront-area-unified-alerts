import { z } from 'zod/v4';

/**
 * Zod schema for TTC Transit alert meta fields as emitted by TransitAlertSelectProvider.
 */
export const TransitMetaSchema = z.object({
    route_type: z.nullable(z.string()),
    route: z.nullable(z.string()),
    severity: z.nullable(z.string()),
    effect: z.nullable(z.string()),
    source_feed: z.nullable(z.string()),
    alert_type: z.nullable(z.string()),
    description: z.nullable(z.string()),
    url: z.nullable(z.string()),
    direction: z.nullable(z.string()),
    cause: z.nullable(z.string()),
    stop_start: z.nullable(z.string()),
    stop_end: z.nullable(z.string()),
});

export type TransitMeta = z.infer<typeof TransitMetaSchema>;

/**
 * TTC Transit domain alert — fully validated and typed.
 */
export const TransitAlertSchema = z.object({
    kind: z.literal('transit'),
    id: z.string(),
    externalId: z.string(),
    isActive: z.boolean(),
    timestamp: z.string(),
    title: z.string(),
    location: z
        .object({
            name: z.nullable(z.string()),
            lat: z.nullable(z.number()),
            lng: z.nullable(z.number()),
        })
        .nullable(),
    meta: TransitMetaSchema,
});

export type TransitAlert = z.infer<typeof TransitAlertSchema>;

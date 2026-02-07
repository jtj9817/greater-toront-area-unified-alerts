import { z } from 'zod/v4';

/**
 * Zod schema for GO Transit alert meta fields as emitted by GoTransitAlertSelectProvider.
 */
export const GoTransitMetaSchema = z.object({
    alert_type: z.nullable(z.string()),
    service_mode: z.nullable(z.string()),
    sub_category: z.nullable(z.string()),
    corridor_code: z.nullable(z.string()),
    direction: z.nullable(z.string()),
    trip_number: z.nullable(z.string()),
    delay_duration: z.nullable(z.string()),
    line_colour: z.nullable(z.string()),
    message_body: z.nullable(z.string()),
});

export type GoTransitMeta = z.infer<typeof GoTransitMetaSchema>;

/**
 * GO Transit domain alert — fully validated and typed.
 */
export const GoTransitAlertSchema = z.object({
    kind: z.literal('go_transit'),
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
    meta: GoTransitMetaSchema,
});

export type GoTransitAlert = z.infer<typeof GoTransitAlertSchema>;

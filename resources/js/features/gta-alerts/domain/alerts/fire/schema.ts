import { z } from 'zod/v4';

/**
 * Zod schema for Fire alert meta fields as emitted by FireAlertSelectProvider.
 */
export const FireMetaSchema = z.object({
    alarm_level: z.number(),
    event_num: z.string(),
    units_dispatched: z.nullable(z.string()),
    beat: z.nullable(z.string()),
});

export type FireMeta = z.infer<typeof FireMetaSchema>;

/**
 * Fire domain alert — fully validated and typed.
 */
export const FireAlertSchema = z.object({
    kind: z.literal('fire'),
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
    meta: FireMetaSchema,
});

export type FireAlert = z.infer<typeof FireAlertSchema>;

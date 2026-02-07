import { z } from 'zod/v4';

/**
 * Zod schema for Police alert meta fields as emitted by PoliceAlertSelectProvider.
 */
export const PoliceMetaSchema = z.object({
    division: z.nullable(z.string()),
    call_type_code: z.nullable(z.string()),
    object_id: z.number(),
});

export type PoliceMeta = z.infer<typeof PoliceMetaSchema>;

/**
 * Police domain alert — fully validated and typed.
 */
export const PoliceAlertSchema = z.object({
    kind: z.literal('police'),
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
    meta: PoliceMetaSchema,
});

export type PoliceAlert = z.infer<typeof PoliceAlertSchema>;

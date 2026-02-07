import { z } from 'zod/v4';

/**
 * Shared location schema used across all alert types.
 */
export const AlertLocationSchema = z
    .object({
        name: z.nullable(z.string()),
        lat: z.nullable(z.number()),
        lng: z.nullable(z.number()),
    })
    .nullable();

/**
 * Base transit meta — fields common to all transit providers.
 * Extend this with `.extend({...})` for provider-specific fields.
 */
export const BaseTransitMetaSchema = z.object({
    alert_type: z.nullable(z.string()),
    direction: z.nullable(z.string()),
});

export type BaseTransitMeta = z.infer<typeof BaseTransitMetaSchema>;

/**
 * Base transit alert schema — shared structure for all transit providers.
 * Subtype schemas use `.extend()` to add `kind` literal and provider-specific meta.
 */
export const BaseTransitAlertSchema = z.object({
    id: z.string(),
    externalId: z.string(),
    isActive: z.boolean(),
    timestamp: z.string(),
    title: z.string(),
    location: AlertLocationSchema,
});

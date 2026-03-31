import { z } from 'zod/v4';

import { AlertLocationSchema } from '../transit/schema';

/**
 * MiWay alert meta — GTFS-RT fields from MiwayAlertSelectProvider.
 */
export const MiwayMetaSchema = z.object({
    header_text: z.nullable(z.string()),
    description_text: z.nullable(z.string()),
    cause: z.nullable(z.string()),
    effect: z.nullable(z.string()),
    url: z.nullable(z.string()),
    detour_pdf_url: z.nullable(z.string()),
    ends_at: z.nullable(z.string()),
    feed_updated_at: z.nullable(z.string()),
});

export type MiwayMeta = z.infer<typeof MiwayMetaSchema>;

/**
 * MiWay domain alert — GTFS-RT alert with 'miway' kind.
 */
export const MiwayAlertSchema = z.object({
    kind: z.literal('miway'),
    id: z.string(),
    externalId: z.string(),
    isActive: z.boolean(),
    timestamp: z.string(),
    title: z.string(),
    location: AlertLocationSchema,
    meta: MiwayMetaSchema,
});

export type MiwayAlert = z.infer<typeof MiwayAlertSchema>;

import { z } from 'zod/v4';

import { AlertLocationSchema } from '../schema';

/**
 * DRT alert meta fields from DrtAlertSelectProvider.
 */
export const DrtMetaSchema = z.object({
    details_url: z.string(),
    when_text: z.nullable(z.string()),
    route_text: z.nullable(z.string()),
    body_text: z.nullable(z.string()),
    posted_at: z.nullable(z.string()),
    feed_updated_at: z.nullable(z.string()),
});

export type DrtMeta = z.infer<typeof DrtMetaSchema>;

/**
 * DRT domain alert representation — Durham Region Transit service alerts.
 */
export const DrtAlertSchema = z.object({
    kind: z.literal('drt'),
    id: z.string(),
    externalId: z.string(),
    isActive: z.boolean(),
    timestamp: z.string(),
    title: z.string(),
    location: AlertLocationSchema,
    meta: DrtMetaSchema,
});

export type DrtAlert = z.infer<typeof DrtAlertSchema>;

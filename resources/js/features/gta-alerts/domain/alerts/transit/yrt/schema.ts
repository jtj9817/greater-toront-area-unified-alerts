import { z } from 'zod/v4';

import { AlertLocationSchema } from '../schema';

/**
 * YRT alert meta fields from YrtAlertSelectProvider.
 */
export const YrtMetaSchema = z.object({
    details_url: z.string(),
    description_excerpt: z.nullable(z.string()),
    body_text: z.nullable(z.string()),
    posted_at: z.nullable(z.string()),
    feed_updated_at: z.nullable(z.string()),
});

export type YrtMeta = z.infer<typeof YrtMetaSchema>;

/**
 * YRT domain alert representation.
 */
export const YrtAlertSchema = z.object({
    kind: z.literal('yrt'),
    id: z.string(),
    externalId: z.string(),
    isActive: z.boolean(),
    timestamp: z.string(),
    title: z.string(),
    location: AlertLocationSchema,
    meta: YrtMetaSchema,
});

export type YrtAlert = z.infer<typeof YrtAlertSchema>;

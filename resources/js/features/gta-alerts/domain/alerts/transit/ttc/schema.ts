import { z } from 'zod/v4';

import { BaseTransitAlertSchema, BaseTransitMetaSchema } from '../schema';

/**
 * TTC Transit meta — extends base transit meta with TTC-specific fields.
 */
export const TtcTransitMetaSchema = BaseTransitMetaSchema.extend({
    route_type: z.nullable(z.string()),
    route: z.nullable(z.string()),
    severity: z.nullable(z.string()),
    effect: z.nullable(z.string()),
    source_feed: z.nullable(z.string()),
    description: z.nullable(z.string()),
    url: z.nullable(z.string()),
    cause: z.nullable(z.string()),
    stop_start: z.nullable(z.string()),
    stop_end: z.nullable(z.string()),
});

export type TtcTransitMeta = z.infer<typeof TtcTransitMetaSchema>;

/**
 * TTC Transit domain alert — extends base transit alert with TTC kind and meta.
 */
export const TtcTransitAlertSchema = BaseTransitAlertSchema.extend({
    kind: z.literal('transit'),
    meta: TtcTransitMetaSchema,
});

export type TtcTransitAlert = z.infer<typeof TtcTransitAlertSchema>;

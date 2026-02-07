import { z } from 'zod/v4';

import { BaseTransitAlertSchema, BaseTransitMetaSchema } from '../schema';

/**
 * GO Transit meta — extends base transit meta with Metrolinx-specific fields.
 */
export const GoTransitMetaSchema = BaseTransitMetaSchema.extend({
    service_mode: z.nullable(z.string()),
    sub_category: z.nullable(z.string()),
    corridor_code: z.nullable(z.string()),
    trip_number: z.nullable(z.string()),
    delay_duration: z.nullable(z.string()),
    line_colour: z.nullable(z.string()),
    message_body: z.nullable(z.string()),
});

export type GoTransitMeta = z.infer<typeof GoTransitMetaSchema>;

/**
 * GO Transit domain alert — extends base transit alert with GO Transit kind and meta.
 */
export const GoTransitAlertSchema = BaseTransitAlertSchema.extend({
    kind: z.literal('go_transit'),
    meta: GoTransitMetaSchema,
});

export type GoTransitAlert = z.infer<typeof GoTransitAlertSchema>;

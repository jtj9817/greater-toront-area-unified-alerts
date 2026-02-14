import { z } from 'zod/v4';

/**
 * Valid values for update_type based on the backend Enum.
 */
export const SceneIntelTypeSchema = z.enum([
    'milestone',
    'resource_status',
    'alarm_change',
    'phase_change',
    'manual_note',
]);

export type SceneIntelType = z.infer<typeof SceneIntelTypeSchema>;

/**
 * Schema for a single Scene Intel update item.
 * Matches IncidentUpdateResource.php
 */
export const SceneIntelItemSchema = z.object({
    id: z.number(),
    type: SceneIntelTypeSchema,
    type_label: z.string(),
    icon: z.string(),
    content: z.string(),
    timestamp: z.string().datetime({ offset: true }),
    metadata: z.record(z.string(), z.any()).nullable().optional(),
});

export type SceneIntelItem = z.infer<typeof SceneIntelItemSchema>;

import { describe, expect, it } from 'vitest';

import { SceneIntelItemSchema } from './scene-intel';

describe('SceneIntelItemSchema', () => {
    it('accepts ISO-8601 timestamps with timezone offsets', () => {
        const result = SceneIntelItemSchema.safeParse({
            id: 1,
            type: 'milestone',
            type_label: 'Milestone',
            icon: 'flag',
            content: 'Command established',
            timestamp: '2026-02-14T09:28:21+00:00',
            metadata: null,
        });

        expect(result.success).toBe(true);
    });
});

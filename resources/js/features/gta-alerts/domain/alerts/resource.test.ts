import { describe, expect, it } from 'vitest';

import { UnifiedAlertResourceSchema } from './resource';

describe('UnifiedAlertResourceSchema', () => {
    it('accepts yrt as a valid source value', () => {
        const result = UnifiedAlertResourceSchema.safeParse({
            id: 'yrt:a1234',
            source: 'yrt',
            external_id: 'a1234',
            is_active: true,
            timestamp: '2026-04-01T14:20:00Z',
            title: '52 - Holland Landing detour',
            location: null,
            meta: {},
        });

        expect(result.success).toBe(true);
    });
});

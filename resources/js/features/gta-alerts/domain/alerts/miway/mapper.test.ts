import { describe, it, expect, vi } from 'vitest';

import type { UnifiedAlertResourceParsed } from '../resource';
import { mapMiwayAlert } from './mapper';

describe('mapMiwayAlert', () => {
    const timestamp = new Date('2026-02-03T12:00:00Z').toISOString();

    it('maps a valid MiWay resource to a MiwayAlert', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const resource: UnifiedAlertResourceParsed = {
            id: 'miway:alert:12345',
            source: 'miway',
            external_id: 'alert:12345',
            is_active: true,
            timestamp,
            title: 'Route 101 detour',
            location: { name: null, lat: null, lng: null },
            meta: {
                header_text: 'Route 101 Detour',
                description_text:
                    'Route 101 is detoured due to construction on Hurontario St.',
                cause: 'CONSTRUCTION',
                effect: 'DETOUR',
                url: 'https://www.miapp.ca/alerts/12345',
                detour_pdf_url: 'https://www.miapp.ca/detours/12345.pdf',
                ends_at: '2026-03-15T23:59:00+00:00',
                feed_updated_at: '2026-02-03T12:00:00+00:00',
            },
        };

        const alert = mapMiwayAlert(resource);
        expect(alert).not.toBeNull();
        expect(alert?.kind).toBe('miway');
        expect(alert?.externalId).toBe('alert:12345');
        expect(alert?.meta.effect).toBe('DETOUR');
        expect(alert?.meta.cause).toBe('CONSTRUCTION');

        warn.mockRestore();
    });

    it('maps a resource with null meta fields', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const resource: UnifiedAlertResourceParsed = {
            id: 'miway:alert:67890',
            source: 'miway',
            external_id: 'alert:67890',
            is_active: false,
            timestamp,
            title: 'Service update',
            location: { name: null, lat: null, lng: null },
            meta: {
                header_text: null,
                description_text: null,
                cause: null,
                effect: null,
                url: null,
                detour_pdf_url: null,
                ends_at: null,
                feed_updated_at: null,
            },
        };

        const alert = mapMiwayAlert(resource);
        expect(alert).not.toBeNull();
        expect(alert?.kind).toBe('miway');
        expect(alert?.isActive).toBe(false);
        expect(alert?.meta.header_text).toBeNull();

        warn.mockRestore();
    });

    it('returns null (and warns) for non-miway source', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const resource: UnifiedAlertResourceParsed = {
            id: 'fire:E1',
            source: 'fire',
            external_id: 'E1',
            is_active: true,
            timestamp,
            title: 'FIRE',
            location: { name: null, lat: null, lng: null },
            meta: {},
        };

        expect(mapMiwayAlert(resource)).toBeNull();
        expect(warn).toHaveBeenCalledWith(
            '[DomainAlert] MiWay mapper received non-miway resource (fire:E1):',
            'fire',
        );

        warn.mockRestore();
    });

    it('returns null (and warns) for invalid meta', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const resource: UnifiedAlertResourceParsed = {
            id: 'miway:alert:invalid',
            source: 'miway',
            external_id: 'alert:invalid',
            is_active: true,
            timestamp,
            title: 'Test',
            location: { name: null, lat: null, lng: null },
            meta: {
                header_text: 123,
                description_text: null,
                cause: null,
                effect: null,
                url: null,
                detour_pdf_url: null,
                ends_at: null,
                feed_updated_at: null,
            },
        };

        expect(mapMiwayAlert(resource)).toBeNull();
        expect(warn).toHaveBeenCalled();

        warn.mockRestore();
    });
});

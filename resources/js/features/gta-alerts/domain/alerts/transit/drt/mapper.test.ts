import { describe, expect, it, vi } from 'vitest';

import { fromResource } from '../../fromResource';
import type { UnifiedAlertResourceParsed } from '../../resource';
import { mapDomainAlertToPresentation } from '../../view';
import { mapDrtAlert } from './mapper';
import { DrtAlertSchema, DrtMetaSchema } from './schema';

describe('mapDrtAlert', () => {
    const timestamp = new Date('2026-04-03T14:20:00Z').toISOString();

    function makeDrtResource(
        overrides: Partial<UnifiedAlertResourceParsed> = {},
    ): UnifiedAlertResourceParsed {
        return {
            id: 'drt:conlin-grandview-detour',
            source: 'drt',
            external_id: 'conlin-grandview-detour',
            is_active: true,
            timestamp,
            title: 'Conlin Grandview Detour',
            location: { name: '900 Conlin', lat: null, lng: null },
            meta: {
                details_url:
                    'https://www.durhamregiontransit.com/en/news/conlin-grandview-detour.aspx',
                when_text: 'Effective until further notice',
                route_text: '900, 920',
                body_text:
                    'Routes 900 and 920 are detoured via Grandview Drive.',
                posted_at: '2026-04-03 10:20:00',
                feed_updated_at: '2026-04-03 10:25:00',
            },
            ...overrides,
        };
    }

    it('maps a valid drt resource to a DrtAlert', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const alert = mapDrtAlert(makeDrtResource());

        expect(alert).not.toBeNull();
        expect(alert?.kind).toBe('drt');
        expect(alert?.externalId).toBe('conlin-grandview-detour');
        expect(alert?.meta.details_url).toBe(
            'https://www.durhamregiontransit.com/en/news/conlin-grandview-detour.aspx',
        );
        expect(alert?.meta.when_text).toBe('Effective until further notice');
        expect(alert?.meta.route_text).toBe('900, 920');
        expect(alert?.meta.body_text).toBe(
            'Routes 900 and 920 are detoured via Grandview Drive.',
        );
        expect(warn).not.toHaveBeenCalled();

        warn.mockRestore();
    });

    it('returns null (and warns) for non-drt source', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const alert = mapDrtAlert(
            makeDrtResource({
                id: 'transit:api:61748',
                source: 'transit',
            }),
        );

        expect(alert).toBeNull();
        expect(warn).toHaveBeenCalledWith(
            '[DomainAlert] DRT mapper received non-drt resource (transit:api:61748):',
            'transit',
        );

        warn.mockRestore();
    });

    it('returns null (and warns) for invalid drt meta', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const alert = mapDrtAlert(
            makeDrtResource({
                id: 'drt:invalid',
                meta: {
                    details_url: 123,
                    when_text: null,
                    route_text: null,
                    body_text: null,
                    posted_at: null,
                    feed_updated_at: null,
                },
            }),
        );

        expect(alert).toBeNull();
        expect(warn).toHaveBeenCalled();

        warn.mockRestore();
    });

    it('validates required and optional drt meta fields', () => {
        const validMeta = DrtMetaSchema.safeParse({
            details_url:
                'https://www.durhamregiontransit.com/en/news/test.aspx',
            when_text: null,
            route_text: null,
            body_text: null,
            posted_at: null,
            feed_updated_at: null,
        });
        expect(validMeta.success).toBe(true);

        const invalidMeta = DrtMetaSchema.safeParse({
            when_text: null,
            route_text: null,
            body_text: null,
            posted_at: null,
            feed_updated_at: null,
        });
        expect(invalidMeta.success).toBe(false);
    });

    it('handles fromResource drt switch path and maps to transit presentation', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const mapped = fromResource(makeDrtResource());
        expect(mapped).not.toBeNull();
        expect(mapped?.kind).toBe('drt');

        if (!mapped) {
            throw new Error('Expected drt domain mapping to succeed');
        }

        const presentation = mapDomainAlertToPresentation(mapped);
        expect(presentation.type).toBe('transit');
        expect(presentation.metadata?.source).toBe('DRT');
        expect(presentation.description).toContain(
            'Routes 900 and 920 are detoured',
        );
        expect(warn).not.toHaveBeenCalled();

        warn.mockRestore();
    });

    it('falls back to title when body text is unavailable', () => {
        const titleAlertResult = DrtAlertSchema.safeParse({
            kind: 'drt',
            id: 'drt:title-only',
            externalId: 'title-only',
            isActive: true,
            timestamp,
            title: 'Title fallback',
            location: null,
            meta: {
                details_url:
                    'https://www.durhamregiontransit.com/en/news/title.aspx',
                when_text: null,
                route_text: null,
                body_text: null,
                posted_at: null,
                feed_updated_at: null,
            },
        });
        expect(titleAlertResult.success).toBe(true);

        if (!titleAlertResult.success) {
            throw new Error('Expected titleAlertResult to parse');
        }

        const titlePresentation = mapDomainAlertToPresentation(
            titleAlertResult.data,
        );
        expect(titlePresentation.description).toBe('Title fallback');
    });

    it('maps nullable route_text as location name from meta', () => {
        const alertResult = DrtAlertSchema.safeParse({
            kind: 'drt',
            id: 'drt:no-route',
            externalId: 'no-route',
            isActive: true,
            timestamp,
            title: 'General service alert',
            location: null,
            meta: {
                details_url:
                    'https://www.durhamregiontransit.com/en/news/general.aspx',
                when_text: 'Until April 10',
                route_text: null,
                body_text: 'Service update for Durham Region.',
                posted_at: '2026-04-03 08:00:00',
                feed_updated_at: '2026-04-03 08:05:00',
            },
        });
        expect(alertResult.success).toBe(true);

        if (!alertResult.success) {
            throw new Error('Expected alertResult to parse');
        }

        const presentation = mapDomainAlertToPresentation(alertResult.data);
        expect(presentation.metadata?.route).toBeUndefined();
        expect(presentation.description).toContain('Service update');
    });
});

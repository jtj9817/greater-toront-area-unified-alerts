import { describe, expect, it, vi } from 'vitest';

import { fromResource } from '../../fromResource';
import type { UnifiedAlertResourceParsed } from '../../resource';
import { mapDomainAlertToPresentation } from '../../view';
import { mapYrtAlert } from './mapper';
import { YrtAlertSchema, YrtMetaSchema } from './schema';

describe('mapYrtAlert', () => {
    const timestamp = new Date('2026-04-01T14:20:00Z').toISOString();

    function makeYrtResource(
        overrides: Partial<UnifiedAlertResourceParsed> = {},
    ): UnifiedAlertResourceParsed {
        return {
            id: 'yrt:a1234',
            source: 'yrt',
            external_id: 'a1234',
            is_active: true,
            timestamp,
            title: '52 - Holland Landing detour',
            location: { name: '52', lat: null, lng: null },
            meta: {
                details_url: 'https://www.yrt.ca/en/news/52-detour.aspx',
                description_excerpt:
                    'Temporary detour in effect near Green Lane.',
                body_text: 'Routes affected: 52, 58. Expect 15 minute delays.',
                posted_at: '2026-04-01 10:20:00',
                feed_updated_at: '2026-04-01 10:25:00',
            },
            ...overrides,
        };
    }

    it('maps a valid yrt resource to a YrtAlert', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const alert = mapYrtAlert(makeYrtResource());

        expect(alert).not.toBeNull();
        expect(alert?.kind).toBe('yrt');
        expect(alert?.externalId).toBe('a1234');
        expect(alert?.meta.details_url).toBe(
            'https://www.yrt.ca/en/news/52-detour.aspx',
        );
        expect(warn).not.toHaveBeenCalled();

        warn.mockRestore();
    });

    it('returns null (and warns) for non-yrt source', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const alert = mapYrtAlert(
            makeYrtResource({
                id: 'transit:api:61748',
                source: 'transit',
            }),
        );

        expect(alert).toBeNull();
        expect(warn).toHaveBeenCalledWith(
            '[DomainAlert] YRT mapper received non-yrt resource (transit:api:61748):',
            'transit',
        );

        warn.mockRestore();
    });

    it('returns null (and warns) for invalid yrt meta', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const alert = mapYrtAlert(
            makeYrtResource({
                id: 'yrt:invalid',
                meta: {
                    details_url: 123,
                    description_excerpt: null,
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

    it('validates required and optional yrt meta fields', () => {
        const validMeta = YrtMetaSchema.safeParse({
            details_url: 'https://www.yrt.ca/en/news/52-detour.aspx',
            description_excerpt: null,
            body_text: null,
            posted_at: null,
            feed_updated_at: null,
        });
        expect(validMeta.success).toBe(true);

        const invalidMeta = YrtMetaSchema.safeParse({
            description_excerpt: null,
            body_text: null,
            posted_at: null,
            feed_updated_at: null,
        });
        expect(invalidMeta.success).toBe(false);
    });

    it('handles fromResource yrt switch path and maps to transit presentation', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const mapped = fromResource(makeYrtResource());
        expect(mapped).not.toBeNull();
        expect(mapped?.kind).toBe('yrt');

        if (!mapped) {
            throw new Error('Expected yrt domain mapping to succeed');
        }

        const presentation = mapDomainAlertToPresentation(mapped);
        expect(presentation.type).toBe('transit');
        expect(presentation.metadata?.source).toBe('YRT');
        expect(presentation.description).toContain('Routes affected: 52, 58');
        expect(warn).not.toHaveBeenCalled();

        warn.mockRestore();
    });

    it('falls back to excerpt then title when body text is unavailable', () => {
        const excerptAlertResult = YrtAlertSchema.safeParse({
            kind: 'yrt',
            id: 'yrt:excerpt',
            externalId: 'excerpt',
            isActive: true,
            timestamp,
            title: 'Title fallback',
            location: null,
            meta: {
                details_url: 'https://www.yrt.ca/en/news/excerpt.aspx',
                description_excerpt: 'Excerpt fallback text',
                body_text: null,
                posted_at: null,
                feed_updated_at: null,
            },
        });
        expect(excerptAlertResult.success).toBe(true);

        if (!excerptAlertResult.success) {
            throw new Error('Expected excerptAlertResult to parse');
        }

        const excerptPresentation = mapDomainAlertToPresentation(
            excerptAlertResult.data,
        );
        expect(excerptPresentation.description).toBe('Excerpt fallback text');

        const titleAlertResult = YrtAlertSchema.safeParse({
            kind: 'yrt',
            id: 'yrt:title',
            externalId: 'title',
            isActive: true,
            timestamp,
            title: 'Title fallback',
            location: null,
            meta: {
                details_url: 'https://www.yrt.ca/en/news/title.aspx',
                description_excerpt: null,
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
});

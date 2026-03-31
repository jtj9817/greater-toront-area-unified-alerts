import { describe, it, expect } from 'vitest';

import {
    buildMiwayDescriptionAndMetadata,
    deriveMiwaySeverity,
} from '../transit/presentation';
import type { MiwayAlert } from './schema';

describe('MiWay presentation', () => {
    const baseMiwayAlert = {
        kind: 'miway' as const,
        id: 'miway:alert:12345',
        externalId: 'alert:12345',
        isActive: true,
        timestamp: '2026-02-03T12:00:00+00:00',
        title: 'Route 101 detour',
        location: { name: null, lat: null, lng: null },
    };

    describe('deriveMiwaySeverity', () => {
        it('returns high for NO_SERVICE effect', () => {
            const meta = {
                header_text: null,
                description_text: null,
                cause: null,
                effect: 'NO_SERVICE',
                url: null,
                detour_pdf_url: null,
                ends_at: null,
                feed_updated_at: null,
            };
            expect(deriveMiwaySeverity(meta)).toBe('high');
        });

        it('returns medium for REDUCED_SERVICE effect', () => {
            const meta = {
                header_text: null,
                description_text: null,
                cause: null,
                effect: 'REDUCED_SERVICE',
                url: null,
                detour_pdf_url: null,
                ends_at: null,
                feed_updated_at: null,
            };
            expect(deriveMiwaySeverity(meta)).toBe('medium');
        });

        it('returns medium for SIGNIFICANT_DELAYS effect', () => {
            const meta = {
                header_text: null,
                description_text: null,
                cause: null,
                effect: 'SIGNIFICANT_DELAYS',
                url: null,
                detour_pdf_url: null,
                ends_at: null,
                feed_updated_at: null,
            };
            expect(deriveMiwaySeverity(meta)).toBe('medium');
        });

        it('returns medium for DETOUR effect', () => {
            const meta = {
                header_text: null,
                description_text: null,
                cause: null,
                effect: 'DETOUR',
                url: null,
                detour_pdf_url: null,
                ends_at: null,
                feed_updated_at: null,
            };
            expect(deriveMiwaySeverity(meta)).toBe('medium');
        });

        it('returns low for unknown effect', () => {
            const meta = {
                header_text: null,
                description_text: null,
                cause: null,
                effect: 'UNKNOWN_EFFECT',
                url: null,
                detour_pdf_url: null,
                ends_at: null,
                feed_updated_at: null,
            };
            expect(deriveMiwaySeverity(meta)).toBe('low');
        });

        it('returns low for null effect', () => {
            const meta = {
                header_text: null,
                description_text: null,
                cause: null,
                effect: null,
                url: null,
                detour_pdf_url: null,
                ends_at: null,
                feed_updated_at: null,
            };
            expect(deriveMiwaySeverity(meta)).toBe('low');
        });

        it('is case-insensitive', () => {
            const meta = {
                header_text: null,
                description_text: null,
                cause: null,
                effect: 'no_service',
                url: null,
                detour_pdf_url: null,
                ends_at: null,
                feed_updated_at: null,
            };
            expect(deriveMiwaySeverity(meta)).toBe('high');
        });
    });

    describe('buildMiwayDescriptionAndMetadata', () => {
        it('builds description with effect and cause', () => {
            const alert: MiwayAlert = {
                ...baseMiwayAlert,
                meta: {
                    header_text: 'Route 101 Detour',
                    description_text: 'Due to construction.',
                    cause: 'CONSTRUCTION',
                    effect: 'DETOUR',
                    url: null,
                    detour_pdf_url: null,
                    ends_at: null,
                    feed_updated_at: null,
                },
            };

            const result = buildMiwayDescriptionAndMetadata(alert);
            expect(result.description).toContain('Detour');
            expect(result.description).toContain('CONSTRUCTION');
            expect(result.metadata?.source).toBe('MiWay');
            expect(result.metadata?.eventNum).toBe('alert:12345');
        });

        it('falls back to header_text when both effect and description_text are null', () => {
            const alert: MiwayAlert = {
                ...baseMiwayAlert,
                meta: {
                    header_text: 'Service Update',
                    description_text: null,
                    cause: null,
                    effect: null,
                    url: null,
                    detour_pdf_url: null,
                    ends_at: null,
                    feed_updated_at: null,
                },
            };

            const result = buildMiwayDescriptionAndMetadata(alert);
            expect(result.description).toContain('Service Update');
        });

        it('falls back to title when header_text and description_text are null', () => {
            const alert: MiwayAlert = {
                ...baseMiwayAlert,
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

            const result = buildMiwayDescriptionAndMetadata(alert);
            expect(result.description).toBe('Route 101 detour');
        });

        it('falls back to generic message when everything is null', () => {
            const alert: MiwayAlert = {
                ...baseMiwayAlert,
                title: '',
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

            const result = buildMiwayDescriptionAndMetadata(alert);
            expect(result.description).toBe('MiWay service alert.');
        });
    });
});

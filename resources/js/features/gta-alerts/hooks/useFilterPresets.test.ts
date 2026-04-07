import { router } from '@inertiajs/react';
import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useFilterPresets } from './useFilterPresets';
import type { FilterPresetParams } from './useFilterPresets';

const STORAGE_KEY = 'gta_filter_presets_v1';
const MAX_PRESETS = 10;

// ------------------------------------------------------------------
// Helpers
// ------------------------------------------------------------------

const defaultParams: FilterPresetParams = {
    status: 'all',
    source: null,
    q: null,
    since: null,
};

const makeParams = (
    overrides: Partial<FilterPresetParams> = {},
): FilterPresetParams => ({
    ...defaultParams,
    ...overrides,
});

const writeStorage = (presets: unknown[]): void => {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ version: 1, presets }));
};

const readStorage = (): unknown[] => {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return [];
    const parsed = JSON.parse(raw) as { presets: unknown[] };
    return parsed.presets;
};

// ------------------------------------------------------------------
// Tests
// ------------------------------------------------------------------

describe('useFilterPresets', () => {
    beforeEach(() => {
        localStorage.clear();
        vi.restoreAllMocks();
    });

    afterEach(() => {
        localStorage.clear();
    });

    // ----------------------------------------------------------------
    // Initialization
    // ----------------------------------------------------------------

    describe('initialization', () => {
        it('initializes with empty presets when localStorage is empty', () => {
            const { result } = renderHook(() =>
                useFilterPresets({ currentParams: defaultParams }),
            );

            expect(result.current.presets).toEqual([]);
            expect(result.current.maxPresetsReached).toBe(false);
            expect(result.current.hasNonDefaultFilters).toBe(false);
        });

        it('loads presets from localStorage on init', () => {
            writeStorage([
                {
                    id: 'abc-123',
                    name: 'My Preset',
                    params: {
                        status: 'active',
                        source: 'fire',
                        q: null,
                        since: null,
                    },
                    createdAt: 1000,
                },
            ]);

            const { result } = renderHook(() =>
                useFilterPresets({ currentParams: defaultParams }),
            );

            expect(result.current.presets).toHaveLength(1);
            expect(result.current.presets[0].name).toBe('My Preset');
        });

        it('silently discards presets with invalid source values', () => {
            writeStorage([
                {
                    id: 'abc-123',
                    name: 'Bad Source',
                    params: {
                        status: 'active',
                        source: 'invalid_source',
                        q: null,
                        since: null,
                    },
                    createdAt: 1000,
                },
                {
                    id: 'def-456',
                    name: 'Valid',
                    params: {
                        status: 'active',
                        source: 'fire',
                        q: null,
                        since: null,
                    },
                    createdAt: 2000,
                },
            ]);

            const { result } = renderHook(() =>
                useFilterPresets({ currentParams: defaultParams }),
            );

            expect(result.current.presets).toHaveLength(1);
            expect(result.current.presets[0].name).toBe('Valid');
        });

        it('silently discards presets with invalid status values', () => {
            writeStorage([
                {
                    id: 'abc-123',
                    name: 'Bad Status',
                    params: {
                        status: 'unknown',
                        source: null,
                        q: null,
                        since: null,
                    },
                    createdAt: 1000,
                },
            ]);

            const { result } = renderHook(() =>
                useFilterPresets({ currentParams: defaultParams }),
            );

            expect(result.current.presets).toEqual([]);
        });

        it('silently discards presets with invalid since values', () => {
            writeStorage([
                {
                    id: 'abc-123',
                    name: 'Bad Since',
                    params: {
                        status: 'all',
                        source: null,
                        q: null,
                        since: '99d',
                    },
                    createdAt: 1000,
                },
            ]);

            const { result } = renderHook(() =>
                useFilterPresets({ currentParams: defaultParams }),
            );

            expect(result.current.presets).toEqual([]);
        });

        it('handles malformed JSON in localStorage gracefully', () => {
            localStorage.setItem(STORAGE_KEY, 'not-valid-json{{');

            const { result } = renderHook(() =>
                useFilterPresets({ currentParams: defaultParams }),
            );

            expect(result.current.presets).toEqual([]);
        });

        it('handles non-array presets field gracefully', () => {
            localStorage.setItem(
                STORAGE_KEY,
                JSON.stringify({ version: 1, presets: 'not-an-array' }),
            );

            const { result } = renderHook(() =>
                useFilterPresets({ currentParams: defaultParams }),
            );

            expect(result.current.presets).toEqual([]);
        });
    });

    // ----------------------------------------------------------------
    // savePreset
    // ----------------------------------------------------------------

    describe('savePreset', () => {
        it('saves a new preset and persists to localStorage', () => {
            const params = makeParams({ source: 'fire' });
            const { result } = renderHook(() =>
                useFilterPresets({ currentParams: params }),
            );

            act(() => {
                result.current.savePreset('Fire Alerts', params);
            });

            expect(result.current.presets).toHaveLength(1);
            expect(result.current.presets[0].name).toBe('Fire Alerts');
            expect(result.current.presets[0].params.source).toBe('fire');
            expect(readStorage()).toHaveLength(1);
        });

        it('trims whitespace from preset name', () => {
            const { result } = renderHook(() =>
                useFilterPresets({ currentParams: defaultParams }),
            );

            act(() => {
                result.current.savePreset('  My Preset  ', defaultParams);
            });

            expect(result.current.presets[0].name).toBe('My Preset');
        });

        it('truncates names exceeding 30 characters', () => {
            const longName = 'A'.repeat(40);
            const { result } = renderHook(() =>
                useFilterPresets({ currentParams: defaultParams }),
            );

            act(() => {
                result.current.savePreset(longName, defaultParams);
            });

            expect(result.current.presets[0].name).toHaveLength(30);
        });

        it('does not save a preset with an empty or whitespace-only name', () => {
            const { result } = renderHook(() =>
                useFilterPresets({ currentParams: defaultParams }),
            );

            act(() => {
                result.current.savePreset('   ', defaultParams);
            });

            expect(result.current.presets).toHaveLength(0);
        });

        it('enforces the maximum preset cap', () => {
            const { result } = renderHook(() =>
                useFilterPresets({ currentParams: defaultParams }),
            );

            for (let i = 0; i < MAX_PRESETS + 2; i++) {
                act(() => {
                    result.current.savePreset(
                        `Preset ${i}`,
                        makeParams({ source: 'fire' }),
                    );
                });
            }

            expect(result.current.presets).toHaveLength(MAX_PRESETS);
            expect(result.current.maxPresetsReached).toBe(true);
        });
    });

    // ----------------------------------------------------------------
    // deletePreset
    // ----------------------------------------------------------------

    describe('deletePreset', () => {
        it('removes a preset by id and updates localStorage', () => {
            const { result } = renderHook(() =>
                useFilterPresets({ currentParams: defaultParams }),
            );

            act(() => {
                result.current.savePreset('Keep', defaultParams);
                result.current.savePreset('Delete Me', defaultParams);
            });

            const deleteId = result.current.presets[1].id;

            act(() => {
                result.current.deletePreset(deleteId);
            });

            expect(result.current.presets).toHaveLength(1);
            expect(result.current.presets[0].name).toBe('Keep');
            expect(readStorage()).toHaveLength(1);
        });

        it('is a no-op for a non-existent id', () => {
            const { result } = renderHook(() =>
                useFilterPresets({ currentParams: defaultParams }),
            );

            act(() => {
                result.current.savePreset('Only One', defaultParams);
            });

            act(() => {
                result.current.deletePreset('non-existent-id');
            });

            expect(result.current.presets).toHaveLength(1);
        });
    });

    // ----------------------------------------------------------------
    // renamePreset
    // ----------------------------------------------------------------

    describe('renamePreset', () => {
        it('renames a preset and persists the change', () => {
            const { result } = renderHook(() =>
                useFilterPresets({ currentParams: defaultParams }),
            );

            act(() => {
                result.current.savePreset('Old Name', defaultParams);
            });

            const id = result.current.presets[0].id;

            act(() => {
                result.current.renamePreset(id, 'New Name');
            });

            expect(result.current.presets[0].name).toBe('New Name');
            const stored = readStorage();
            expect((stored as Array<{ name: string }>)[0].name).toBe(
                'New Name',
            );
        });

        it('trims and truncates the new name', () => {
            const { result } = renderHook(() =>
                useFilterPresets({ currentParams: defaultParams }),
            );

            act(() => {
                result.current.savePreset('Test', defaultParams);
            });

            const id = result.current.presets[0].id;

            act(() => {
                result.current.renamePreset(id, `  ${'B'.repeat(40)}  `);
            });

            expect(result.current.presets[0].name).toBe('B'.repeat(30));
        });

        it('does not rename if the new name is empty', () => {
            const { result } = renderHook(() =>
                useFilterPresets({ currentParams: defaultParams }),
            );

            act(() => {
                result.current.savePreset('Original', defaultParams);
            });

            const id = result.current.presets[0].id;

            act(() => {
                result.current.renamePreset(id, '   ');
            });

            expect(result.current.presets[0].name).toBe('Original');
        });
    });

    // ----------------------------------------------------------------
    // isPresetActive
    // ----------------------------------------------------------------

    describe('isPresetActive', () => {
        it('returns true when current params match the preset params', () => {
            const params = makeParams({
                status: 'active',
                source: 'fire',
                since: '1h',
            });

            const { result } = renderHook(() =>
                useFilterPresets({ currentParams: params }),
            );

            act(() => {
                result.current.savePreset('Active Fire', params);
            });

            const id = result.current.presets[0].id;
            expect(result.current.isPresetActive(id)).toBe(true);
        });

        it('returns false when current params differ', () => {
            const presetParams = makeParams({ source: 'fire' });
            const currentParams = makeParams({ source: 'police' });

            writeStorage([
                {
                    id: 'abc-123',
                    name: 'Fire',
                    params: presetParams,
                    createdAt: 1000,
                },
            ]);

            const { result } = renderHook(() =>
                useFilterPresets({ currentParams }),
            );

            expect(result.current.isPresetActive('abc-123')).toBe(false);
        });

        it('returns false for a non-existent preset id', () => {
            const { result } = renderHook(() =>
                useFilterPresets({ currentParams: defaultParams }),
            );

            expect(result.current.isPresetActive('non-existent')).toBe(false);
        });

        it('treats null and empty string q as equivalent', () => {
            const presetParams = makeParams({ q: null });
            const currentParams = makeParams({ q: '' });

            writeStorage([
                {
                    id: 'abc-123',
                    name: 'Test',
                    params: presetParams,
                    createdAt: 1000,
                },
            ]);

            const { result } = renderHook(() =>
                useFilterPresets({ currentParams }),
            );

            expect(result.current.isPresetActive('abc-123')).toBe(true);
        });

        it('treats null and null since as equivalent', () => {
            const presetParams = makeParams({ since: null });
            const currentParams = makeParams({ since: null });

            writeStorage([
                {
                    id: 'abc-123',
                    name: 'Test',
                    params: presetParams,
                    createdAt: 1000,
                },
            ]);

            const { result } = renderHook(() =>
                useFilterPresets({ currentParams }),
            );

            expect(result.current.isPresetActive('abc-123')).toBe(true);
        });
    });

    // ----------------------------------------------------------------
    // hasNonDefaultFilters
    // ----------------------------------------------------------------

    describe('hasNonDefaultFilters', () => {
        it('returns false when all params are default', () => {
            const { result } = renderHook(() =>
                useFilterPresets({ currentParams: defaultParams }),
            );

            expect(result.current.hasNonDefaultFilters).toBe(false);
        });

        it('returns true when source is set', () => {
            const { result } = renderHook(() =>
                useFilterPresets({
                    currentParams: makeParams({ source: 'fire' }),
                }),
            );

            expect(result.current.hasNonDefaultFilters).toBe(true);
        });

        it('returns true when status is active', () => {
            const { result } = renderHook(() =>
                useFilterPresets({
                    currentParams: makeParams({ status: 'active' }),
                }),
            );

            expect(result.current.hasNonDefaultFilters).toBe(true);
        });

        it('returns true when since is set', () => {
            const { result } = renderHook(() =>
                useFilterPresets({
                    currentParams: makeParams({ since: '1h' }),
                }),
            );

            expect(result.current.hasNonDefaultFilters).toBe(true);
        });

        it('returns true when q is set', () => {
            const { result } = renderHook(() =>
                useFilterPresets({
                    currentParams: makeParams({ q: 'search' }),
                }),
            );

            expect(result.current.hasNonDefaultFilters).toBe(true);
        });
    });

    // ----------------------------------------------------------------
    // applyPreset
    // ----------------------------------------------------------------

    describe('applyPreset', () => {
        it('calls router.get with the preset params', () => {
            const mockRouterGet = vi.fn();
            vi.mocked(router).get = mockRouterGet;

            const params = makeParams({
                status: 'active',
                source: 'fire',
                since: '1h',
            });

            writeStorage([
                {
                    id: 'abc-123',
                    name: 'My Preset',
                    params,
                    createdAt: 1000,
                },
            ]);

            const { result } = renderHook(() =>
                useFilterPresets({ currentParams: defaultParams }),
            );

            act(() => {
                result.current.applyPreset('abc-123');
            });

            expect(mockRouterGet).toHaveBeenCalledTimes(1);
            const [url, , options] = mockRouterGet.mock.calls[0];
            expect(url).toContain('status=active');
            expect(url).toContain('source=fire');
            expect(url).toContain('since=1h');
            expect(options).toMatchObject({
                preserveScroll: true,
                preserveState: true,
                replace: true,
                only: ['alerts', 'filters'],
            });
        });

        it('preserves asc sort when applying a preset', () => {
            const mockRouterGet = vi.fn();
            vi.mocked(router).get = mockRouterGet;

            writeStorage([
                {
                    id: 'abc-123',
                    name: 'My Preset',
                    params: makeParams({ source: 'fire' }),
                    createdAt: 1000,
                },
            ]);

            const { result } = renderHook(() =>
                useFilterPresets({
                    currentParams: defaultParams,
                    currentSort: 'asc',
                }),
            );

            act(() => {
                result.current.applyPreset('abc-123');
            });

            expect(mockRouterGet).toHaveBeenCalledTimes(1);
            const [url] = mockRouterGet.mock.calls[0] as [string];
            expect(url).toContain('sort=asc');
        });

        it('is a no-op for a non-existent preset id', () => {
            const mockRouterGet = vi.fn();
            vi.mocked(router).get = mockRouterGet;

            const { result } = renderHook(() =>
                useFilterPresets({ currentParams: defaultParams }),
            );

            act(() => {
                result.current.applyPreset('non-existent');
            });

            expect(mockRouterGet).not.toHaveBeenCalled();
        });
    });

    // ----------------------------------------------------------------
    // Valid source values
    // ----------------------------------------------------------------

    describe('valid source values', () => {
        const validSources = [
            'fire',
            'police',
            'transit',
            'go_transit',
            'miway',
            'yrt',
            'drt',
        ];

        it.each(validSources)('accepts "%s" as a valid source', (source) => {
            const params = makeParams({ source });
            writeStorage([
                {
                    id: 'test-id',
                    name: `Preset ${source}`,
                    params,
                    createdAt: 1000,
                },
            ]);

            const { result } = renderHook(() =>
                useFilterPresets({ currentParams: params }),
            );

            expect(result.current.presets).toHaveLength(1);
            expect(result.current.presets[0].params.source).toBe(source);
        });
    });

    // ----------------------------------------------------------------
    // Valid since values
    // ----------------------------------------------------------------

    describe('valid since values', () => {
        const validSinces = ['30m', '1h', '3h', '6h', '12h'];

        it.each(validSinces)('accepts "%s" as a valid since', (since) => {
            const params = makeParams({ since });
            writeStorage([
                {
                    id: 'test-id',
                    name: `Preset ${since}`,
                    params,
                    createdAt: 1000,
                },
            ]);

            const { result } = renderHook(() =>
                useFilterPresets({ currentParams: params }),
            );

            expect(result.current.presets).toHaveLength(1);
            expect(result.current.presets[0].params.since).toBe(since);
        });
    });
});

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual('@inertiajs/react');
    return {
        ...actual,
        router: {
            ...actual,
            get: vi.fn(),
        },
    };
});

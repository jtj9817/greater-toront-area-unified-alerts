import { router } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import { home } from '@/routes';

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

/** Versioned localStorage key for filter presets. */
const STORAGE_KEY = 'gta_filter_presets_v1';

/** Maximum number of presets a user can save. */
export const MAX_PRESETS = 10;

/** Valid status values accepted by the backend. */
const VALID_STATUSES = ['all', 'active', 'cleared'] as const;

/** Valid source values accepted by the backend (mirrors AlertSource enum). */
const VALID_SOURCES = [
    'fire',
    'police',
    'transit',
    'go_transit',
    'miway',
    'yrt',
    'drt',
] as const;

/** Valid since values accepted by the backend (mirrors SINCE_OPTIONS). */
const VALID_SINCES = ['30m', '1h', '3h', '6h', '12h'] as const;

/** Maximum length for a preset name. */
const MAX_NAME_LENGTH = 30;

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

/** The filter parameters that make up a preset. */
export interface FilterPresetParams {
    status: 'all' | 'active' | 'cleared';
    source: string | null;
    q: string | null;
    since: string | null;
}

/** A saved filter preset. */
export interface FilterPreset {
    id: string;
    name: string;
    params: FilterPresetParams;
    createdAt: number;
}

/** Storage wrapper for versioned persistence. */
interface FilterPresetsStorage {
    version: 1;
    presets: FilterPreset[];
}

export interface UseFilterPresetsOptions {
    /** Current active filter params, used for isPresetActive comparison. */
    currentParams: FilterPresetParams;
    /** Current sort direction from URL so preset apply preserves ordering. */
    currentSort?: 'asc' | 'desc';
}

export interface UseFilterPresetsReturn {
    presets: FilterPreset[];
    savePreset: (name: string, params: FilterPresetParams) => void;
    deletePreset: (id: string) => void;
    renamePreset: (id: string, newName: string) => void;
    applyPreset: (id: string) => void;
    isPresetActive: (id: string) => boolean;
    hasNonDefaultFilters: boolean;
    maxPresetsReached: boolean;
}

// ---------------------------------------------------------------------------
// Validation helpers
// ---------------------------------------------------------------------------

function isValidStatus(value: unknown): value is FilterPresetParams['status'] {
    return (
        typeof value === 'string' &&
        (VALID_STATUSES as readonly string[]).includes(value)
    );
}

function isValidSource(value: unknown): value is string | null {
    if (value === null || value === undefined) return true;
    return (
        typeof value === 'string' &&
        (VALID_SOURCES as readonly string[]).includes(value)
    );
}

function isValidSince(value: unknown): value is string | null {
    if (value === null || value === undefined) return true;
    return (
        typeof value === 'string' &&
        (VALID_SINCES as readonly string[]).includes(value)
    );
}

function isValidPreset(preset: unknown): preset is FilterPreset {
    if (typeof preset !== 'object' || preset === null) return false;

    const p = preset as Record<string, unknown>;

    if (typeof p.id !== 'string' || p.id.trim() === '') return false;
    if (typeof p.name !== 'string' || p.name.trim() === '') return false;
    if (typeof p.createdAt !== 'number') return false;

    if (typeof p.params !== 'object' || p.params === null) return false;

    const params = p.params as Record<string, unknown>;
    if (!isValidStatus(params.status)) return false;
    if (!isValidSource(params.source)) return false;
    if (typeof params.q !== 'string' && params.q !== null) return false;
    if (!isValidSince(params.since)) return false;

    return true;
}

function sanitizePresets(raw: unknown): FilterPreset[] {
    if (
        typeof raw !== 'object' ||
        raw === null ||
        !('presets' in raw) ||
        !Array.isArray((raw as FilterPresetsStorage).presets)
    ) {
        return [];
    }

    return (raw as FilterPresetsStorage).presets.filter(
        (item: unknown): item is FilterPreset => isValidPreset(item),
    );
}

// ---------------------------------------------------------------------------
// localStorage helpers (SSR-safe)
// ---------------------------------------------------------------------------

function readStoredPresets(): FilterPreset[] {
    if (typeof window === 'undefined') {
        return [];
    }

    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return [];

        const parsed: unknown = JSON.parse(raw);
        return sanitizePresets(parsed);
    } catch {
        return [];
    }
}

function writeStoredPresets(presets: FilterPreset[]): void {
    if (typeof window === 'undefined') return;

    try {
        const storage: FilterPresetsStorage = {
            version: 1,
            presets,
        };
        localStorage.setItem(STORAGE_KEY, JSON.stringify(storage));
    } catch {
        // localStorage may be unavailable (private browsing, quota exceeded).
    }
}

// ---------------------------------------------------------------------------
// Param comparison
// ---------------------------------------------------------------------------

function paramsEqual(a: FilterPresetParams, b: FilterPresetParams): boolean {
    return (
        a.status === b.status &&
        a.source === b.source &&
        (a.q ?? '') === (b.q ?? '') &&
        (a.since ?? '') === (b.since ?? '')
    );
}

const DEFAULT_PARAMS: FilterPresetParams = {
    status: 'all',
    source: null,
    q: null,
    since: null,
};

// ---------------------------------------------------------------------------
// Hook
// ---------------------------------------------------------------------------

/**
 * useFilterPresets -- client-side filter preset persistence.
 *
 * Presets are stored in localStorage and applied by navigating to the
 * home route with the preset's saved query params via Inertia router.
 */
export function useFilterPresets({
    currentParams,
    currentSort = 'desc',
}: UseFilterPresetsOptions): UseFilterPresetsReturn {
    const [presets, setPresets] = useState<FilterPreset[]>(() =>
        readStoredPresets(),
    );

    // Persist to localStorage whenever presets change.
    useEffect(() => {
        writeStoredPresets(presets);
    }, [presets]);

    const maxPresetsReached = presets.length >= MAX_PRESETS;

    const hasNonDefaultFilters = !paramsEqual(currentParams, DEFAULT_PARAMS);

    const savePreset = useCallback(
        (name: string, params: FilterPresetParams): void => {
            const trimmed = name.trim().slice(0, MAX_NAME_LENGTH);
            if (trimmed.length === 0) return;

            setPresets((prev) => {
                if (prev.length >= MAX_PRESETS) return prev;

                const preset: FilterPreset = {
                    id: crypto.randomUUID(),
                    name: trimmed,
                    params,
                    createdAt: Date.now(),
                };

                return [...prev, preset];
            });
        },
        [],
    );

    const deletePreset = useCallback((id: string): void => {
        setPresets((prev) => prev.filter((p) => p.id !== id));
    }, []);

    const renamePreset = useCallback((id: string, newName: string): void => {
        const trimmed = newName.trim().slice(0, MAX_NAME_LENGTH);
        if (trimmed.length === 0) return;

        setPresets((prev) =>
            prev.map((p) => (p.id === id ? { ...p, name: trimmed } : p)),
        );
    }, []);

    const applyPreset = useCallback(
        (id: string): void => {
            const preset = presets.find((p) => p.id === id);
            if (!preset) return;

            router.get(
                home({
                    query: {
                        status:
                            preset.params.status === 'all'
                                ? null
                                : preset.params.status,
                        sort: currentSort === 'asc' ? 'asc' : null,
                        source: preset.params.source ?? null,
                        q: preset.params.q || null,
                        since: preset.params.since ?? null,
                    },
                }).url,
                {},
                {
                    preserveScroll: true,
                    preserveState: true,
                    replace: true,
                    only: ['alerts', 'filters'],
                },
            );
        },
        [presets, currentSort],
    );

    const isPresetActive = useCallback(
        (id: string): boolean => {
            const preset = presets.find((p) => p.id === id);
            if (!preset) return false;
            return paramsEqual(preset.params, currentParams);
        },
        [presets, currentParams],
    );

    return {
        presets,
        savePreset,
        deletePreset,
        renamePreset,
        applyPreset,
        isPresetActive,
        hasNonDefaultFilters,
        maxPresetsReached,
    };
}

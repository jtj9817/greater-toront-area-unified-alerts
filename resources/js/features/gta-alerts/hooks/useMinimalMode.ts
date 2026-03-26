import { useCallback, useEffect, useState } from 'react';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

/** Identifiers for the three toggleable feed sections */
export type MinimalModeSection = 'status' | 'category' | 'filter';

/** Structure of the hidden sections state */
export interface HiddenSections {
    status: boolean; // gta-alerts-feed-status-row-content
    category: boolean; // gta-alerts-feed-category-links
    filter: boolean; // gta-alerts-feed-filter-row
}

/** Complete minimal mode preferences stored in localStorage */
export interface MinimalModePreferences {
    /** Version for migration support */
    version: 1;
    /** Which sections are hidden */
    hidden: HiddenSections;
}

/** Return type for the useMinimalMode hook */
export interface UseMinimalModeReturn {
    /** Check if a specific section is hidden */
    isHidden: (section: MinimalModeSection) => boolean;
    /** Toggle visibility of a single section */
    toggleSection: (section: MinimalModeSection) => void;
    /** True if all sections are hidden (minimal mode active) */
    isMinimalMode: boolean;
    /** Toggle all sections at once (enter/exit minimal mode) */
    toggleMinimalMode: () => void;
    /** Show all sections (exit minimal mode) */
    showAll: () => void;
    /** Hide all sections (enter minimal mode) */
    hideAll: () => void;
}

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

/** Versioned localStorage key for minimal mode preferences */
const STORAGE_KEY = 'gta_minimal_mode_v1';

/** Default state - all sections visible */
const DEFAULT_HIDDEN: HiddenSections = {
    status: false,
    category: false,
    filter: false,
};

// ---------------------------------------------------------------------------
// localStorage helpers (SSR-safe)
// ---------------------------------------------------------------------------

function readStoredPreferences(): HiddenSections {
    if (typeof window === 'undefined') {
        return DEFAULT_HIDDEN;
    }

    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return DEFAULT_HIDDEN;

        const parsed: unknown = JSON.parse(raw);

        if (
            typeof parsed === 'object' &&
            parsed !== null &&
            'hidden' in parsed &&
            typeof (parsed as Record<string, unknown>).hidden === 'object' &&
            (parsed as Record<string, unknown>).hidden !== null
        ) {
            const hidden = (parsed as MinimalModePreferences).hidden;

            // Validate all required keys exist
            if (
                typeof hidden.status === 'boolean' &&
                typeof hidden.category === 'boolean' &&
                typeof hidden.filter === 'boolean'
            ) {
                return hidden;
            }
        }

        return DEFAULT_HIDDEN;
    } catch {
        return DEFAULT_HIDDEN;
    }
}

function writeStoredPreferences(hidden: HiddenSections): void {
    if (typeof window === 'undefined') return;

    try {
        const prefs: MinimalModePreferences = {
            version: 1,
            hidden,
        };
        localStorage.setItem(STORAGE_KEY, JSON.stringify(prefs));
    } catch {
        // localStorage may be unavailable (private browsing, quota exceeded)
    }
}

// ---------------------------------------------------------------------------
// Hook
// ---------------------------------------------------------------------------

export function useMinimalMode(): UseMinimalModeReturn {
    // Initialize from localStorage on mount (SSR-safe)
    const [hiddenSections, setHiddenSections] = useState<HiddenSections>(() =>
        readStoredPreferences(),
    );

    // Persist to localStorage whenever state changes
    useEffect(() => {
        writeStoredPreferences(hiddenSections);
    }, [hiddenSections]);

    // Check if a section is hidden
    const isHidden = useCallback(
        (section: MinimalModeSection): boolean => hiddenSections[section],
        [hiddenSections],
    );

    // Toggle a single section
    const toggleSection = useCallback((section: MinimalModeSection): void => {
        setHiddenSections((prev) => ({
            ...prev,
            [section]: !prev[section],
        }));
    }, []);

    // Derived state: true if all sections are hidden
    const isMinimalMode =
        hiddenSections.status &&
        hiddenSections.category &&
        hiddenSections.filter;

    // Hide all sections (enter minimal mode)
    const hideAll = useCallback((): void => {
        setHiddenSections({
            status: true,
            category: true,
            filter: true,
        });
    }, []);

    // Show all sections (exit minimal mode)
    const showAll = useCallback((): void => {
        setHiddenSections({
            status: false,
            category: false,
            filter: false,
        });
    }, []);

    // Toggle minimal mode (all on/off)
    const toggleMinimalMode = useCallback((): void => {
        if (isMinimalMode) {
            showAll();
        } else {
            hideAll();
        }
    }, [isMinimalMode, hideAll, showAll]);

    return {
        isHidden,
        toggleSection,
        isMinimalMode,
        toggleMinimalMode,
        showAll,
        hideAll,
    };
}

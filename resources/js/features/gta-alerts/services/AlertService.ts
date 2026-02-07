import {
    fromResource,
    mapDomainAlertToAlertItem,
    type DomainAlert,
} from '../domain/alerts';
import type { AlertItem, UnifiedAlertResource } from '../types';

export interface AlertFilterOptions {
    query: string;
    category: string;
    timeLimit?: number | null; // minutes, null means no limit
    dateScope?: 'today' | 'yesterday' | 'all';
}

/**
 * AlertService
 * Encapsulates all data operations for the Alerts dashboard.
 * Refactored to be data-driven and support live backend resources.
 */
export class AlertService {
    private static categoryAliases: Record<string, ReadonlyArray<string>> = {
        transit: ['transit', 'go_transit'],
    };

    /**
     * Helper to parse relative time strings (e.g. "4m ago", "1h ago") into minutes for numeric sorting
     */
    private static parseTimeAgo(timeStr: string): number {
        const match = timeStr.match(/(\d+)([mhd])/);
        if (!match) return 0;

        const val = parseInt(match[1], 10);
        const unit = match[2];

        if (unit === 'h') return val * 60;
        if (unit === 'd') return val * 1440;
        return val;
    }

    private static searchAlertItems(
        items: AlertItem[],
        options: AlertFilterOptions,
    ): AlertItem[] {
        let filtered = [...items];
        const { query, category, timeLimit, dateScope } = options;

        // 1. Sort by recency (newest first)
        filtered = filtered.sort(
            (a, b) =>
                new Date(b.timestamp).getTime() -
                new Date(a.timestamp).getTime(),
        );

        // 2. Category Filter
        if (category !== 'all') {
            const allowedTypes = this.categoryAliases[category] ?? [category];
            filtered = filtered.filter((item) =>
                allowedTypes.includes(item.type),
            );
        }

        // 3. Time Limit Filter (Last X minutes)
        if (timeLimit) {
            filtered = filtered.filter(
                (item) => this.parseTimeAgo(item.timeAgo) <= timeLimit,
            );
        }

        // 4. Date Scope Filter
        if (dateScope === 'today') {
            filtered = filtered.filter(
                (item) => this.parseTimeAgo(item.timeAgo) < 1440,
            );
        } else if (dateScope === 'yesterday') {
            filtered = filtered.filter((item) => {
                const mins = this.parseTimeAgo(item.timeAgo);
                return mins >= 1440 && mins < 2880;
            });
        }

        // 5. Multi-field Search Query
        const normalizedQuery = query.trim().toLowerCase();
        if (normalizedQuery) {
            filtered = filtered.filter((item) => {
                return (
                    item.title.toLowerCase().includes(normalizedQuery) ||
                    item.description.toLowerCase().includes(normalizedQuery) ||
                    item.location.toLowerCase().includes(normalizedQuery) ||
                    item.id.toLowerCase().includes(normalizedQuery) ||
                    item.type.toLowerCase().includes(normalizedQuery)
                );
            });
        }

        return filtered;
    }

    static mapUnifiedAlertToDomainAlert(
        alert: UnifiedAlertResource,
    ): DomainAlert | null {
        return fromResource(alert);
    }

    static mapUnifiedAlertsToDomainAlerts(
        alerts: UnifiedAlertResource[],
    ): DomainAlert[] {
        const mapped: DomainAlert[] = [];

        for (const alert of alerts) {
            const item = this.mapUnifiedAlertToDomainAlert(alert);
            if (item) mapped.push(item);
        }

        return mapped;
    }

    static searchDomainAlerts(
        items: DomainAlert[],
        options: AlertFilterOptions,
    ): DomainAlert[] {
        const alertsById = new Map(items.map((item) => [item.id, item]));
        const presentationItems = items.map((item) =>
            mapDomainAlertToAlertItem(item),
        );

        const filteredPresentation = this.searchAlertItems(
            presentationItems,
            options,
        );

        return filteredPresentation
            .map((item) => alertsById.get(item.id))
            .filter((item): item is DomainAlert => item !== undefined);
    }

    /**
     * Maps backend UnifiedAlert resource to frontend AlertItem interface.
     *
     * Hard enforcement:
     * - Invalid resources are caught, logged, and discarded (returns null).
     * - This must never throw into UI rendering.
     */
    static mapUnifiedAlertToAlertItem(
        alert: UnifiedAlertResource,
    ): AlertItem | null {
        const domainAlert = this.mapUnifiedAlertToDomainAlert(alert);
        if (!domainAlert) {
            return null;
        }

        return mapDomainAlertToAlertItem(domainAlert);
    }

    static mapUnifiedAlertsToAlertItems(
        alerts: UnifiedAlertResource[],
    ): AlertItem[] {
        return this.mapUnifiedAlertsToDomainAlerts(alerts).map((item) =>
            mapDomainAlertToAlertItem(item),
        );
    }

    /**
     * Core Search and Filter Engine
     * Filters a provided list of items based on user options.
     */
    static search(
        items: AlertItem[],
        options: AlertFilterOptions,
    ): AlertItem[] {
        return this.searchAlertItems(items, options);
    }
}

import { formatTimeAgo } from '@/lib/utils';
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

    /**
     * Maps backend UnifiedAlert resource to frontend AlertItem interface
     */
    static mapUnifiedAlertToAlertItem(alert: UnifiedAlertResource): AlertItem {
        const type = this.getAlertItemType(alert);
        const severity = this.getSeverity(alert, type);
        const { description, metadata } = this.getDescriptionAndMetadata(alert);

        return {
            id: alert.id,
            title: alert.title,
            location: alert.location?.name ?? 'Unknown location',
            timeAgo: formatTimeAgo(alert.timestamp),
            description,
            type: type,
            severity: severity,
            iconName: this.getIconForType(type, alert.title),
            accentColor: this.getAccentColorForType(type, severity),
            iconColor: this.getIconColorForType(type, severity),
            metadata,
        };
    }

    private static getAlertItemType(
        alert: UnifiedAlertResource,
    ): AlertItem['type'] {
        if (alert.source === 'police') {
            return 'police';
        }

        if (alert.source === 'transit') {
            return 'transit';
        }

        return this.normalizeType(alert.title);
    }

    private static getSeverity(
        alert: UnifiedAlertResource,
        type: AlertItem['type'],
    ): AlertItem['severity'] {
        if (alert.source === 'fire') {
            const alarmLevel = Number(
                (alert.meta as Record<string, unknown>)['alarm_level'] ?? 0,
            );
            if (alarmLevel > 1) return 'high';
            if (alarmLevel === 1) return 'medium';
            return 'low';
        }

        if (alert.source === 'police') {
            const title = alert.title.toUpperCase();
            if (title.includes('IN PROGRESS')) return 'high';
            if (title.includes('COLLISION')) return 'medium';
            return 'low';
        }

        if (type === 'hazard') return 'high';

        return 'low';
    }

    private static getDescriptionAndMetadata(
        alert: UnifiedAlertResource,
    ): Pick<AlertItem, 'description' | 'metadata'> {
        const meta = alert.meta as Record<string, unknown>;

        if (alert.source === 'fire') {
            const eventNum = String(
                (meta['event_num'] ?? alert.external_id) as string,
            );
            const alarmLevel = Number(meta['alarm_level'] ?? 0);
            const unitsDispatched =
                (meta['units_dispatched'] as string | null | undefined) ?? null;
            const beat = (meta['beat'] as string | null | undefined) ?? null;

            return {
                description: `Event #${eventNum}. Units: ${unitsDispatched || 'None'}. Beat: ${beat || 'N/A'}.`,
                metadata: {
                    eventNum,
                    alarmLevel,
                    unitsDispatched,
                    beat,
                },
            };
        }

        if (alert.source === 'police') {
            const objectId = String(
                (meta['object_id'] ?? alert.external_id) as string,
            );
            const division =
                (meta['division'] as string | null | undefined) ?? null;
            const callTypeCode =
                (meta['call_type_code'] as string | null | undefined) ?? null;

            const suffix = [
                division ? `Division: ${division}` : null,
                callTypeCode ? `Code: ${callTypeCode}` : null,
            ]
                .filter(Boolean)
                .join(' • ');

            return {
                description: suffix
                    ? `Call #${objectId}. ${suffix}.`
                    : `Call #${objectId}.`,
                metadata: {
                    eventNum: objectId,
                    alarmLevel: 0,
                    unitsDispatched: null,
                    beat: division,
                },
            };
        }

        return {
            description: alert.external_id
                ? `Alert #${alert.external_id}.`
                : 'Alert details unavailable.',
            metadata: undefined,
        };
    }

    /**
     * Normalizes disparate event types into core categories
     */
    private static normalizeType(eventType: string): AlertItem['type'] {
        const et = eventType.toUpperCase();
        if (et.includes('FIRE') || et.includes('STRUCTURE')) return 'fire';
        if (et.includes('POLICE') || et.includes('COLLISION')) return 'police';
        if (
            et.includes('GAS') ||
            et.includes('HAZARD') ||
            et.includes('CHEMICAL')
        )
            return 'hazard';
        if (
            et.includes('TRANSIT') ||
            et.includes('SUBWAY') ||
            et.includes('BUS')
        )
            return 'transit';
        if (et.includes('MEDICAL') || et.includes('AMBULANCE'))
            return 'medical';
        return 'fire'; // Default to fire category for unknown CAD alerts
    }

    /**
     * Returns appropriate icon based on type and specific event metadata
     */
    private static getIconForType(
        type: AlertItem['type'],
        eventType: string,
    ): string {
        const et = eventType.toUpperCase();
        if (et.includes('GAS')) return 'warning';
        if (et.includes('COLLISION')) return 'car_crash';

        switch (type) {
            case 'fire':
                return 'local_fire_department';
            case 'police':
                return 'shield';
            case 'hazard':
                return 'warning';
            case 'transit':
                return 'train';
            case 'medical':
                return 'medical_services';
            default:
                return 'info';
        }
    }

    /**
     * Returns Tailwind accent color class based on type and severity
     */
    private static getAccentColorForType(
        type: AlertItem['type'],
        severity: string,
    ): string {
        if (severity === 'high') return 'bg-[#d8464f]';

        switch (type) {
            case 'fire':
                return 'bg-[#e2751f]';
            case 'police':
                return 'bg-blue-500';
            case 'hazard':
                return 'bg-[#feb457]';
            case 'transit':
                return 'bg-[#3d584b]';
            case 'medical':
                return 'bg-[#d8464f]';
            default:
                return 'bg-gray-500';
        }
    }

    /**
     * Returns Tailwind text color class for the category icon
     */
    private static getIconColorForType(
        type: AlertItem['type'],
        severity: string,
    ): string {
        if (severity === 'high') return 'text-[#d8464f]';

        switch (type) {
            case 'fire':
                return 'text-[#e2751f]';
            case 'police':
                return 'text-blue-500';
            case 'hazard':
                return 'text-[#feb457]';
            case 'transit':
                return 'text-[#3d584b]';
            case 'medical':
                return 'text-[#d8464f]';
            default:
                return 'text-gray-500';
        }
    }

    /**
     * Core Search and Filter Engine
     * Filters a provided list of items based on user options.
     */
    static search(
        items: AlertItem[],
        options: AlertFilterOptions,
    ): AlertItem[] {
        let filtered = [...items];
        const { query, category, timeLimit, dateScope } = options;

        // 1. Sort by recency
        filtered = filtered.sort(
            (a, b) =>
                this.parseTimeAgo(a.timeAgo) - this.parseTimeAgo(b.timeAgo),
        );

        // 2. Category Filter
        if (category !== 'all') {
            filtered = filtered.filter((item) => item.type === category);
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
}

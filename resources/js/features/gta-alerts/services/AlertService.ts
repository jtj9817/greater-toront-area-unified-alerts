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
            timestamp: alert.timestamp,
            description,
            type: type,
            severity: severity,
            iconName: this.getIconForType(type, alert.title, alert),
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

        if (alert.source === 'go_transit') {
            return 'go_transit';
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

        if (alert.source === 'transit') {
            return this.getTransitSeverity(
                alert.meta as Record<string, unknown>,
            );
        }

        if (alert.source === 'go_transit') {
            return this.getGoTransitSeverity(
                alert.meta as Record<string, unknown>,
            );
        }

        if (type === 'hazard') return 'high';

        return 'low';
    }

    private static getTransitSeverity(
        meta: Record<string, unknown>,
    ): AlertItem['severity'] {
        const severity = String(meta['severity'] ?? '')
            .trim()
            .toUpperCase();
        const effect = String(meta['effect'] ?? '')
            .trim()
            .toUpperCase();

        if (severity === 'CRITICAL') {
            return 'high';
        }

        if (
            effect === 'SIGNIFICANT_DELAYS' ||
            effect === 'REDUCED_SERVICE' ||
            effect === 'DETOUR' ||
            effect === 'ACCESSIBILITY_ISSUE'
        ) {
            return 'medium';
        }

        return 'low';
    }

    private static getGoTransitSeverity(
        meta: Record<string, unknown>,
    ): AlertItem['severity'] {
        const subCategory = String(meta['sub_category'] ?? '')
            .trim()
            .toUpperCase();

        if (subCategory === 'BCANCEL') {
            return 'high';
        }

        if (subCategory === 'TDELAY' || subCategory === 'BDETOUR') {
            return 'medium';
        }

        // SAAG notifications (real-time delays) are medium
        const alertType = String(meta['alert_type'] ?? '')
            .trim()
            .toLowerCase();
        if (alertType === 'saag') {
            return 'medium';
        }

        return 'low';
    }

    private static getSourceName(
        source: UnifiedAlertResource['source'],
    ): string {
        switch (source) {
            case 'fire':
                return 'Toronto Fire Services';
            case 'police':
                return 'Toronto Police';
            case 'transit':
                return 'TTC Control';
            case 'go_transit':
                return 'GO Transit';
            default:
                return 'Unknown Source';
        }
    }

    private static getDescriptionAndMetadata(
        alert: UnifiedAlertResource,
    ): Pick<AlertItem, 'description' | 'metadata'> {
        const meta = alert.meta as Record<string, unknown>;
        const sourceName = this.getSourceName(alert.source);

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
                    source: sourceName,
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
                    source: sourceName,
                },
            };
        }

        if (alert.source === 'go_transit') {
            const messageBody =
                (meta['message_body'] as string | null | undefined) ??
                undefined;
            const serviceMode =
                (meta['service_mode'] as string | null | undefined) ??
                undefined;
            const corridorCode =
                (meta['corridor_code'] as string | null | undefined) ??
                undefined;
            const goDirection =
                (meta['direction'] as string | null | undefined) ?? undefined;
            const delayDuration =
                (meta['delay_duration'] as string | null | undefined) ??
                undefined;
            const subCategory =
                (meta['sub_category'] as string | null | undefined) ??
                undefined;

            const summaryParts = [
                serviceMode,
                corridorCode ? `Corridor: ${corridorCode}` : null,
                goDirection ? `Direction: ${goDirection}` : null,
                delayDuration && delayDuration !== '00:00:00'
                    ? `Delay: ${delayDuration}`
                    : null,
            ]
                .filter(Boolean)
                .join(' • ');

            const goDescription = [summaryParts || null, messageBody || null]
                .filter(Boolean)
                .join('. ');

            return {
                description:
                    goDescription || alert.title || 'GO Transit service alert.',
                metadata: {
                    eventNum: alert.external_id,
                    alarmLevel: 0,
                    unitsDispatched: null,
                    beat: null,
                    source: sourceName,
                    routeType: serviceMode,
                    route: corridorCode ?? undefined,
                    effect: subCategory ?? undefined,
                    direction: goDirection,
                    estimatedDelay: delayDuration ?? undefined,
                },
            };
        }

        if (alert.source === 'transit') {
            const routeType =
                (meta['route_type'] as string | null | undefined) ?? undefined;
            const route =
                (meta['route'] as string | null | undefined) ?? undefined;
            const effect =
                (meta['effect'] as string | null | undefined) ?? undefined;
            const direction =
                (meta['direction'] as string | null | undefined) ?? undefined;
            const stopStart =
                (meta['stop_start'] as string | null | undefined) ?? undefined;
            const stopEnd =
                (meta['stop_end'] as string | null | undefined) ?? undefined;
            const sourceFeed =
                (meta['source_feed'] as string | null | undefined) ?? undefined;
            const transitDescriptionRaw =
                (meta['description'] as string | null | undefined) ?? undefined;
            const estimatedDelay =
                (meta['estimated_delay'] as string | null | undefined) ??
                undefined;
            const shuttleInfo =
                (meta['shuttle_info'] as string | null | undefined) ??
                undefined;
            const segment =
                stopStart && stopEnd
                    ? `${stopStart} to ${stopEnd}`
                    : stopStart || stopEnd;

            const routeLabel = this.getTransitRouteLabel(routeType, route);
            const effectLabel = this.getTransitEffectLabel(effect);
            const summary = [
                routeLabel,
                effectLabel,
                segment ? `Segment: ${segment}` : null,
                direction ? `Direction: ${direction}` : null,
            ]
                .filter(Boolean)
                .join(' • ');

            const transitDescription = [
                summary || null,
                transitDescriptionRaw || null,
            ]
                .filter(Boolean)
                .join('. ');

            return {
                description:
                    transitDescription ||
                    alert.title ||
                    'Transit service alert.',
                metadata: {
                    eventNum: alert.external_id,
                    alarmLevel: 0,
                    unitsDispatched: null,
                    beat: null,
                    source: sourceName,
                    routeType,
                    route,
                    effect,
                    direction,
                    sourceFeed,
                    estimatedDelay,
                    shuttleInfo,
                },
            };
        }

        return {
            description: alert.external_id
                ? `Alert #${alert.external_id}.`
                : 'Alert details unavailable.',
            metadata: {
                eventNum: alert.external_id,
                alarmLevel: 0,
                unitsDispatched: null,
                beat: null,
                source: sourceName,
            },
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
        alert?: UnifiedAlertResource,
    ): string {
        const et = eventType.toUpperCase();
        if (et.includes('GAS')) return 'warning';
        if (et.includes('COLLISION')) return 'car_crash';

        if (type === 'go_transit') return 'train';

        if (type === 'transit' && alert?.source === 'transit') {
            const meta = alert.meta as Record<string, unknown>;
            const routeType = String(meta['route_type'] ?? '')
                .trim()
                .toLowerCase();
            const effect = String(meta['effect'] ?? '')
                .trim()
                .toUpperCase();

            if (routeType.includes('subway')) return 'directions_subway';
            if (routeType.includes('bus')) return 'directions_bus';
            if (routeType.includes('streetcar') || routeType.includes('tram')) {
                return 'tram';
            }
            if (
                routeType.includes('elevator') ||
                effect === 'ACCESSIBILITY_ISSUE'
            ) {
                return 'elevator';
            }

            return 'train';
        }

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

    private static getTransitRouteLabel(
        routeType?: string,
        route?: string,
    ): string | null {
        if (routeType && route) {
            return `${routeType} ${route}`;
        }

        if (routeType) {
            return routeType;
        }

        if (route) {
            return `Route ${route}`;
        }

        return null;
    }

    private static getTransitEffectLabel(effect?: string): string | null {
        if (!effect) {
            return null;
        }

        switch (effect.toUpperCase()) {
            case 'REDUCED_SERVICE':
                return 'Reduced service';
            case 'SIGNIFICANT_DELAYS':
                return 'Significant delays';
            case 'DETOUR':
                return 'Detour in effect';
            case 'ACCESSIBILITY_ISSUE':
                return 'Accessibility issue';
            default:
                return effect
                    .replace(/_/g, ' ')
                    .toLowerCase()
                    .replace(/\b\w/g, (char) => char.toUpperCase());
        }
    }

    /**
     * Returns Tailwind accent color class based on type and severity
     */
    private static getAccentColorForType(
        type: AlertItem['type'],
        severity: string,
    ): string {
        if (severity === 'high') return 'bg-[#e05560]';

        switch (type) {
            case 'fire':
                return 'bg-[#e07830]';
            case 'police':
                return 'bg-[#6890ff]';
            case 'hazard':
                return 'bg-[#f0b040]';
            case 'transit':
                return 'bg-[#a78bfa]';
            case 'go_transit':
                return 'bg-[#3b9a4f]';
            case 'medical':
                return 'bg-[#f472b6]';
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
        if (severity === 'high') return 'text-[#e05560]';

        switch (type) {
            case 'fire':
                return 'text-[#e07830]';
            case 'police':
                return 'text-[#6890ff]';
            case 'hazard':
                return 'text-[#f0b040]';
            case 'transit':
                return 'text-[#a78bfa]';
            case 'go_transit':
                return 'text-[#3b9a4f]';
            case 'medical':
                return 'text-[#f472b6]';
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

        // 1. Sort by recency (newest first)
        filtered = filtered.sort(
            (a, b) =>
                new Date(b.timestamp).getTime() -
                new Date(a.timestamp).getTime(),
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

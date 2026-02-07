import { formatTimeAgo } from '@/lib/utils';
import { fromResource } from '../domain/alerts';
import type { DomainAlert } from '../domain/alerts';
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
    private static categoryAliases: Record<
        string,
        ReadonlyArray<AlertItem['type']>
    > = {
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
        const domainAlert = fromResource(alert);
        if (!domainAlert) {
            return null;
        }

        return this.mapDomainAlertToAlertItem(domainAlert);
    }

    static mapUnifiedAlertsToAlertItems(
        alerts: UnifiedAlertResource[],
    ): AlertItem[] {
        const mapped: AlertItem[] = [];

        for (const alert of alerts) {
            const item = this.mapUnifiedAlertToAlertItem(alert);
            if (item) mapped.push(item);
        }

        return mapped;
    }

    private static mapDomainAlertToAlertItem(alert: DomainAlert): AlertItem {
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
        alert: DomainAlert,
    ): AlertItem['type'] {
        if (alert.kind === 'police') {
            return 'police';
        }

        if (alert.kind === 'transit') {
            return 'transit';
        }

        if (alert.kind === 'go_transit') {
            return 'go_transit';
        }

        return this.normalizeType(alert.title);
    }

    private static getSeverity(
        alert: DomainAlert,
        type: AlertItem['type'],
    ): AlertItem['severity'] {
        if (alert.kind === 'fire') {
            const alarmLevel = alert.meta.alarm_level;
            if (alarmLevel > 1) return 'high';
            if (alarmLevel === 1) return 'medium';
            return 'low';
        }

        if (alert.kind === 'police') {
            const title = alert.title.toUpperCase();
            if (title.includes('IN PROGRESS')) return 'high';
            if (title.includes('COLLISION')) return 'medium';
            return 'low';
        }

        if (alert.kind === 'transit') {
            return this.getTransitSeverity(alert.meta);
        }

        if (alert.kind === 'go_transit') {
            return this.getGoTransitSeverity(alert.meta);
        }

        if (type === 'hazard') return 'high';

        return 'low';
    }

    private static getTransitSeverity(
        meta: { severity: string | null; effect: string | null },
    ): AlertItem['severity'] {
        const severity = (meta.severity ?? '').trim().toUpperCase();
        const effect = (meta.effect ?? '').trim().toUpperCase();

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
        meta: { sub_category: string | null; alert_type: string | null },
    ): AlertItem['severity'] {
        const subCategory = (meta.sub_category ?? '').trim().toUpperCase();

        if (subCategory === 'BCANCEL') {
            return 'high';
        }

        if (subCategory === 'TDELAY' || subCategory === 'BDETOUR') {
            return 'medium';
        }

        // SAAG notifications (real-time delays) are medium
        const alertType = (meta.alert_type ?? '').trim().toLowerCase();
        if (alertType === 'saag') {
            return 'medium';
        }

        return 'low';
    }

    private static getSourceName(
        source: DomainAlert['kind'],
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
        alert: DomainAlert,
    ): Pick<AlertItem, 'description' | 'metadata'> {
        const sourceName = this.getSourceName(alert.kind);

        if (alert.kind === 'fire') {
            const eventNum = alert.meta.event_num || alert.externalId;
            const alarmLevel = alert.meta.alarm_level;
            const unitsDispatched = alert.meta.units_dispatched;
            const beat = alert.meta.beat;

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

        if (alert.kind === 'police') {
            const objectId = String(alert.meta.object_id || alert.externalId);
            const division = alert.meta.division ?? null;
            const callTypeCode = alert.meta.call_type_code ?? null;

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

        if (alert.kind === 'go_transit') {
            const messageBody = alert.meta.message_body ?? undefined;
            const serviceMode = alert.meta.service_mode ?? undefined;
            const corridorCode = alert.meta.corridor_code ?? undefined;
            const goDirection = alert.meta.direction ?? undefined;
            const delayDuration = alert.meta.delay_duration ?? undefined;
            const subCategory = alert.meta.sub_category ?? undefined;

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
                    eventNum: alert.externalId,
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

        if (alert.kind === 'transit') {
            const routeType = alert.meta.route_type ?? undefined;
            const route = alert.meta.route ?? undefined;
            const effect = alert.meta.effect ?? undefined;
            const direction = alert.meta.direction ?? undefined;
            const stopStart = alert.meta.stop_start ?? undefined;
            const stopEnd = alert.meta.stop_end ?? undefined;
            const sourceFeed = alert.meta.source_feed ?? undefined;
            const transitDescriptionRaw = alert.meta.description ?? undefined;
            const estimatedDelay = undefined;
            const shuttleInfo = undefined;
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
                    eventNum: alert.externalId,
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
            description: 'Alert details unavailable.',
            metadata: {
                eventNum: 'unknown',
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
        alert?: DomainAlert,
    ): string {
        const et = eventType.toUpperCase();
        if (et.includes('GAS')) return 'warning';
        if (et.includes('COLLISION')) return 'car_crash';

        if (type === 'go_transit') return 'train';

        if (type === 'transit' && alert?.kind === 'transit') {
            const routeType = (alert.meta.route_type ?? '')
                .trim()
                .toLowerCase();
            const effect = (alert.meta.effect ?? '').trim().toUpperCase();

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
}

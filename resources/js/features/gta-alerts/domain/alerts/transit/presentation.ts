import type { AlertPresentation } from '../view/types';
import type { GoTransitAlert } from './go/schema';
import type { TtcTransitAlert } from './ttc/schema';

export function deriveTtcSeverity(
    meta: TtcTransitAlert['meta'],
): AlertPresentation['severity'] {
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

export function deriveGoTransitSeverity(
    meta: GoTransitAlert['meta'],
): AlertPresentation['severity'] {
    const subCategory = (meta.sub_category ?? '').trim().toUpperCase();

    if (subCategory === 'BCANCEL') {
        return 'high';
    }

    if (subCategory === 'TDELAY' || subCategory === 'BDETOUR') {
        return 'medium';
    }

    const alertType = (meta.alert_type ?? '').trim().toLowerCase();
    if (alertType === 'saag') {
        return 'medium';
    }

    return 'low';
}

export function deriveTtcIconName(alert: TtcTransitAlert): string {
    const routeType = (alert.meta.route_type ?? '').trim().toLowerCase();
    const effect = (alert.meta.effect ?? '').trim().toUpperCase();

    if (routeType.includes('subway')) return 'directions_subway';
    if (routeType.includes('bus')) return 'directions_bus';
    if (routeType.includes('streetcar') || routeType.includes('tram')) {
        return 'tram';
    }
    if (routeType.includes('elevator') || effect === 'ACCESSIBILITY_ISSUE') {
        return 'elevator';
    }

    return 'train';
}

export function getTransitRouteLabel(
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

export function getTransitEffectLabel(effect?: string): string | null {
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

export function buildTtcDescriptionAndMetadata(
    alert: TtcTransitAlert,
): Pick<AlertPresentation, 'description' | 'metadata'> {
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

    const routeLabel = getTransitRouteLabel(routeType, route);
    const effectLabel = getTransitEffectLabel(effect);
    const summary = [
        routeLabel,
        effectLabel,
        segment ? `Segment: ${segment}` : null,
        direction ? `Direction: ${direction}` : null,
    ]
        .filter(Boolean)
        .join(' • ');

    const transitDescription = [summary || null, transitDescriptionRaw || null]
        .filter(Boolean)
        .join('. ');

    return {
        description:
            transitDescription || alert.title || 'Transit service alert.',
        metadata: {
            eventNum: alert.externalId,
            alarmLevel: 0,
            unitsDispatched: null,
            beat: null,
            source: 'TTC Control',
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

export function buildGoTransitDescriptionAndMetadata(
    alert: GoTransitAlert,
): Pick<AlertPresentation, 'description' | 'metadata'> {
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
            source: 'GO Transit',
            routeType: serviceMode,
            route: corridorCode ?? undefined,
            effect: subCategory ?? undefined,
            direction: goDirection,
            estimatedDelay: delayDuration ?? undefined,
        },
    };
}

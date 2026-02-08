import type { AlertPresentation } from '../view/types';
import type { FireAlert } from './schema';

/**
 * Normalizes fire CAD event titles into UI-facing presentation categories.
 * This remains presentation-level and does not alter DomainAlert discriminators.
 */
export function deriveFirePresentationType(
    title: string,
): AlertPresentation['type'] {
    const normalizedTitle = title.toUpperCase();

    if (
        normalizedTitle.includes('FIRE') ||
        normalizedTitle.includes('STRUCTURE')
    ) {
        return 'fire';
    }

    if (
        normalizedTitle.includes('POLICE') ||
        normalizedTitle.includes('COLLISION')
    ) {
        return 'police';
    }

    if (
        normalizedTitle.includes('GAS') ||
        normalizedTitle.includes('HAZARD') ||
        normalizedTitle.includes('CHEMICAL')
    ) {
        return 'hazard';
    }

    if (
        normalizedTitle.includes('TRANSIT') ||
        normalizedTitle.includes('SUBWAY') ||
        normalizedTitle.includes('BUS')
    ) {
        return 'transit';
    }

    if (
        normalizedTitle.includes('MEDICAL') ||
        normalizedTitle.includes('AMBULANCE')
    ) {
        return 'medical';
    }

    return 'fire';
}

export function deriveFireSeverity(
    alert: FireAlert,
): AlertPresentation['severity'] {
    const alarmLevel = alert.meta.alarm_level;
    if (alarmLevel > 1) return 'high';
    if (alarmLevel === 1) return 'medium';
    return 'low';
}

export function buildFireDescriptionAndMetadata(
    alert: FireAlert,
): Pick<AlertPresentation, 'description' | 'metadata'> {
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
            source: 'Toronto Fire Services',
        },
    };
}

import { deriveTtcIconName } from '../transit/presentation';
import type { DomainAlert } from '../types';
import type { AlertPresentation } from './types';

export function deriveIconName(
    alert: DomainAlert,
    type: AlertPresentation['type'],
): string {
    const normalizedTitle = alert.title.toUpperCase();
    if (normalizedTitle.includes('GAS')) return 'warning';
    if (normalizedTitle.includes('COLLISION')) return 'car_crash';

    if (type === 'go_transit') return 'train';

    if (type === 'transit' && alert.kind === 'transit') {
        return deriveTtcIconName(alert);
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

export function deriveAccentColor(
    type: AlertPresentation['type'],
    severity: AlertPresentation['severity'],
): string {
    void type;
    return severity === 'high' ? 'bg-critical' : 'bg-primary';
}

export function deriveIconColor(
    type: AlertPresentation['type'],
    severity: AlertPresentation['severity'],
): string {
    void type;
    void severity;
    return 'text-primary';
}

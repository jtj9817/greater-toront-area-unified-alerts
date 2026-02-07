import type { AlertItem } from '../../../types';
import type { DomainAlert } from '../types';
import { deriveTtcIconName } from '../transit/presentation';

export function deriveIconName(
    alert: DomainAlert,
    type: AlertItem['type'],
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
    type: AlertItem['type'],
    severity: AlertItem['severity'],
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

export function deriveIconColor(
    type: AlertItem['type'],
    severity: AlertItem['severity'],
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

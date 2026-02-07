import { formatTimeAgo } from '@/lib/utils';

import type { AlertItem } from '../../../types';
import {
    buildFireDescriptionAndMetadata,
    deriveFirePresentationType,
    deriveFireSeverity,
} from '../fire/presentation';
import {
    buildPoliceDescriptionAndMetadata,
    derivePoliceSeverity,
} from '../police/presentation';
import {
    buildGoTransitDescriptionAndMetadata,
    buildTtcDescriptionAndMetadata,
    deriveGoTransitSeverity,
    deriveTtcSeverity,
} from '../transit/presentation';
import type { DomainAlert } from '../types';

import { deriveAccentColor, deriveIconColor, deriveIconName } from './presentationStyles';

export function mapDomainAlertToAlertItem(alert: DomainAlert): AlertItem {
    let type: AlertItem['type'];
    let severity: AlertItem['severity'];
    let details: Pick<AlertItem, 'description' | 'metadata'>;

    switch (alert.kind) {
        case 'fire': {
            type = deriveFirePresentationType(alert.title);
            severity = deriveFireSeverity(alert);
            details = buildFireDescriptionAndMetadata(alert);
            break;
        }
        case 'police': {
            type = 'police';
            severity = derivePoliceSeverity(alert);
            details = buildPoliceDescriptionAndMetadata(alert);
            break;
        }
        case 'transit': {
            type = 'transit';
            severity = deriveTtcSeverity(alert.meta);
            details = buildTtcDescriptionAndMetadata(alert);
            break;
        }
        case 'go_transit': {
            type = 'go_transit';
            severity = deriveGoTransitSeverity(alert.meta);
            details = buildGoTransitDescriptionAndMetadata(alert);
            break;
        }
    }

    return {
        id: alert.id,
        title: alert.title,
        location: alert.location?.name ?? 'Unknown location',
        timeAgo: formatTimeAgo(alert.timestamp),
        timestamp: alert.timestamp,
        description: details.description,
        type,
        severity,
        iconName: deriveIconName(alert, type),
        accentColor: deriveAccentColor(type, severity),
        iconColor: deriveIconColor(type, severity),
        metadata: details.metadata,
    };
}

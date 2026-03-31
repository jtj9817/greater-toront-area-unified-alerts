import { formatTimeAgo } from '@/lib/utils';

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
    buildMiwayDescriptionAndMetadata,
    buildTtcDescriptionAndMetadata,
    deriveGoTransitSeverity,
    deriveMiwaySeverity,
    deriveTtcSeverity,
} from '../transit/presentation';
import type { DomainAlert } from '../types';
import {
    deriveAccentColor,
    deriveIconColor,
    deriveIconName,
} from './presentationStyles';
import type { AlertPresentation, AlertPresentationCoordinates } from './types';

function normalizeCoordinates(
    location: DomainAlert['location'],
): AlertPresentationCoordinates | null {
    const { lat, lng } = location ?? {};

    if (
        typeof lat !== 'number' ||
        !Number.isFinite(lat) ||
        typeof lng !== 'number' ||
        !Number.isFinite(lng)
    ) {
        return null;
    }

    if (lat < 40 || lat > 50 || lng < -90 || lng > -70) {
        return null;
    }

    return { lat, lng };
}

export function mapDomainAlertToPresentation(
    alert: DomainAlert,
): AlertPresentation {
    let type: AlertPresentation['type'];
    let severity: AlertPresentation['severity'];
    let details: Pick<AlertPresentation, 'description' | 'metadata'>;

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
        case 'miway': {
            type = 'transit';
            severity = deriveMiwaySeverity(alert.meta);
            details = buildMiwayDescriptionAndMetadata(alert);
            break;
        }
    }

    return {
        id: alert.id,
        title: alert.title,
        location: alert.location?.name?.trim() || 'Unknown location',
        locationCoords: normalizeCoordinates(alert.location),
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

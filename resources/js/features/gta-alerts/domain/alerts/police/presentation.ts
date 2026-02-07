import type { AlertItem } from '../../../types';

import type { PoliceAlert } from './schema';

export function derivePoliceSeverity(alert: PoliceAlert): AlertItem['severity'] {
    const title = alert.title.toUpperCase();

    if (title.includes('IN PROGRESS')) {
        return 'high';
    }

    if (title.includes('COLLISION')) {
        return 'medium';
    }

    return 'low';
}

export function buildPoliceDescriptionAndMetadata(
    alert: PoliceAlert,
): Pick<AlertItem, 'description' | 'metadata'> {
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
        description: suffix ? `Call #${objectId}. ${suffix}.` : `Call #${objectId}.`,
        metadata: {
            eventNum: objectId,
            alarmLevel: 0,
            unitsDispatched: null,
            beat: division,
            source: 'Toronto Police',
        },
    };
}

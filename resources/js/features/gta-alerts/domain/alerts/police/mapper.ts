import { buildBaseDomainInput } from '../mapperUtils';
import type { UnifiedAlertResourceParsed } from '../resource';

import { PoliceAlertSchema } from './schema';
import type { PoliceAlert } from './schema';

export function mapPoliceAlert(
    resource: UnifiedAlertResourceParsed,
): PoliceAlert | null {
    if (resource.source !== 'police') {
        console.warn(
            `[DomainAlert] Police mapper received non-police resource (${resource.id}):`,
            resource.source,
        );
        return null;
    }

    const result = PoliceAlertSchema.safeParse({
        ...buildBaseDomainInput(resource),
        kind: 'police',
    });

    if (!result.success) {
        console.warn(
            `[DomainAlert] Invalid police alert (${resource.id}):`,
            result.error.issues,
        );
        return null;
    }

    return result.data;
}

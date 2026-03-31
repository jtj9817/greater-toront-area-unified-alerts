import { buildBaseDomainInput } from '../mapperUtils';
import type { UnifiedAlertResourceParsed } from '../resource';

import { MiwayAlertSchema } from './schema';
import type { MiwayAlert } from './schema';

export function mapMiwayAlert(
    resource: UnifiedAlertResourceParsed,
): MiwayAlert | null {
    if (resource.source !== 'miway') {
        console.warn(
            `[DomainAlert] MiWay mapper received non-miway resource (${resource.id}):`,
            resource.source,
        );
        return null;
    }

    const result = MiwayAlertSchema.safeParse({
        ...buildBaseDomainInput(resource),
        kind: 'miway',
    });

    if (!result.success) {
        console.warn(
            `[DomainAlert] Invalid MiWay alert (${resource.id}):`,
            result.error.issues,
        );
        return null;
    }

    return result.data;
}

import { buildBaseDomainInput } from '../../mapperUtils';
import type { UnifiedAlertResourceParsed } from '../../resource';

import { DrtAlertSchema } from './schema';
import type { DrtAlert } from './schema';

export function mapDrtAlert(
    resource: UnifiedAlertResourceParsed,
): DrtAlert | null {
    if (resource.source !== 'drt') {
        console.warn(
            `[DomainAlert] DRT mapper received non-drt resource (${resource.id}):`,
            resource.source,
        );
        return null;
    }

    const result = DrtAlertSchema.safeParse({
        ...buildBaseDomainInput(resource),
        kind: 'drt',
    });

    if (!result.success) {
        console.warn(
            `[DomainAlert] Invalid DRT alert (${resource.id}):`,
            result.error.issues,
        );
        return null;
    }

    return result.data;
}

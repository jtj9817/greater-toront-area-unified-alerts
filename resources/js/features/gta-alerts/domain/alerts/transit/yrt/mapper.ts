import { buildBaseDomainInput } from '../../mapperUtils';
import type { UnifiedAlertResourceParsed } from '../../resource';

import { YrtAlertSchema } from './schema';
import type { YrtAlert } from './schema';

export function mapYrtAlert(
    resource: UnifiedAlertResourceParsed,
): YrtAlert | null {
    if (resource.source !== 'yrt') {
        console.warn(
            `[DomainAlert] YRT mapper received non-yrt resource (${resource.id}):`,
            resource.source,
        );
        return null;
    }

    const result = YrtAlertSchema.safeParse({
        ...buildBaseDomainInput(resource),
        kind: 'yrt',
    });

    if (!result.success) {
        console.warn(
            `[DomainAlert] Invalid YRT alert (${resource.id}):`,
            result.error.issues,
        );
        return null;
    }

    return result.data;
}

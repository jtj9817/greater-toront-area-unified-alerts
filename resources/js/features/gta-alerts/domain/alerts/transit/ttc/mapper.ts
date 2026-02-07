import { buildBaseDomainInput } from '../../mapperUtils';
import type { UnifiedAlertResourceParsed } from '../../resource';

import { TtcTransitAlertSchema } from './schema';
import type { TtcTransitAlert } from './schema';

export function mapTtcTransitAlert(
    resource: UnifiedAlertResourceParsed,
): TtcTransitAlert | null {
    if (resource.source !== 'transit') {
        console.warn(
            `[DomainAlert] TTC mapper received non-transit resource (${resource.id}):`,
            resource.source,
        );
        return null;
    }

    const result = TtcTransitAlertSchema.safeParse({
        ...buildBaseDomainInput(resource),
        kind: 'transit',
    });

    if (!result.success) {
        console.warn(
            `[DomainAlert] Invalid transit alert (${resource.id}):`,
            result.error.issues,
        );
        return null;
    }

    return result.data;
}

import { buildBaseDomainInput } from '../../mapperUtils';
import type { UnifiedAlertResourceParsed } from '../../resource';

import { GoTransitAlertSchema } from './schema';
import type { GoTransitAlert } from './schema';

export function mapGoTransitAlert(
    resource: UnifiedAlertResourceParsed,
): GoTransitAlert | null {
    if (resource.source !== 'go_transit') {
        console.warn(
            `[DomainAlert] GO Transit mapper received non-go_transit resource (${resource.id}):`,
            resource.source,
        );
        return null;
    }

    const result = GoTransitAlertSchema.safeParse({
        ...buildBaseDomainInput(resource),
        kind: 'go_transit',
    });

    if (!result.success) {
        console.warn(
            `[DomainAlert] Invalid GO Transit alert (${resource.id}):`,
            result.error.issues,
        );
        return null;
    }

    return result.data;
}

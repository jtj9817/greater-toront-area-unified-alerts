import { buildBaseDomainInput } from '../mapperUtils';
import type { UnifiedAlertResourceParsed } from '../resource';

import { FireAlertSchema } from './schema';
import type { FireAlert } from './schema';

export function mapFireAlert(
    resource: UnifiedAlertResourceParsed,
): FireAlert | null {
    if (resource.source !== 'fire') {
        console.warn(
            `[DomainAlert] Fire mapper received non-fire resource (${resource.id}):`,
            resource.source,
        );
        return null;
    }

    const result = FireAlertSchema.safeParse({
        ...buildBaseDomainInput(resource),
        kind: 'fire',
    });

    if (!result.success) {
        console.warn(
            `[DomainAlert] Invalid fire alert (${resource.id}):`,
            result.error.issues,
        );
        return null;
    }

    return result.data;
}

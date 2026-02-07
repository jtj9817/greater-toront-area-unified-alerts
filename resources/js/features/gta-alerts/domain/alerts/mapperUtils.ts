import type { UnifiedAlertResourceParsed } from './resource';

export function buildBaseDomainInput(resource: UnifiedAlertResourceParsed): {
    id: string;
    externalId: string;
    isActive: boolean;
    timestamp: string;
    title: string;
    location: UnifiedAlertResourceParsed['location'];
    meta: UnifiedAlertResourceParsed['meta'];
} {
    return {
        id: resource.id,
        externalId: resource.external_id,
        isActive: resource.is_active,
        timestamp: resource.timestamp,
        title: resource.title,
        location: resource.location,
        meta: resource.meta,
    };
}


// Domain Alerts - barrel export
export { fromResource } from './fromResource';
export { UnifiedAlertResourceSchema } from './resource';
export type { UnifiedAlertResourceParsed } from './resource';
export type { DomainAlert, AlertKind } from './types';

// Source-specific schemas and types
export { FireAlertSchema, FireMetaSchema } from './fire/schema';
export type { FireAlert, FireMeta } from './fire/schema';

export { PoliceAlertSchema, PoliceMetaSchema } from './police/schema';
export type { PoliceAlert, PoliceMeta } from './police/schema';

export { TransitAlertSchema, TransitMetaSchema } from './transit/schema';
export type { TransitAlert, TransitMeta } from './transit/schema';

export { GoTransitAlertSchema, GoTransitMetaSchema } from './go-transit/schema';
export type { GoTransitAlert, GoTransitMeta } from './go-transit/schema';

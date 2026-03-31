// Domain Alerts - barrel export
export { fromResource } from './fromResource';
export { mapDomainAlertToPresentation } from './view';
export { UnifiedAlertResourceSchema } from './resource';
export type {
    UnifiedAlertResource,
    UnifiedAlertResourceParsed,
} from './resource';
export type { DomainAlert, AlertKind } from './types';
export type {
    AlertPresentation,
    AlertPresentationMetadata,
    AlertPresentationSeverity,
    AlertPresentationType,
} from './view';

// Source-specific schemas and types
export { FireAlertSchema, FireMetaSchema } from './fire/schema';
export type { FireAlert, FireMeta } from './fire/schema';

export { PoliceAlertSchema, PoliceMetaSchema } from './police/schema';
export type { PoliceAlert, PoliceMeta } from './police/schema';

// Transit (base)
export {
    BaseTransitAlertSchema,
    BaseTransitMetaSchema,
} from './transit/schema';
export type { BaseTransitMeta } from './transit/schema';

// Transit - TTC
export {
    TtcTransitAlertSchema,
    TtcTransitMetaSchema,
} from './transit/ttc/schema';
export type { TtcTransitAlert, TtcTransitMeta } from './transit/ttc/schema';

// Transit - GO (Metrolinx)
export { GoTransitAlertSchema, GoTransitMetaSchema } from './transit/go/schema';
export type { GoTransitAlert, GoTransitMeta } from './transit/go/schema';

// MiWay
export { MiwayAlertSchema, MiwayMetaSchema } from './miway/schema';
export type { MiwayAlert, MiwayMeta } from './miway/schema';

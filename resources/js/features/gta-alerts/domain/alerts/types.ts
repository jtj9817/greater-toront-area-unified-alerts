import type { FireAlert } from './fire/schema';
import type { MiwayAlert } from './miway/schema';
import type { PoliceAlert } from './police/schema';
import type { GoTransitAlert } from './transit/go/schema';
import type { TtcTransitAlert } from './transit/ttc/schema';

export type DomainAlert =
    | FireAlert
    | PoliceAlert
    | TtcTransitAlert
    | GoTransitAlert
    | MiwayAlert;

export type AlertKind = DomainAlert['kind'];

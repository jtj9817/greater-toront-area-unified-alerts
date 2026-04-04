import type { FireAlert } from './fire/schema';
import type { MiwayAlert } from './miway/schema';
import type { PoliceAlert } from './police/schema';
import type { DrtAlert } from './transit/drt/schema';
import type { GoTransitAlert } from './transit/go/schema';
import type { TtcTransitAlert } from './transit/ttc/schema';
import type { YrtAlert } from './transit/yrt/schema';

export type DomainAlert =
    | FireAlert
    | PoliceAlert
    | TtcTransitAlert
    | GoTransitAlert
    | MiwayAlert
    | YrtAlert
    | DrtAlert;

export type AlertKind = DomainAlert['kind'];

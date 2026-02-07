import type { FireAlert } from './fire/schema';
import type { PoliceAlert } from './police/schema';
import type { GoTransitAlert } from './transit/go/schema';
import type { TtcTransitAlert } from './transit/ttc/schema';

/**
 * Discriminated union of all alert domain types.
 * Discriminator field: `kind`
 *
 * Usage:
 *   switch (alert.kind) {
 *     case 'fire':       // alert is FireAlert
 *     case 'police':     // alert is PoliceAlert
 *     case 'transit':    // alert is TtcTransitAlert
 *     case 'go_transit': // alert is GoTransitAlert
 *   }
 */
export type DomainAlert =
    | FireAlert
    | PoliceAlert
    | TtcTransitAlert
    | GoTransitAlert;

/**
 * The set of valid alert kind discriminator values.
 */
export type AlertKind = DomainAlert['kind'];

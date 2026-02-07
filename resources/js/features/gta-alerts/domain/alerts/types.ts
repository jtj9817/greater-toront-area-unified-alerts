import type { FireAlert } from './fire/schema';
import type { GoTransitAlert } from './go-transit/schema';
import type { PoliceAlert } from './police/schema';
import type { TransitAlert } from './transit/schema';

/**
 * Discriminated union of all alert domain types.
 * Discriminator field: `kind`
 *
 * Usage:
 *   switch (alert.kind) {
 *     case 'fire':    // alert is FireAlert
 *     case 'police':  // alert is PoliceAlert
 *     case 'transit': // alert is TransitAlert
 *     case 'go_transit': // alert is GoTransitAlert
 *   }
 */
export type DomainAlert =
    | FireAlert
    | PoliceAlert
    | TransitAlert
    | GoTransitAlert;

/**
 * The set of valid alert kind discriminator values.
 */
export type AlertKind = DomainAlert['kind'];

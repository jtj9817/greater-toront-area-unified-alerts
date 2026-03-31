import { mapFireAlert } from './fire/mapper';
import { mapMiwayAlert } from './miway/mapper';
import { mapPoliceAlert } from './police/mapper';
import { UnifiedAlertResourceSchema } from './resource';
import { mapGoTransitAlert } from './transit/go/mapper';
import { mapTtcTransitAlert } from './transit/ttc/mapper';
import type { DomainAlert } from './types';

/**
 * Canonical entry point for mapping a raw API resource into a typed DomainAlert.
 *
 * Uses Zod `safeParse` at the boundary:
 * - First validates the raw resource envelope shape.
 * - Then validates the source-specific schema (including typed meta).
 * - Returns `null` and logs a warning for any invalid item.
 *
 * This ensures the UI never processes malformed data.
 */
export function fromResource(resource: unknown): DomainAlert | null {
    // Step 1: Validate the transport envelope
    const envelopeResult = UnifiedAlertResourceSchema.safeParse(resource);
    if (!envelopeResult.success) {
        console.warn(
            '[DomainAlert] Invalid resource envelope:',
            envelopeResult.error.issues,
        );
        return null;
    }

    const validated = envelopeResult.data;

    // Step 2: Parse with source-specific schema
    switch (validated.source) {
        case 'fire': {
            return mapFireAlert(validated);
        }
        case 'police': {
            return mapPoliceAlert(validated);
        }
        case 'transit': {
            return mapTtcTransitAlert(validated);
        }
        case 'go_transit': {
            return mapGoTransitAlert(validated);
        }
        case 'miway': {
            return mapMiwayAlert(validated);
        }
        default: {
            console.warn(
                `[DomainAlert] Unknown source "${(validated as { source: string }).source}" for alert ${validated.id}`,
            );
            return null;
        }
    }
}

import { FireAlertSchema } from './fire/schema';
import { GoTransitAlertSchema } from './go-transit/schema';
import { PoliceAlertSchema } from './police/schema';
import { UnifiedAlertResourceSchema } from './resource';
import { TransitAlertSchema } from './transit/schema';
import type { DomainAlert } from './types';

/**
 * Maps a raw UnifiedAlertResource (from the API) into a source-specific base
 * object suitable for Zod schema validation.
 */
function buildDomainInput(
    resource: Record<string, unknown>,
): Record<string, unknown> {
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
    const input = {
        ...buildDomainInput(validated as unknown as Record<string, unknown>),
        kind: validated.source,
    };

    // Step 2: Parse with source-specific schema
    switch (validated.source) {
        case 'fire': {
            const result = FireAlertSchema.safeParse(input);
            if (!result.success) {
                console.warn(
                    `[DomainAlert] Invalid fire alert (${validated.id}):`,
                    result.error.issues,
                );
                return null;
            }
            return result.data;
        }
        case 'police': {
            const result = PoliceAlertSchema.safeParse(input);
            if (!result.success) {
                console.warn(
                    `[DomainAlert] Invalid police alert (${validated.id}):`,
                    result.error.issues,
                );
                return null;
            }
            return result.data;
        }
        case 'transit': {
            const result = TransitAlertSchema.safeParse(input);
            if (!result.success) {
                console.warn(
                    `[DomainAlert] Invalid transit alert (${validated.id}):`,
                    result.error.issues,
                );
                return null;
            }
            return result.data;
        }
        case 'go_transit': {
            const result = GoTransitAlertSchema.safeParse(input);
            if (!result.success) {
                console.warn(
                    `[DomainAlert] Invalid GO Transit alert (${validated.id}):`,
                    result.error.issues,
                );
                return null;
            }
            return result.data;
        }
        default: {
            console.warn(
                `[DomainAlert] Unknown source "${(validated as { source: string }).source}" for alert ${validated.id}`,
            );
            return null;
        }
    }
}

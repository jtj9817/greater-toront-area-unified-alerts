import {
    fromResource,
    type DomainAlert,
    type UnifiedAlertResource,
} from '../domain/alerts';

/**
 * AlertService
 * Encapsulates all data operations for the Alerts dashboard.
 * Refactored to be data-driven and support live backend resources.
 */
export class AlertService {
    static mapUnifiedAlertToDomainAlert(
        alert: UnifiedAlertResource,
    ): DomainAlert | null {
        return fromResource(alert);
    }

    static mapUnifiedAlertsToDomainAlerts(
        alerts: UnifiedAlertResource[],
    ): DomainAlert[] {
        const mapped: DomainAlert[] = [];

        for (const alert of alerts) {
            const item = this.mapUnifiedAlertToDomainAlert(alert);
            if (item) mapped.push(item);
        }

        return mapped;
    }
}

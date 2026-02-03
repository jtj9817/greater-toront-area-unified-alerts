# Implementation Plan: Unified Alerts Architecture

## Phase 1: Test Data Preparation & DTOs [checkpoint: 670ffd3]
- [x] (0cbec96) Task: Refine Factories & Create Test Seeders.
    - [x] Add `inactive()` state to `FireIncidentFactory` (similar to `PoliceCallFactory`).
    - [x] Create `database/seeders/UnifiedAlertsTestSeeder.php` to generate mixed datasets (active/cleared, mixed timestamps) for integration testing.
- [x] (0cbec96) Task: Define Unified Alert DTOs.
    - [x] Create `App\Services\Alerts\DTOs\AlertLocation.php`.
    - [x] Create `App\Services\Alerts\DTOs\UnifiedAlert.php`.
    - [x] Write unit tests for DTO instantiation and data integrity.
- [x] (0cbec96) Task: Define Provider Contract.
    - [x] Create `App\Services\Alerts\Contracts\AlertSelectProvider.php` interface.
- [x] (670ffd3) Task: Conductor - User Manual Verification 'Phase 1: Test Foundations' (Protocol in workflow.md)

## Phase 2: Provider Implementations
- [x] Task: Implement Fire Alert Provider.
    - [x] Write tests for `FireAlertSelectProvider` ensuring correct column mapping (use `FireIncidentFactory`).
    - [x] Implement `App\Services\Alerts\Providers\FireAlertSelectProvider`.
- [x] Task: Implement Police Alert Provider.
    - [x] Write tests for `PoliceAlertSelectProvider` ensuring correct column mapping (use `PoliceCallFactory`).
    - [x] Implement `App\Services\Alerts\Providers\PoliceAlertSelectProvider`.
- [x] Task: Implement Transit Alert Provider (Placeholder).
    - [x] Write tests for `TransitAlertSelectProvider` ensuring it handles empty/placeholder state.
    - [x] Implement `App\Services\Alerts\Providers\TransitAlertSelectProvider`.
- [x] (1c39470) Task: Conductor - User Manual Verification 'Phase 2: Provider Implementations' (Protocol in workflow.md)

## Phase 3: Unified Querying & API Transformation [checkpoint: 7bdd684]
- [x] (c444f5a) Task: Implement Unified Alerts Query Logic.
    - [x] Write tests for `UnifiedAlertsQuery` covering UNION logic, pagination, and status filtering (use `UnifiedAlertsTestSeeder`).
    - [x] Implement `App\Services\Alerts\UnifiedAlertsQuery`.
- [x] (4369acb) Task: Create API Resource for Unified Alerts.
    - [x] Write tests for `UnifiedAlertResource` ensuring DTO-to-JSON mapping.
    - [x] Create `App\Http\Resources\UnifiedAlertResource`.
- [x] (7bdd684) Task: Conductor - User Manual Verification 'Phase 3: Unified Querying' (Protocol in workflow.md)

## Phase 4: Controller & Frontend Integration (The Hard Switch)
- [x] (97ceba3) Task: Refactor GtaAlertsController.
    - [x] Write tests for `GtaAlertsController` verifying the replacement of `incidents` with `alerts`.
    - [x] Update `GtaAlertsController` to use `UnifiedAlertsQuery` and `UnifiedAlertResource`.
- [x] (97ceba3) Task: Update Frontend Types & Service.
    - [x] Update `resources/js/features/gta-alerts/types.ts` with `UnifiedAlertResource`.
    - [x] Write unit tests for `AlertService.mapUnifiedAlertToAlertItem()`.
    - [x] Implement `mapUnifiedAlertToAlertItem` in `AlertService.ts`.
- [x] (97ceba3) Task: Update UI Components.
    - [x] Write component tests for `FeedView` and `AlertCard` using unified alert data.
    - [x] Refactor components to consume the `alerts` prop and handle the new data structure.
- [x] Task: Conductor - User Manual Verification 'Phase 4: Frontend Integration' (Protocol in workflow.md)

## Phase 5: Quality Gate & Finalization
- [ ] Task: Final Coverage & Audit.
    - [ ] Run `sail artisan test --coverage` and verify >90% on all new/modified files.
    - [ ] Run `sail artisan pint` and `pnpm lint` to ensure code style compliance.
    - [ ] Perform a final security audit with `composer audit` and `pnpm audit`.
- [ ] Task: Conductor - User Manual Verification 'Phase 5: Quality Gate' (Protocol in workflow.md)

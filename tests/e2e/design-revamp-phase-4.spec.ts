/**
 * Phase 4 verification spec for the UI Design Revamp (Prototype Two).
 *
 * This file is a source-of-truth checklist for Playwright-based verification
 * against the local runtime at http://localhost:8080/.
 *
 * Execution reference:
 * - Playwright MCP run date: 2026-03-05
 * - Artifacts: artifacts/playwright/design-revamp-phase4-20260305-*
 */

export const designRevampPhase4Spec = {
    baseUrl: 'http://localhost:8080/',
    runtimeAssumptions: {
        engine: 'Playwright MCP browser session',
        authState: 'anonymous/local session (no explicit login bootstrap)',
        desktopViewport: '1440x900',
        mobileViewport: '390x844',
    },
    scenarios: [
        {
            id: 'shell-desktop-parity',
            checks: [
                'Sidebar renders with GTA Alerts nav actions',
                'Header search affordance and icon actions render',
                'Footer links/stats render on desktop',
                'Refresh FAB is visible in the lower-right shell',
            ],
        },
        {
            id: 'feed-table-toggle-contract',
            checks: [
                'Feed/Table toggle is client-side and does not navigate URL',
                'Feed mode shows alert cards',
                'Table mode shows incident table rows with expand controls',
            ],
        },
        {
            id: 'table-interaction-contract',
            checks: [
                'Expand affordance toggles summary row without navigation side-effects',
                'Summary CTA opens AlertDetailsView state',
                'Back navigation returns to feed shell state',
            ],
        },
        {
            id: 'feed-card-status-parity',
            checks: [
                'Active alerts retain high-visibility styling',
                'Cleared alerts use muted/grayscale treatment while remaining legible',
            ],
        },
        {
            id: 'responsive-mobile-parity',
            checks: [
                'Drawer opens/closes with menu actions and Escape key',
                'Bottom nav coexists with feed shell on mobile',
                'Refresh FAB does not overlap bottom nav touch targets',
            ],
        },
    ],
} as const;


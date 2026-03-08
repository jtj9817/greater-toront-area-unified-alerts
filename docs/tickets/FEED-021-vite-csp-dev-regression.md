---
ticket_id: FEED-021
title: "[Bug] Vite dev assets blocked by static CSP on Inertia pages"
status: Closed
priority: High
assignee: Unassigned
tags: [bug, frontend, security, vite, inertia, laravel, dev-environment]
related_files:
  - app/Http/Middleware/EnsureSecurityHeaders.php
  - bootstrap/app.php
  - resources/views/app.blade.php
  - app/Http/Controllers/GtaAlertsController.php
  - tests/Feature/Security/SecurityHeadersTest.php
  - public/hot
---

## Summary

Local development pages fail to boot when Laravel serves an Inertia response
while Vite is running in hot mode. The browser blocks `@vite` and React Refresh
scripts because the app sends a fixed Content Security Policy that allows only
`'self'` for `script-src`, while the actual dev scripts are loaded from the Vite
origin stored in `public/hot`.

## Problem Statement

The GTA Alerts frontend is rendered through Laravel, Inertia, React, and Vite:

- Laravel serves the HTML shell from the app origin.
- Inertia renders the root Blade template for the first request.
- `@viteReactRefresh` and `@vite(...)` inject development scripts when the hot
  server is active.
- Vite serves those scripts from a separate origin such as
  `http://[::1]:5174`.

The current CSP treats that dev server as an untrusted external origin, so the
browser refuses to load:

- `http://[::1]:5174/@vite/client`
- `http://[::1]:5174/resources/js/app.tsx`
- `http://[::1]:5174/resources/js/pages/gta-alerts.tsx`
- `http://[::1]:5174/@react-refresh`

## Current Behavior

- `GET /` returns a valid HTML document.
- The response includes `Content-Security-Policy`.
- The page contains `@viteReactRefresh` and `@vite(...)` output.
- The browser blocks those script tags before React and Inertia initialize.
- The page appears broken even though the backend route and controller succeed.

## Expected Behavior

- Production responses should keep a strict CSP.
- Local Vite development should allow the active hot server origin for script
  loading and HMR connections.
- Security tests should validate environment-aware CSP behavior rather than a
  single hard-coded string.

## Bug Analysis

### 1. Files Involved

- [app/Http/Middleware/EnsureSecurityHeaders.php](/mnt/0B8533211952FCF2/greater-toronto-area-alerts/app/Http/Middleware/EnsureSecurityHeaders.php)
- [bootstrap/app.php](/mnt/0B8533211952FCF2/greater-toronto-area-alerts/bootstrap/app.php)
- [resources/views/app.blade.php](/mnt/0B8533211952FCF2/greater-toronto-area-alerts/resources/views/app.blade.php)
- [app/Http/Controllers/GtaAlertsController.php](/mnt/0B8533211952FCF2/greater-toronto-area-alerts/app/Http/Controllers/GtaAlertsController.php)
- [tests/Feature/Security/SecurityHeadersTest.php](/mnt/0B8533211952FCF2/greater-toronto-area-alerts/tests/Feature/Security/SecurityHeadersTest.php)
- [public/hot](/mnt/0B8533211952FCF2/greater-toronto-area-alerts/public/hot)

### 2. Functions Involved

- `EnsureSecurityHeaders::handle()`
- Blade `@viteReactRefresh`
- Blade `@vite(...)`
- `GtaAlertsController::__invoke()`

### 3. Code Involved

The blocking CSP is produced in
[app/Http/Middleware/EnsureSecurityHeaders.php](/mnt/0B8533211952FCF2/greater-toronto-area-alerts/app/Http/Middleware/EnsureSecurityHeaders.php):

```php
$csp = "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://fonts.bunny.net; img-src 'self' data: https:; font-src 'self' data: https://fonts.gstatic.com https://fonts.bunny.net; connect-src 'self' wss:; frame-ancestors 'self'; form-action 'self';";
```

The dev scripts are injected in
[resources/views/app.blade.php](/mnt/0B8533211952FCF2/greater-toronto-area-alerts/resources/views/app.blade.php):

```blade
@viteReactRefresh
@vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
```

The active dev server origin is currently:

```text
http://[::1]:5174
```

### 4. Reasoning

From first principles:

1. The page origin is `http://localhost`.
2. The Vite dev origin is `http://[::1]:5174`.
3. CSP `script-src 'self'` only allows the same origin as the page.
4. `localhost` and `[::1]:5174` are different origins because both host and
   port differ.
5. The browser blocks Vite modules before React, TypeScript, and Inertia can
   execute.
6. `connect-src 'self' wss:` is also incomplete for HMR because it does not
   explicitly allow the hot server origin.

## Findings

### 1. The bug lives in security middleware, not in the React code

[GtaAlertsController.php](/mnt/0B8533211952FCF2/greater-toronto-area-alerts/app/Http/Controllers/GtaAlertsController.php)
returns a normal Inertia response. The failure happens afterward when the
browser evaluates the CSP against Vite-injected script tags.

### 2. The Blade template is doing the correct thing for Laravel + Vite

[resources/views/app.blade.php](/mnt/0B8533211952FCF2/greater-toronto-area-alerts/resources/views/app.blade.php)
uses the standard Laravel `@vite` integration. In development, this is expected
to point to the hot server rather than self-hosted built assets.

### 3. The current automated test suite encodes the regression

[tests/Feature/Security/SecurityHeadersTest.php](/mnt/0B8533211952FCF2/greater-toronto-area-alerts/tests/Feature/Security/SecurityHeadersTest.php)
asserts the static string `script-src 'self' 'unsafe-inline' 'unsafe-eval'`.
That test passes today, which means it protects the broken behavior instead of
catching it.

### 4. The dev origin is runtime-driven, not hard-coded in PHP

[public/hot](/mnt/0B8533211952FCF2/greater-toronto-area-alerts/public/hot)
currently contains `http://[::1]:5174`. Any reliable fix must derive the
allowed development origin from that runtime value instead of hard-coding
`localhost:5174`.

## Root Cause

The CSP middleware was changed from generic security headers to a fixed
Content Security Policy that assumes all executable scripts come from the app
origin. That assumption is false in Vite hot mode. Laravel correctly injects
development assets from the hot server, but the middleware blocks them because
the policy is not environment-aware.

## Regression Origin

The frontend worked before CSP was introduced because no policy blocked Vite's
cross-origin development scripts.

Relevant git history:

- `3f30f12 feat(security): implement security headers middleware and tests`
  added the middleware and tests, but did not add CSP yet.
- `165b0fa Sentinel: Add Content-Security-Policy and X-XSS-Protection Headers`
  introduced the fixed CSP and the matching test assertions. This is the change
  that introduced the regression.
- `4513879 fix(design-revamp): resolve phase 4 blockers`
  expanded CSP for external font hosts and updated the test expectations, but
  kept the same broken `script-src 'self'` / `connect-src 'self' wss:` model.
  This did not create the bug, but it preserved it.

## Resolution Plan

### Goal

Replace the static CSP string with a production-first, runtime-aware policy
builder that models the app's real asset-loading architecture. Local Vite hot
mode should be treated as one runtime variant of that architecture, not as an
isolated exception.

### Context

Laravel serves the HTML shell, Inertia mounts the page, React runs through Vite,
and Vite hot mode uses a separate dev origin. In production, built assets are
typically served from the application origin unless the deployment later adopts a
CDN or separate asset host. The CSP should therefore be derived from runtime
origins and inline-script strategy rather than from a fixed string.

### Task

1. Refactor
   [app/Http/Middleware/EnsureSecurityHeaders.php](/mnt/0B8533211952FCF2/greater-toronto-area-alerts/app/Http/Middleware/EnsureSecurityHeaders.php)
   to build CSP directives from structured arrays and then serialize them into
   the final header.
2. Define a strict baseline policy for production first:
   - app origin in `default-src`
   - minimal allowed script/style/connect/font/image sources
   - no dev-only Vite origin
3. Detect runtime asset origins dynamically:
   - if `public/hot` exists, parse the hot server origin
   - append that origin to `script-src`
   - append the matching HTTP/WebSocket origin to `connect-src`
4. Remove the assumption that `'unsafe-inline'` is an acceptable permanent
   production setting:
   - review the inline `<script>` and `<style>` blocks in
     [resources/views/app.blade.php](/mnt/0B8533211952FCF2/greater-toronto-area-alerts/resources/views/app.blade.php)
   - either move them out of the document head or protect them with CSP nonces
5. If nonces are adopted, generate the nonce in Laravel and apply it
   consistently to:
   - the CSP header
   - the inline Blade script
   - the inline Blade style, if it remains inline
6. Keep external font hosts explicitly listed only if the deployed frontend
   still depends on them.
7. Update
   [tests/Feature/Security/SecurityHeadersTest.php](/mnt/0B8533211952FCF2/greater-toronto-area-alerts/tests/Feature/Security/SecurityHeadersTest.php)
   to validate runtime-aware behavior instead of a single hard-coded CSP string.
8. Add focused tests for:
   - production behavior with no hot file
   - local hot-mode behavior with a temporary `public/hot`
   - nonce presence and enforcement if inline blocks remain
9. Verify the result with:
   - `php artisan test --filter=SecurityHeadersTest`
   - a live browser check while Vite is running
   - a browser check against built production assets

## Long-Term Fix

The long-term fix is not merely "allow Vite in development." The long-term fix
is a CSP implementation that is correct for deployed production architecture and
extends cleanly to development:

- Production is the primary policy target.
- Development inherits the same model and adds only the active hot server
  origins when present.
- Inline allowances are reduced or eliminated through nonces or by removing
  inline code paths.
- Future deployment changes such as a CDN, separate asset host, or realtime
  service can be modeled by adding explicit runtime-derived origins rather than
  editing a monolithic string.

## Requirements

- Preserve the other security headers already set by the middleware.
- Avoid weakening production CSP with broad wildcards such as `*` or `http:`.
- Build the policy from runtime facts instead of a fixed string.
- Read the active dev origin from `public/hot` when hot mode is enabled.
- Cover both script loading and HMR connectivity.
- Provide a path to remove or tightly control `'unsafe-inline'` in production.
- Keep the Laravel + Inertia + React + TypeScript development flow intact.

## Acceptance Criteria

- [ ] Production responses use a strict CSP derived from the deployed runtime and
      do not include Vite dev origins.
- [ ] Local Inertia pages load successfully while Vite hot mode is active.
- [ ] `@vite/client`, `@react-refresh`, and page entry modules are not blocked by
      CSP in local development.
- [ ] HMR network traffic is permitted by `connect-src` only when the hot server
      is active.
- [ ] Inline script/style handling is explicitly governed by nonces or by removal
      of inline blocks rather than being treated as a permanent exception.
- [ ] Security tests fail if a future change reintroduces a static
      runtime-incorrect CSP.

## Notes

- This is a development-mode regression, not a React component bug.
- Normalizing Vite to `localhost` in `vite.config.ts` is optional and would not
  solve the root issue by itself because the dev server still runs on a
  different origin than the Laravel app.

# Security Headers and Content Security Policy

**Status:** Implemented (updated March 2026)

## Overview

HTTP security headers are applied to every response by `App\Http\Middleware\EnsureSecurityHeaders`, which is registered globally in `bootstrap/app.php`. The middleware generates a per-request CSP nonce and builds a runtime-aware Content Security Policy.

## Headers Set

| Header | Value |
|---|---|
| `X-Frame-Options` | `SAMEORIGIN` |
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(self)` |
| `X-XSS-Protection` | `1; mode=block` |
| `Content-Security-Policy` | Runtime-built (see below) |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` (HTTPS or production only) |

HSTS is added only when `$request->isSecure()` returns true or `app()->isProduction()` is true. It is not sent over plain HTTP in development.

## Content Security Policy

### Nonce Generation

A per-request CSP nonce is generated as `base64_encode(random_bytes(16))` at the top of `handle()`. It is:
- Stored on `$request->attributes` as `csp_nonce` for Blade templates.
- Passed to `Vite::useCspNonce($nonce)` so the `@vite` Blade directive can attach the nonce to injected script tags.

### Production Baseline Policy

When `public/hot` does not exist (i.e., Vite hot mode is not active), the policy contains:

```
default-src 'self';
script-src 'self' 'nonce-<nonce>';
style-src 'self' 'nonce-<nonce>' https://fonts.googleapis.com https://fonts.bunny.net;
img-src 'self' data: https:;
font-src 'self' data: https://fonts.gstatic.com https://fonts.bunny.net;
connect-src 'self' [<echo-origin> <echo-ws-origin>];
frame-ancestors 'self';
form-action 'self';
```

`connect-src` also includes the configured Laravel Echo/Pusher origin when `BROADCAST_CONNECTION` is set and `config('broadcasting.frontend.echo.key')` is non-empty.

### Vite Hot Mode Extension

When `public/hot` exists, the middleware reads the hot server URL from that file (e.g., `http://[::1]:5174`) and extends the policy:

- The hot server HTTP origin is added to `script-src` (so Vite module scripts can load).
- `'unsafe-eval'` is added to `script-src` (required by Vite HMR internals).
- `'unsafe-inline'` is added to `style-src` (required by Vite style injection).
- Both the HTTP origin and its WebSocket equivalent (derived by replacing `http://` with `ws://`) are added to `connect-src` (so HMR WebSocket connections are permitted).

The hot server origin is parsed at runtime from `public/hot` rather than being hard-coded, so any valid Vite dev server URL (including IPv6 addresses like `[::1]:5174`) is handled correctly.

### Laravel Echo / Broadcast Extension

If `config('broadcasting.frontend.echo.key')` is non-empty, `broadcastConnectOrigins()` derives the broadcast WebSocket origin from the Echo frontend config (`config/broadcasting.php` under `frontend.echo`). Both the HTTP origin and its WebSocket equivalent are appended to `connect-src`.

## Implementation

- Middleware: `app/Http/Middleware/EnsureSecurityHeaders.php`
- Tests: `tests/Feature/Security/SecurityHeadersTest.php`

The tests cover:
- Production behavior (no `public/hot` file present)
- Vite hot mode behavior (with a temporary `public/hot` file)
- Nonce presence and format in each environment
- CSP structure including directive keys and source lists
- HSTS presence on secure vs plain-HTTP requests

## Adding New Script/Style Sources

To permit a new origin:
1. If it is always required in production, add it to the appropriate `$scriptSrc`, `$styleSrc`, or `$connectSrc` array inside `buildContentSecurityPolicy()`.
2. If it is conditional on a configuration value, follow the `broadcastConnectOrigins()` pattern: read from config, validate, and append.
3. Add a test case in `SecurityHeadersTest.php` that verifies the new source appears under the correct directive.

Do not replace the nonce-based inline script approach with `'unsafe-inline'` for production — `'unsafe-inline'` is only present in `style-src` when Vite hot mode is active.

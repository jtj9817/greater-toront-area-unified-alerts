# FEED-040: Add Social Metadata and Open Graph Preview Tags

**Type:** Enhancement
**Priority:** P2
**Status:** Closed
**Component:** Frontend (Blade Layout, Inertia Head, Public Assets)

---

## Summary

When the GTA Alerts URL is shared on Reddit, X (formerly Twitter), BlueSky, LinkedIn, or any other social platform, no rich link preview is generated. The page lacks `<meta name="description">`, Open Graph (`og:*`) tags, and Twitter Card tags entirely. This ticket adds the minimal metadata layer required to produce well-formed previews across all major link-unfurling crawlers.

---

## Problem

- No `<meta name="description">` exists anywhere in the page head.
- No Open Graph tags (`og:title`, `og:description`, `og:image`, `og:url`, `og:type`, `og:site_name`) are present.
- No Twitter Card tags (`twitter:card`, `twitter:title`, `twitter:description`, `twitter:image`) are present.
- No BlueSky / generic OEmbed fallback is configured.
- Social crawlers fall back to scraping raw page text, producing no preview or an incoherent one.
- There is no committed social preview image asset (`og:image`). The active `favicon.svg` is vector-only; SVG is rejected by all major crawlers as an `og:image` source.

---

## Current State

- `resources/views/app.blade.php` contains only `<meta charset>`, `<meta viewport>`, `<meta csrf-token>`, and the `<title inertia>` tag.
- All Inertia page components use `<Head title="..." />` for title only; no page passes meta children to `<Head>`.
- `public/favicon.svg` is the only committed brand visual asset.
- `public/apple-touch-icon.png` exists as a raster asset but is not suitable for social preview dimensions (1200×630).
- `APP_URL` in `.env.example` is `http://localhost`. There is no `APP_DESCRIPTION` environment variable.

---

## Proposed Solution

Implement a two-layer metadata strategy: global static defaults in the Blade layout, with per-page overrides via Inertia `<Head>`.

### Layer 1 — Global Defaults (Blade)

Add the following to `resources/views/app.blade.php` inside `<head>`, before `@inertiaHead`:

1. `<meta name="description">` — site-wide description read from `config('app.description')` (backed by a new `APP_DESCRIPTION` env var).
2. Open Graph baseline:
   - `og:type` → `website`
   - `og:site_name` → `config('app.name')`
   - `og:title` → `config('app.name')`
   - `og:description` → `config('app.description')`
   - `og:url` → `config('app.url')`
   - `og:image` → absolute URL to a committed 1200×630 social preview PNG at `public/images/social-preview.png`
   - `og:image:width` → `1200`
   - `og:image:height` → `630`
   - `og:image:alt` → short alt text matching the site description
   - `og:locale` → `en_CA`
3. Twitter Card baseline:
   - `twitter:card` → `summary_large_image`
   - `twitter:title` → `config('app.name')`
   - `twitter:description` → `config('app.description')`
   - `twitter:image` → same `social-preview.png` URL

### Layer 2 — Per-Page Overrides (Inertia `<Head>`)

Update `resources/js/pages/gta-alerts.tsx` to pass explicit `og:*` meta children through `<Head>` so the main public page refines the global defaults. Inertia's `@inertiaHead` injection point is already present in `app.blade.php` and will override the static defaults when `<Head>` emits a tag with the same property name.

### Social Preview Image

Create `public/images/social-preview.png` — a 1200×630 PNG using the existing brand palette:

- Background: `#1a1a1a` (dark, matching the site)
- Orange accent: `#FF7F00` (matching the favicon radar arcs)
- Content: site name, tagline, and a simplified version of the radar/GTA motif from `favicon.svg`

This image must be committed to the repository so it is served statically.

### Environment and Configuration

1. Add `APP_DESCRIPTION` to `.env.example` with a descriptive placeholder value.
2. Add `'description' => env('APP_DESCRIPTION', '')` to `config/app.php`.
3. Ensure `APP_URL` is set correctly in each environment's `.env`; the Blade template will use it to build the absolute `og:url` and `og:image` URLs.

---

## Out of Scope

- Per-alert detail page Open Graph tags (dynamic `og:title`/`og:image` per alert).
- OEmbed endpoint implementation.
- JSON-LD / Schema.org structured data.
- `twitter:site` handle (requires a registered account handle; add when available).
- Automated social preview image generation (Puppeteer, Browsershot, etc.) — static PNG is sufficient for MVP.
- Sitemap.xml or `robots.txt` changes.
- Search engine ranking optimization beyond metadata.

---

## Implementation Notes

### Files Likely Affected

- `resources/views/app.blade.php` — add meta tags before `@inertiaHead`
- `resources/js/pages/gta-alerts.tsx` — add `<Head>` meta children for the main page
- `config/app.php` — add `description` key
- `.env.example` — add `APP_DESCRIPTION`
- `public/images/social-preview.png` — new asset (create directory + file)

### Technical Approach

- All global tags go into `app.blade.php` as static Blade output. Use `config()` helpers — no JavaScript required.
- The `@inertiaHead` directive is positioned after the static tags; Inertia page-level `<Head>` output will naturally follow and can duplicate-override property values, which is the expected behavior for head tag management in this stack.
- For `og:url`, use `{{ config('app.url') }}` as the canonical origin. If a page needs a path-specific canonical URL, pass it via `<Head>`.
- The `og:image` URL must be absolute. Construct it as `{{ config('app.url') }}/images/social-preview.png`.

### Edge Cases

- `APP_URL` set to `http://localhost` in development: OG image URLs will be non-crawlable locally, which is acceptable; this is expected behavior.
- `APP_DESCRIPTION` not set: use a sensible hardcoded default in `config/app.php` rather than an empty string so previews are never blank.
- Long descriptions: keep `APP_DESCRIPTION` under 160 characters for `<meta name="description">` and under 200 characters for OG description, as per platform limits.

---

## Acceptance Criteria

- [x] Sharing the production URL on Reddit shows the site name, description, and preview image.
- [x] Sharing the production URL on X shows a `summary_large_image` Twitter Card.
- [x] Sharing the production URL on BlueSky shows the og:title, og:description, and og:image.
- [x] `<meta name="description">` is present in the rendered HTML.
- [x] `og:title`, `og:description`, `og:image`, `og:url`, `og:type`, `og:site_name` are all present.
- [x] `twitter:card`, `twitter:title`, `twitter:description`, `twitter:image` are all present.
- [x] `og:image` resolves to a valid 1200×630 PNG asset served from the application.
- [x] `APP_DESCRIPTION` is documented in `.env.example`.
- [x] No existing tests are broken by the changes.
- [x] Pint, ESLint, Prettier, and TypeScript quality gates all pass.

---

## Changes Applied

1. Added `'description'` key to `config/app.php` backed by `APP_DESCRIPTION` env var; hardcoded fallback ensures previews are never blank.
2. Added `APP_DESCRIPTION` to `.env.example` with the default description string.
3. Added all social metadata tags to `resources/views/app.blade.php` before `@inertiaHead`:
   - `<meta name="description">` (SEO)
   - `og:type`, `og:site_name`, `og:title`, `og:description`, `og:url`, `og:image`, `og:image:width`, `og:image:height`, `og:image:alt`, `og:locale`
   - `twitter:card`, `twitter:title`, `twitter:description`, `twitter:image`
   - All dynamic values read via `config()` helpers; `og:image` URL built with `rtrim(config('app.url'), '/')`.
4. Updated `resources/js/pages/gta-alerts.tsx` `<Head>` to pass `head-key`-tagged meta children for `description`, `og:title`, `og:description`, `twitter:title`, `twitter:description` (client-side deduplication via Inertia).
5. Generated `public/images/social-preview.png` (1200×630 PNG, 46KB) using the brand palette — dark `#1a1a1a` background, `#FF7F00` radar arc motif from the favicon, site name, and tagline.

---

## Files Changed

- `config/app.php`
- `.env.example`
- `resources/views/app.blade.php`
- `resources/js/pages/gta-alerts.tsx`
- `public/images/social-preview.png` (new)

---

## Verification

### Targeted tests

- `vendor/bin/sail artisan test --compact --filter=GtaAlertsTest` → **PASS** (33 passed, 484 assertions)

### Full suite

- `vendor/bin/sail composer test` → **PASS** (756 passed, 7 skipped)

### Required quality gates

- `vendor/bin/sail bin pint --dirty` → **PASS**
- `pnpm run lint` → **PASS**
- `pnpm run format` → **PASS**
- `pnpm run types` → **PASS**

---

## Related Tickets

| Ticket | Description | Priority |
|---|---|---|
| [FEED-035](./FEED-035-alert-share-link.md) | Alert deep-link sharing | P3 |

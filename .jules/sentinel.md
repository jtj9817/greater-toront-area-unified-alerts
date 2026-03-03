## Sentinel Journal

## 2025-02-18 - Stored XSS in Saved Place Names
**Vulnerability:** Saved Place names allowed raw HTML/Script tags to be stored in the database.
**Learning:** The `SavedPlaceStoreRequest` and `SavedPlaceUpdateRequest` relied on standard string validation rules but did not sanitize content. While the frontend likely escapes output, storing raw XSS payloads is poor hygiene and risky if data is consumed by other clients (e.g., email, mobile apps).
**Prevention:** Implement `prepareForValidation` in FormRequests to sanitize inputs (e.g., `strip_tags`) for fields that should be plain text.

## 2025-02-18 - Stored XSS in Profile Names
**Vulnerability:** User profile names allowed raw HTML/Script tags to be stored in the database via `ProfileUpdateRequest`.
**Learning:** `ProfileValidationRules` trait only provided validation rules but did not handle input sanitization. This left a gap where FormRequests relying solely on this trait for validation missed the sanitization step.
**Prevention:** Explicitly implement `prepareForValidation` in FormRequests to sanitize free-text inputs like names, even when using shared validation traits.

## 2025-02-18 - Stored XSS in Registration
**Vulnerability:** User registration (Fortify's `CreateNewUser` action) allowed raw HTML/Script tags to be stored in the database.
**Learning:** Unlike FormRequests where we implemented `prepareForValidation`, Fortify Actions receive raw input arrays and do not have an automatic hook for sanitization before validation. Relying on shared validation traits (`ProfileValidationRules`) gives a false sense of security regarding sanitization.
**Prevention:** Explicitly sanitize inputs in Fortify Actions (e.g., using `strip_tags`) before passing them to the validator or model creation.

## 2025-02-24 - Stored XSS in Scene Intel Metadata
**Vulnerability:** The `StoreSceneIntelEntryRequest` allowed raw HTML/Script tags to be stored in the `metadata` JSON field. While `content` was sanitized, the flexible `metadata` array was not, and `IncidentUpdateResource` exposed it raw.
**Learning:** Flexible JSON/Array fields (like `metadata`) in FormRequests are often overlooked by simple scalar validation/sanitization rules. `prepareForValidation` needs recursive logic to handle these structures safely.
**Prevention:** Use a recursive `sanitizeArray` helper in `prepareForValidation` for any `array` input that accepts free-text values.

## 2025-02-24 - Stored Script/CSS Text in TTC Alerts Descriptions
**Vulnerability:** TTC Live API descriptions containing `<script>` or `<style>` blocks were parsed using only `strip_tags()`, which removes the HTML delimiters but leaves the script/CSS content intact in the stored alert description (e.g. `alert(1)`). While `strip_tags()` neutralized the active tags, storing raw JS/CSS text is a defense-in-depth gap and ruins data formatting.
**Learning:** `strip_tags()` is insufficient for sanitizing fields that may contain non-visible structural HTML tags like `<script>` or `<style>`. It preserves their text content.
**Prevention:** Always use regex (`preg_replace`) to completely remove `<script>` and `<style>` elements (tags AND their internal content) *before* applying `strip_tags()` when sanitizing raw HTML responses from third-party APIs. Be sure to call `html_entity_decode` before regex so that encoded tags (e.g., `&lt;script&gt;`) are not missed.

## 2026-03-03 - Nested dependencies ReDoS vulnerabilities
**Vulnerability:** Several packages within the Node modules tree (`minimatch` and `ajv`) were using vulnerable versions causing potential ReDoS attacks (Combinatorial backtracking via globstar segments and `$data` option). The `minimatch` dependency was indirectly relied upon by the `eslint` toolchain.
**Learning:** CI build pipelines will fail on dependency audits if deep-nested packages have unresolved vulnerabilities, blocking deployment. Using `pnpm`'s `overrides` field in `package.json` provides an effective mechanism to forcefully pin inner dependencies across the entire resolution tree when upstream libraries haven't updated their own dependencies yet.
**Prevention:** Periodically review `pnpm audit` results and utilize the `overrides` feature to lock vulnerable indirect dependencies to their patched versions (e.g., `minimatch@>=10.2.3` and `ajv@>=6.14.0`).

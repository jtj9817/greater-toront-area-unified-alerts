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

## 2025-03-02 - DoS via TypeError in Input Sanitization
**Vulnerability:** The Fortify `CreateNewUser` action blindly called `trim()` and `strip_tags()` on the `name` input if it was set. If an attacker sent an array (e.g., `name[]=value`), this caused a fatal `TypeError`, leading to a 500 Server Error and potential Denial of Service (DoS) instead of graceful validation failure.
**Learning:** When manually sanitizing input outside of FormRequests (where Laravel handles some type casting), you must explicitly verify the input type (e.g., `is_string()`) before applying string-only functions to prevent crashes from malformed payloads.
**Prevention:** Always wrap manual string sanitization logic in an `is_string($input)` check.

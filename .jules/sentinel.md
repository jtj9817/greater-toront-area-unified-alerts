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

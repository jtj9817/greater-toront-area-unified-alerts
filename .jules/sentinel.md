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

## 2025-02-27 - Fortify Action Array Input TypeError
**Vulnerability:** Fortify Actions (like `CreateNewUser`) process raw input arrays directly without going through FormRequests, meaning automatic string casting and sanitization hooks aren't naturally present. Calling string functions like `trim` and `strip_tags` on an array payload causes a PHP `TypeError`, leading to a 500 server crash.
**Learning:** Manual sanitization inside Fortify actions must explicitly use type checking (e.g., `is_string()`) to ensure array payloads fail validation correctly (422) instead of crashing the process (500).
**Prevention:** Always wrap manual sanitization steps in type checks (`is_string`) before applying string functions, particularly in interfaces like Fortify Actions that handle direct raw request arrays.

## 2025-02-27 - FormRequest Array-to-String TypeError
**Vulnerability:** `ProfileUpdateRequest` explicitly cast array inputs to strings via `(string) $this->input('name')` when applying sanitization functions like `trim()` and `strip_tags()`. If an array was sent via the request, this resulted in an Array-to-String conversion warning/error or a TypeError when string functions execute, leading to a 500 Internal Server Error instead of a 422 Unprocessable Entity.
**Learning:** Directly casting `$this->input()` to a string `(string)` inside `prepareForValidation` is dangerous because HTTP requests can easily provide array payloads (`name[]=foo`).
**Prevention:** Before applying string functions in `prepareForValidation`, use `is_string($this->input('field'))` instead of forcing a cast via `(string)`.

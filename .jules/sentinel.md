## 2026-02-14 - Stored XSS in Manual Intel Entry
**Vulnerability:** User input for manual scene intel entries was validated but not sanitized, allowing Stored XSS payloads (e.g., `<script>`) to be persisted in the database.
**Learning:** Laravel's `FormRequest` validation rules do not automatically sanitize input. Using `strip_tags` in `prepareForValidation` is an effective way to enforce plain text and prevent XSS at the input level.
**Prevention:** Always sanitize user input intended for plain text fields using `strip_tags` or similar methods in `prepareForValidation` before it reaches the controller or database.

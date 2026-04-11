## 2024-10-27 - Input Sanitization in FormRequests
**Vulnerability:** Missing input sanitization on string identifiers like `alert_id` in FormRequests.
**Learning:** While `trim()` was used for formatting, it does not prevent Stored XSS. The `alert_id` field in `SavedAlertStoreRequest` was vulnerable if an attacker provided a crafted string containing HTML/script tags that bypassed the initial format check.
**Prevention:** Always use `strip_tags()` within the `prepareForValidation` method of FormRequests for all string inputs, especially before validation rules that rely on exact string matching or database insertion.

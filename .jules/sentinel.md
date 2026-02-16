## 2026-02-15 - [CRITICAL] Fail-Open Authorization on Manual Entries
**Vulnerability:** The `scene-intel.create-manual-entry` Gate allowed all verified users by default if the `allowed_emails` config was empty.
**Learning:** Default configurations for sensitive features should always "Fail Closed". An empty allowlist implies "no one allowed", not "everyone allowed".
**Prevention:** Check for empty/missing configuration explicitly and deny access by default unless an allowlist is populated and the user is on it.

## 2026-02-15 - [MEDIUM] Unbounded Resource Creation (Saved Places)
**Vulnerability:** Users could create an unlimited number of saved places, potentially leading to resource exhaustion (DoS) or database flooding.
**Learning:** Even authenticated endpoints should have limits on resource creation (quotas or rate limits) to prevent abuse, especially for user-specific data.
**Prevention:** Enforce per-user limits (e.g., `MAX_SAVED_PLACES`) in controllers or requests when allowing users to create persistent resources.

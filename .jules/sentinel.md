## 2026-02-15 - [CRITICAL] Fail-Open Authorization on Manual Entries
**Vulnerability:** The `scene-intel.create-manual-entry` Gate allowed all verified users by default if the `allowed_emails` config was empty.
**Learning:** Default configurations for sensitive features should always "Fail Closed". An empty allowlist implies "no one allowed", not "everyone allowed".
**Prevention:** Check for empty/missing configuration explicitly and deny access by default unless an allowlist is populated and the user is on it.

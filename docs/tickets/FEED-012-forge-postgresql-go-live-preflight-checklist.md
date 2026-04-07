---
ticket_id: FEED-012
title: "[Deployment] Forge + Hetzner PostgreSQL Go-Live Pre-Flight Checklist"
status: Closed
priority: Critical
assignee: Unassigned
created_at: 2026-03-02
closed_at: 2026-04-06
resolution: "Production has been running on PostgreSQL without interruption. All phases validated in production."
tags: [deployment, forge, hetzner, postgresql, preflight, runbook]
related_files:
  - docs/plans/hetzner-forge-deployment-preflight.md
  - docs/deployment/production-seeding.md
  - scripts/forge-deploy.sh
  - composer.json
  - package.json
---

## Summary

This ticket defines an exact, production-focused pre-flight and go-live sequence for deploying this Laravel + Inertia application to a Hetzner VPS managed by Laravel Forge, with PostgreSQL as the production database engine.

This checklist goes beyond DB migration and validates runtime, workers, scheduler, cache/session drivers, build pipeline, observability, and rollback readiness.

## Scope

- Target runtime: Laravel Forge on Hetzner VPS
- Target database: PostgreSQL (`DB_CONNECTION=pgsql`)
- Deployment model: Forge deploy hook using `scripts/forge-deploy.sh`

## Ordered Pre-Flight Checklist (Go/No-Go)

### Phase 1 — Local Release Candidate Validation (before touching production)

1. Ensure working tree is clean and branch is releasable.
   ```bash
   git status
   ```

2. Validate backend tests/lint gate.
   ```bash
   composer test
   ```

3. Validate frontend quality/build.
   ```bash
   pnpm run quality:check
   pnpm run build
   ```

4. Validate PostgreSQL test profile still passes before deploy cutover.
   ```bash
   php artisan config:clear
   php artisan test --env=testing --configuration=phpunit.pgsql.xml
   ```

**Go/No-Go Gate:** Do not deploy if any command above fails.

---

### Phase 2 — Forge Server Runtime & Package Validation

Run on server as `forge` user (SSH).

1. Confirm PHP version and required extensions (especially `pgsql`, `pdo_pgsql`, and `pcntl` for worker signal handling).
   ```bash
   php -v
   php -m | rg -n "pgsql|pdo_pgsql|redis|pcntl|mbstring|openssl|tokenizer|xml|ctype|json|bcmath"
   ```

2. Confirm Node/pnpm versions used by build pipeline.
   ```bash
   node -v
   pnpm -v
   ```

3. Confirm PostgreSQL connectivity from app host.
   ```bash
   cd /home/forge/<site>
   php artisan tinker --execute="DB::connection()->getPdo(); echo 'db-ok';"
   ```

4. Confirm Redis connectivity if using Redis cache/session/queue.
   ```bash
   php artisan tinker --execute="cache()->put('preflight','ok',60); echo cache()->get('preflight');"
   ```

**Go/No-Go Gate:** Block deploy until runtime mismatch or extension gaps are fixed.

---

### Phase 3 — Forge Configuration Integrity Checks

In Forge UI, verify these values before first deploy:

1. **Environment**
   - `APP_ENV=production`
   - `APP_DEBUG=false`
   - `APP_URL=https://<your-domain>`
   - `DB_CONNECTION=pgsql`
   - `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
   - `CACHE_STORE=redis` (recommended)
   - `SESSION_DRIVER=redis` (recommended)
   - `QUEUE_CONNECTION=redis`
   - `QUEUE_UNIQUE_LOCK_STORE=redis`
   - `DB_QUEUE_RETRY_AFTER=180`
   - `REDIS_QUEUE_RETRY_AFTER=180`
   - `QUEUE_DEPTH_ALERT_THRESHOLD=100`
   - `QUEUE_DEPTH_ALERT_LOG_CHANNEL=queue_alerts`
   - `QUEUE_ALERT_CHANNELS=single,slack` (or your central log destination)
   - Redis queue behavior note: `ScheduledFetchJobDispatcher` database-row pre-enqueue dedupe applies only to `database` queue driver; with `QUEUE_CONNECTION=redis`, protection is lock-based via `QUEUE_UNIQUE_LOCK_STORE`.
   - `LOG_CHANNEL=stack`
   - `BROADCAST_CONNECTION=log` (or your chosen provider)

2. **Deploy Script**
   - Set deploy script to:
     ```bash
     bash scripts/forge-deploy.sh
     ```

3. **Daemons**
   - Queue worker daemon exists and is running:
     ```bash
     php artisan queue:work --sleep=1 --tries=3 --timeout=120 --max-time=3600
     ```

4. **Scheduler**
   - Cron exists:
     ```bash
     * * * * * cd /home/forge/<site> && php artisan scheduler:run-and-log --no-interaction >> /dev/null 2>&1
     ```

5. **SSL**
   - Certificate active and auto-renew enabled in Forge.

**Go/No-Go Gate:** Do not proceed if env, daemon, or cron are missing.

---

### Phase 4 — Data Safety & Rollback Readiness

1. Create VPS snapshot at Hetzner level.
2. Ensure Forge DB backup job is configured and tested.
3. Export production seed SQL artifact from current source environment if needed:
   ```bash
   php artisan db:export-sql --output=storage/app/private/preflight-export.sql --compress
   ```
4. Verify import command is available and documented:
   ```bash
   php artisan list | rg "db:import-sql|db:export-sql"
   ```
5. Confirm rollback plan:
   - previous release hash recorded
   - DB restore command/path tested
   - maintenance window and owner assigned

**Go/No-Go Gate:** No backup + no rollback owner = no deploy.

---

### Phase 5 — Staging/Smoke Deploy on Forge (production-like)

1. Trigger deploy in Forge to staging site.
2. Verify Laravel health quickly:
   ```bash
   cd /home/forge/<staging-site>
   php artisan about
   php artisan migrate:status
   php artisan queue:failed
   php artisan schedule:list
   ```
3. Validate routes and asset manifest:
   ```bash
   php artisan route:list | rg "gta-alerts|login|dashboard"
   ls -la public/build/manifest.json
   ```
4. Validate write permissions and storage symlink:
   ```bash
   ls -la public/storage
   test -w storage && echo "storage writable"
   test -w bootstrap/cache && echo "bootstrap/cache writable"
   ```

**Go/No-Go Gate:** Production deploy only after staging smoke passes.

---

### Phase 6 — Production Deployment Execution

1. Optional: enter maintenance mode during first cutover.
   ```bash
   cd /home/forge/<site>
   php artisan down --render="errors::503"
   ```

2. Trigger Forge deploy.

3. After deploy, run immediate checks:
   ```bash
   cd /home/forge/<site>
   php artisan migrate:status
   php artisan optimize:clear
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   php artisan event:cache
   php artisan queue:restart
   ```

4. Exit maintenance mode:
   ```bash
   php artisan up
   ```

---

### Phase 7 — Post-Deploy Production Verification (first 30–60 minutes)

1. Functional checks:
   - Home page loads (`/`)
   - Authentication flow works
   - GTA alerts page loads and filters/search execute

2. Queue/scheduler checks:
   ```bash
   php artisan queue:failed
   php artisan schedule:list
   php artisan scheduler:status --max-age=5
   ```

3. Logs and error checks:
   ```bash
   tail -n 200 storage/logs/laravel.log
   ```

4. PostgreSQL sanity checks:
   - No SQL function errors (`DATE_FORMAT`, `JSON_OBJECT`, `MATCH AGAINST`)
   - Expected row growth in alert tables after scheduled jobs run

5. Performance sanity:
   - Initial page response acceptable
   - No repeated worker crashes in Forge daemon logs

**Go/No-Go Gate:** If critical errors appear, rollback immediately.

## Required Pre-Flight Checks Beyond PostgreSQL Migration

- PHP extension parity (`pgsql`, `pdo_pgsql`, `redis`, `pcntl`) is verified.
- Queue worker and scheduler are explicitly configured in Forge.
- Production env vars are audited (debug off, URL/SSL correct, redis-backed queue/session/cache/unique locks, retry-after aligned with timeout).
- Deploy script is confirmed to build frontend assets (`pnpm`) and run migrations.
- Backups/snapshots and rollback drill are in place before cutover.
- Staging dry-run is completed with the same runtime profile as production.
- Post-deploy observability (logs, failed jobs, scheduler status, queue-depth alert channel delivery) is actively monitored.

## Verification Log (Local, 2026-03-02)

The following checks were executed locally in CLI mode without using
`./vendor/bin/sail` (no direct interaction with the running Sail containers).

### Executed Successfully (Assistant)

- `php -v`
- `php -m | rg -n "pgsql|pdo_pgsql|redis|mbstring|openssl|tokenizer|xml|ctype|json|bcmath"`
- `node -v`
- `pnpm -v`
- `pnpm run quality:check`
- `pnpm run build`
- `php artisan about`
- `php artisan route:list | rg "gta-alerts|login|dashboard"`
- `CACHE_STORE=array php artisan schedule:list`
- `php artisan db:export-sql --help`
- `php artisan db:import-sql --help`
- `php artisan list | rg "db:import-sql|db:export-sql"`

### Executed with Failures / Blockers (Assistant)

- `composer test` failed due a Pint style issue:
  `tests/manual/verify_feed_010_phase_5_documentation_registry_hygiene.php`.
- `php artisan test --env=testing --configuration=phpunit.pgsql.xml` did not run
  as intended because `phpunit.pgsql.xml` already sets config and the command
  emitted `Option --configuration cannot be used more than once`.
- `php artisan schedule:list` failed under default local env because cache uses
  database driver and local host `mysql` was not resolvable.
- `php artisan tinker --execute="DB::connection()->getPdo(); ..."` failed due
  local MySQL host resolution (`mysql` not reachable in non-Sail context).
- `php artisan tinker --execute="cache()->put(...)"` failed for the same reason.
- `ls -la public/storage` failed because symlink is currently missing locally.

### Documentation Correction Identified

This ticket previously used command names:
- `alert-data:export-sql`
- `alert-data:import-sql`

Actual command names in current codebase:
- `db:export-sql`
- `db:import-sql`

## Verification Log Addendum (Local, 2026-03-03)

Additional pre-switch checks were executed before local PostgreSQL cutover:

### Executed Successfully (Assistant)

- `pnpm run build` completed successfully.
- `/bin/bash -lc "./vendor/bin/sail composer test"` completed successfully
  (597 passed, 7 skipped).

### Executed with Failures / Blockers (Assistant)

- `pnpm run quality:check` failed during `eslint` with:
  `TypeError: Cannot set properties of undefined (setting 'defaultMeta')`.
- `/bin/bash -lc "./vendor/bin/sail artisan db:export-sql ..."` failed with:
  `Docker is not running.` after the initial test run.
- `php artisan db:export-sql --output=... --compress` failed outside Sail because
  local MySQL hostname `mysql` is not resolvable in non-container context.

### Pre-Switch Infrastructure Prep Completed

- `compose.yaml` now includes a first-class `pgsql` service for local dev
  runtime and a persistent `sail-pgsql` volume.
- `laravel.test` now depends on `pgsql` (alongside existing services), so
  switching `.env` to PostgreSQL will be supported once Docker is available.

## Acceptance Criteria

- [x] All 7 phases complete with no failed go/no-go gate.
- [x] First production deploy succeeds without manual hotfix.
- [x] No `SQLSTATE` driver-specific query errors observed after cutover.
- [x] Queue and scheduler are both processing normally in Forge.
- [x] Backup and rollback evidence is documented in deployment notes.

# Forge Go-Live Checklist (Hetzner + PostgreSQL)

## Scope

This runbook is the operator execution guide for production deployment on
Laravel Forge (Hetzner VPS) with PostgreSQL.

For full planning context and acceptance criteria, see:
`docs/tickets/FEED-012-forge-postgresql-go-live-preflight-checklist.md`

## Phase 1 — Local Release Candidate Gate

Run locally before any production deploy:

- `git status`
- `composer test`
- `pnpm run quality:check`
- `pnpm run build`
- `php artisan config:clear`
- `php artisan test --env=testing --configuration=phpunit.pgsql.xml`

If any command fails, stop and fix before deploying.

## Phase 2 — Forge Runtime Validation

Run on server as `forge`:

- `php -v`
- `php -m | rg -n "pgsql|pdo_pgsql|redis|pcntl|mbstring|openssl|tokenizer|xml|ctype|json|bcmath"`
- `node -v`
- `pnpm -v`
- `cd /home/forge/<site>`
- `php artisan tinker --execute="DB::connection()->getPdo(); echo 'db-ok';"`
- `php artisan tinker --execute="cache()->put('preflight','ok',60); echo cache()->get('preflight');"`
- `php artisan tinker --execute="Log::channel(config('logging.queue_depth_alert_channel'))->error('queue-alert-preflight'); echo 'queue-alert-ok';"`

## Phase 3 — Forge UI Configuration Check

Verify in Forge before deploy:

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=https://<your-domain>`
- `DB_CONNECTION=pgsql` and complete DB credentials
- `CACHE_STORE=redis`
- `SESSION_DRIVER=redis`
- `QUEUE_CONNECTION=redis`
- `QUEUE_UNIQUE_LOCK_STORE=redis`
- `DB_QUEUE_RETRY_AFTER=180`
- `REDIS_QUEUE_RETRY_AFTER=180`
- `QUEUE_DEPTH_ALERT_THRESHOLD=100`
- `QUEUE_DEPTH_ALERT_LOG_CHANNEL=queue_alerts`
- `QUEUE_ALERT_CHANNELS=single,slack` (or your central log destination)
- Redis queue behavior: `ScheduledFetchJobDispatcher` skips database-row pre-enqueue dedupe when `QUEUE_CONNECTION=redis`; uniqueness protection is lock-based (`QUEUE_UNIQUE_LOCK_STORE`) and assumes workers stay healthy.
- Deploy script: `bash scripts/forge-deploy.sh`
- Queue daemon running:
  `php artisan queue:work --sleep=1 --tries=3 --timeout=120 --max-time=3600`
- Scheduler cron present:
  `* * * * * cd /home/forge/<site> && php artisan scheduler:run-and-log --no-interaction >> /dev/null 2>&1`

## Phase 4 — Backup and Rollback Readiness

Before cutover:

- Create Hetzner VPS snapshot
- Confirm Forge database backup is enabled and tested
- Record previous release git hash
- Confirm rollback owner and maintenance window
- Confirm import/export SQL commands are available:
  - `php artisan list | rg "db:export-sql|db:import-sql"`

## Phase 5 — Staging Smoke Deploy

Deploy to staging first, then verify:

- `cd /home/forge/<staging-site>`
- `php artisan about`
- `php artisan migrate:status`
- `php artisan queue:failed`
- `php artisan schedule:list`
- `php artisan route:list | rg "gta-alerts|login|dashboard"`
- `ls -la public/build/manifest.json`
- `ls -la public/storage`
- `test -w storage && echo "storage writable"`
- `test -w bootstrap/cache && echo "bootstrap/cache writable"`

Only proceed to production if staging checks pass.

## Phase 6 — Production Deploy Execution

Optional maintenance mode for first cutover:

- `cd /home/forge/<site>`
- `php artisan down --render="errors::503"`

Deploy in Forge, then run:

- `php artisan migrate:status`
- `php artisan optimize:clear`
- `php artisan config:cache`
- `php artisan route:cache`
- `php artisan view:cache`
- `php artisan event:cache`
- `php artisan queue:restart`
- `php artisan up`

## Phase 7 — Post-Deploy Verification (30–60 min)

Validate:

- Home page, auth flow, and GTA alerts page are functional
- `php artisan queue:failed` remains clean
- `php artisan schedule:list` shows expected jobs
- `php artisan scheduler:status --max-age=5` returns success
- `tail -n 200 storage/logs/laravel.log` has no critical errors
- No PostgreSQL SQL function errors (`DATE_FORMAT`, `JSON_OBJECT`, `MATCH AGAINST`)
- Forge daemon logs show stable workers (no repeated crashes)
- Queue-depth alerts route to an operator notification destination (for example Slack)

If critical errors appear, execute rollback immediately.

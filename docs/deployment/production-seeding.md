# Production Data Seeding (Forge)

This guide documents the safe path to migrate alert data from a local dataset into production using generated Laravel seeders.

## Scope

This process only covers alert-source tables exported by `db:export-to-seeder`:

- `fire_incidents`
- `police_calls`
- `transit_alerts`
- `go_transit_alerts`

## Prerequisites

1. Local branch is up to date and clean enough to inspect generated seeder diffs.
2. Production schema is current (`php artisan migrate --force` must run before seeding).
3. You have a production DB backup/snapshot you can restore quickly.
4. You understand generated seeders may contain operationally sensitive location text and incident details.

## Step 1: Generate And Verify Seeders Locally

Run from repository root:

```bash
./scripts/generate-production-seed.sh --sail
```

Optional tuning:

```bash
./scripts/generate-production-seed.sh --sail --chunk 500 --max-bytes 10485760
```

Expected behavior:

1. Runs `db:export-to-seeder`.
2. Confirms `ProductionDataSeeder.php` exists.
3. Runs `db:verify-production-seed`.
4. Lists generated files (including split `ProductionDataSeeder_PartN.php` files when applicable).
5. Prompts to stage/commit generated files.

## Step 1.5: Run Final Quality Gate Verification

Before promoting generated seeders, run the Phase 4 end-to-end verification script:

```bash
php tests/manual/verify_production_data_migration_phase_4_final_quality_gate.php
```

This script validates the full migration path in isolated local SQLite databases:

1. Seeds deterministic source data.
2. Runs `db:export-to-seeder`.
3. Runs `db:verify-production-seed`.
4. Replays generated seeders into a fresh secondary database.
5. Verifies row-count and row-level fidelity (including timestamps).
6. Re-runs seeders to confirm idempotency.
7. Runs Pint and command test coverage gates (`--coverage --min=90`) for migration commands.

## Step 2: Review And Commit

1. Inspect generated files in `database/seeders/`.
2. Confirm no secrets or unexpected columns were exported.
3. Commit and push:

```bash
git add database/seeders/ProductionDataSeeder*.php
# include any split parts that were generated
git commit -m "chore(db): refresh production seeders"
git push
```

## Step 3: Run In Laravel Forge

### Option A: One-off command (recommended for first run)

In Forge, open the site server and run:

```bash
cd /home/forge/<your-site>
php artisan migrate --force
php artisan db:verify-production-seed --path=database/seeders/ProductionDataSeeder.php
php artisan db:seed --class=ProductionDataSeeder --force
```

### Option B: Deploy script integration (one-time import block)

Add this block after migrations in your Forge deploy script:

```bash
php artisan db:verify-production-seed --path=database/seeders/ProductionDataSeeder.php
php artisan db:seed --class=ProductionDataSeeder --force
```

After the initial migration is complete, remove this block to avoid unnecessary seeding runs on every deploy.

## Step 4: Post-Deploy Validation

1. Check row counts for all four alert tables.
2. Confirm dashboard feed renders historical and active incidents.
3. Spot-check that `created_at`/`updated_at` values match source records.
4. Re-run the seeder once to confirm idempotency (no duplicate insert errors).

## Security Warnings

- Do not run this process against the wrong environment.
- Keep production seed files in private repository access scope.
- Never include credentials or tokens in generated seeders.
- Run DB backup/snapshot before seeding in production.

## Troubleshooting

| Symptom | Cause | Resolution |
|---|---|---|
| `Seeder file not found` during verify | Wrong path or missing generated file | Regenerate with `./scripts/generate-production-seed.sh` and confirm file path. |
| `Missing split seeder file referenced by main seeder` | Split part file missing from commit/deploy | Commit and deploy all `ProductionDataSeeder_Part*.php` files. |
| `Syntax check failed` | Manual edit introduced invalid PHP | Regenerate seeders and avoid manual edits to generated files. |
| Seeder runs but data is incomplete | Export ran on stale/empty local DB | Refresh local data, rerun export, verify counts before commit. |
| Seeder fails in production with schema errors | Production migrations not applied | Run `php artisan migrate --force` before seeding. |

---
ticket_id: FEED-009
title: "[Feature] Replace PHP Seeder Export with SQL Dump Pipeline — Add `db:export-sql` and `db:import-sql` Commands"
status: Open
priority: High
assignee: Unassigned
created_at: 2026-02-24
tags: [feature, backend, database, data-migration, infrastructure, devops]
related_files:
  - app/Console/Commands/ExportProductionData.php
  - app/Console/Commands/VerifyProductionSeed.php
  - scripts/generate-production-seed.sh
---

## Summary

The current production data transfer mechanism (`db:export-to-seeder`) serialises all rows from the four alert tables into PHP seeder files using `var_export()`, commits those files to git, and then runs them on production via `php artisan db:seed`. This approach is functional but carries structural problems: database records do not belong in version control, PHP's `var_export()` format is verbose and slow to parse under load, and the 10 MB file-splitting logic is a symptom of a format that was not designed for bulk data transfer.

The correct tool for moving relational data between MySQL instances is SQL. This ticket replaces the seeder-based workflow with two new artisan commands — `db:export-sql` and `db:import-sql` — that produce and consume a standard `.sql` dump file. The file is transferred out-of-band (SCP, Forge file manager, etc.) and never committed to git.

This is especially important now that the production database contains live records collected since deployment. The development database holds historical records captured during development. Merging both datasets requires an idempotent, conflict-safe mechanism. The PHP seeder uses `insertOrIgnore`, which achieves idempotency but at the cost of the format problems above. The SQL pipeline achieves the same idempotency via `INSERT INTO ... ON DUPLICATE KEY UPDATE id = id` while remaining portable, inspectable, and importable by native MySQL tooling without any Laravel runtime involvement.

## Problem Analysis

### Current Workflow

```
dev database
    ↓
php artisan db:export-to-seeder
    ↓
database/seeders/ProductionDataSeeder.php   ← PHP file, committed to git
    ↓
git push → git pull on production
    ↓
php artisan db:seed --class=ProductionDataSeeder
    ↓
production database
```

### Structural Problems

**1. Data in version control**

`ProductionDataSeeder.php` and its part files (`_Part1`, `_Part2`, …) contain raw alert records. Every row from `fire_incidents`, `police_calls`, `transit_alerts`, and `go_transit_alerts` is embedded as a PHP array literal. Git is not designed for this. The seeder files will grow without bound as data accumulates, bloating the repository history permanently — git history is immutable.

**2. Verbosity of `var_export()` output**

PHP's `var_export()` produces deeply indented array syntax with explicit key names for every field on every row. A single `fire_incidents` row spanning 20 columns produces approximately 800–1,200 bytes of PHP text. An equivalent SQL `VALUES` row is 200–400 bytes. The 10 MB file-splitting logic was added anticipating this growth — it addresses a symptom of the wrong format.

**3. PHP runtime required for import**

Running the seeder requires a working Laravel installation (`composer install`, `.env`, `APP_KEY`, migrations) on the target machine before a single row can be inserted. A `.sql` file can be imported with only `mysql` — no PHP runtime, no framework, no environment configuration.

**4. No standalone import path**

The current codebase has no import command of any kind. The seeder is the only mechanism. There is no way to import a SQL dump, a JSON export, or any external snapshot. Adding a dedicated import command fills this gap generally.

**5. Schema drift risk**

Seeder files serialise column names as array keys at the time of export. If a column is renamed or dropped before the seeder is executed, the `insertOrIgnore` will silently drop mismatched columns or throw. SQL `INSERT INTO table (col1, col2, ...) VALUES (...)` has the same risk, but the failure mode is explicit and the fix (re-exporting) is obvious. PHP array files in git may sit for weeks before execution, accumulating drift silently.

### Why `insertOrIgnore` / `ON DUPLICATE KEY UPDATE` Is Still Required

Production already has live data. Development has historical data. The union of both is needed. Any import mechanism must be idempotent — re-running it must not duplicate rows or overwrite production data that was collected after the export was taken. Both the PHP seeder (`insertOrIgnore`) and the SQL pipeline (`ON DUPLICATE KEY UPDATE id = id`) achieve this. The SQL approach does so without changing the format.

### Current Command Inventory

| Command | Purpose | Kept? |
|---|---|---|
| `db:export-to-seeder` | Export to PHP seeder | Deprecated for data transfer; may be retained for other uses |
| `db:verify-production-seed` | Verify PHP seeder syntax | Deprecated alongside the above |
| `scripts/generate-production-seed.sh` | Orchestrate export + verify + git staging | Replaced by `scripts/export-alert-data.sh` |

## Fix Specification

### New Command: `db:export-sql`

**File:** `app/Console/Commands/ExportAlertDataSql.php`

**Signature:**
```
db:export-sql
    {--output= : Absolute or relative path for the .sql output file (default: storage/app/alert-export.sql)}
    {--tables= : Comma-separated list of tables to export (default: all four alert tables)}
    {--chunk=500 : Rows per INSERT VALUES batch}
    {--compress : Gzip the output file (appends .gz extension)}
    {--no-header : Omit the SQL file header (SET NAMES, FOREIGN_KEY_CHECKS)}
```

**Output format:**

```sql
-- GTA Alerts SQL export
-- Generated: 2026-02-24 14:30:00 UTC
-- Source: gta_alerts_dev (mysql, 127.0.0.1:3307)
-- Tables: fire_incidents, police_calls, transit_alerts, go_transit_alerts

SET NAMES utf8mb4;
SET TIME_ZONE = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

--
-- Table: fire_incidents (1 247 rows)
--

INSERT INTO `fire_incidents`
    (`id`, `event_num`, `alarm_lev`, `event_type`, `address`, `lat`, `lng`,
     `is_active`, `units`, `fire_station`, `fire_district`, `fire_num`,
     `ext_updated_at`, `created_at`, `updated_at`)
VALUES
    (1, 'F2026-0001', 1, 'Structure Fire', '123 Main St', 43.6532, -79.3832,
     1, 'P312 P314', '312', '4', NULL, '2026-02-24 12:00:00', '2026-02-24 12:00:00', '2026-02-24 12:01:00'),
    (2, 'F2026-0002', 2, 'Medical', '456 King St W', 43.6441, -79.3992,
     0, 'A241', '241', '3', NULL, '2026-02-24 11:45:00', '2026-02-24 11:45:00', '2026-02-24 12:05:00')
ON DUPLICATE KEY UPDATE `id` = `id`;

--
-- Table: police_calls (3 891 rows)
--
...

SET FOREIGN_KEY_CHECKS = 1;
```

**Implementation notes:**

- Value escaping: use `DB::connection()->getPdo()->quote($value)` for string/unknown types; emit the bare literal `NULL` (unquoted) for PHP `null` values; emit unquoted integers and floats for numeric columns.
- Column names and table names are backtick-quoted.
- Rows are grouped into batches of `--chunk` size per `VALUES` block. Each batch is a single `INSERT` statement, keeping individual statement sizes bounded.
- `ON DUPLICATE KEY UPDATE id = id` is a MySQL no-op that satisfies the `INSERT ... ON DUPLICATE KEY` parser requirement without modifying any column. This makes every import idempotent.
- The `--compress` flag pipes output through `gzencode()` before writing. The caller is responsible for decompressing before passing to `mysql`. Compression is not applied by default because `mysql` cannot read gzip natively.
- If `--output` points to `storage/app/`, the file is excluded from git via the existing `storage/app/.gitignore` (`*` / `!.gitignore`). Callers targeting other paths must ensure the file is not staged.

**Chunking and memory:**

The existing `ExportProductionData.php` uses `chunkById()` to avoid loading full tables into memory. The new command uses the same strategy. Each chunk builds a single `INSERT ... VALUES (...)` statement string, writes it to the file handle, and discards the chunk. Peak memory usage is proportional to chunk size × average row width, not to total table size.

---

### New Command: `db:import-sql`

**File:** `app/Console/Commands/ImportAlertDataSql.php`

**Signature:**
```
db:import-sql
    {file : Path to the .sql file to import}
    {--force : Skip confirmation prompt (required for non-interactive / CI use)}
    {--dry-run : Parse and validate the file without executing any statements}
```

**Implementation approach:**

The command shells out to the `mysql` CLI binary via Laravel's `Process` facade, passing connection parameters from the application's active database configuration:

```php
$config = config('database.connections.' . config('database.default'));

$command = [
    'mysql',
    '--host=' . $config['host'],
    '--port=' . $config['port'],
    '--user=' . $config['username'],
    '--password=' . $config['password'],
    $config['database'],
];

$result = Process::pipe(function (Pipe $pipe) use ($command, $file) {
    $pipe->command("cat {$file}");
    $pipe->command($command);
});
```

This avoids reading the entire SQL file into PHP memory and delegates parsing and execution to the native MySQL client, which handles large files efficiently.

**Dry-run mode:**

When `--dry-run` is passed, the command reads the file in chunks and verifies:
1. The file is readable and non-empty
2. The header block is present (`SET NAMES`, `SET FOREIGN_KEY_CHECKS`)
3. Each `INSERT` statement references a known table (`fire_incidents`, `police_calls`, `transit_alerts`, `go_transit_alerts`)
4. No statements other than `INSERT`, `SET`, and comments are present (reject DDL — `DROP`, `CREATE`, `ALTER`, `TRUNCATE` — to prevent accidental schema mutation)

**Safety rails:**

- Without `--force`, the command prints the target database name and host and prompts for explicit confirmation before executing.
- The command refuses to execute if the SQL file contains any DDL statements (`DROP TABLE`, `CREATE TABLE`, `ALTER TABLE`, `TRUNCATE`). Data-only imports only.
- The command refuses to import into a database named `*_testing` or where `APP_ENV=testing` without an explicit `--allow-testing` flag.

---

### New Script: `scripts/export-alert-data.sh`

Replaces `scripts/generate-production-seed.sh` for the production data transfer use case.

**Usage:**
```bash
./scripts/export-alert-data.sh [--sail] [--compress] [--output=path]
```

**Flow:**
1. Resolve whether to invoke artisan via `./vendor/bin/sail artisan` (if `--sail`) or `php artisan` directly
2. Run `db:export-sql` with the resolved path and any forwarded options
3. Print the output file path, size, and row counts
4. Print transfer instructions (SCP example, Forge file manager note)
5. Remind the caller not to `git add` the output file

---

### Deprecation of PHP Seeder Workflow

`ExportProductionData.php` (`db:export-to-seeder`) and `VerifyProductionSeed.php` (`db:verify-production-seed`) are **not deleted** by this ticket. They remain functional. They are marked `@deprecated` in their class docblocks and noted as superseded in `scripts/README.md`. Deletion is deferred to a separate cleanup ticket once the SQL pipeline is confirmed in production.

`scripts/generate-production-seed.sh` gains a deprecation notice at the top directing users to `scripts/export-alert-data.sh`.

---

### File Changes Summary

| File | Action | Description |
|---|---|---|
| `app/Console/Commands/ExportAlertDataSql.php` | **Create** | New `db:export-sql` artisan command |
| `app/Console/Commands/ImportAlertDataSql.php` | **Create** | New `db:import-sql` artisan command |
| `app/Console/Commands/ExportProductionData.php` | **Edit** | Add `@deprecated` docblock |
| `app/Console/Commands/VerifyProductionSeed.php` | **Edit** | Add `@deprecated` docblock |
| `scripts/export-alert-data.sh` | **Create** | New orchestration script |
| `scripts/generate-production-seed.sh` | **Edit** | Add deprecation notice at top |
| `scripts/README.md` | **Edit** | Document new commands; mark old ones superseded |
| `tests/Feature/ExportAlertDataSqlTest.php` | **Create** | Feature tests for export command |
| `tests/Feature/ImportAlertDataSqlTest.php` | **Create** | Feature tests for import command (dry-run path) |

---

### What Changes

| Aspect | Before | After |
|---|---|---|
| Export format | PHP `var_export()` array literals in `.php` files | SQL `INSERT INTO ... VALUES (...)` in a `.sql` file |
| Export destination | `database/seeders/ProductionDataSeeder.php` (committed to git) | `storage/app/alert-export.sql` (gitignored) |
| Transfer mechanism | `git push` / `git pull` | SCP, Forge file manager, or any file transfer |
| Import mechanism | `php artisan db:seed --class=ProductionDataSeeder` | `php artisan db:import-sql --file=alert-export.sql` or `mysql < alert-export.sql` |
| Laravel runtime required for import | Yes | No (for `mysql` direct import) |
| Git repository bloat | Grows with every export | None |
| File size (approximate, 10k rows) | ~12–18 MB PHP text | ~4–7 MB SQL text |
| DDL safety | No guard (seeder executes arbitrary PHP) | `db:import-sql` rejects DDL statements |
| Idempotency | `insertOrIgnore` | `ON DUPLICATE KEY UPDATE id = id` |

### What Does NOT Change

- `routes/console.php` — Scheduled feed fetching unchanged
- All `Fetch*Command.php` and `Fetch*Job.php` — Unchanged
- `FireIncident`, `PoliceCall`, `TransitAlert`, `GoTransitAlert` models — Unchanged
- All controllers, query layer, providers, DTOs — Unchanged
- Frontend — Unchanged
- Production Forge deployment — Unchanged
- Test suite (existing tests) — No modifications required

---

## Edge Cases

**Large tables exceeding available disk space**

The export writes sequentially to a single file handle. If disk space is exhausted mid-export, `fwrite()` returns `false`. The command must check the return value of each write and abort cleanly, deleting the partial file and emitting an actionable error.

**NULL values in numeric columns**

`var_export()` renders PHP `null` as `NULL` (the string), which is coincidentally valid SQL. The new command must explicitly check `is_null($value)` before quoting, emitting the unquoted SQL literal `NULL` for `null` and calling `PDO::quote()` only for non-null values. Passing `null` to `PDO::quote()` returns the string `''`, which is incorrect for nullable integer/timestamp columns.

**Timestamp and datetime columns**

Laravel stores `created_at`/`updated_at` as strings in the format `Y-m-d H:i:s`. These are retrieved as strings from `getAttributes()` and quoted as strings in the SQL output. MySQL accepts this format natively. No conversion is required.

**Binary or non-UTF-8 data**

The `SET NAMES utf8mb4` header at the top of the export file sets the client character set for the import session. All four alert tables use `utf8mb4`. If any column contains non-UTF-8 binary data (unexpected but possible in scrape targets like `TransitAlert.description`), `PDO::quote()` will still escape it correctly because it operates on the raw byte string. The import will succeed if the column is declared as a binary or blob type; it may produce incorrect results if the column is declared as `VARCHAR CHARACTER SET utf8mb4` and the data contains invalid sequences.

**`db:import-sql` without `mysql` binary**

If the `mysql` CLI is not available in the execution environment (e.g., a minimal Docker image with only PHP), `db:import-sql` fails at process spawn. The error message must explicitly state that the `mysql` binary is required and suggest `./vendor/bin/sail artisan db:import-sql` as an alternative that runs inside the Sail container where `mysql` is available.

**Concurrent imports**

Two simultaneous `db:import-sql` executions against the same target database will not produce duplicates because `ON DUPLICATE KEY UPDATE id = id` is applied at the MySQL level. However, concurrent imports of the same file will produce redundant I/O. No application-level locking is implemented; this is considered an operator concern.

**`--compress` with direct `mysql` import**

The `mysql` CLI does not natively decompress gzip. Callers must decompress before importing:
```bash
gunzip -c alert-export.sql.gz | mysql -u user -p database
```
The `db:import-sql` command does not support `.gz` files. If a compressed file is passed, the command detects the `.gz` extension and emits an error with the correct decompress-and-pipe invocation.

---

## Acceptance Criteria

- [ ] `php artisan db:export-sql` produces a valid `.sql` file containing `INSERT INTO ... ON DUPLICATE KEY UPDATE id = id` statements for all four alert tables
- [ ] Exported SQL file is written to `storage/app/alert-export.sql` by default and is absent from git tracking
- [ ] `php artisan db:export-sql --tables=fire_incidents` exports only the specified table
- [ ] `php artisan db:export-sql --compress` produces a `.sql.gz` file
- [ ] `php artisan db:import-sql --file=alert-export.sql` imports the file into the active database
- [ ] `php artisan db:import-sql --dry-run --file=alert-export.sql` validates the file without executing any SQL
- [ ] `db:import-sql` rejects files containing `DROP TABLE`, `CREATE TABLE`, `ALTER TABLE`, or `TRUNCATE` statements
- [ ] `db:import-sql` prompts for confirmation when `--force` is not provided
- [ ] Importing the same file twice does not duplicate rows (`ON DUPLICATE KEY UPDATE` is idempotent)
- [ ] Importing into a database that already has live records preserves the live records (no row is overwritten by the import)
- [ ] `NULL` values in nullable columns are correctly emitted as the SQL literal `NULL` (not `''` or `'NULL'`)
- [ ] `scripts/export-alert-data.sh --sail` runs the export inside the Sail container and prints transfer instructions
- [ ] `scripts/generate-production-seed.sh` emits a deprecation notice directing users to `scripts/export-alert-data.sh`
- [ ] `ExportProductionData` and `VerifyProductionSeed` classes carry `@deprecated` docblocks
- [ ] Feature tests cover: export produces valid SQL, import dry-run rejects DDL, import dry-run accepts valid export, null column handling
- [ ] `composer run test` passes clean

# DRT Service Implementation Plan

## General Overview

This document outlines the complete implementation plan for integrating Durham Region Transit (DRT) service alerts and advisories into the RACC News platform. The implementation follows a phased approach to ensure systematic development and testing of each component.

The integration will support DRT Service Advisories (scraped from HTML), providing comprehensive transit information alongside existing TTC, GO Transit, and YRT alerts. The implementation leverages the existing Laravel job queue system, LLM processing pipeline, and frontend alert display components.

Key features include:
1.  Automated HTML scraping of DRT service advisories using Laravel's HTTP client and job queues
2.  LLM-powered content processing to extract structured alert data (routes, causes, effects, dates)
3.  Database storage with change detection using payload hashing for efficient updates
4.  Seamless integration with existing transit alert infrastructure and frontend components
5.  Search indexing via Laravel Scout for full-text search capabilities
6.  Scheduled execution aligned with other transit service collection jobs

This approach ensures consistency with the existing RACC News architecture while providing robust, scalable DRT alert integration.

## Performance Optimizations

### Early Pagination Termination and Stream Processing
The DRT alert processing includes intelligent pagination optimization that stops fetching additional pages when existing alerts are encountered. This reduces unnecessary API calls and processing time:

- **Stop on Existing**: When an alert with a known external ID is found, pagination halts immediately
- **Efficient Database Queries**: Uses `pluck('external_id')->flip()` for O(1) existence checks
- **Stream Processing**: Processes alerts per-page instead of collecting all alerts first, reducing memory usage
- **Per-Page Change Detection**: Checks for existing alerts and payload changes on each page before continuing
- **Immediate Processing**: Builds LLM processing and database upsert arrays as alerts are discovered
- **Logging**: Comprehensive logging when pagination stops early for monitoring and debugging

This optimization is particularly effective for DRT alerts as newer alerts appear first, making early termination highly beneficial for performance. The stream processing approach also reduces memory footprint for large alert sets.

---

## Implementation Overview

- **Phase 1**: Database schema and model setup
- **Phase 2**: Backend scraping logic and LLM integration
- **Phase 3**: Console commands and job scheduling
- **Phase 4**: Frontend integration and display

---

## Phase 1: Database and Model Setup

1.  **Create the Model and Migration:**
    *   Run the command: `php artisan make:model DrtServiceInfo -m`.
2.  **Define the Database Schema:**
    *   In the generated migration file for the `drt_service_info` table, define the columns to mirror the structure of `yrt_service_info` and `ttc_service_info`. This
ensures data consistency for the frontend controllers.
    *   **Schema:**

    ```php
    Schema::create('drt_service_info', function (Blueprint $table) {
        $table->id();
        $table->string('external_id')->unique()->comment('The unique ID from the source DRT article URL slug.');
        $table->string('source')->comment('The source, e.g., "drt-service-advisories".');
        $table->string('title')->comment('The main title of the alert.');
        $table->text('rewritten_subject')->nullable()->comment('AI-rewritten subject.');
        $table->text('rewritten_body_markdown')->nullable()->comment('AI-rewritten body in Markdown.');
        $table->string('route_number')->nullable()->comment('The route number(s).');
        $table->string('route_name')->nullable()->comment('The name of the route.');
        $table->string('cause')->nullable()->comment('The cause of the alert, e.g., Construction.');
        $table->string('effect')->nullable()->comment('The effect of the alert, e.g., Detour.');
        $table->timestamp('posted_at');
        $table->timestamp('effective_start_date')->nullable();
        $table->timestamp('effective_end_date')->nullable();
        $table->json('original_payload')->comment('The original full HTML of the advisory detail page.');
        $table->string('payload_hash')->comment('SHA1 hash of the original_payload to detect changes.');
        $table->timestamps();
    });
    ```
3.  **Configure the `DrtServiceInfo` Model:**
    *   In `app/Models/DrtServiceInfo.php`:
        *   Add the `HasFactory` and `Searchable` traits.
        *   Define the `$fillable` property with all the fields from the migration.
        *   Define the `casts()` method to cast date fields to `datetime` and `original_payload` to `array`.
        *   Implement the `toSearchableArray()` method, following the pattern in `TtcServiceInfo.php` to define the search index structure.

---

## Phase 2: Backend Logic and Scraping

1.  **Create a New Job:**
    *   Run the command: `php artisan make:job FetchAndProcessDrtAlertsJob`. This job will be responsible for scraping a single page of alerts and dispatching a job for the
next page if it exists.
2.  **Implement the Job's `handle()` Method:**
    *   **Step 2.1: Scrape List Page:** Use `Http::get()` to fetch the HTML from the path provided to the job (e.g., `/Modules/News/en?feedid=...`). Include necessary headers
to ensure a successful response.
    *   **Step 2.2: Parse Advisory List:** Use `Symfony\Component\DomCrawler\Crawler` to parse the response. Iterate through each `div.blogItem`. For each item, extract its
detail page URL (`h2 > a[href]`).
    *   **Step 2.3: Identify New/Updated Advisories:** For each advisory, fetch its detail page HTML. Calculate a `payload_hash` from this content. Compare this against
hashes stored in the `drt_service_info` table to identify new or updated advisories.
    *   **Step 2.4: Call LLM Service:** Collect the full HTML content of all new and updated advisories. Pass this array to a new `formatDrtAlerts` method in the
`LlmApiService`.
    *   **Step 2.5: Store Data:** Process the structured JSON array returned by the LLM. Use `DrtServiceInfo::upsert()` to efficiently create or update the records in the
database, matching on the `external_id`.
    *   **Step 2.6: Handle Pagination:** Check for a "next page" link (`.PagedList-skipToNext a`). If found, dispatch a new `FetchAndProcessDrtAlertsJob` for that URL to
continue the scraping chain.
3.  **Extend the `LlmApiService`:**
    *   **Step 3.1: Create New Method:** In `app/Services/LlmApiService.php`, add a new public method: `public function formatDrtAlerts(array $detailPagesHtml): array`.
    *   **Step 3.2: Create New Prompt:** Create a prompt file at `prompts/drt_alerts_formatter.md`. This prompt will instruct the LLM to process a batch of HTML documents and
extract key fields (Title, Routes, Reason, Dates, etc.) into a structured JSON array.
    *   **Step 3.3: Define Output Schema:** Add a corresponding private method `getDrtAlertsSchema()` to define and validate the expected JSON structure from the LLM.

---

## Phase 3: Console Command and Scheduling

1.  **Create an Artisan Command:**
    *   Run the command: `php artisan make:command FetchDrtAlerts`.
    *   In `app/Console/Commands/FetchDrtAlerts.php`, implement the `handle()` method to dispatch the initial `FetchAndProcessDrtAlertsJob` with the starting path
`/Modules/News/en?feedid=f3f5ff28-b7b8-45ab-8d28-fdf53f51d6bf`.
2.  **Schedule the Command:**
    *   In `bootstrap/app.php`, add the new `app:fetch-drt-alerts` command to the schedule to run periodically, consistent with other transit alert jobs.

---

## Phase 4: Frontend Integration

1.  **Update `PublicTransitAlertsController`:**
    *   In `app/Http/Controllers/PublicTransitAlertsController.php`:
        *   **Query DRT Data:** In the `index()` method, add logic to query the `DrtServiceInfo` model when the provider filter is `'DRT'` or `'all'`.
        *   **Create DRT Mapper:** Add a new private function `mapDrtAlerts(Collection $alerts): Collection`. This will transform `DrtServiceInfo` model data into the
standardized alert format the frontend expects.
        *   **Merge Results:** Merge the mapped DRT alerts into the main `$alerts` collection before sorting and passing to the Inertia view.
2.  **Update `HandleInertiaRequests` Middleware:**
    *   In `app/Http/Middleware/HandleInertiaRequests.php`, add a `'drt_alerts'` key to the `share()` method's return array. The closure will fetch the latest DRT alerts for
use in shared frontend components like the sidebar service alert widget.
3.  **Update Frontend Components:**
    *   In `resources/js/components/ServiceAlertWidget.tsx`, add a "DRT" tab to the `TabsList` to allow users to filter for Durham Region Transit alerts.
    *   In `resources/js/pages/transit-alerts.tsx`, add a "DRT" tab to the provider filter `TabsList` to enable filtering on the main transit alerts page.
# YRT Service Implementation Plan

This document outlines the complete implementation plan for integrating York Region Transit (YRT) service alerts and advisories into the RACC News platform. The implementation follows a phased approach to ensure systematic development and testing of each component.

The integration will support both YRT Service Advisories (scraped from HTML) and Service Changes (from JSON API), providing comprehensive transit information alongside existing TTC and GO Transit alerts. The implementation leverages the existing Laravel job queue system, LLM processing pipeline, and frontend alert display components.

## Implementation Overview

- **Phase 1**: Database schema and model setup
- **Phase 2**: Backend scraping logic and LLM integration  
- **Phase 3**: Console commands and job scheduling
- **Phase 4**: Frontend integration and display
- **Phase 5**: Extended service changes integration

---

## Phase 1: Database and Model Setup

1.  **Create the Model and Migration:**
    *   Run the command: `php artisan make:model YrtServiceInfo -m`.
2.  **Define the Database Schema:**
    *   In the generated migration file for the `yrt_service_info` table, define the columns to mirror the structure of `ttc_service_info`. This ensures data consistency for the frontend controllers.
    *   **Schema:**

    ```php
    Schema::create('yrt_service_info', function (Blueprint $table) {
        $table->id();
        $table->string('external_id')->unique()->comment('The unique ID from the source YRT article URL slug.');
        $table->string('source')->comment('The source, e.g., "yrt-service-advisories".');
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
3.  **Configure the `YrtServiceInfo` Model:**
    *   In `app/Models/YrtServiceInfo.php`:
        *   Add the `HasFactory` and `Searchable` traits.
        *   Define the `$fillable` property with all the fields from the migration (except `id`).
        *   Define the `casts()` method to cast `posted_at`, `effective_start_date`, and `effective_end_date` to `datetime`, and `original_payload` to `array`.
        *   Implement the `toSearchableArray()` method, following the pattern in `TtcServiceInfo.php` to define the search index structure.

---

## Phase 2: Backend Logic and Scraping

1.  **Create a New Job:**
    *   Run the command: `php artisan make:job FetchAndProcessYrtAlertsJob`.
2.  **Implement the Job's `handle()` Method:**
    *   **Service Dependencies:** The job handle method uses dependency injection for `LlmApiService` and `HtmlToMarkdownService` to process scraped HTML content and convert it to Markdown format for consistent display.
    *   **Step 2.1: Scrape List Page:** Use `Http::get()` to fetch the HTML from `https://www.yrt.ca/modules/news/en/serviceadvisories`.
    *   **Step 2.2: Parse Advisory List:** Use `Symfony\Component\DomCrawler\Crawler` to parse the response. Iterate through each `div.blogItem`. For each item, extract its detail page URL (`h2 > a[href]`).
    *   **Step 2.3: Identify New/Updated Advisories:** For each advisory, fetch its detail page HTML using `Http::get()`. Convert the HTML to Markdown using `HtmlToMarkdownService` for consistent formatting. Calculate a `payload_hash` from the converted Markdown content. Compare this against the hashes stored in the `yrt_service_info` table to identify which advisories are new or have been updated.
    *   **Step 2.4: Call LLM Service:** Collect the full HTML content of all new and updated advisories into an array. Pass this array to a new `formatYrtAlerts` method in the `LlmApiService`.
    *   **Step 2.5: Store Data:** Process the structured JSON array returned by the LLM. Use `YrtServiceInfo::upsert()` to efficiently create or update the records in the database, matching on the `external_id`.
3.  **Extend the `LlmApiService`:**
    *   **Step 3.1: Create New Method:** In `app/Services/LlmApiService.php`, add a new public method: `public function formatYrtAlerts(array $detailPagesHtml): array`.
    *   **Step 3.2: Create New Prompt:** Create a prompt file at `prompts/yrt_alerts_formatter.md`. This prompt will instruct the LLM to process a batch of HTML documents and extract the key fields (Title, Routes Affected, Reason, Dates, Detour info) into a structured JSON array, with one object per advisory.
    *   **Step 3.3: Define Output Schema:** Add a corresponding private method `getYrtAlertsSchema()` to define and validate the expected JSON structure from the LLM.

---

## Phase 3: Console Command and Scheduling

1.  **Create an Artisan Command:**
    *   Run the command: `php artisan make:command FetchYrtAlerts`.
    *   In `app/Console/Commands/FetchYrtAlerts.php`, implement the `handle()` method to dispatch the `FetchAndProcessYrtAlertsJob` and log informational messages to the console.
2.  **(Optional) Schedule the Command:**
    *   In `app/Console/Kernel.php`, add the new command to the schedule to run periodically.

---

## Phase 4: Frontend Integration

1.  **Update `PublicTransitAlertsController`:**
    *   In `app/Http/Controllers/PublicTransitAlertsController.php`:
        *   **Query YRT Data:** In the `index()` method, add logic to query the `YrtServiceInfo` model when the provider filter is `'YRT'` or `'all'`.
        *   **Create YRT Mapper:** Add a new private function `mapYrtAlerts(Collection $alerts): Collection` method. This will transform the `YrtServiceInfo` model data into the standardized alert format the frontend expects, ensuring fields like `provider`, `severity`, and `status` are correctly set.
        *   **Merge Results:** Merge the mapped YRT alerts into the main `$alerts` collection before it is sorted and passed to the Inertia view.
2.  **Update `HandleInertiaRequests` Middleware:**
    *   In `app/Http/Middleware/HandleInertiaRequests.php`, add a `'yrt_alerts'` key to the `share()` method's return array. The associated closure will fetch the latest YRT alerts for use in shared frontend components like the sidebar service alert widget.

## Phase 5: Integrate YRT Service Changes

This phase extends the YRT integration to include "Service Changes" from a secondary JSON API endpoint, supplementing the existing "Service Advisories" scraped from the
website.

1.  **Update Database and Model (`app/Models/YrtServiceInfo.php`)**
    *   **1.1: Create a Migration for Alert Type:**
        *   Run the command: `php artisan make:migration add_alert_type_to_yrt_service_info_table`.
        *   In the generated migration file, add an `alert_type` column to align with other transit data models and improve filtering capabilities.
        ```php
        Schema::table('yrt_service_info', function (Blueprint $table) {
            $table->string('alert_type')->nullable()->after('payload_hash')->comment('The type of alert, e.g., "service_advisory" or "service_change".');
        });
        ```
    *   **1.2: Update the `YrtServiceInfo` Model:**
        *   In `app/Models/YrtServiceInfo.php`, add `'alert_type'` to the `$fillable` array to make it mass-assignable.

2.  **Update Backend Scraping Logic (`app/Jobs/FetchAndProcessYrtAlertsJob.php`)**
    *   **2.1: Configure Service Changes URL:**
        *   The job already reads the URL from `config('services.yrt.service_changes_url')`. Ensure your `.env` file is configured with the correct endpoint:
        *   `YRT_SERVICE_CHANGES_URL="https://www.yrt.ca/Modules/NewsModule/services/getServiceAdvisories.ashx?categories=b8f1acba-f043-ec11-9468-0050569c41bf&lang=en"`
    *   **2.2: Implement `fetchServiceChanges()`:**
        *   In `app/Jobs/FetchAndProcessYrtAlertsJob.php`, modify the `fetchServiceChanges()` method to correctly parse the JSON API response.
        *   The method should use Laravel's `Http` client, handle potential request failures, and map the response array.
        *   A unique `id` for each alert must be derived from the `link` field, as the API response does not provide a stable ID.
        ```php
        // in app/Jobs/FetchAndProcessYrtAlertsJob.php -> fetchServiceChanges()
        // ...
        return collect($changes)->map(function ($change) {
            if (! isset($change['link'])) {
                return null;
            }

            return [
                'id' => basename($change['link']),
                'data' => $change,
                'type' => 'service_change',
            ];
        })->filter();
        ```
    *   **2.3: Update `handle()` to Process Service Change Data:**
        *   In the `handle()` method, the logic for processing alerts of type `'service_change'` must be updated to match the structure of the data from the new endpoint.
        *   This involves adjusting the `external_id` derivation and date parsing. The data from this endpoint will be stored directly without being processed by the LLM
service.
        ```php
        // in app/Jobs/FetchAndProcessYrtAlertsJob.php -> handle() -> else block
        $data = $alert['data'];
        $finalAlertsToUpsert[] = [
            'external_id' => basename($data['link']), // Use basename of the link
            'source' => 'yrt-service-changes',
            'title' => $data['title'] ?? 'Service Change',
            'rewritten_subject' => $data['title'] ?? null,
            'rewritten_body_markdown' => trim($data['description']) ?? null, // Trim whitespace
            'route_number' => null, // Not available in API response
            'route_name' => null, // Not available in API response
            'cause' => null, // Not available in API response
            'effect' => null, // Not available in API response
            'posted_at' => Carbon::parse("{$data['postedDate']} {$data['postedTime']}"), // Combine date/time fields
            'effective_start_date' => null, // Not available in API response
            'effective_end_date' => null, // Not available in API response
            'original_payload' => json_encode($data), // Store original JSON object
            'payload_hash' => sha1(json_encode($data)),
            'alert_type' => 'service_change',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ];
        ```
    *   **2.4: Update the `upsert` Operation:**
        *   Confirm that `'alert_type'` is included in the list of fields within the `YrtServiceInfo::upsert()` call to ensure it is saved correctly during create and update
operations.

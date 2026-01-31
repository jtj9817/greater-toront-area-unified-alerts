# Toronto Police Scraper - Cloud Architecture

## Executive Summary

To reliably scrape the Toronto Police Service (TPS) "Calls for Service" dashboard, we will deploy a **decoupled sidecar microservice** on **Google Cloud Run**. 

Running a headless browser (Chromium) inside the main monolithic PHP/Laravel container is an anti-pattern in serverless environments due to image bloat, dependency conflicts, and memory management issues. Instead, a lightweight Node.js service using **Playwright** will handle the browser automation, triggered by the main Laravel application.

---

## High-Level Architecture

```mermaid
graph LR
    subgraph "Cloud Run Environment"
        A[Laravel App (Orchestrator)] -- HTTP POST /scrape --> B[Scraper Service (Node.js/Playwright)]
        B -- Spawns --> C[Headless Chromium]
        C -- Intercepts JSON --> D[ArcGIS FeatureServer]
        B -- Returns JSON Payload --> A
    end
    A -- Upsert --> E[(Cloud SQL / Database)]
```

### Component 1: The Scraper Service (Microservice)

A dedicated, lightweight Node.js Express application responsible solely for launching the browser, performing the interaction, and returning structured data.

*   **Runtime:** Node.js 20+ (TypeScript)
*   **Library:** Playwright (Microsoft)
*   **Base Image:** `mcr.microsoft.com/playwright:v1.41.0-jammy`
*   **Endpoint:** `POST /api/v1/scrape/tps-calls`

**Why Node.js?**
While Go is efficient for general microservices, **Node.js is the pragmatic choice for browser automation**. The resource consumption is dominated by the Chrome process (500MB+), making the runtime overhead negligible. Playwright's native JavaScript API offers the most robust handling of complex async web events (Promise-based waiting) compared to low-level CDP wrappers in other languages.

### Component 2: The Orchestrator (Laravel)

The main Laravel application manages scheduling and data ingestion.

*   **Command:** `police:fetch-incidents`
*   **Schedule:** Every 15 minutes (TPS updates every 20 mins).
*   **Authentication:** Service-to-Service authentication via Google Cloud IAM (OIDC).

---

## Infrastructure Configuration (Cloud Run)

Browser automation is resource-intensive. The default Cloud Run specs are insufficient.

**Service Specifications:**
*   **Memory:** **2 GiB minimum** (4 GiB recommended). Chromium creates separate processes for tabs and rendering.
*   **CPU:** 1 vCPU (2 vCPU speeds up cold start).
*   **Timeout:** **60 seconds** (Process should race against this timeout).
*   **Concurrency:** **1 (Single Request)**. We process one scrape per container instance to ensure full memory availability for that browser session.
*   **Execution Environment:** `gen2` (Linux) for better binary compatibility.

**Critical Docker Flags:**
To ensure stability in the containerized environment, the browser must be launched with specific flags:

```javascript
const browser = await chromium.launch({
    args: [
        '--no-sandbox',            // Required for Docker environments
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage', // CRITICAL: Writes shared memory to /tmp to prevent crashes
        '--disable-gpu'
    ]
});
```

---

## Reliability Strategy

1.  **Stealth & Detection:**
    *   Use standard User-Agent rotation.
    *   Playwright's `waitForResponse` ensures we capture data exactly when the application fetches it.

2.  **Memory Leaks:**
    *   Browser contexts are created *per request* and aggressively closed.
    *   Container instances are ephemeral; Cloud Run handles recycling.

3.  **Failure Recovery:**
    *   **Selector Changes:** If the "Terms of Use" modal DOM changes, the scraper returns a 500 error. The Laravel command logs this to Sentry but **does not purge** existing active alerts.
    *   **API Changes:** If the network interception fails, a fallback DOM parsing logic can be implemented.

---

## Implementation Roadmap

1.  **Scaffold Service:** Create `services/scraper` directory in the monorepo.
2.  **Dockerize:** Create a `Dockerfile` specifically for the Node.js/Playwright stack.
3.  **Develop Handler:** Implement `server.ts` with the Playwright logic defined in the main scraping doc.
4.  **Integrate:** Write the `TorontoPoliceFeedService` in Laravel to call this microservice.

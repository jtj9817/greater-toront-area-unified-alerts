# Toronto Police Calls for Service - Data Extraction

## Context

The Toronto Police Service (TPS) provides a real-time "Calls for Service" dashboard using the [ArcGIS Experience Builder](https://experience.arcgis.com/experience/a22f5295933e48a5b0a4c90cd3c4cae1/page/CFS/). Unlike the Toronto Fire feed, which is a simple XML endpoint, the TPS dashboard is a complex single-page application (SPA) that requires user interaction (accepting a disclaimer) and executes dynamic queries against an ArcGIS Feature Server.

This document outlines the technical mechanisms for extracting this data using browser automation (Playwright/CDP) to ensure session-validity and handle front-end logic, while leveraging network interception to obtain high-fidelity structured JSON.

---

## Scraping Mechanisms

### 1. Browser Automation (Playwright / CDP)
The application loads a mandatory "Terms of Use" modal on arrival. Automated scraping must navigate this state before the data becomes accessible in the DOM or via network requests.

**Automation Workflow:**
1. **Navigate:** Load the dashboard URL.
2. **Modal Interaction:**
   - Locate the "I understand" checkbox (`role="checkbox"`).
   - Click/Check to enable the "Proceed" button.
   - Click the "Proceed" button (`role="button"`).
3. **Data Access:** Once the modal is cleared, the application populates a `listbox` (ARIA role) with active calls.

### 2. Network Request Interception (Recommended)
While the DOM can be scraped, the application fetches data from a structured REST API. The most robust approach is to use Playwright or CDP to intercept the `POST` or `GET` requests sent to the ArcGIS FeatureServer. This provides clean JSON without the fragility of CSS selector-based scraping.

**Intercept Target:**
`https://services.arcgis.com/S9th0jAJ7bqgIRjw/arcgis/rest/services/C4S_Public_NoGO/FeatureServer/0/query`

- **Method:** `GET` (or `POST`)
- **Query Params:** `f=json`, `where=1=1`, `outFields=*`
- **Updates:** The application reports updates every 20 minutes.

---

## Data Source (ArcGIS REST API)

### JSON Schema

```json
{
  "features": [
    {
      "attributes": {
        "OBJECTID": 10,
        "OCCURRENCE_TIME": 1769888215000,
        "DIVISION": "D31",
        "LATITUDE": 43.758907985682086,
        "LONGITUDE": -79.50954871925563,
        "CALL_TYPE": "ASSAULT JUST OCCURRED",
        "CROSS_STREETS": "TOPCLIFF AVE - DEMARIS AVE"
      },
      "geometry": { "x": -79.50954871925563, "y": 43.758907985682086 }
    }
  ]
}
```

### Field Reference

| API Field | DB Column | Type | Description |
|---|---|---|---|
| `OBJECTID` | `remote_id` | `bigInt` (unique) | Unique identifier in the ArcGIS system. |
| `CALL_TYPE` | `incident_type` | `string` | Type of service call (e.g., "ARREST", "FIRE", "UNKNOWN TROUBLE"). |
| `DIVISION` | `division` | `string` | Police division responsible for the call. |
| `CROSS_STREETS` | `location` | `string` | Intersecting streets or area description. |
| `OCCURRENCE_TIME` | `occurrence_at` | `datetime` | Epoch timestamp (milliseconds) of the occurrence. |
| `LATITUDE` | `latitude` | `decimal(10,8)` | Geographic latitude. |
| `LONGITUDE` | `longitude` | `decimal(11,8)` | Geographic longitude. |

---

## Implementation Plan

### Architecture

```
app/
├── Services/
│   └── TorontoPoliceFeedService.php    # Playwright/Puppeteer bridge or direct HTTP
├── Console/Commands/
│   └── FetchPoliceCallsCommand.php     # police:fetch-calls artisan command
├── Models/
│   └── PoliceIncident.php              # Eloquent model for police data
database/migrations/
│   └── [timestamp]_create_police_incidents_table.php
```

### Database Schema

Table: `police_incidents`

```sql
id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
remote_id       BIGINT UNSIGNED UNIQUE -- ArcGIS OBJECTID
incident_type   VARCHAR(255)
division        VARCHAR(255)
location        VARCHAR(255) NULLABLE
latitude        DECIMAL(10, 8)
longitude       DECIMAL(11, 8)
occurrence_at   DATETIME
is_active       BOOLEAN DEFAULT TRUE
created_at      TIMESTAMP
updated_at      TIMESTAMP

INDEX (is_active, occurrence_at)
INDEX (incident_type)
```

### Automation Script (Conceptual Playwright)

```javascript
const { chromium } = require('playwright');

async function scrapeTPS() {
  const browser = await chromium.launch();
  const page = await browser.newPage();
  
  // Navigate and handle the disclaimer
  await page.goto('https://experience.arcgis.com/experience/a22f5295933e48a5b0a4c90cd3c4cae1/page/CFS/');
  await page.click('div[role="checkbox"]'); // "I understand"
  await page.click('button:has-text("Proceed")');

  // Intercept the FeatureServer response
  const response = await page.waitForResponse(res => 
    res.url().includes('FeatureServer/0/query') && res.status() === 200
  );
  
  const data = await response.json();
  // Pass JSON back to Laravel via CLI or API
  await browser.close();
}
```

---

## Observation & Constraints

- **Update Frequency:** The TPS CAD system refreshes this feed every 20 minutes. Polling more frequently than every 10-15 minutes provides diminishing returns.
- **Privacy Exclusions:** Per the dashboard disclaimer, calls involving domestic violence, sexual assault, or medical distress are excluded from the public feed.
- **Session Tokens:** The ArcGIS API sometimes requires ephemeral tokens passed in headers or as query parameters. Scraping via a headless browser automatically handles these token negotiations.
- **Geospatial Data:** Geometry is provided in `EPSG:4326` (WGS84), suitable for direct use in web maps.

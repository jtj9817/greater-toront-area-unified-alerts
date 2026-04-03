# FEED-058: YRT `body_text` Contains Full Page Noise (Nav, Footer, Sidebars)

## Meta

- **Issue Type:** Bug — Data Quality
- **Priority:** P2
- **Status:** Closed
- **Labels:** `alerts`, `yrt`, `backend`, `feed-service`, `data-quality`, `scraper`
- **Fix Commit:** `25b5ab4`
- **Affected Files:**
  - `app/Services/YrtServiceAdvisoriesFeedService.php` — `extractBodyTextFromHtml()` (lines 233–280 pre-fix)
- **Related Tickets:** FEED-053 (YRT feed service review), FEED-006 (GO Transit HTML sanitization — similar class of issue)

## Summary

The `body_text` column in `yrt_alerts` stored the entire YRT detail page as a single text blob — including navigation menus, footer links, sidebar content, cookie notices, and browser compatibility warnings — instead of just the alert's article body.

This happened because `extractBodyTextFromHtml()` queried `//main | //article | //body` and selected whichever container had the most text. The YRT website (`yrt.ca`) wraps its full page (nav, mega-menu, footer, sidebars) inside the `<main>` semantic element, so `<main>` always "won" the largest-content heuristic.

## Reproduction

Affected alerts: all YRT alerts where `body_text` is populated (i.e., detail pages were successfully fetched).

**Observed `body_text` for alert "Ongoing Detours at Finch Terminal" (id=9):**

```
Skip to Content Toggle search popup Accessibility and Accommodation Search Service Advisories Schedules and MapsTrip PlannerService Changes and UpdatesSchedule FinderConnecting ServicesService SchedulesViva RoutesLocal RoutesService to GO StationsExpress RoutesSchool RoutesOn-Request...Platforms 1 to 12 at Finch GO Bus Terminal are currently closed...About UsAccessibility and AccommodationAsk Us a Question...York Region TransitSchedules and MapsFares and PassesTravelling with UsAbout Us Provide Transit Feedback © 2026 York RTA Contact UsFeedbackSitemapPrivacy Policy By GHD Digital Browser Compatibility Notification...
```

**Expected `body_text`:**

```
Platforms 1 to 12 at Finch GO Bus Terminal are currently closed until further notice for emergency hydro tower work.All YRT routes will continue to service the terminal; however, your bus may be servicing a different platform during this time...
```

**Noise ratio:** ~30% of stored text was nav/footer/sidebar boilerplate.

## Root Cause Analysis

### Extraction Logic (Pre-Fix)

`extractBodyTextFromHtml()` performed:

1. Load HTML into `DOMDocument`
2. Strip `<script>`, `<style>`, `<noscript>` nodes
3. Query `//main | //article | //body`
4. Pick the candidate with the **longest** `textContent`
5. Return normalized text

### Why It Failed on YRT Pages

The YRT CMS (Sitecore-based, served by GHD Digital) structures pages as:

```html
<body>
  <nav><!-- mega-menu with ~50 links --></nav>
  <main>
    <div class="topContentWrapper"><!-- title + toolbar --></div>
    <div class="nocontent"><!-- share buttons --></div>
    <div class="ge-content ge-content-type-gridHTML">  <!-- ACTUAL ARTICLE -->
      ...alert body content here...
    </div>
    <div class="nocontent"><!-- footer sidebar --></div>
  </main>
  <footer><!-- full footer with links --></footer>
</body>
```

- `<main>` contains the article **plus** the toolbar, share buttons, and sidebar — always the largest candidate.
- `<article>` is absent from YRT pages.
- `<body>` is even noisier (adds nav + footer on top of `<main>`).
- The actual article content lives in `div.ge-content.ge-content-type-gridHTML`.

### Selector Comparison

| Selector | Length | Content Start |
|---|---|---|
| `div.ge-content` | 1,745 chars | "Platforms 1 to 12 at Finch GO Bus Terminal..." |
| `main` (old winner) | 2,299 chars | "console.log(defaultInterior); Ongoing Detours..." |
| `body` | 4,504 chars | "Skip to Content Toggle search popup..." |

## Fix

Added `div.ge-content` as a **first-priority selector** before the `//main|//article|//body` fallback heuristic:

```php
// YRT uses div.ge-content for the actual article body — prefer it
// over <main>/<article> which include nav, footer, and sidebar noise.
$articleContent = $xpath->query('//div[contains(@class, "ge-content")]');

if ($articleContent !== false && $articleContent->length > 0) {
    $text = $this->normalizeText($articleContent->item(0)->textContent);

    if ($text !== null) {
        return $text;
    }
}

// Fallback: largest content among semantic containers (unchanged)
$candidates = $xpath->query('//main|//article|//body');
// ...
```

### Why `ge-content` Is a Safe Selector

- Present on all tested YRT news/service-advisory pages (6 pages verified).
- YRT's CMS uses this class for the primary body content region (likely "Grid HTML" content type in Sitecore).
- If YRT removes or renames this class, the code gracefully falls back to the original `<main>/<article>/<body>` heuristic — no worse than before.

## Verification

- **Live test:** Ran the updated `extractBodyTextFromHtml()` against three YRT pages via tinker — all returned clean article-only text.
- **Unit tests:** All 60 YRT tests pass (`php artisan test --compact --filter=Yrt`).
- **Lint:** Pint passes clean.

## Stale Data Note

Existing rows in `yrt_alerts.body_text` still contain the old noisy text. These will be refreshed automatically on the next fetch cycle when the 24-hour staleness window triggers a detail re-fetch (`shouldFetchDetails()` → stale refresh condition). No manual data migration is required.

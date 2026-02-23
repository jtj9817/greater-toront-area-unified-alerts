---
ticket_id: FEED-006
title: "[Bug] GO Transit MessageBody Contains Raw CSS After strip_tags()"
status: Open
priority: High
assignee: Unassigned
created_at: 2026-02-23
tags: [bug, backend, go-transit, scraper, data-quality]
related_files:
  - app/Services/GoTransitFeedService.php
  - tests/Feature/Services/GoTransitFeedServiceTest.php
---

## Summary

GO Transit alert descriptions display raw CSS rulesets and class names inline with alert text. The root cause is that `strip_tags()` in `GoTransitFeedService::parseNotifications()` removes HTML tags but preserves the **text content** of `<style>` blocks, leaking embedded CSS into the stored `message_body` column.

## Reproduction

Affected alerts: any GO Transit notification where the Metrolinx API returns `MessageBody` containing `<style>` blocks (observed consistently on Station-type alerts and some Train/Bus notifications).

**Observed output (stored in `go_transit_alerts.message_body`):**

```
Station. .masteroverridePublic_En {font-family: "Arial" !important;font-size:10.5pt !important;}
.masteroverridePublic_En p {font-family: "Arial" !important;font-size:10.5pt !important;}
...
As a result of a struck light pole, there is a local power outage affecting your bus station...
```

**Expected output:**

```
As a result of a struck light pole, there is a local power outage affecting your bus station. The lighting at your station, heating within the station, elevators, Ticket Vending Machines (TVM), PRESTO tap-on/tap-off machines and washrooms are temporarily unavailable. To purchase an e-ticket, click here.
```

## Root Cause Analysis

### Data Source

The Metrolinx JSON API (`api.metrolinx.com/external/go/serviceupdate/en/all`) returns `MessageBody` fields containing rich HTML with:

1. Embedded `<style type="text/css">` blocks defining `.masteroverridePublic_En` CSS rules
2. Nested `<div>` / `<span>` elements with `class="masteroverridePublic_En"` and inline `style` attributes
3. HTML entities (`&nbsp;`) for spacing

Example raw API payload:

```html
<style type="text/css">
.masteroverridePublic_En {font-family: "Arial" !important;font-size:10.5pt !important;}
.masteroverridePublic_En p {font-family: "Arial" !important;font-size:10.5pt !important;}
.masteroverridePublic_En div {font-family: "Arial" !important;font-size:10.5pt !important;}
.masteroverridePublic_En span {font-family: "Arial" !important;font-size:10.5pt !important;}
.masteroverridePublic_En li {font-family: "Arial" !important;font-size:10.5pt !important;}
</style>
<div class="masteroverridePublic_En"><div><div><span style="font-size:10.5pt;font-family:&quot;Arial&quot;,sans-serif">
As a result of a struck light pole, there is a local power outage...&nbsp;&nbsp;To purchase an e-ticket, click here.&nbsp;
</span></div></div></div>
```

### Bug Location

`app/Services/GoTransitFeedService.php`, line 196, inside `parseNotifications()`:

```php
$body = trim((string) ($notification['MessageBody'] ?? ''));
$body = $body !== '' ? strip_tags($body) : null;
```

`strip_tags()` removes only the HTML **tags** (the angle-bracket delimiters), not their **text content**. For structural tags like `<div>`, `<p>`, `<b>`, this is correct — their text content is the visible text we want. But `<style>` (and `<script>`) tags contain non-visible content (CSS / JS source) that becomes garbage text when the tags are removed.

### Impact Path

```
Metrolinx API (MessageBody with <style> blocks)
  → GoTransitFeedService::parseNotifications() line 196
    → strip_tags() preserves CSS text content
      → stored in go_transit_alerts.message_body
        → GoTransitAlertSelectProvider packs into meta JSON
          → UnifiedAlertResource sends to frontend
            → buildGoTransitDescriptionAndMetadata() reads meta.message_body
              → AlertDetailsView renders CSS as visible text
```

## Fix Specification

### Approach

Replace the bare `strip_tags()` call with a multi-step HTML-to-plaintext pipeline:

1. **Remove `<style>` blocks** — `preg_replace('/<style\b[^>]*>.*?<\/style>/si', '', $html)` (non-greedy, case-insensitive, dotall)
2. **Remove `<script>` blocks** — same pattern for `<script>` (defensive; not observed in current payloads but standard sanitization)
3. **Strip remaining HTML tags** — `strip_tags()` for `<div>`, `<span>`, `<p>`, `<b>`, `<a>`, `<br>`, etc.
4. **Decode HTML entities** — `html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')` to convert `&nbsp;` → space, `&amp;` → `&`, etc.
5. **Normalize whitespace** — `preg_replace('/\s+/', ' ', $text)` to collapse multi-space / newline runs
6. **Trim** — `trim()` final result

### File Changes

**`app/Services/GoTransitFeedService.php`**

- Add private method `stripHtmlToText(string $html): string` implementing the 6-step pipeline above.
- Replace line 196 (`strip_tags($body)`) with `$this->stripHtmlToText($body)`.

**`tests/Feature/Services/GoTransitFeedServiceTest.php`**

- Add test case: `MessageBody` with `<style>` block + `.masteroverridePublic_En` CSS + nested divs (mirrors real API payload). Assert output contains only visible text.
- Add test case: `MessageBody` with `&nbsp;` entities. Assert entities are decoded to spaces and normalized.

### Existing Data

Records already stored in `go_transit_alerts.message_body` contain the corrupted text. These will self-heal on the next feed cycle: `FetchGoTransitAlertsCommand` upserts by `external_id`, so the next fetch will overwrite `message_body` with the correctly sanitized value. No migration or backfill needed.

## Acceptance Criteria

- [ ] GO Transit alerts with `<style>` blocks in `MessageBody` produce clean plaintext in `message_body`
- [ ] HTML entities (`&nbsp;`, `&amp;`, `&quot;`) are decoded to their character equivalents
- [ ] Existing test `'it strips html from message body'` continues to pass (backward compatibility)
- [ ] New test covers the exact `masteroverridePublic_En` CSS pattern from production
- [ ] `composer run test` passes clean

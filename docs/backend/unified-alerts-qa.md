# Unified Alerts Design Choices (Q&A)

This document captures the “why” behind the unified alerts architecture decisions, in a format intended to be easy to revisit later (including for technical interviews).

## Q: What problem are we solving?

We want a single “alerts feed” that combines multiple sources (Toronto Fire, Toronto Police, future Transit) while supporting a consistent browsing experience:

- Users can page through alerts without items “disappearing” when they become inactive.
- The feed is truly paginated at the database layer (not “load everything then slice”).
- The implementation stays straightforward given we are committed to MySQL.

## Q: Why did we choose UNION (read-time unification) over a projection table?

We chose the UNION approach because:

- We’re staying on a single database (MySQL), so we can safely leverage MySQL’s capabilities.
- We want the unified feed to remain a pure read model derived from existing source tables, without introducing a second write-path that must stay in sync.
- For our scale and expected usage, a single UNION query (with proper indexes and ordering) is simpler to operate than a projection pipeline.

Trade-off we accept:

- Deep history paging can make OFFSET pagination slower. If this becomes noticeable, we plan to switch to cursor/keyset pagination using a deterministic cursor tuple `(timestamp, source, external_id)`.

## Q: Why not use SQL triggers to maintain a projection table?

In the UNION approach, there is no projection table, so there is nothing to “sync”.

If we ever adopt a projection table later, we still prefer syncing it in the application’s scrape/update commands (explicit, testable, observable) rather than triggers (implicit, harder to debug during bulk operations, and less visible in app logs/metrics).

## Q: Why must pagination include history (active + cleared)?

Because the user’s mental model is “I saw an alert; I should be able to find it again.”

If the feed only paged over `is_active = true`, alerts would vanish from the feed the moment they clear, which is confusing (especially if the user is mid-browse or returning later).

So the unified feed pages over both states:

- `status=all` (default): active + cleared mixed by recency
- `status=active`: active only
- `status=cleared`: cleared only

## Q: Why is the default `status=all`?

It optimizes for continuity:

- The feed behaves like a timeline.
- Alerts don’t “drop out” when the backend marks them inactive.
- Users can still filter down to only active if they want.

This default does require the UI to clearly label inactive items (e.g., “Cleared”), which is why the transport includes `is_active` and the frontend view-model is expected to derive a `status` field.

## Q: How do we keep pagination stable so results don’t shuffle between pages?

We always order by a deterministic tuple, not just by timestamp alone:

1) `timestamp` DESC
2) `source` ASC
3) `external_id` DESC (or another unique per-source stable key)

This prevents ambiguous ordering when multiple rows share the same timestamp, which is essential for predictable pagination and for cursor pagination later.

## Q: How does this fit the existing GTA Alerts frontend?

The existing UI already uses a view-model (`AlertItem`) and a mapping layer (`AlertService`) to produce display-ready fields (icons, accent colors, “time ago”, etc.).

So we keep a clean separation:

- Backend returns a transport shape (`UnifiedAlertResource`) that includes source, IDs, timestamp, location, `is_active`, and `meta`.
- Frontend maps `UnifiedAlertResource` → `AlertItem`, deriving:
  - `status` from `is_active`
  - `timeAgo` from `timestamp`
  - `severity`, `iconName`, `accentColor` from `source` + `meta`

This keeps UI concerns out of the backend and minimizes churn in the existing component tree.

## Q: What happens when we add a new source (e.g., Transit)?

We add a new “select provider” that returns the standardized unified columns and UNION it into the query.

This is a predictable extension point:

- New source table + scraper job/command.
- New select provider mapping to the unified columns.
- Optional frontend mapping enhancements to classify icons/severity and show source-specific details.

## Q: What are the main risks and mitigations?

- Risk: OFFSET pagination becomes slow on deep history.
  - Mitigation: keyset pagination via `(timestamp, source, external_id)` cursor.
- Risk: `meta` JSON grows and becomes hard to query.
  - Mitigation: promote frequently-filtered fields into first-class columns in the unified select (and index them), leaving the rest in `meta`.
- Risk: Inactive items aren’t visually distinguishable.
  - Mitigation: require UI to show a “Cleared” badge/state derived from `is_active`.

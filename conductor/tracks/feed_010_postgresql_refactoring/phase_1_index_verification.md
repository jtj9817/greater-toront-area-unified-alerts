# Phase 1 Verification: PostgreSQL FTS Index Presence and Planner Usage

Date: 2026-02-26

Use this checklist after deploying or migrating on a PostgreSQL environment.

## 1) Confirm index existence

Run:

```sql
SELECT tablename, indexname, indexdef
FROM pg_indexes
WHERE schemaname = 'public'
  AND indexname IN (
    'fire_incidents_fulltext',
    'police_calls_fulltext',
    'transit_alerts_fulltext',
    'go_transit_alerts_fulltext'
  )
ORDER BY indexname;
```

Expected:
- Four rows returned.
- Each `indexdef` includes `USING gin` and `to_tsvector('simple', concat_ws(' ', ...))`.

## 2) Confirm planner uses FTS index expression

Run representative `EXPLAIN` queries matching provider search expressions.

```sql
EXPLAIN SELECT id
FROM fire_incidents
WHERE to_tsvector('simple', concat_ws(' ', event_type, prime_street, cross_streets))
      @@ plainto_tsquery('simple', 'queen');
```

```sql
EXPLAIN SELECT id
FROM police_calls
WHERE to_tsvector('simple', concat_ws(' ', call_type, cross_streets))
      @@ plainto_tsquery('simple', 'assault');
```

```sql
EXPLAIN SELECT id
FROM transit_alerts
WHERE to_tsvector('simple', concat_ws(' ', title, description, stop_start, stop_end, route, route_type))
      @@ plainto_tsquery('simple', 'detour');
```

```sql
EXPLAIN SELECT id
FROM go_transit_alerts
WHERE to_tsvector('simple', concat_ws(' ', message_subject, message_body, corridor_or_route, corridor_code, service_mode))
      @@ plainto_tsquery('simple', 'lakeshore');
```

Expected:
- Plan includes a bitmap index path (`Bitmap Index Scan`) or index scan using the corresponding `*_fulltext` index.
- If planner chooses sequential scan for very small tables, validate again with realistic row counts.

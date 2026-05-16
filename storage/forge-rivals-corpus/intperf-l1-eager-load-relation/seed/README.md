# Seed · intperf-l1-eager-load-relation

`CaptureListing::recent()` lists the most recent captures and exposes
`author->name` per row. Today it runs **1 query** to fetch the captures
plus **N queries** to lazily fetch each author. Add eager loading so
the call site issues at most **2 queries** total.

Constraints:
- Output JSON shape must not change.
- No new DB schema, no new dependency.
- The arm only touches `CaptureListing.php` and the test.

`CaptureRepository` (provided) exposes a simple in-memory simulation
with a `queryCount` accessor — the test asserts `queryCount() <= 2`
after `recent()` is called.

## Files
- `CaptureListing.php`, `CaptureRepository.php` — scaffolds.
- `CaptureListingTest.php` — query-budget regression.

## Pass criteria
```
php artisan test --filter='CaptureListingTest'
```

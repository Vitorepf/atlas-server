# Seed · bugfix-l2-timezone-double-utc

`TimestampFormatter::format($iso)` is double-applying the UTC offset.
A 12:00 UTC input comes out as 06:00 UTC because the formatter first
parses the timestamp as Sao_Paulo-local then converts again to UTC.

Fix the function so that:

- A canonical ISO8601 input in UTC stays in UTC.
- Inputs in `America/Sao_Paulo` and `Europe/Berlin` are converted to
  UTC exactly once.
- The function stays pure (no globals, no I/O).

A regression test covers the 3 timezones plus the canonical UTC case.

## Files
- `TimestampFormatter.php` — buggy implementation.
- `TimestampFormatterTest.php` — regression suite.

## Pass criteria
```
php artisan test --filter='TimestampFormatterTest'
```

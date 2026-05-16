# Seed · architecture-l1-public-api-readme

`CaptureService` (provided) exposes exactly 4 public methods. Author
`app/Domain/Captures/README.md` documenting **only** those 4 methods,
covering:

- exact PHP signature (return type included)
- one-line description
- documented error mode (thrown exception class or returned error code)
- usage example (short snippet)

Markdownlint-friendly (heading hierarchy, fenced code blocks).

A scaffold test `PublicApiReadmeTest` parses the README and asserts the
4 method names appear as `### method(...)` headings and the README only
documents what is actually public.

## Files
- `CaptureService.php` — read-only fixture: lists the public surface.
- `tests/Unit/Captures/PublicApiReadmeTest.php` — golden checker scaffold.

## Pass criteria
```
php artisan test --filter='PublicApiReadmeTest'
```

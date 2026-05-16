# Seed · testdesign-l1-add-edge-case-tests

`CaptureMarkdownParser` (provided, read-only) parses inbox capture
markdown. The current test only covers the happy path. Add **4 named
edge-case tests** to `CaptureMarkdownParserTest`:

1. empty input
2. whitespace-only input
3. emoji-only input
4. invalid embedded JSON inside fenced ``` ```json block

Constraints:
- Do not touch the parser (production stays byte-for-byte).
- Tests must be deterministic (no `rand()` without explicit seed).

## Files
- `CaptureMarkdownParser.php` — read-only fixture.
- `CaptureMarkdownParserTest.php` — happy-path scaffold the arm extends.

## Pass criteria
```
php artisan test --filter='CaptureMarkdownParserTest'
```

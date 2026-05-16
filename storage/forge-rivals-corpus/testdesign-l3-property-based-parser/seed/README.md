# Seed · testdesign-l3-property-based-parser

Add a property-based test for `CaptureMarkdownParser` covering the
roundtrip property:

```
serialize(parse(x)) === x   for every well-formed markdown x
```

The harness must:

- Use a deterministic seed (e.g. `mt_srand(42)`).
- Generate 100 inputs.
- On failure, shrink the input deterministically (e.g. via halving)
  so the reported failing input is minimal, not the original giant input.

No external property-testing library — implement the generator + shrinker
inline. Production code stays untouched.

## Files
- `CaptureMarkdownParser.php` — read-only fixture providing parse + serialize.
- `PropertyBasedParserTest.php` — scaffold.

## Pass criteria
```
php artisan test --filter='PropertyBasedParserTest'
```

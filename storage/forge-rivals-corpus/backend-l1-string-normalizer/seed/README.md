# Seed · backend-l1-string-normalizer

Implement `App\Support\StringNormalizer::canonical(string $value): string`.
The function MUST:

- Trim whitespace at both ends.
- Collapse internal whitespace runs into a single space.
- Casefold (lowercase) ASCII letters; preserve non-ASCII codepoints
  byte-for-byte (do **not** strip accents).
- Be idempotent: `canonical(canonical($x)) === canonical($x)`.
- Be pure: no globals, no I/O, no side-effects.

A scaffold `StringNormalizer` is provided that returns the input untouched.
Tests cover 8 tabulated entries (including unicode) plus idempotency.

## Files
- `StringNormalizer.php` — scaffold to extend.
- `StringNormalizerTest.php` — 8-row golden + idempotence.

## Pass criteria
```
php artisan test --filter='StringNormalizerTest'
```

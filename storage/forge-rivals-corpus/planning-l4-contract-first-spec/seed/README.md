# Seed · planning-l4-contract-first-spec

Produce two artefacts before any implementation:

1. `docs/planning/inbox/feature.contract.md` — declares the DTO shape
   (one canonical struct), its invariants, the error modes (named
   `error_code` strings), and an explicit `backward_compatibility` note
   stating what may/may not change in a later release.
2. `docs/planning/inbox/feature.contract.examples.json` — a JSON array
   of at least 3 valid examples and 2 invalid examples. Each example is
   `{name, payload, expect: {kind: "ok"|"error", error_code?: string}}`.

The provided `ContractSpecTest` parses the contract markdown for invariant
keys (lines starting with `- invariant:`), parses the examples JSON, and
asserts every valid example carries `expect.kind=ok` and every invalid
example carries `expect.kind=error` plus the `error_code` declared in
the contract.

## Files
- `tests/Unit/Planning/ContractSpecTest.php` — golden checker scaffold.

## Pass criteria
```
php artisan test --filter='ContractSpecTest'
```

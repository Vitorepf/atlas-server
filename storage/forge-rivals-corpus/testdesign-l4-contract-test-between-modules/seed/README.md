# Seed · testdesign-l4-contract-test-between-modules

Pin the wire shape between `InboxIngest` (producer) and
`CaptureFormatter` (consumer) with a contract test backed by a versioned
golden JSON fixture.

The arm must:

- Author `tests/fixtures/contracts/ingest_formatter_v1.json` containing
  one or more `{ingest_input, expected_formatter_input}` rows.
- Implement `IngestFormatterContractTest` that loads the golden file,
  runs `InboxIngest::produce()` on `ingest_input` and asserts the
  output equals `expected_formatter_input` byte-for-byte.
- Include a comment naming the contract version (`v1`) and the
  approval channel (e.g. `#inbox-contract-board`).

Production code must stay untouched.

## Files
- `InboxIngest.php`, `CaptureFormatter.php` — read-only fixtures.
- `tests/Contract/IngestFormatterContractTest.php` — scaffold.

## Pass criteria
```
php artisan test --filter='IngestFormatterContractTest'
```

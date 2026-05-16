# Seed · refactor-l4-replace-switch-with-strategy

`CaptureFormatter::format($capture, $format)` uses a 6-branch switch.
Replace it with:

- `FormatterStrategy` interface (`name(): string`, `render(array $capture): string`).
- `StrategyRegistry` with `register(FormatterStrategy)` and
  `resolve(string $format): FormatterStrategy`. Unknown format MUST
  raise a blocker, never silently fall back.
- 6 final strategies: `json`, `csv`, `md`, `html`, `xml`, `plain`.
- `CaptureFormatter` becomes a thin facade that delegates to the registry.

Behaviour is byte-for-byte preserved — the existing test fixtures stay
green. No new format (e.g. PDF) is allowed in this case.

## Files
- `CaptureFormatter.php`, `FormatterStrategy.php`, `StrategyRegistry.php`
- `CaptureFormatterTest.php` — 6-row golden + unknown-format guard.

## Pass criteria
```
php artisan test --filter='CaptureFormatterTest'
```

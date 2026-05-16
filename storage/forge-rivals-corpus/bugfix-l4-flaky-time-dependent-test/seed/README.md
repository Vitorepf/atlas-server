# Seed · bugfix-l4-flaky-time-dependent-test

`ExpirationService::isExpired($at)` calls `microtime(true)` directly,
producing a flaky test. Introduce a canonical clock seam:

- `ClockInterface::nowEpochSeconds(): float`
- `SystemClock implements ClockInterface` — production wiring.
- `FrozenClock implements ClockInterface` — deterministic test clock,
  advanced via `advance(int $seconds)`.

Patch `ExpirationService` to accept a `ClockInterface` and remove the
direct `microtime()` call. The test must pass 50 re-runs in a row —
the scaffold drives 50 iterations under a frozen clock and never sleeps.

## Files
- `ClockInterface.php`, `SystemClock.php`, `FrozenClock.php`,
  `ExpirationService.php` — scaffolds.
- `ExpirationServiceTest.php` — 50-rerun determinism harness.

## Pass criteria
```
php artisan test --filter='ExpirationServiceTest'
```

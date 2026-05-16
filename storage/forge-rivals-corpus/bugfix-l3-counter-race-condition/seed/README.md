# Seed · bugfix-l3-counter-race-condition

`Counter::decrement()` loses updates under concurrent calls because
`$this->value--` is read-then-write without a lock. Repair it by
introducing a `LockInterface` dependency:

- Production wires a real lock (e.g. file-based) — out of scope here.
- Tests wire a `FakeLock` that records `acquire/release` order so the
  test asserts every decrement happened inside a held lock.

The provided test invokes `decrement()` 10 times against a single
counter through the fake lock and asserts the final value is exactly
`initial - 10` with no lost updates.

## Files
- `Counter.php`, `LockInterface.php`, `FakeLock.php` — scaffolds.
- `CounterTest.php` — concurrency simulation (no sleep, no microtime).

## Pass criteria
```
php artisan test --filter='CounterTest'
```

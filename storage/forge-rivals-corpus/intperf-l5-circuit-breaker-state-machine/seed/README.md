# Seed · intperf-l5-circuit-breaker-state-machine

Implement a 3-state circuit breaker for an outbound dependency:

- `closed` → calls pass through. After `failureThreshold` consecutive
  failures, transition to `open`.
- `open` → calls short-circuit with `circuit_open`. After
  `backoffPolicy->nextDelay()` seconds since the last open transition,
  the next call triggers a transition to `half_open`.
- `half_open` → exactly one trial call is permitted. Success →
  `closed`. Failure → `open`.

Backoff is exponential with a cap: `delay = min(cap, base * 2^n)`.

Every transition emits a `CircuitBreakerEvent` recording the from/to
state, the trigger and the deterministic clock timestamp.

A scaffold for `CircuitBreaker`, `BackoffPolicy` and `CircuitBreakerEvent`
is provided.

## Files
- `CircuitBreaker.php`, `BackoffPolicy.php`, `CircuitBreakerEvent.php`
- `CircuitBreakerTest.php` — state machine probes.

## Pass criteria
```
php artisan test --filter='CircuitBreakerTest'
```

# Seed · backend-l5-state-machine-transitions

Implement `CaptureStateMachine` with 5 states and an explicit allowlist
of legal transitions. Deny-by-default for everything else. Every
attempt — accepted or rejected — must be appended to `CaptureAuditLog`
with `(from, to, by, at, reason)`.

States: `draft`, `submitted`, `accepted`, `archived`, `rejected`.

Allowed transitions:

| from       | to          |
| ---------- | ----------- |
| draft      | submitted   |
| draft      | rejected    |
| submitted  | accepted    |
| submitted  | rejected    |
| accepted   | archived    |

Everything else is denied with reason `transition_not_in_allowlist`.

The audit log is append-only and replayable: feeding the same log into
a fresh state machine MUST produce the same final state and same hash.

A scaffold for both classes is provided plus a 25-cell transition test.

## Files
- `CaptureStateMachine.php`, `CaptureAuditLog.php` — scaffolds.
- `CaptureStateMachineTest.php` — 5×5 grid + replay determinism.

## Pass criteria
```
php artisan test --filter='CaptureStateMachineTest'
```

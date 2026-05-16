# Seed · bugfix-l5-cascade-failure-fanout

`NotificationDispatcher::dispatchAll($payload)` fans out a notification
to four channels: email, slack, sms, webhook. Today a slow channel
starves the rest — the dispatcher runs them serially and a single
channel that hangs for `$slowMillis` blocks the others, eventually
timing out every channel.

Fix this at the dispatcher root (not in each consumer):

- Each channel call is wrapped in a `ChannelGuard` that enforces a
  per-channel deadline (millisecond budget).
- A channel that exceeds its budget is reported as `timed_out`; other
  channels still get dispatched.
- A `ChannelGuard` short-circuits once a channel has failed N times
  consecutively (circuit breaker open) for the rest of the run.
- The dispatcher never re-implements per-channel timeout logic; it
  always routes through the guard.

The test simulates a slow channel and asserts the other three still
dispatch within their budgets, plus that the circuit breaker opens
after the configured failure count.

## Files
- `NotificationDispatcher.php`, `ChannelGuard.php` — scaffolds.
- `NotificationDispatcherTest.php` — fanout regression.

## Pass criteria
```
php artisan test --filter='NotificationDispatcherTest'
```

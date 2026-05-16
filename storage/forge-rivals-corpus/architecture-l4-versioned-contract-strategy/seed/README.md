# Seed · architecture-l4-versioned-contract-strategy

`CaptureEventV1` is the current external event. Introduce `CaptureEventV2`
with one new optional field (`source_channel`) and wire
`CaptureEventGateway::accept(array $payload)` to:

1. Accept v1 payloads (emits a deprecation `E_USER_DEPRECATED` notice).
2. Accept v2 payloads (no notice).
3. Reject v3+ (throws `UnsupportedCaptureEventVersionException`).

Also write `docs/contracts/capture_event.md` describing the release-by-release
compatibility window (v1+v2 supported through release R, v3 starts at R+1).

The provided test scaffold covers all three branches.

## Files
- `CaptureEventV1.php` — current shape (read-only).
- `tests/Unit/Captures/Events/CaptureEventGatewayTest.php` — checker.

## Pass criteria
```
php artisan test --filter='CaptureEventGatewayTest'
```

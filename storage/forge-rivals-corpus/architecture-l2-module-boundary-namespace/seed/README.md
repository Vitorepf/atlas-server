# Seed · architecture-l2-module-boundary-namespace

The Inbox module reaches directly into `App\Domain\Captures\CaptureRepository`
in three legacy spots (`InboxLeak1.php`, `InboxLeak2.php`, `InboxLeak3.php`).
You must:

1. Create `deptrac.yaml` declaring two layers, `Inbox` and `Captures`,
   with a forbidden rule "Inbox -> Captures".
2. Introduce `IngestPort` (Inbox-owned interface) and have the legacy
   files import the port instead of `CaptureRepository` directly.
3. The provided test scaffold `BoundaryEnforcementTest` parses the
   leak files for forbidden imports and asserts none remain.

Constraints:
- No new third-party package.
- Production behaviour must stay identical (the leak files only contain
  type hints + comments; they do not call into the repo).

## Files
- `deptrac.example.yaml` — reference template the arm may copy.
- `InboxLeak1.php`, `InboxLeak2.php`, `InboxLeak3.php` — files importing
  `CaptureRepository` directly.
- `tests/Architecture/BoundaryEnforcementTest.php` — checker.

## Pass criteria
```
php artisan test --filter='BoundaryEnforcementTest'
```

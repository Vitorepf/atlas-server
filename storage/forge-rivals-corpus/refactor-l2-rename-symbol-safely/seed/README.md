# Seed · refactor-l2-rename-symbol-safely

Rename `AtlasUserService::getById()` → `findById()` keeping the old
name as a thin alias for one release:

- `findById()` holds the implementation.
- `getById()` calls `findById()` and is tagged `@deprecated`.
- A call to `getById()` triggers `E_USER_DEPRECATED` (via
  `trigger_error`).
- New code paths MUST use `findById()`; the deprecated alias is for
  legacy callers only.

The test scaffold verifies both names return identical results and that
calling `getById()` raises the deprecation notice.

## Files
- `AtlasUserService.php` — current implementation.
- `AtlasUserServiceTest.php` — golden checker.

## Pass criteria
```
php artisan test --filter='AtlasUserServiceTest'
```

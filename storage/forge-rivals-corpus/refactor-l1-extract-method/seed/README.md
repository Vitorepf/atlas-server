# Seed · refactor-l1-extract-method

`InboxRankingService::rank()` inlines a ~10-line score calculation per
item. Extract it into a `private function calculateScore(array $item): float`
that:

- Receives an item array
- Returns the same `float` score the inline block was returning
- Has no side effects, no new dependency
- Keeps `final` visibility on the surrounding method

Existing test cases MUST pass byte-for-byte without modification —
extraction is a pure refactor.

## Files
- `InboxRankingService.php` — current implementation.
- `InboxRankingServiceTest.php` — frozen behaviour suite.

## Pass criteria
```
php artisan test --filter='InboxRankingServiceTest'
```

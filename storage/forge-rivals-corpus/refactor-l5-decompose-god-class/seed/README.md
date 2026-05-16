# Seed · refactor-l5-decompose-god-class

`InboxOrchestrator` packs three responsibilities into one class:

1. Ingest the incoming capture payload.
2. Rank inbox items.
3. Notify the desktop client.

Decompose it into three focused services keeping the public API:

- `InboxIngest::ingest(array $payload): array`
- `InboxRanking::rank(array $items): array`
- `InboxNotification::dispatch(array $payload): void`

`InboxOrchestrator` becomes a thin facade (≤ 80 lines) delegating to
those three.

The feature suite asserts the public API stays byte-for-byte. The
provided unit tests check each new service in isolation (≥ 1 test per
service is the minimum bar; arms are encouraged to add more).

## Files
- `InboxOrchestrator.php` — current god-class (3 responsibilities mixed).
- `InboxOrchestratorFeatureTest.php` — frozen public-API behaviour.
- `InboxIngestTest.php`, `InboxRankingTest.php`, `InboxNotificationTest.php` — unit harnesses.

## Pass criteria
```
php artisan test --filter='Inbox'
```

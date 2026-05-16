<?php

declare(strict_types=1);

namespace App\Domain\Inbox;

/**
 * SEED: god-class mixing ingestion, ranking and notification. The
 * arm must split into InboxIngest + InboxRanking + InboxNotification
 * and make this class a thin facade keeping the public API.
 */
final class InboxOrchestrator
{
    /** @var list<array<string,mixed>> */
    private array $events = [];

    /** @param array{capture_id:string,body:string,priority:int,starred:bool} $payload */
    public function ingest(array $payload): array
    {
        // Responsibility 1: ingest
        $body = trim($payload['body']);
        $row = [
            'capture_id' => $payload['capture_id'],
            'body' => $body,
            'priority' => $payload['priority'] ?? 0,
            'starred' => $payload['starred'] ?? false,
            'received_at_epoch' => 1_700_000_000,
        ];
        $this->events[] = ['kind' => 'ingested', 'capture_id' => $row['capture_id']];

        return $row;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    public function rank(array $items): array
    {
        // Responsibility 2: ranking
        usort($items, static function (array $a, array $b): int {
            $score = static function (array $i): float {
                $p = (int) ($i['priority'] ?? 0);
                $s = (bool) ($i['starred'] ?? false);

                return $p * 1.0 + ($s ? 0.5 : 0.0);
            };

            return $score($b) <=> $score($a);
        });

        return $items;
    }

    /** @param array<string,mixed> $payload */
    public function notify(array $payload): void
    {
        // Responsibility 3: notification
        $this->events[] = ['kind' => 'notified', 'capture_id' => $payload['capture_id'] ?? null];
    }

    /** @return list<array<string,mixed>> */
    public function recordedEvents(): array
    {
        return $this->events;
    }
}

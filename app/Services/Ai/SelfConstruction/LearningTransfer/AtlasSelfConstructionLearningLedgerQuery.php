<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\LearningTransfer;

/**
 * Read-only query interface over AtlasSelfConstructionLearningLedger. No in-memory cache —
 * every call re-reads the JSONL so the operator sees the latest durable state.
 */
final class AtlasSelfConstructionLearningLedgerQuery
{
    public function __construct(private readonly AtlasSelfConstructionLearningLedger $ledger) {}

    /**
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        return $this->ledger->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function byClass(string $class): array
    {
        return array_values(array_filter(
            $this->ledger->all(),
            static fn (array $row): bool => (string) ($row['lesson']['class'] ?? '') === $class,
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function byDecision(string $decision): array
    {
        return array_values(array_filter(
            $this->ledger->all(),
            static fn (array $row): bool => (string) ($row['lesson']['decision'] ?? '') === $decision,
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listChronological(): array
    {
        $rows = $this->ledger->all();
        usort($rows, static function (array $a, array $b): int {
            $ta = (string) ($a['lesson']['observation_ts'] ?? '');
            $tb = (string) ($b['lesson']['observation_ts'] ?? '');

            return strcmp($ta, $tb);
        });

        return array_values($rows);
    }
}

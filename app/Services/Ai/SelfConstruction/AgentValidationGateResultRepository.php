<?php

namespace App\Services\Ai\SelfConstruction;

/**
 * In-memory repository for dry-run validation result sets.
 *
 * Storage is per-instance and never touches the filesystem, database,
 * Evidence Ledger or any external store. Designed for tests, dry-runs and
 * orchestration scratchpads only. A future runtime store may replace this
 * class with a durable implementation under a separate signed receipt.
 *
 * Read-only with respect to the rest of Atlas: never starts processes,
 * never calls Codex CLI/app, never spawns subprocesses, never invokes
 * adapters, never dispatches work, never spends tokens, never advances
 * the next required slice, never enables self-programming, never writes
 * the Evidence Ledger.
 */
final class AgentValidationGateResultRepository
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_validation_gate_result_repository.v1';

    public const MODE = 'read_only_agent_validation_gate_result_repository';

    /** @var array<string, array<string, mixed>> */
    private array $store = [];

    /** @var array<int, string> insertion order of result_set_ids */
    private array $order = [];

    /**
     * @param  array<string, mixed>  $resultSet
     */
    public function store(array $resultSet): string
    {
        $id = (string) ($resultSet['result_set_id'] ?? '');
        if ($id === '') {
            $id = 'result-'.substr(hash('sha256', (string) json_encode($resultSet)), 0, 16);
            $resultSet['result_set_id'] = $id;
        }
        $resultSet['stored_at_microtime'] = microtime(true);
        $resultSet['storage_revision'] = isset($this->store[$id])
            ? (int) ($this->store[$id]['storage_revision'] ?? 0) + 1
            : 1;

        $this->store[$id] = $resultSet;
        if (! in_array($id, $this->order, true)) {
            $this->order[] = $id;
        }

        return $id;
    }

    /** @return array<string, mixed>|null */
    public function find(string $resultSetId): ?array
    {
        return $this->store[$resultSetId] ?? null;
    }

    public function has(string $resultSetId): bool
    {
        return isset($this->store[$resultSetId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        $out = [];
        foreach ($this->order as $id) {
            $out[] = $this->store[$id];
        }

        return $out;
    }

    public function count(): int
    {
        return count($this->store);
    }

    public function isEmpty(): bool
    {
        return $this->store === [];
    }

    /** @return array<string, mixed>|null */
    public function latest(): ?array
    {
        if ($this->order === []) {
            return null;
        }
        $lastId = $this->order[count($this->order) - 1];

        return $this->store[$lastId] ?? null;
    }

    /** @return array<int, string> */
    public function ids(): array
    {
        return $this->order;
    }

    public function clear(): void
    {
        $this->store = [];
        $this->order = [];
    }

    public function forget(string $resultSetId): bool
    {
        if (! isset($this->store[$resultSetId])) {
            return false;
        }
        unset($this->store[$resultSetId]);
        $this->order = array_values(array_filter($this->order, static fn (string $id): bool => $id !== $resultSetId));

        return true;
    }

    /** @return array<int, array<string, mixed>> */
    public function whereOverallStatus(string $overall): array
    {
        $out = [];
        foreach ($this->order as $id) {
            $rs = $this->store[$id];
            if (($rs['overall_status'] ?? '') === $overall) {
                $out[] = $rs;
            }
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    public function failed(): array
    {
        return $this->whereOverallStatus('failed');
    }

    /** @return array<int, array<string, mixed>> */
    public function passed(): array
    {
        return $this->whereOverallStatus('passed');
    }

    /**
     * Hashable digest of the current store contents, useful for snapshot tests.
     */
    public function digest(): array
    {
        $items = [];
        foreach ($this->order as $id) {
            $rs = $this->store[$id];
            $items[] = [
                'result_set_id' => $id,
                'plan_id' => $rs['plan_id'] ?? null,
                'plan_hash' => $rs['plan_hash'] ?? null,
                'overall_status' => $rs['overall_status'] ?? null,
                'evaluation_hash' => $rs['evaluation_hash'] ?? null,
                'storage_revision' => $rs['storage_revision'] ?? null,
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'count' => count($items),
            'order' => $this->order,
            'items' => $items,
            'digest_hash' => hash('sha256', (string) json_encode($items)),
            'runtime_safety' => [
                'runtime_safety_all_false' => true,
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
                'ledger_write_allowed' => false,
                'runtime_write_allowed' => false,
            ],
        ];
    }
}

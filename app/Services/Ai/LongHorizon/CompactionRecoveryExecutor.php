<?php

declare(strict_types=1);

namespace App\Services\Ai\LongHorizon;

use App\Models\AiSessionState;
use App\Models\AtlasLongHorizonCompactionReceipt;
use Illuminate\Support\Facades\Schema;

final class CompactionRecoveryExecutor
{
    /**
     * @return array<string,mixed>
     */
    public function recover(AtlasLongHorizonCompactionReceipt|array $receipt): array
    {
        $receiptPayload = $receipt instanceof AtlasLongHorizonCompactionReceipt ? $receipt->toArray() : $receipt;
        $queries = $this->stringList($receiptPayload['recovery_queries'] ?? []);
        $items = [];
        $missing = [];

        foreach ($queries as $query) {
            $parsed = $this->parseRecoveryQuery($query);
            if ($parsed === null) {
                $missing[] = ['query' => $query, 'reason' => 'unparseable_recovery_query'];
                continue;
            }

            $key = $parsed['kind'].':'.$parsed['id'];
            $item = $this->findInSessionState($parsed['kind'], $parsed['id'])
                ?? $this->findInReceiptPayload($receiptPayload, $parsed['kind'], $parsed['id']);
            if ($item === null) {
                $missing[] = ['query' => $query, 'reason' => 'canonical_source_not_found'];
                continue;
            }

            $items[$key] = $item + [
                'kind' => $parsed['kind'],
                'id' => $parsed['id'],
                'recovery_query' => $query,
            ];
        }

        return [
            'schema_version' => 'atlas.long_horizon.compaction_recovery.v1',
            'status' => $missing === [] ? 'recovered' : ($items === [] ? 'missing' : 'partial'),
            'recovered_count' => count($items),
            'missing_count' => count($missing),
            'items' => $items,
            'missing' => $missing,
            'policy' => [
                'read_only' => true,
                'writes_recovered_content' => false,
                'write_gate' => 'LongHorizonMemoryPromotionGuard',
            ],
        ];
    }

    /**
     * @return array{kind:string,id:string}|null
     */
    private function parseRecoveryQuery(string $query): ?array
    {
        if (preg_match('/^rehydrate\s+([a-z_]+):([^ ]+)\s+from canonical sources$/', trim($query), $matches) !== 1) {
            return null;
        }

        return [
            'kind' => $matches[1],
            'id' => $matches[2],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findInSessionState(string $kind, string $id): ?array
    {
        if (! class_exists(AiSessionState::class) || ! Schema::hasTable('ai_session_states')) {
            return null;
        }

        $column = match ($kind) {
            'decision' => 'decisions',
            'blocker' => 'open_loops',
            'dod' => 'next_steps',
            'risk_critical' => 'constraints',
            default => null,
        };
        if ($column === null) {
            return null;
        }

        /** @var iterable<int,AiSessionState> $states */
        $states = AiSessionState::query()->latest('updated_at')->limit(200)->get();
        foreach ($states as $state) {
            foreach (array_values((array) ($state->{$column} ?? [])) as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                if ((string) ($entry['id'] ?? '') !== $id) {
                    continue;
                }

                return [
                    'source' => 'ai_session_states.'.$column,
                    'payload' => $entry,
                    'digest' => (string) ($entry['text'] ?? $entry['value'] ?? ''),
                ];
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>|null
     */
    private function findInReceiptPayload(array $receipt, string $kind, string $id): ?array
    {
        foreach (['must_keep_items', 'discarded_items', 'unresolved_loss'] as $column) {
            foreach ((array) ($receipt[$column] ?? []) as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                if ((string) ($entry['id'] ?? '') !== $id || (string) ($entry['kind'] ?? $kind) !== $kind) {
                    continue;
                }

                return [
                    'source' => 'compaction_receipt.'.$column,
                    'payload' => $entry['payload'] ?? $entry,
                    'digest' => (string) ($entry['digest'] ?? ''),
                ];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $entry): string => is_scalar($entry) ? trim((string) $entry) : '',
            (array) $value,
        ), static fn (string $entry): bool => $entry !== ''));
    }
}

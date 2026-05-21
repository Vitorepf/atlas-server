<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Carbon;

final class AtlasCognitiveMemoryFabricService
{
    public const SCHEMA_VERSION = 'atlas.aucri.cognitive_memory_fabric.v1';

    public const BUDGET_SCHEMA = 'atlas.cognitive_memory.budget.v1';

    public const WORKING_SET_SCHEMA = 'atlas.cognitive_memory.working_set.v1';

    public const HOT_CONTEXT_ITEM_SCHEMA = 'atlas.cognitive_memory.hot_context_item.v1';

    public const DELTA_RECEIPT_SCHEMA = 'atlas.cognitive_memory.delta_receipt.v1';

    public const SPILLOVER_RECEIPT_SCHEMA = 'atlas.cognitive_memory.spillover_receipt.v1';

    public const PRESSURE_EVENT_SCHEMA = 'atlas.cognitive_memory.pressure_event.v1';

    private const GB = 1073741824;

    public function __construct(private readonly AtlasRetrievalPrivacyTrustLayerService $privacyTrustLayer) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function plan(array $input = []): array
    {
        $memory = $this->memorySnapshot($input);
        $mode = $this->mode($memory);
        $budget = $this->budget($memory, $mode);
        $items = $this->items($input);
        $privacy = $this->privacyTrustLayer->evaluate([
            'raw_context' => $this->rawContext($input),
            'provider_target' => 'local',
            'risk_level' => (string) ($input['risk_level'] ?? 'low'),
        ]);
        [$kept, $evicted] = $this->selectWorkingSet($items, (int) $budget['ram_budget_bytes']);
        $workingSet = $this->workingSet($kept, $evicted, $mode, $budget, $privacy);
        $deltaReceipt = $this->deltaReceipt($kept, $evicted, $input);
        $spilloverReceipt = $this->spilloverReceipt($evicted, $mode);
        $pressureEvent = $this->pressureEvent($memory, $mode, $budget, $evicted);
        $status = $mode === 'emergency_trim' ? 'degraded' : ((string) data_get($privacy, 'provider_gate.status') === 'blocked' ? 'blocked' : 'ready');

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => Carbon::now()->toIso8601String(),
            'budget' => $budget,
            'working_set' => $workingSet,
            'hot_context_items' => $kept,
            'evicted_items' => $evicted,
            'delta_receipt' => $deltaReceipt,
            'spillover_receipt' => $spilloverReceipt,
            'pressure_event' => $pressureEvent,
            'privacy_ref' => [
                'schema_version' => AtlasRetrievalPrivacyTrustLayerService::SCHEMA_VERSION,
                'status' => (string) ($privacy['status'] ?? 'unknown'),
                'privacy_trust_hash' => (string) ($privacy['privacy_trust_hash'] ?? ''),
                'raw_text_exposed' => false,
            ],
            'claims' => [
                'providers_invoked' => false,
                'writes' => false,
                'rivals_run' => false,
                'benchmark_run' => false,
                'raw_text_exposed' => false,
                'allocates_ram' => false,
            ],
        ];

        $hashPayload = $payload;
        unset($hashPayload['generated_at']);
        $payload['cognitive_memory_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,int|float>
     */
    private function memorySnapshot(array $input): array
    {
        $total = $this->bytes($input['memory_total_bytes'] ?? null, 48 * self::GB);
        $available = $this->bytes($input['memory_available_bytes'] ?? null, max(0, $total - memory_get_usage(true)));
        $swap = $this->bytes($input['swap_used_bytes'] ?? null, 0);
        $cpu = max(0.0, min(1.0, (float) ($input['cpu_load'] ?? 0.20)));

        return [
            'memory_total_bytes' => $total,
            'memory_available_bytes' => $available,
            'swap_used_bytes' => $swap,
            'cpu_load' => $cpu,
        ];
    }

    /**
     * @param  array<string,int|float>  $memory
     */
    private function mode(array $memory): string
    {
        $available = (int) $memory['memory_available_bytes'];
        $swap = (int) $memory['swap_used_bytes'];
        $cpu = (float) $memory['cpu_load'];

        if ($available < 3 * self::GB) {
            return 'emergency_trim';
        }
        if ($available < 6 * self::GB || $swap > 8 * self::GB || $cpu > 0.88) {
            return 'minimal';
        }
        if ($available < 12 * self::GB || $swap > 2 * self::GB) {
            return 'balanced';
        }
        if ($available < 24 * self::GB) {
            return 'performance';
        }

        return 'deep_work';
    }

    /**
     * @param  array<string,int|float>  $memory
     * @return array<string,mixed>
     */
    private function budget(array $memory, string $mode): array
    {
        $maxBudget = match ($mode) {
            'emergency_trim' => 0,
            'minimal' => 1 * self::GB,
            'balanced' => 4 * self::GB,
            'performance' => 8 * self::GB,
            default => 14 * self::GB,
        };
        $availableAfterReserve = max(0, (int) $memory['memory_available_bytes'] - (3 * self::GB));
        $ramBudget = min($maxBudget, $availableAfterReserve);

        return [
            'schema_version' => self::BUDGET_SCHEMA,
            'mode' => $mode,
            'ram_budget_bytes' => $ramBudget,
            'safe_reserve_min_bytes' => 3 * self::GB,
            'safe_reserve_target_bytes' => 6 * self::GB,
            'memory_total_bytes' => (int) $memory['memory_total_bytes'],
            'memory_available_bytes' => (int) $memory['memory_available_bytes'],
            'swap_used_bytes' => (int) $memory['swap_used_bytes'],
            'prewarming_allowed' => in_array($mode, ['performance', 'deep_work'], true),
            'emergency_trim_required' => $mode === 'emergency_trim',
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<int,array<string,mixed>>
     */
    private function items(array $input): array
    {
        $items = (array) ($input['items'] ?? []);
        if ($items === []) {
            $items = [
                ['kind' => 'decision', 'ref' => 'decision:current', 'tokens' => 900, 'bytes' => 8 * 1024 * 1024, 'heat' => 0.96, 'must_keep' => true],
                ['kind' => 'blocker', 'ref' => 'blocker:current', 'tokens' => 650, 'bytes' => 6 * 1024 * 1024, 'heat' => 0.94, 'must_keep' => true],
                ['kind' => 'context_pack', 'ref' => 'context:retrieval', 'tokens' => 4200, 'bytes' => 80 * 1024 * 1024, 'heat' => 0.78, 'rebuildable' => true],
                ['kind' => 'summary', 'ref' => 'summary:prior', 'tokens' => 1800, 'bytes' => 24 * 1024 * 1024, 'heat' => 0.62, 'rebuildable' => true],
            ];
        }

        return array_values(array_map(fn (mixed $item, int $index): array => $this->item(is_array($item) ? $item : ['ref' => 'item:'.$index], $index), $items, array_keys($items)));
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function item(array $item, int $index): array
    {
        $kind = (string) ($item['kind'] ?? 'context');
        $ref = (string) ($item['ref'] ?? $kind.':'.$index);
        $mustKeep = (bool) ($item['must_keep'] ?? in_array($kind, ['decision', 'blocker', 'constraint', 'dod', 'receipt'], true));
        $tokens = max(1, (int) ($item['tokens'] ?? 1000));
        $bytes = max(1024, (int) ($item['bytes'] ?? ($tokens * 640)));
        $heat = max(0.0, min(1.0, (float) ($item['heat'] ?? ($mustKeep ? 1.0 : 0.5))));

        return [
            'schema_version' => self::HOT_CONTEXT_ITEM_SCHEMA,
            'item_hash' => MissionCanonicalHash::sha256([$kind, $ref]),
            'kind' => $kind,
            'ref_hash' => MissionCanonicalHash::sha256($ref),
            'must_keep' => $mustKeep,
            'rebuildable' => (bool) ($item['rebuildable'] ?? ! $mustKeep),
            'estimated_tokens' => $tokens,
            'estimated_bytes' => $bytes,
            'heat_score' => round($heat, 4),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>}
     */
    private function selectWorkingSet(array $items, int $budgetBytes): array
    {
        usort($items, static fn (array $a, array $b): int => [(bool) $b['must_keep'], (float) $b['heat_score']] <=> [(bool) $a['must_keep'], (float) $a['heat_score']]);

        $kept = [];
        $evicted = [];
        $used = 0;
        foreach ($items as $item) {
            $bytes = (int) $item['estimated_bytes'];
            if ((bool) $item['must_keep'] || ($budgetBytes > 0 && $used + $bytes <= $budgetBytes)) {
                $kept[] = $item + ['memory_action' => 'keep_hot'];
                $used += $bytes;

                continue;
            }

            $evicted[] = $item + ['memory_action' => 'spillover_or_rebuild'];
        }

        return [$kept, $evicted];
    }

    /**
     * @param  array<int,array<string,mixed>>  $kept
     * @param  array<int,array<string,mixed>>  $evicted
     * @param  array<string,mixed>  $budget
     * @param  array<string,mixed>  $privacy
     * @return array<string,mixed>
     */
    private function workingSet(array $kept, array $evicted, string $mode, array $budget, array $privacy): array
    {
        $mustKeepKept = count(array_filter($kept, static fn (array $item): bool => (bool) $item['must_keep']));
        $mustKeepTotal = $mustKeepKept + count(array_filter($evicted, static fn (array $item): bool => (bool) $item['must_keep']));

        return [
            'schema_version' => self::WORKING_SET_SCHEMA,
            'mode' => $mode,
            'ram_budget_bytes' => (int) $budget['ram_budget_bytes'],
            'items_kept' => count($kept),
            'items_evicted' => count($evicted),
            'must_keep_refs' => array_values(array_map(static fn (array $item): string => (string) $item['item_hash'], array_filter($kept, static fn (array $item): bool => (bool) $item['must_keep']))),
            'must_keep_coverage' => $mustKeepTotal === 0 ? 1.0 : round($mustKeepKept / $mustKeepTotal, 4),
            'privacy_status' => (string) data_get($privacy, 'provider_gate.status', 'unknown'),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $kept
     * @param  array<int,array<string,mixed>>  $evicted
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function deltaReceipt(array $kept, array $evicted, array $input): array
    {
        $repeatedTokens = max(0, (int) ($input['repeated_tokens'] ?? 0));
        $evictedTokens = array_sum(array_map(static fn (array $item): int => (int) $item['estimated_tokens'], $evicted));
        $keptTokens = array_sum(array_map(static fn (array $item): int => (int) $item['estimated_tokens'], $kept));
        $saved = $repeatedTokens + $evictedTokens;
        $receipt = [
            'schema_version' => self::DELTA_RECEIPT_SCHEMA,
            'previous_context_hash' => (string) ($input['previous_context_hash'] ?? ''),
            'kept_tokens' => $keptTokens,
            'token_savings_estimate' => $saved,
            'savings_ratio' => round($saved / max(1, $keptTokens + $saved), 4),
            'must_keep_loss' => false,
        ];
        $receipt['receipt_hash'] = MissionCanonicalHash::sha256($receipt);

        return $receipt;
    }

    /**
     * @param  array<int,array<string,mixed>>  $evicted
     * @return array<string,mixed>
     */
    private function spilloverReceipt(array $evicted, string $mode): array
    {
        $receipt = [
            'schema_version' => self::SPILLOVER_RECEIPT_SCHEMA,
            'spillover_required' => $evicted !== [],
            'mode' => $mode,
            'spilled_item_hashes' => array_values(array_map(static fn (array $item): string => (string) $item['item_hash'], $evicted)),
            'spilled_tokens' => array_sum(array_map(static fn (array $item): int => (int) $item['estimated_tokens'], $evicted)),
            'raw_text_exposed' => false,
        ];
        $receipt['receipt_hash'] = MissionCanonicalHash::sha256($receipt);

        return $receipt;
    }

    /**
     * @param  array<string,int|float>  $memory
     * @param  array<string,mixed>  $budget
     * @param  array<int,array<string,mixed>>  $evicted
     * @return array<string,mixed>
     */
    private function pressureEvent(array $memory, string $mode, array $budget, array $evicted): array
    {
        return [
            'schema_version' => self::PRESSURE_EVENT_SCHEMA,
            'mode' => $mode,
            'memory_available_bytes' => (int) $memory['memory_available_bytes'],
            'safe_reserve_min_bytes' => (int) $budget['safe_reserve_min_bytes'],
            'below_min_reserve' => (int) $memory['memory_available_bytes'] < (int) $budget['safe_reserve_min_bytes'],
            'evicted_rebuildable_count' => count(array_filter($evicted, static fn (array $item): bool => (bool) $item['rebuildable'])),
            'action' => $mode === 'emergency_trim' ? 'emergency_trim' : ($evicted === [] ? 'keep_budget' : 'spillover_rebuildable'),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function rawContext(array $input): string
    {
        $parts = [];
        foreach ((array) ($input['items'] ?? []) as $item) {
            if (is_array($item) && isset($item['content']) && is_scalar($item['content'])) {
                $parts[] = (string) $item['content'];
            }
        }

        return trim(implode("\n", $parts));
    }

    private function bytes(mixed $value, int $fallback): int
    {
        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        return $fallback;
    }
}

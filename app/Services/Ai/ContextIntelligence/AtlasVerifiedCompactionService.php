<?php

declare(strict_types=1);

namespace App\Services\Ai\ContextIntelligence;

use App\Services\Ai\AiCompactionService;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Carbon\CarbonImmutable;

final class AtlasVerifiedCompactionService
{
    public const SCHEMA_VERSION = 'atlas.context_intelligence.verified_compaction.v1';

    public const SEMANTIC_DIFF_SCHEMA_VERSION = 'atlas.context_intelligence.semantic_diff.v1';

    public const STATUS_PASSED = 'passed';

    public const STATUS_BLOCKED = 'blocked';

    public function __construct(private readonly AiCompactionService $compaction) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function compact(array $input): array
    {
        $now = ($input['now'] ?? null) instanceof CarbonImmutable ? $input['now'] : CarbonImmutable::now();
        $mustKeepItems = array_values((array) ($input['must_keep_items'] ?? []));
        $sourceContextRefs = array_values((array) ($input['source_context_refs'] ?? []));
        $forcedDiscards = array_values((array) ($input['forced_discards'] ?? []));
        $semanticDiff = $this->semanticDiff($mustKeepItems, $forcedDiscards, $sourceContextRefs);

        $receipt = $this->compaction->compactForScope([
            'scope_type' => (string) ($input['scope_type'] ?? AtlasLongHorizonCanon::SCOPE_TYPE_LONG_HORIZON),
            'scope_id' => isset($input['scope_id']) && is_scalar($input['scope_id']) ? (string) $input['scope_id'] : null,
            'source_context_refs' => $sourceContextRefs,
            'must_keep_items' => $mustKeepItems,
            'forced_discards' => $forcedDiscards,
            'evidence_refs' => array_values((array) ($input['evidence_refs'] ?? [])),
            'stale_risks' => array_values((array) ($input['stale_risks'] ?? [])),
            'detected_contradictions' => $semanticDiff['detected_contradictions'],
            'recovery_queries' => array_values((array) ($input['recovery_queries'] ?? [])),
            'actor_alias' => 'atlas_context_intelligence',
        ]);

        $status = ((float) ($receipt['must_keep_coverage'] ?? 0.0)) >= 1.0
            && (bool) ($receipt['write_allowed'] ?? false)
            && $semanticDiff['missing_must_keep_ids'] === []
                ? self::STATUS_PASSED
                : self::STATUS_BLOCKED;

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => $now->toJSON(),
            'scope_type' => $receipt['scope_type'],
            'scope_id' => $receipt['scope_id'],
            'compaction_receipt' => [
                'schema_version' => $receipt['schema_version'],
                'receipt_uuid' => $receipt['receipt_uuid'],
                'receipt_hash' => $receipt['receipt_hash'],
                'must_keep_coverage' => $receipt['must_keep_coverage'],
                'write_allowed' => $receipt['write_allowed'],
                'loss_risk' => $receipt['loss_risk'],
                'unresolved_loss' => $receipt['unresolved_loss'],
                'recovery_queries' => $receipt['recovery_queries'],
            ],
            'semantic_diff' => $semanticDiff,
            'blockers' => $this->blockers($receipt, $semanticDiff),
            'claim_policy' => [
                'benchmark_not_run' => true,
                'rivals_compared' => false,
                'provider_calls_made' => false,
            ],
            'writes' => true,
        ];
        $payload['verified_compaction_hash'] = $this->hash($payload);

        return $payload;
    }

    /**
     * @param  list<mixed>  $mustKeepItems
     * @param  list<mixed>  $forcedDiscards
     * @param  list<mixed>  $sourceContextRefs
     * @return array<string,mixed>
     */
    public function semanticDiff(array $mustKeepItems, array $forcedDiscards = [], array $sourceContextRefs = []): array
    {
        $mustKeepIds = array_values(array_filter(array_map(
            static fn (mixed $item): ?string => is_array($item) && isset($item['id']) ? (string) $item['id'] : null,
            $mustKeepItems,
        )));
        $discardIds = array_values(array_filter(array_map(
            static fn (mixed $item): ?string => is_array($item) && isset($item['id']) ? (string) $item['id'] : null,
            $forcedDiscards,
        )));
        $missing = array_values(array_intersect($mustKeepIds, $discardIds));

        $contradictions = [];
        foreach ($forcedDiscards as $discard) {
            if (is_array($discard) && in_array((string) ($discard['id'] ?? ''), $mustKeepIds, true)) {
                $contradictions[] = [
                    'kind' => 'forced_discard_of_must_keep',
                    'id' => (string) $discard['id'],
                    'reason' => (string) ($discard['reason'] ?? 'unknown'),
                ];
            }
        }

        $payload = [
            'schema_version' => self::SEMANTIC_DIFF_SCHEMA_VERSION,
            'source_context_ref_count' => count($sourceContextRefs),
            'must_keep_count' => count($mustKeepIds),
            'forced_discard_count' => count($discardIds),
            'missing_must_keep_ids' => $missing,
            'detected_contradictions' => $contradictions,
            'coverage_intent' => $mustKeepIds === [] ? 1.0 : round((count($mustKeepIds) - count($missing)) / max(1, count($mustKeepIds)), 3),
        ];
        $payload['semantic_diff_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @param  array<string,mixed>  $semanticDiff
     * @return list<array<string,string>>
     */
    private function blockers(array $receipt, array $semanticDiff): array
    {
        $blockers = [];
        if ((float) ($receipt['must_keep_coverage'] ?? 0.0) < 1.0) {
            $blockers[] = ['id' => 'must_keep_coverage_below_one', 'reason' => 'verified compaction cannot pass with lost must_keep items'];
        }
        if (($receipt['write_allowed'] ?? false) !== true) {
            $blockers[] = ['id' => 'write_not_allowed', 'reason' => 'underlying compaction receipt blocked write'];
        }
        foreach ((array) ($semanticDiff['missing_must_keep_ids'] ?? []) as $id) {
            $blockers[] = ['id' => 'semantic_diff_missing_must_keep', 'reason' => 'missing must_keep item: '.(string) $id];
        }

        return $blockers;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hash(array $payload): string
    {
        unset($payload['generated_at'], $payload['verified_compaction_hash']);

        return MissionCanonicalHash::sha256($payload);
    }
}

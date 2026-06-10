<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Carbon;

final class AtlasRetrievalCostLatencyGovernorService
{
    public const SCHEMA_VERSION = 'atlas.aucri.retrieval_cost_latency_governor.v1';

    public const BUDGET_POLICY_SCHEMA = 'atlas.aucri.retrieval_budget_policy.v1';

    public const RECEIPT_SCHEMA = 'atlas.aucri.retrieval_cost_latency_receipt.v1';

    public const CACHE_DECISION_SCHEMA = 'atlas.aucri.retrieval_cache_decision.v1';

    public const DEGRADED_MODE_SCHEMA = 'atlas.aucri.retrieval_degraded_mode.v1';

    public function __construct(private readonly AtlasRetrievalEvaluationBenchmarkArenaService $arena) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function govern(array $input = []): array
    {
        $risk = $this->risk((string) ($input['risk_level'] ?? $input['risk'] ?? 'low'));
        $flowId = trim((string) ($input['flow_id'] ?? $this->flowId($input)));
        $policy = $this->budgetPolicy($risk, $flowId, $input);
        $arenaReport = $this->arena->evaluate(['risk_level' => $risk]);
        $requiredSources = $this->requiredSources($input, $risk);
        $maxRefs = max(0, (int) ($input['max_refs'] ?? $policy['max_refs']));
        $keptSources = array_slice($requiredSources, 0, $maxRefs);
        $removedSources = array_values(array_diff($requiredSources, $keptSources));
        $observedLatencyMs = max(1, (int) ($input['observed_latency_ms'] ?? $input['simulated_latency_ms'] ?? $this->estimatedLatency($maxRefs, $risk)));
        $estimatedCostUnits = $this->estimatedCostUnits($maxRefs, $risk, (int) data_get($arenaReport, 'summary.case_count', 0));
        $cacheDecision = $this->cacheDecision($risk, $arenaReport, $input);
        $degradedMode = $this->degradedMode($policy, $observedLatencyMs, $estimatedCostUnits, $removedSources, $risk, $cacheDecision);
        $receipt = $this->receipt($flowId, $risk, $policy, $observedLatencyMs, $estimatedCostUnits, $requiredSources, $keptSources, $removedSources, $arenaReport, $cacheDecision, $degradedMode);
        $status = $this->status($receipt, $degradedMode, $arenaReport);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => Carbon::now()->toIso8601String(),
            'budget_policy' => $policy,
            'cache_decision' => $cacheDecision,
            'degraded_mode' => $degradedMode,
            'receipt' => $receipt,
            'quality_gate_ref' => [
                'arena_status' => (string) ($arenaReport['status'] ?? 'unknown'),
                'arena_hash' => (string) ($arenaReport['arena_hash'] ?? ''),
                'required_source_recall' => (float) data_get($arenaReport, 'summary.metrics.required_source_recall', 0.0),
                'groundedness' => (float) data_get($arenaReport, 'summary.metrics.groundedness', 0.0),
                'context_roi' => (float) data_get($arenaReport, 'summary.metrics.context_roi', 0.0),
            ],
            'areg_alignment' => [
                'schema_version' => 'atlas.aucri.areg_budget_alignment.v1',
                'external_areg_write_performed' => false,
                'cognitive_budget_governor' => 'AtlasRuntimeEfficiencyGovernorService',
                'policy' => 'reuse_budget_doctrine_without_persisting_areg_decision',
            ],
            'claims' => [
                'providers_invoked' => false,
                'writes' => false,
                'rivals_run' => false,
                'benchmark_run' => false,
                'required_source_removed_silently' => false,
                'raw_text_exposed' => false,
            ],
        ];

        $hashPayload = $payload;
        unset($hashPayload['generated_at']);
        $payload['governor_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function budgetPolicy(string $risk, string $flowId, array $input): array
    {
        $defaults = match ($risk) {
            'high', 'irreversible' => ['budget_ms' => 5000, 'budget_cost_units' => 42, 'max_refs' => 12, 'quality_floor' => 0.92],
            'medium' => ['budget_ms' => 2600, 'budget_cost_units' => 24, 'max_refs' => 8, 'quality_floor' => 0.84],
            default => ['budget_ms' => 1400, 'budget_cost_units' => 12, 'max_refs' => 6, 'quality_floor' => 0.76],
        };

        return [
            'schema_version' => self::BUDGET_POLICY_SCHEMA,
            'flow_id' => $flowId,
            'risk_level' => $risk,
            'budget_ms' => max(100, (int) ($input['budget_ms'] ?? $defaults['budget_ms'])),
            'budget_cost_units' => max(1, (int) ($input['budget_cost_units'] ?? $defaults['budget_cost_units'])),
            'max_refs' => max(1, (int) ($input['policy_max_refs'] ?? $defaults['max_refs'])),
            'quality_floor' => (float) ($input['quality_floor'] ?? $defaults['quality_floor']),
            'cache_allowed' => $risk !== 'high' && $risk !== 'irreversible',
            'degraded_mode_requires_receipt' => true,
            'required_source_trim_policy' => 'block_not_trim',
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function flowId(array $input): string
    {
        $domain = trim((string) ($input['domain'] ?? 'atlas'));
        $taskType = trim((string) ($input['task_type'] ?? 'direct'));

        return $domain.'.'.$taskType;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<int,string>
     */
    private function requiredSources(array $input, string $risk): array
    {
        $sources = AtlasContextStringListNormalizer::uniqueTrimmedStrings($input['required_sources'] ?? []);

        if ($sources !== []) {
            return $sources;
        }

        return match ($risk) {
            'high', 'irreversible' => ['evidence_replay', 'code_intelligence', 'memory_signals', 'semantic_candidate'],
            'medium' => ['evidence_replay', 'memory_signals', 'semantic_candidate'],
            default => ['memory_signals', 'semantic_candidate'],
        };
    }

    /**
     * @param  array<string,mixed>  $arenaReport
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function cacheDecision(string $risk, array $arenaReport, array $input): array
    {
        $requested = (bool) ($input['cache_requested'] ?? true);
        $freshnessValid = (bool) ($input['cache_fresh'] ?? true);
        $qualityReady = (string) ($arenaReport['status'] ?? 'blocked') === 'ready';
        $allowed = $requested && $freshnessValid && $qualityReady && ! in_array($risk, ['high', 'irreversible'], true);

        return [
            'schema_version' => self::CACHE_DECISION_SCHEMA,
            'status' => $allowed ? 'eligible' : 'bypass',
            'requested' => $requested,
            'freshness_valid' => $freshnessValid,
            'quality_ready' => $qualityReady,
            'risk_allows_cache' => ! in_array($risk, ['high', 'irreversible'], true),
            'reason' => match (true) {
                ! $requested => 'cache_not_requested',
                ! $freshnessValid => 'cache_stale',
                ! $qualityReady => 'quality_floor_not_ready',
                in_array($risk, ['high', 'irreversible'], true) => 'high_risk_requires_fresh_retrieval',
                default => 'fresh_provider_safe_cache_allowed',
            },
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<int,string>  $removedSources
     * @param  array<string,mixed>  $cacheDecision
     * @return array<string,mixed>
     */
    private function degradedMode(array $policy, int $observedLatencyMs, int $estimatedCostUnits, array $removedSources, string $risk, array $cacheDecision): array
    {
        $reasons = [];
        if ($observedLatencyMs > (int) $policy['budget_ms']) {
            $reasons[] = 'latency_budget_exceeded';
        }
        if ($estimatedCostUnits > (int) $policy['budget_cost_units']) {
            $reasons[] = 'cost_budget_exceeded';
        }
        if ($removedSources !== []) {
            $reasons[] = 'required_sources_would_be_trimmed';
        }
        if ((string) ($cacheDecision['status'] ?? 'bypass') === 'bypass' && (string) ($cacheDecision['reason'] ?? '') === 'cache_stale') {
            $reasons[] = 'cache_stale_requires_fresh_retrieval';
        }

        $blocks = $removedSources !== [] || (in_array($risk, ['high', 'irreversible'], true) && $reasons !== []);

        return [
            'schema_version' => self::DEGRADED_MODE_SCHEMA,
            'status' => $reasons === [] ? 'not_needed' : ($blocks ? 'blocked' : 'degraded_with_receipt'),
            'reasons' => $reasons,
            'blocks_execution' => $blocks,
            'operator_review_required' => $blocks || $risk !== 'low',
            'silent_degradation_allowed' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<int,string>  $requiredSources
     * @param  array<int,string>  $keptSources
     * @param  array<int,string>  $removedSources
     * @param  array<string,mixed>  $arenaReport
     * @param  array<string,mixed>  $cacheDecision
     * @param  array<string,mixed>  $degradedMode
     * @return array<string,mixed>
     */
    private function receipt(string $flowId, string $risk, array $policy, int $observedLatencyMs, int $estimatedCostUnits, array $requiredSources, array $keptSources, array $removedSources, array $arenaReport, array $cacheDecision, array $degradedMode): array
    {
        $receipt = [
            'schema_version' => self::RECEIPT_SCHEMA,
            'flow_id' => $flowId,
            'risk_level' => $risk,
            'budget_ms' => (int) $policy['budget_ms'],
            'observed_latency_ms' => $observedLatencyMs,
            'budget_cost_units' => (int) $policy['budget_cost_units'],
            'estimated_cost_units' => $estimatedCostUnits,
            'required_sources' => $requiredSources,
            'kept_required_sources' => $keptSources,
            'removed_required_sources' => $removedSources,
            'quality_floor' => (float) $policy['quality_floor'],
            'arena_hash' => (string) ($arenaReport['arena_hash'] ?? ''),
            'cache_status' => (string) ($cacheDecision['status'] ?? 'unknown'),
            'degraded_status' => (string) ($degradedMode['status'] ?? 'unknown'),
            'blocked_reasons' => (array) ($degradedMode['blocks_execution'] ? $degradedMode['reasons'] : []),
        ];
        $receipt['receipt_hash'] = MissionCanonicalHash::sha256($receipt);

        return $receipt;
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @param  array<string,mixed>  $degradedMode
     * @param  array<string,mixed>  $arenaReport
     */
    private function status(array $receipt, array $degradedMode, array $arenaReport): string
    {
        if ((bool) ($degradedMode['blocks_execution'] ?? false)) {
            return 'blocked';
        }

        if ((string) ($arenaReport['status'] ?? 'blocked') !== 'ready') {
            return 'blocked';
        }

        if ((int) $receipt['observed_latency_ms'] > (int) $receipt['budget_ms']
            || (int) $receipt['estimated_cost_units'] > (int) $receipt['budget_cost_units']) {
            return 'degraded';
        }

        return 'ready';
    }

    private function estimatedLatency(int $maxRefs, string $risk): int
    {
        $base = match ($risk) {
            'high', 'irreversible' => 1100,
            'medium' => 700,
            default => 360,
        };

        return $base + $maxRefs * 75;
    }

    private function estimatedCostUnits(int $maxRefs, string $risk, int $caseCount): int
    {
        $riskMultiplier = match ($risk) {
            'high', 'irreversible' => 3,
            'medium' => 2,
            default => 1,
        };

        return max(1, $maxRefs * $riskMultiplier + $caseCount);
    }

    private function risk(string $risk): string
    {
        return in_array($risk, ['low', 'medium', 'high', 'irreversible'], true) ? $risk : 'low';
    }
}

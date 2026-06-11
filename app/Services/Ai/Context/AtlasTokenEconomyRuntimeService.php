<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Carbon;

final class AtlasTokenEconomyRuntimeService
{
    public const SCHEMA_VERSION = 'atlas.aucri.token_economy_runtime.v1';

    public const BUDGET_SCHEMA = 'atlas.token_economy.budget.v1';

    public const COMPRESSION_RECEIPT_SCHEMA = 'atlas.token_economy.compression_receipt.v1';

    public const REUSE_RECEIPT_SCHEMA = 'atlas.token_economy.reuse_receipt.v1';

    public const LOCAL_PREREASONING_SCHEMA = 'atlas.token_economy.local_prereasoning.v1';

    public const QUALITY_CHECK_SCHEMA = 'atlas.token_economy.quality_check.v1';

    public const CONTEXT_DELIVERY_POLICY_SCHEMA = 'atlas.token_economy.context_delivery_policy.v1';

    private readonly ContextWindowMustKeepBudgetAllocator $mustKeepAllocator;

    private readonly RecallContextBudgetSplitScorer $recallSplitScorer;

    public function __construct(
        private readonly AtlasContextCompilerRuntimeService $compiler,
        private readonly AtlasAucriTokenQualityCanarySetService $canarySet,
        ?ContextWindowMustKeepBudgetAllocator $mustKeepAllocator = null,
        ?RecallContextBudgetSplitScorer $recallSplitScorer = null,
    ) {
        $this->mustKeepAllocator = $mustKeepAllocator ?? new ContextWindowMustKeepBudgetAllocator();
        $this->recallSplitScorer = $recallSplitScorer ?? new RecallContextBudgetSplitScorer();
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function optimize(array $input = []): array
    {
        $risk = $this->risk((string) ($input['risk_level'] ?? 'low'));
        $provider = $this->provider((string) ($input['provider'] ?? 'gpt'));
        $compiled = $this->compiler->compile($input + ['provider' => $provider, 'risk_level' => $risk]);
        $before = (int) data_get($compiled, 'prompt_budget_receipt.input_tokens_before', 0);
        $compiledAfter = (int) data_get($compiled, 'prompt_budget_receipt.input_tokens_after', $before);
        $reuse = $this->reuseReceipt($input, $compiled);
        $local = $this->localPrereasoning($input);
        $compression = $this->compressionReceipt($compiledAfter, $risk, $reuse, $local, $input);
        $contextDeliveryPolicy = $this->contextDeliveryPolicy($input, $compiled, $compression, $risk);
        $budget = $this->budget($compiled, $compression, $risk, $provider, $contextDeliveryPolicy);
        $providerSelection = $this->providerSelection($risk, $provider, (int) $compression['input_tokens_after'], $local);
        $quality = $this->qualityCheck($compression, $compiled, $input);
        $status = (string) $quality['quality_gate_status'] === 'passed' ? 'ready' : 'blocked';

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => Carbon::now()->toIso8601String(),
            'budget' => $budget,
            'compression_receipt' => $compression,
            'context_delivery_policy' => $contextDeliveryPolicy,
            'reuse_receipt' => $reuse,
            'local_prereasoning' => $local,
            'provider_model_selection' => $providerSelection,
            'quality_check' => $quality,
            'compiled_ref' => [
                'schema_version' => AtlasContextCompilerRuntimeService::SCHEMA_VERSION,
                'status' => (string) ($compiled['status'] ?? 'unknown'),
                'context_compiler_hash' => (string) ($compiled['context_compiler_hash'] ?? ''),
                'compiled_hash' => (string) data_get($compiled, 'compiled_pack.compiled_hash', ''),
            ],
            'canary_ref' => [
                'schema_version' => AtlasAucriTokenQualityCanarySetService::SCHEMA_VERSION,
                'canary_set_hash' => (string) $this->canarySet->report()['canary_set_hash'],
            ],
            'claims' => [
                'providers_invoked' => false,
                'writes' => false,
                'rivals_run' => false,
                'benchmark_run' => false,
                'raw_text_exposed' => false,
                'provider_selection_applied' => false,
            ],
        ];

        // Default-OFF consolidated kernels (see config/atlas.php context_budget).
        // When the flag is OFF the kernel is never invoked and $payload is
        // byte-identical to the pre-wiring behavior. Advisory sections only.
        if ((bool) config('atlas.context_budget.must_keep_allocator_enabled', false)) {
            $payload['must_keep_budget_allocation'] = $this->mustKeepAllocation($compiled, $budget);
        }

        if ((bool) config('atlas.context_budget.recall_split_scorer_enabled', false)) {
            $payload['recall_context_split'] = $this->recallContextSplit($input, $compiled, $risk);
        }

        $hashPayload = $payload;
        unset($hashPayload['generated_at']);
        $payload['token_economy_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /**
     * Advisory must_keep overflow degradation plan (consolidated kernel, flag-gated).
     * Reconstructs the compiled segment shape the allocator expects from the
     * compiled pack + provider token budget. Pure; does not mutate live receipts.
     *
     * @param  array<string,mixed>  $compiled
     * @param  array<string,mixed>  $budget
     * @return array<string,mixed>
     */
    private function mustKeepAllocation(array $compiled, array $budget): array
    {
        $sections = (array) data_get($compiled, 'compiled_pack.compiled_sections', []);
        $segments = [];
        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }
            $segments[] = [
                'kind' => (string) ($section['kind'] ?? 'context'),
                'ref' => (string) ($section['segment_hash'] ?? ($section['kind'] ?? 'context')),
                'tokens' => max(0, (int) ($section['tokens'] ?? 0)),
                'priority' => $section['must_keep'] ?? false ? 1.0 : 0.5,
                'must_keep' => (bool) ($section['must_keep'] ?? false),
            ];
        }

        $tokenBudget = (int) data_get($budget, 'input_tokens_before', (int) ($budget['input_tokens_after'] ?? 0));

        return $this->mustKeepAllocator->allocate($segments, $tokenBudget);
    }

    /**
     * Advisory recall-vs-context char split (consolidated kernel, flag-gated).
     * Derives split signals from the runtime input + compiled risk. Pure.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $compiled
     * @return array<string,mixed>
     */
    private function recallContextSplit(array $input, array $compiled, string $risk): array
    {
        $signals = [
            'total_budget_chars' => (int) ($input['recall_split_total_budget_chars'] ?? 0),
            'task_type' => (string) ($input['task_type'] ?? data_get($compiled, 'compiler_input.task_type', '')),
            'risk_level' => $risk,
            'conversation_depth' => (int) ($input['conversation_depth'] ?? 0),
            'has_prior_episode' => (bool) ($input['has_prior_episode'] ?? false),
        ];

        $split = $this->recallSplitScorer->split($signals);
        $split['schema_version'] = 'atlas.token_economy.recall_context_split.v1';
        $split['receipt_hash'] = MissionCanonicalHash::sha256($split);

        return $split;
    }

    /**
     * @param  array<string,mixed>  $compiled
     * @param  array<string,mixed>  $compression
     * @return array<string,mixed>
     */
    private function budget(array $compiled, array $compression, string $risk, string $provider, array $contextDeliveryPolicy): array
    {
        $outputBudget = match ($risk) {
            'low' => 900,
            'medium' => 1400,
            'high' => 2200,
            default => 3000,
        };

        $budget = [
            'schema_version' => self::BUDGET_SCHEMA,
            'flow_id' => (string) data_get($compiled, 'compiler_input.flow_id', 'atlas.context.compile'),
            'risk_level' => $risk,
            'provider' => $provider,
            'input_tokens_before' => (int) $compression['input_tokens_before'],
            'input_tokens_after' => (int) $compression['input_tokens_after'],
            'output_budget' => $outputBudget,
            'savings_estimate' => (int) $compression['savings_estimate'],
            'context_delivery_mode' => (string) ($contextDeliveryPolicy['delivery_mode'] ?? 'standard_compiled_pack'),
            'initial_context_token_budget' => (int) ($contextDeliveryPolicy['initial_context_token_budget'] ?? $compression['input_tokens_after']),
            'expansion_token_reserve' => (int) ($contextDeliveryPolicy['expansion_token_reserve'] ?? 0),
        ];
        $budget['receipt_hash'] = MissionCanonicalHash::sha256($budget);

        return $budget;
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $compiled
     * @param  array<string,mixed>  $compression
     * @return array<string,mixed>
     */
    private function contextDeliveryPolicy(array $input, array $compiled, array $compression, string $risk): array
    {
        $impact = $this->feedbackImpactReportInput($input);
        $tokensAfter = max(0, (int) ($compression['input_tokens_after'] ?? 0));
        $impactActive = (string) ($impact['status'] ?? 'inactive') === 'active';

        if (! $impactActive) {
            $policy = [
                'schema_version' => self::CONTEXT_DELIVERY_POLICY_SCHEMA,
                'status' => 'inactive',
                'source' => 'none',
                'delivery_mode' => 'standard_compiled_pack',
                'reason' => 'no_active_feedback_impact_report',
                'compiled_hash' => (string) data_get($compiled, 'compiled_pack.compiled_hash', ''),
                'input_tokens_after_compression' => $tokensAfter,
                'initial_context_token_budget' => $tokensAfter,
                'expansion_token_reserve' => 0,
                'initial_token_multiplier' => 1.0,
                'initial_ref_limit' => $this->initialRefLimit($risk, false, false),
                'initial_source_types' => [],
                'deferred_source_types' => [],
                'guarded_required_source_types' => [],
                'expansion_triggers' => [
                    'provider_requests_more_context',
                    'quality_gate_blocks',
                ],
                'quality_gate_hint' => 'standard_quality_gate',
                'advisory_only' => true,
                'policy' => $this->contextDeliveryPolicyClaims(),
            ];
            $policy['receipt_hash'] = MissionCanonicalHash::sha256($policy);

            return $policy;
        }

        $newlySelectedSources = $this->sourceTypesFromRefs(data_get($impact, 'newly_selected_refs', []));
        $promotedSources = $this->sourceTypesFromRefs(data_get($impact, 'promoted_refs', []));
        $droppedSources = $this->sourceTypesFromRefs(data_get($impact, 'dropped_refs', []));
        $demotedSources = $this->sourceTypesFromRefs(data_get($impact, 'demoted_refs', []));
        $gainedRequired = $this->stringList(data_get($impact, 'coverage_delta.gained_required_sources', []));
        $lostRequired = $this->stringList(data_get($impact, 'coverage_delta.lost_required_sources', []));
        $rankChangeCount = max(0, (int) ($impact['rank_position_change_count'] ?? 0));
        $selectedSetChanged = (bool) ($impact['selected_set_changed'] ?? false);
        $hasRequiredCoverageChange = $gainedRequired !== [] || $lostRequired !== [];
        $hasLostRequired = $lostRequired !== [];
        $deliveryMode = $this->deliveryMode($hasLostRequired, $hasRequiredCoverageChange, $selectedSetChanged, $rankChangeCount);
        $multiplier = $this->initialTokenMultiplier($deliveryMode, $risk);
        $initialBudget = min($tokensAfter, (int) round($tokensAfter * $multiplier));
        $reserve = max(0, $tokensAfter - $initialBudget);
        $initialSources = $this->uniqueStrings(array_merge($newlySelectedSources, $promotedSources, $gainedRequired));
        $deferredSources = $this->uniqueStrings(array_merge($droppedSources, $demotedSources));

        $policy = [
            'schema_version' => self::CONTEXT_DELIVERY_POLICY_SCHEMA,
            'status' => 'active',
            'source' => (string) ($impact['source'] ?? 'unknown'),
            'delivery_mode' => $deliveryMode,
            'reason' => $this->deliveryReason($deliveryMode),
            'compiled_hash' => (string) data_get($compiled, 'compiled_pack.compiled_hash', ''),
            'feedback_impact_hash' => MissionCanonicalHash::sha256($this->providerSafeImpactProjection($impact)),
            'input_tokens_after_compression' => $tokensAfter,
            'initial_context_token_budget' => $initialBudget,
            'expansion_token_reserve' => $reserve,
            'initial_token_multiplier' => $multiplier,
            'initial_ref_limit' => $this->initialRefLimit($risk, $selectedSetChanged, $hasLostRequired),
            'selected_set_changed' => $selectedSetChanged,
            'rank_position_change_count' => $rankChangeCount,
            'initial_source_types' => $initialSources,
            'deferred_source_types' => $deferredSources,
            'guarded_required_source_types' => $lostRequired,
            'coverage_delta' => [
                'gained_required_sources' => $gainedRequired,
                'lost_required_sources' => $lostRequired,
            ],
            'expansion_triggers' => $this->expansionTriggers($hasLostRequired, $hasRequiredCoverageChange, $deferredSources),
            'quality_gate_hint' => $hasLostRequired ? 'required_source_recheck_before_implementation' : 'feedback_guided_staging_allowed',
            'advisory_only' => true,
            'policy' => $this->contextDeliveryPolicyClaims(),
        ];
        $policy['receipt_hash'] = MissionCanonicalHash::sha256($policy);

        return $policy;
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $compiled
     * @return array<string,mixed>
     */
    private function reuseReceipt(array $input, array $compiled): array
    {
        $compiledHash = (string) data_get($compiled, 'compiled_pack.compiled_hash', '');
        $previousHash = (string) ($input['previous_compiled_hash'] ?? '');
        $fresh = (bool) ($input['reuse_fresh'] ?? true);
        $eligible = $previousHash !== '' && hash_equals($previousHash, $compiledHash) && $fresh;
        $saved = $eligible ? (int) round((int) data_get($compiled, 'prompt_budget_receipt.input_tokens_after', 0) * 0.55) : 0;
        $receipt = [
            'schema_version' => self::REUSE_RECEIPT_SCHEMA,
            'eligible' => $eligible,
            'freshness_required' => true,
            'freshness_passed' => $fresh,
            'previous_compiled_hash_match' => $previousHash !== '' && hash_equals($previousHash, $compiledHash),
            'reused_tokens_estimate' => $saved,
        ];
        $receipt['receipt_hash'] = MissionCanonicalHash::sha256($receipt);

        return $receipt;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function localPrereasoning(array $input): array
    {
        $policy = LocalPrereasoningPolicy::classify(
            (string) ($input['task_type'] ?? ''),
            (int) ($input['local_saved_tokens'] ?? 1200),
        );

        $receipt = [
            'schema_version' => self::LOCAL_PREREASONING_SCHEMA,
            'task_type' => $policy['task_type'],
            'can_resolve_locally' => $policy['can_resolve_locally'],
            'provider_call_avoidable' => $policy['provider_call_avoidable'],
            'saved_tokens_estimate' => $policy['saved_tokens_estimate'],
            'allowed_operations' => $policy['allowed_operations'],
        ];
        $receipt['receipt_hash'] = MissionCanonicalHash::sha256($receipt);

        return $receipt;
    }

    /**
     * @param  array<string,mixed>  $reuse
     * @param  array<string,mixed>  $local
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function compressionReceipt(int $compiledAfter, string $risk, array $reuse, array $local, array $input): array
    {
        $safeCompression = match ($risk) {
            'low' => 0.28,
            'medium' => 0.18,
            'high' => 0.10,
            default => 0.05,
        };
        $compressionSavings = (int) round($compiledAfter * $safeCompression);
        $reuseSavings = (int) ($reuse['reused_tokens_estimate'] ?? 0);
        $localSavings = (int) ($local['saved_tokens_estimate'] ?? 0);
        $totalSavings = min($compiledAfter, $compressionSavings + $reuseSavings + $localSavings);
        $after = max(0, $compiledAfter - $totalSavings);
        $mustKeepCoverage = array_key_exists('must_keep_coverage', $input)
            ? max(0.0, min(1.0, (float) $input['must_keep_coverage']))
            : 1.0;
        $lossScore = round($compressionSavings / max(1, $compiledAfter), 4);

        $receipt = [
            'schema_version' => self::COMPRESSION_RECEIPT_SCHEMA,
            'input_tokens_before' => $compiledAfter,
            'input_tokens_after' => $after,
            'semantic_compression_savings' => $compressionSavings,
            'reuse_savings' => $reuseSavings,
            'local_prereasoning_savings' => $localSavings,
            'savings_estimate' => $totalSavings,
            'must_keep_coverage' => $mustKeepCoverage,
            'loss_score' => $lossScore,
            'compression_strategy' => $risk === 'low' ? 'semantic_optional_trim' : 'lossless_must_keep_only',
        ];
        $receipt['receipt_hash'] = MissionCanonicalHash::sha256($receipt);

        return $receipt;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function feedbackImpactReportInput(array $input): array
    {
        foreach ([
            'feedback_impact_report',
            'ranking_feedback_impact',
            'acrs_feedback_impact_report',
        ] as $key) {
            if (is_array($input[$key] ?? null)) {
                return (array) $input[$key];
            }
        }

        $nested = data_get($input, 'rerank_result.feedback_impact_report');

        return is_array($nested) ? $nested : [];
    }

    private function deliveryMode(bool $hasLostRequired, bool $hasRequiredCoverageChange, bool $selectedSetChanged, int $rankChangeCount): string
    {
        return match (true) {
            $hasLostRequired => 'guarded_required_source_recheck',
            $hasRequiredCoverageChange || $selectedSetChanged => 'staged_minimal_targeted_expansion',
            $rankChangeCount > 0 => 'compact_rank_adjusted',
            default => 'compact_stable',
        };
    }

    private function deliveryReason(string $deliveryMode): string
    {
        return match ($deliveryMode) {
            'guarded_required_source_recheck' => 'feedback_changed_required_source_coverage',
            'staged_minimal_targeted_expansion' => 'feedback_changed_selected_context_set',
            'compact_rank_adjusted' => 'feedback_changed_rank_order_only',
            default => 'feedback_active_without_material_context_change',
        };
    }

    private function initialTokenMultiplier(string $deliveryMode, string $risk): float
    {
        $base = match ($deliveryMode) {
            'guarded_required_source_recheck' => 0.86,
            'staged_minimal_targeted_expansion' => 0.68,
            'compact_rank_adjusted' => 0.58,
            'compact_stable' => 0.52,
            default => 1.0,
        };
        $riskFloor = match ($risk) {
            'irreversible' => 0.92,
            'high' => 0.82,
            'medium' => 0.64,
            default => 0.50,
        };

        return round(max($base, $riskFloor), 2);
    }

    private function initialRefLimit(string $risk, bool $selectedSetChanged, bool $hasLostRequired): int
    {
        $base = match ($risk) {
            'irreversible' => 10,
            'high' => 8,
            'medium' => 6,
            default => 4,
        };

        if ($hasLostRequired) {
            return min(12, $base + 2);
        }

        if ($selectedSetChanged) {
            return min(12, $base + 1);
        }

        return $base;
    }

    /**
     * @return array<int,string>
     */
    private function expansionTriggers(bool $hasLostRequired, bool $hasRequiredCoverageChange, array $deferredSources): array
    {
        $triggers = [
            'provider_requests_more_context',
            'quality_gate_blocks',
            'implementation_target_uncertain',
        ];

        if ($hasRequiredCoverageChange) {
            $triggers[] = 'required_source_coverage_changed';
        }

        if ($hasLostRequired) {
            $triggers[] = 'required_source_recheck_before_implementation';
        }

        if ($deferredSources !== []) {
            $triggers[] = 'deferred_source_requested';
        }

        return $this->uniqueStrings($triggers);
    }

    /**
     * @param  mixed  $refs
     * @return array<int,string>
     */
    private function sourceTypesFromRefs(mixed $refs): array
    {
        if (! is_array($refs)) {
            return [];
        }

        $sourceTypes = [];
        foreach ($refs as $ref) {
            if (! is_array($ref)) {
                continue;
            }

            $sourceTypes[] = (string) ($ref['source_type'] ?? '');
        }

        return $this->uniqueStrings($sourceTypes);
    }

    /**
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        if (is_scalar($value)) {
            return $this->uniqueStrings([(string) $value]);
        }

        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $strings[] = (string) $item;
            }
        }

        return $this->uniqueStrings($strings);
    }

    /**
     * @param  array<int,string>  $strings
     * @return array<int,string>
     */
    private function uniqueStrings(array $strings): array
    {
        return AtlasContextStringListNormalizer::uniqueTrimmedStrings($strings);
    }

    /**
     * @param  array<string,mixed>  $impact
     * @return array<string,mixed>
     */
    private function providerSafeImpactProjection(array $impact): array
    {
        return [
            'status' => (string) ($impact['status'] ?? 'unknown'),
            'source' => (string) ($impact['source'] ?? 'unknown'),
            'selected_set_changed' => (bool) ($impact['selected_set_changed'] ?? false),
            'rank_position_change_count' => max(0, (int) ($impact['rank_position_change_count'] ?? 0)),
            'score_delta_total_abs' => round((float) ($impact['score_delta_total_abs'] ?? 0.0), 4),
            'newly_selected_sources' => $this->sourceTypesFromRefs(data_get($impact, 'newly_selected_refs', [])),
            'dropped_sources' => $this->sourceTypesFromRefs(data_get($impact, 'dropped_refs', [])),
            'promoted_sources' => $this->sourceTypesFromRefs(data_get($impact, 'promoted_refs', [])),
            'demoted_sources' => $this->sourceTypesFromRefs(data_get($impact, 'demoted_refs', [])),
            'coverage_delta' => [
                'gained_required_sources' => $this->stringList(data_get($impact, 'coverage_delta.gained_required_sources', [])),
                'lost_required_sources' => $this->stringList(data_get($impact, 'coverage_delta.lost_required_sources', [])),
            ],
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function contextDeliveryPolicyClaims(): array
    {
        return [
            'provider_safe_only' => true,
            'raw_text_exposed' => false,
            'providers_invoked' => false,
            'writes' => false,
            'auto_apply_learning' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $local
     * @return array<string,mixed>
     */
    private function providerSelection(string $risk, string $requestedProvider, int $tokensAfter, array $local): array
    {
        $selected = match (true) {
            (bool) ($local['provider_call_avoidable'] ?? false) => 'none_local_only',
            $risk === 'low' && $tokensAfter < 2500 => 'local',
            $risk === 'medium' && $tokensAfter < 7000 => 'gpt',
            $risk === 'high' => in_array($requestedProvider, ['claude', 'gpt'], true) ? $requestedProvider : 'gpt',
            $risk === 'irreversible' => 'claude',
            default => $requestedProvider,
        };

        return [
            'schema_version' => 'atlas.token_economy.provider_model_selection.v1',
            'requested_provider' => $requestedProvider,
            'selected_provider' => $selected,
            'selection_applied' => false,
            'reason' => $selected === 'none_local_only' ? 'local_prereasoning_can_resolve' : 'lowest_safe_provider_for_risk_and_token_shape',
            'cheap_provider_blocked_by_risk' => in_array($risk, ['high', 'irreversible'], true) && in_array($selected, ['local', 'none_local_only'], true) === false,
        ];
    }

    /**
     * @param  array<string,mixed>  $compression
     * @param  array<string,mixed>  $compiled
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function qualityCheck(array $compression, array $compiled, array $input): array
    {
        $risk = $this->risk((string) ($input['risk_level'] ?? data_get($compiled, 'compiler_input.risk_level', 'low')));
        $lossLimit = match ($risk) {
            'low' => 0.30,
            'medium' => 0.22,
            'high' => 0.14,
            default => 0.08,
        };
        $mustKeep = (float) $compression['must_keep_coverage'];
        $loss = (float) $compression['loss_score'];
        $compiledLoss = (string) data_get($compiled, 'loss_check.status', 'blocked');
        $blockers = [];
        if ($mustKeep < 1.0) {
            $blockers[] = 'must_keep_coverage_below_one';
        }
        if ($loss > $lossLimit) {
            $blockers[] = 'loss_score_above_risk_limit';
        }
        if ($compiledLoss !== 'passed') {
            $blockers[] = 'compiled_context_loss_check_failed';
        }

        $check = [
            'schema_version' => self::QUALITY_CHECK_SCHEMA,
            'quality_gate_status' => $blockers === [] ? 'passed' : 'blocked',
            'must_keep_coverage' => $mustKeep,
            'loss_score' => $loss,
            'loss_limit' => $lossLimit,
            'blockers' => $blockers,
            'sufficiency_regression_allowed' => false,
            'privacy_regression_allowed' => false,
        ];
        $check['receipt_hash'] = MissionCanonicalHash::sha256($check);

        return $check;
    }

    private function provider(string $provider): string
    {
        return in_array($provider, ['claude', 'gpt', 'gemini', 'local'], true) ? $provider : 'gpt';
    }

    private function risk(string $risk): string
    {
        return in_array($risk, ['low', 'medium', 'high', 'irreversible'], true) ? $risk : 'low';
    }
}

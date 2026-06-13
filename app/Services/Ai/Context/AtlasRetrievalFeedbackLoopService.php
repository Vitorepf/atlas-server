<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Models\AiRagFeedbackEvent;
use App\Services\Ai\Compounding\AtlasRagFeedbackService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;

final class AtlasRetrievalFeedbackLoopService
{
    public const SCHEMA_VERSION = 'atlas.aucri.retrieval_feedback_loop.v1';

    public const FEEDBACK_EVENT_SCHEMA = 'atlas.aucri.feedback_event.v1';

    public const CONTEXT_ROI_SCHEMA = 'atlas.aucri.context_roi.v1';

    public const CONTEXT_REF_ATTRIBUTION_SCHEMA = 'atlas.aucri.context_ref_attribution.v1';

    public const NEXT_CONTEXT_POLICY_SCHEMA = 'atlas.aucri.next_context_policy.v1';

    public const MISSED_REF_SCHEMA = 'atlas.aucri.missed_ref_candidate.v1';

    public const NOISE_REF_SCHEMA = 'atlas.aucri.noise_ref_candidate.v1';

    public const LEARNING_CANDIDATE_SCHEMA = 'atlas.aucri.retrieval_learning_candidate.v1';

    private const MAX_CONTEXT_ATTRIBUTION_REFS = 32;

    public function __construct(
        private readonly AtlasContextFreshnessQualityGateService $freshnessQualityGate,
        private readonly AtlasRagFeedbackService $ragFeedbackService,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function capture(array $input): array
    {
        $record = (bool) ($input['record'] ?? false);
        $outcomeStatus = $this->outcomeStatus((string) ($input['outcome_status'] ?? 'unknown'));
        $gate = $this->freshnessQualityGate->evaluate($input);
        $selected = (array) data_get($gate, 'freshness_report.items', []);
        $coverage = (array) data_get($gate, 'context_quality_gate.required_source_coverage', []);
        $missed = $this->missedRefCandidates($coverage, (array) ($input['missed_required_sources'] ?? []));
        $noise = $this->noiseRefCandidates($selected, (array) ($input['noise_ref_hashes'] ?? []), $outcomeStatus);
        $contextRefAttribution = $this->contextRefAttribution($selected, $missed, $noise, $outcomeStatus, $input);
        $usedCount = (int) $contextRefAttribution['used_count'];
        $noiseCount = max(count($noise), (int) $contextRefAttribution['noise_count']);
        $roi = $this->contextRoi(
            $selected,
            $usedCount,
            $noiseCount,
            count($missed),
            $gate,
            $outcomeStatus,
            $input,
            (int) $contextRefAttribution['delivered_count'],
        );
        $nextContextPolicy = $this->nextContextPolicy($contextRefAttribution, $roi);
        $feedbackEvent = $this->feedbackEvent($gate, $roi, $missed, $noise, $outcomeStatus, $input);
        $persisted = $record
            ? $this->persistFeedback($feedbackEvent, $roi, $missed, $noise, $contextRefAttribution, $nextContextPolicy, $outcomeStatus, $input)
            : null;
        $learningCandidate = $this->learningCandidate($feedbackEvent, $roi, $missed, $noise, $contextRefAttribution, $nextContextPolicy, $persisted);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $this->status($gate, $roi, $missed, $noise, $contextRefAttribution, $outcomeStatus),
            'generated_at' => Carbon::now()->toIso8601String(),
            'feedback_event' => $feedbackEvent,
            'context_roi' => $roi,
            'context_ref_attribution' => $contextRefAttribution,
            'next_context_policy' => $nextContextPolicy,
            'missed_ref_candidates' => $missed,
            'noise_ref_candidates' => $noise,
            'learning_candidate' => $learningCandidate,
            'persistence' => [
                'requested' => $record,
                'available' => DatabaseTableAvailability::has('ai_rag_feedback_events'),
                'persisted' => $persisted instanceof AiRagFeedbackEvent,
                'rag_feedback_id' => $persisted?->id,
                'feedback_hash' => $persisted?->feedback_hash,
                'schema_version' => $persisted?->schema_version,
            ],
            'policy' => [
                'auto_promote_learning' => false,
                'operator_review_required' => true,
                'feedback_without_causality_promotes_policy' => false,
                'raw_text_exposed' => false,
                'providers_invoked' => false,
            ],
            'claims' => [
                'providers_invoked' => false,
                'writes' => $persisted instanceof AiRagFeedbackEvent,
                'auto_promoted' => false,
                'benchmark_run' => false,
                'raw_text_exposed' => false,
            ],
        ];

        $hashPayload = $payload;
        unset($hashPayload['generated_at']);
        $payload['retrieval_feedback_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $coverage
     * @param  array<int,mixed>  $explicitMisses
     * @return array<int,array<string,mixed>>
     */
    private function missedRefCandidates(array $coverage, array $explicitMisses): array
    {
        $misses = [];
        foreach ($coverage as $source => $covered) {
            if ($covered === false) {
                $misses[] = [
                    'schema_version' => self::MISSED_REF_SCHEMA,
                    'source_type' => (string) $source,
                    'reason' => 'required_source_not_covered',
                    'confidence' => 0.92,
                ];
            }
        }

        foreach ($explicitMisses as $miss) {
            $sourceType = is_scalar($miss) ? trim((string) $miss) : trim((string) data_get($miss, 'source_type', ''));
            if ($sourceType === '') {
                continue;
            }

            $misses[] = [
                'schema_version' => self::MISSED_REF_SCHEMA,
                'source_type' => $sourceType,
                'reason' => is_array($miss) ? (string) ($miss['reason'] ?? 'operator_or_outcome_reported_miss') : 'operator_or_outcome_reported_miss',
                'confidence' => is_array($miss) ? max(0.0, min(1.0, (float) ($miss['confidence'] ?? 0.75))) : 0.75,
            ];
        }

        return collect($misses)->unique(fn (array $item): string => $item['source_type'].'|'.$item['reason'])->values()->all();
    }

    /**
     * @param  array<int,array<string,mixed>>  $selected
     * @param  array<int,mixed>  $explicitNoiseHashes
     * @return array<int,array<string,mixed>>
     */
    private function noiseRefCandidates(array $selected, array $explicitNoiseHashes, string $outcomeStatus): array
    {
        $explicit = array_values(array_filter(array_map(
            static fn (mixed $hash): string => is_scalar($hash) ? trim((string) $hash) : '',
            $explicitNoiseHashes,
        )));

        $noise = [];
        foreach ($selected as $item) {
            $sourceRefHash = (string) ($item['source_ref_hash'] ?? '');
            $contextScore = (float) ($item['context_score'] ?? 0.0);
            $stale = (string) ($item['status'] ?? '') === 'stale_or_unknown';
            $explicitlyNoisy = $sourceRefHash !== '' && in_array($sourceRefHash, $explicit, true);

            if (! $explicitlyNoisy && ! $stale && ! ($outcomeStatus !== 'passed' && $contextScore < 0.50)) {
                continue;
            }

            $noise[] = [
                'schema_version' => self::NOISE_REF_SCHEMA,
                'source_ref_hash' => $sourceRefHash,
                'candidate_hash' => (string) ($item['candidate_hash'] ?? ''),
                'source_type' => (string) ($item['source_type'] ?? 'unknown'),
                'reason' => $explicitlyNoisy ? 'explicit_noise_signal' : ($stale ? 'stale_context' : 'low_score_in_failed_outcome'),
                'confidence' => $explicitlyNoisy ? 0.90 : 0.68,
            ];
        }

        return $noise;
    }

    /**
     * @param  array<int,array<string,mixed>>  $selected
     * @param  array<int,array<string,mixed>>  $missed
     * @param  array<string,mixed>  $gate
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function contextRoi(
        array $selected,
        int $usedCount,
        int $noiseCount,
        int $missedCount,
        array $gate,
        string $outcomeStatus,
        array $input,
        ?int $includedCount = null,
    ): array {
        $included = $includedCount ?? count($selected);
        $sufficiency = match ((string) data_get($gate, 'status', 'blocked')) {
            'passed' => 92,
            'degraded' => 62,
            default => 28,
        };
        $utility = array_key_exists('post_execution_utility', $input)
            ? max(0, min(100, (int) round((float) $input['post_execution_utility'])))
            : match ($outcomeStatus) {
                'passed' => 86,
                'partial' => 58,
                default => 32,
            };
        $useRatio = $included === 0 ? 0.0 : $usedCount / $included;
        $noisePenalty = $included === 0 ? 0.0 : min(0.40, $noiseCount / max(1, $included));
        $missPenalty = min(0.35, $missedCount * 0.10);
        $roiScore = max(0.0, min(1.0, ($utility / 100) * 0.48 + $useRatio * 0.34 + ($sufficiency / 100) * 0.18 - $noisePenalty - $missPenalty));

        return [
            'schema_version' => self::CONTEXT_ROI_SCHEMA,
            'included_sources' => $included,
            'used_sources' => $usedCount,
            'noise_sources' => $noiseCount,
            'missed_required_sources' => $missedCount,
            'context_sufficiency' => $sufficiency,
            'post_execution_utility' => $utility,
            'use_ratio' => round($useRatio, 4),
            'roi_score' => round($roiScore, 4),
            'quality_band' => match (true) {
                $roiScore >= 0.75 && $missedCount === 0 && $noiseCount === 0 => 'strong',
                $roiScore >= 0.50 => 'mixed',
                default => 'weak',
            },
        ];
    }

    /**
     * @param  array<string,mixed>  $gate
     * @param  array<string,mixed>  $roi
     * @param  array<int,array<string,mixed>>  $missed
     * @param  array<int,array<string,mixed>>  $noise
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function feedbackEvent(array $gate, array $roi, array $missed, array $noise, string $outcomeStatus, array $input): array
    {
        $flowId = $this->flowId($input);

        return [
            'schema_version' => self::FEEDBACK_EVENT_SCHEMA,
            'flow_id' => $flowId,
            'retrieval_receipt_id' => (string) ($input['retrieval_receipt_id'] ?? data_get($gate, 'ranking_ref.rerank_result_hash', '')),
            'query_plan_hash' => (string) data_get($gate, 'ranking_ref.rerank_result_hash', ''),
            'freshness_quality_gate_hash' => (string) ($gate['freshness_quality_gate_hash'] ?? ''),
            'outcome_status' => $outcomeStatus,
            'included_sources' => (int) ($roi['included_sources'] ?? 0),
            'used_sources' => (int) ($roi['used_sources'] ?? 0),
            'noise_sources' => (int) ($roi['noise_sources'] ?? 0),
            'missed_required_sources' => array_values(array_map(static fn (array $item): string => (string) $item['source_type'], $missed)),
            'context_sufficiency' => (int) ($roi['context_sufficiency'] ?? 0),
            'post_execution_utility' => (int) ($roi['post_execution_utility'] ?? 0),
            'source_utility' => $this->sourceUtility((array) data_get($gate, 'freshness_report.items', []), $noise, $input),
            'failure_reason' => $this->failureReason($input, $outcomeStatus, $missed, $noise),
        ];
    }

    /**
     * @param  array<string,mixed>  $feedbackEvent
     * @param  array<string,mixed>  $roi
     * @param  array<int,array<string,mixed>>  $missed
     * @param  array<int,array<string,mixed>>  $noise
     * @param  array<string,mixed>  $input
     */
    private function persistFeedback(
        array $feedbackEvent,
        array $roi,
        array $missed,
        array $noise,
        array $contextRefAttribution,
        array $nextContextPolicy,
        string $outcomeStatus,
        array $input,
    ): ?AiRagFeedbackEvent {
        if (! DatabaseTableAvailability::has('ai_rag_feedback_events')) {
            return null;
        }

        return $this->ragFeedbackService->record([
            'retrieval_receipt_id' => $feedbackEvent['retrieval_receipt_id'],
            'flow_id' => $feedbackEvent['flow_id'],
            'query_plan_hash' => $feedbackEvent['query_plan_hash'],
            'included_sources' => $roi['included_sources'],
            'used_sources' => $roi['used_sources'],
            'noise_sources' => $roi['noise_sources'],
            'missed_required_sources' => $feedbackEvent['missed_required_sources'],
            'context_sufficiency' => $roi['context_sufficiency'],
            'post_execution_utility' => $roi['post_execution_utility'],
            'source_utility' => $feedbackEvent['source_utility'],
            'outcome_status' => $outcomeStatus,
            'failure_reason' => $feedbackEvent['failure_reason'],
            'next_retrieval_hint' => $this->nextRetrievalHint($missed, $noise, $roi, $nextContextPolicy),
            'run_outcome_id' => is_scalar($input['run_outcome_id'] ?? null) ? (string) $input['run_outcome_id'] : null,
            'payload' => [
                'schema_version' => self::SCHEMA_VERSION,
                'freshness_quality_gate_hash' => $feedbackEvent['freshness_quality_gate_hash'],
                'context_roi' => $roi,
                'context_ref_attribution' => $contextRefAttribution,
                'next_context_policy' => $nextContextPolicy,
                'missed_count' => count($missed),
                'noise_count' => count($noise),
                'raw_text_exposed' => false,
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $feedbackEvent
     * @param  array<string,mixed>  $roi
     * @param  array<int,array<string,mixed>>  $missed
     * @param  array<int,array<string,mixed>>  $noise
     * @param  array<string,mixed>  $contextRefAttribution
     * @param  array<string,mixed>  $nextContextPolicy
     * @return array<string,mixed>
     */
    private function learningCandidate(
        array $feedbackEvent,
        array $roi,
        array $missed,
        array $noise,
        array $contextRefAttribution,
        array $nextContextPolicy,
        ?AiRagFeedbackEvent $persisted,
    ): array {
        $reasons = array_values(array_filter([
            $missed !== [] ? 'missed_required_sources' : null,
            ((int) ($contextRefAttribution['noise_count'] ?? 0)) > 0 ? 'noise_context_detected' : null,
            ((float) ($contextRefAttribution['waste_ratio'] ?? 0.0)) >= 0.40 ? 'context_waste_detected' : null,
            (float) ($roi['roi_score'] ?? 0.0) < 0.50 ? 'low_context_roi' : null,
            ($feedbackEvent['outcome_status'] ?? 'unknown') !== 'passed' ? 'non_passing_outcome' : null,
        ]));

        return [
            'schema_version' => self::LEARNING_CANDIDATE_SCHEMA,
            'status' => $reasons === [] ? 'none' : 'proposed',
            'auto_apply' => false,
            'requires_review' => $reasons !== [],
            'reasons' => $reasons,
            'evidence_refs' => array_values(array_filter([
                'retrieval_receipt:'.$feedbackEvent['retrieval_receipt_id'],
                'freshness_quality_gate:'.$feedbackEvent['freshness_quality_gate_hash'],
                $persisted instanceof AiRagFeedbackEvent ? 'rag_feedback:'.$persisted->feedback_hash : null,
            ])),
            'proposed_state' => [
                'repromote_source_types' => $this->itemStringColumn($missed, 'source_type'),
                'demote_noise_source_hashes' => $this->itemStringColumn($noise, 'source_ref_hash'),
                'demote_context_refs' => (array) ($nextContextPolicy['demote_context_refs'] ?? []),
                'next_context_actions' => (array) ($nextContextPolicy['actions'] ?? []),
                'next_initial_budget_multiplier' => (float) ($nextContextPolicy['next_initial_budget_multiplier'] ?? 1.0),
                'minimum_context_roi' => 0.70,
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @param  array<int,array<string,mixed>>  $noise
     * @return array<string,string>
     */
    private function sourceUtility(array $items, array $noise, array $input = []): array
    {
        $noiseHashes = $this->itemStringColumn($noise, 'source_ref_hash');
        $utility = [];

        foreach ($items as $item) {
            $hash = (string) ($item['source_ref_hash'] ?? '');
            if ($hash === '') {
                continue;
            }
            $utility[$hash] = in_array($hash, $noiseHashes, true) ? 'noise' : 'useful';
        }

        foreach ($this->inputContextRefEntries($input['used_context_refs'] ?? [], 'explicit_used') as $entry) {
            $ref = (string) ($entry['ref'] ?? '');
            if ($ref !== '') {
                $utility[$ref] = 'used';
            }
        }

        foreach ($this->scalarStringList($input['used_ref_hashes'] ?? []) as $hash) {
            $utility[$hash] = 'used';
        }

        foreach ($this->inputContextRefEntries($input['noise_context_refs'] ?? [], 'explicit_noise') as $entry) {
            $ref = (string) ($entry['ref'] ?? '');
            if ($ref !== '') {
                $utility[$ref] = 'noise';
            }
        }

        return $utility;
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,string>
     */
    private function itemStringColumn(array $items, string $key): array
    {
        return AtlasContextStringListNormalizer::uniqueMappedStrings(
            $items,
            static fn (mixed $item): mixed => is_array($item) ? ($item[$key] ?? null) : null,
        );
    }

    /**
     * @param  array<int,array<string,mixed>>  $missed
     * @param  array<int,array<string,mixed>>  $noise
     */
    private function failureReason(array $input, string $outcomeStatus, array $missed, array $noise): ?string
    {
        if (is_scalar($input['failure_reason'] ?? null) && trim((string) $input['failure_reason']) !== '') {
            return substr(MissionCanonicalHash::sha256((string) $input['failure_reason']), 0, 24);
        }

        if ($missed !== []) {
            return 'retrieval_missed_required_source';
        }

        if ($noise !== []) {
            return 'retrieval_noise_or_stale_context';
        }

        return $outcomeStatus === 'passed' ? null : 'non_passing_outcome';
    }

    /**
     * @param  array<int,array<string,mixed>>  $missed
     * @param  array<int,array<string,mixed>>  $noise
     * @param  array<string,mixed>  $roi
     * @return array<string,mixed>|null
     */
    private function nextRetrievalHint(array $missed, array $noise, array $roi, array $nextContextPolicy): ?array
    {
        if ($missed === [] && $noise === [] && (float) ($roi['roi_score'] ?? 1.0) >= 0.70 && ($nextContextPolicy['actions'] ?? []) === ['keep_current_pack']) {
            return null;
        }

        return [
            'schema_version' => 'atlas.aucri.next_retrieval_hint.v1',
            'should_repromote_sources' => AtlasContextStringListNormalizer::uniqueMappedStrings(
                $missed,
                static fn (mixed $item): mixed => is_array($item) ? ($item['source_type'] ?? null) : null,
            ),
            'should_demote_count' => count($noise),
            'context_policy_actions' => (array) ($nextContextPolicy['actions'] ?? []),
            'next_initial_budget_multiplier' => (float) ($nextContextPolicy['next_initial_budget_multiplier'] ?? 1.0),
            'defer_sections' => (array) ($nextContextPolicy['defer_sections'] ?? []),
            'min_context_roi_target' => 0.70,
            'advisory' => true,
            'auto_apply' => false,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $missed
     * @param  array<int,array<string,mixed>>  $noise
     * @param  array<string,mixed>  $gate
     * @param  array<string,mixed>  $contextRefAttribution
     */
    private function status(array $gate, array $roi, array $missed, array $noise, array $contextRefAttribution, string $outcomeStatus): string
    {
        if ((string) ($gate['status'] ?? 'blocked') === 'blocked' || $missed !== []) {
            return 'needs_review';
        }

        if (
            $noise !== []
            || ((int) ($contextRefAttribution['noise_count'] ?? 0)) > 0
            || ((float) ($contextRefAttribution['waste_ratio'] ?? 0.0)) >= 0.40
            || (float) ($roi['roi_score'] ?? 0.0) < 0.50
            || $outcomeStatus !== 'passed'
        ) {
            return 'learning_candidate';
        }

        return 'recorded';
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function flowId(array $input): string
    {
        if (is_scalar($input['flow_id'] ?? null) && trim((string) $input['flow_id']) !== '') {
            return trim((string) $input['flow_id']);
        }

        $domain = (string) ($input['domain'] ?? 'atlas');
        $taskType = (string) ($input['task_type'] ?? 'direct');

        return $domain.'.'.$taskType;
    }

    private function outcomeStatus(string $status): string
    {
        return match ($status) {
            'passed', 'success', 'succeeded', 'ok' => 'passed',
            'partial', 'needs_review' => 'partial',
            'failed', 'blocked', 'error' => 'failed',
            default => 'unknown',
        };
    }

    /**
     * @param  array<int,array<string,mixed>>  $selected
     * @param  array<int,array<string,mixed>>  $missed
     * @param  array<int,array<string,mixed>>  $noise
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function contextRefAttribution(array $selected, array $missed, array $noise, string $outcomeStatus, array $input): array
    {
        $delivered = [];
        foreach ($selected as $item) {
            $entry = $this->selectedContextRefEntry($item);
            if ($entry !== null) {
                $delivered[$entry['key']] = $entry;
            }
        }

        foreach ($this->inputContextRefEntries($input['delivered_context_refs'] ?? [], 'explicit_delivered') as $entry) {
            $delivered[$entry['key']] = $entry;
        }

        $usedSignals = $this->inputContextRefEntries($input['used_context_refs'] ?? [], 'explicit_used');
        foreach ($usedSignals as $entry) {
            $delivered[$entry['key']] ??= $entry;
        }

        $noiseEntries = [];
        foreach ($noise as $item) {
            $entry = $this->candidateContextRefEntry($item, 'noise_candidate');
            if ($entry !== null) {
                $noiseEntries[$entry['key']] = $entry;
                $delivered[$entry['key']] ??= $entry;
            }
        }

        foreach ($this->inputContextRefEntries($input['noise_context_refs'] ?? [], 'explicit_noise') as $entry) {
            $noiseEntries[$entry['key']] = $entry;
            $delivered[$entry['key']] ??= $entry;
        }

        $usedHashes = $this->scalarStringList($input['used_ref_hashes'] ?? []);
        $usedKeys = [];
        $hasExplicitUseSignal = $usedSignals !== [] || $usedHashes !== [];
        if ($hasExplicitUseSignal) {
            foreach ($usedSignals as $entry) {
                $usedKeys[$entry['key']] = true;
            }

            foreach ($delivered as $key => $entry) {
                if (in_array((string) ($entry['ref_hash'] ?? ''), $usedHashes, true)) {
                    $usedKeys[$key] = true;
                }
            }
            $usageBasis = 'explicit_used_refs';
        } elseif ($outcomeStatus === 'passed') {
            foreach ($delivered as $key => $entry) {
                if (! array_key_exists($key, $noiseEntries)) {
                    $usedKeys[$key] = true;
                }
            }
            $usageBasis = 'passed_outcome_inferred_all_non_noise';
        } else {
            $nonNoiseKeys = array_values(array_filter(
                array_keys($delivered),
                static fn (string $key): bool => ! array_key_exists($key, $noiseEntries),
            ));
            foreach (array_slice($nonNoiseKeys, 0, max(0, (int) floor(count($nonNoiseKeys) / 2))) as $key) {
                $usedKeys[$key] = true;
            }
            $usageBasis = 'non_passing_outcome_inferred_partial';
        }

        $used = [];
        $unused = [];
        foreach ($delivered as $key => $entry) {
            if (array_key_exists($key, $noiseEntries)) {
                continue;
            }

            if (array_key_exists($key, $usedKeys)) {
                $used[$key] = $entry;
            } else {
                $unused[$key] = $entry;
            }
        }

        $deliveredCount = count($delivered);
        $usedCount = count($used);
        $noiseCount = count($noiseEntries);
        $unusedCount = count($unused);
        $wasteRatio = $deliveredCount === 0 ? 0.0 : ($unusedCount + $noiseCount) / $deliveredCount;
        $useRatio = $deliveredCount === 0 ? 0.0 : $usedCount / $deliveredCount;

        return [
            'schema_version' => self::CONTEXT_REF_ATTRIBUTION_SCHEMA,
            'delivered_count' => $deliveredCount,
            'used_count' => $usedCount,
            'unused_count' => $unusedCount,
            'noise_count' => $noiseCount,
            'missed_count' => count($missed),
            'use_ratio' => round($useRatio, 4),
            'waste_ratio' => round($wasteRatio, 4),
            'usage_basis' => $usageBasis,
            'missing_source_types' => $this->itemStringColumn($missed, 'source_type'),
            'delivered_refs' => $this->publicContextRefs($delivered),
            'used_refs' => $this->publicContextRefs($used),
            'unused_refs' => $this->publicContextRefs($unused),
            'noise_refs' => $this->publicContextRefs($noiseEntries),
            'source_policy' => [
                'ref_contract' => 'provider_safe_ref_or_hash_only',
                'raw_text_exposed' => false,
                'max_refs' => self::MAX_CONTEXT_ATTRIBUTION_REFS,
                'auto_apply' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $contextRefAttribution
     * @param  array<string,mixed>  $roi
     * @return array<string,mixed>
     */
    private function nextContextPolicy(array $contextRefAttribution, array $roi): array
    {
        $actions = [];
        $missingSourceTypes = (array) ($contextRefAttribution['missing_source_types'] ?? []);
        $noiseRefs = (array) ($contextRefAttribution['noise_refs'] ?? []);
        $unusedRefs = (array) ($contextRefAttribution['unused_refs'] ?? []);
        $wasteRatio = (float) ($contextRefAttribution['waste_ratio'] ?? 0.0);

        if ($missingSourceTypes !== []) {
            $actions[] = 'expand_missing_source_types';
        }

        if ($noiseRefs !== []) {
            $actions[] = 'demote_noise_context_refs';
        }

        if ($wasteRatio >= 0.40) {
            $actions[] = 'shrink_initial_context';
        }

        if ((float) ($roi['roi_score'] ?? 1.0) < 0.50) {
            $actions[] = 'review_context_pack';
        }

        if ($actions === []) {
            $actions[] = 'keep_current_pack';
        }

        $nextInitialBudgetMultiplier = match (true) {
            in_array('shrink_initial_context', $actions, true) && in_array('expand_missing_source_types', $actions, true) => 0.85,
            in_array('shrink_initial_context', $actions, true) => 0.75,
            in_array('expand_missing_source_types', $actions, true) => 1.10,
            default => 1.00,
        };

        return [
            'schema_version' => self::NEXT_CONTEXT_POLICY_SCHEMA,
            'initial_context_contract' => 'minimal_provider_safe_top_k',
            'expansion_contract' => 'on_demand_by_source_type_and_ref_handle',
            'actions' => array_values(array_unique($actions)),
            'recommended_action' => $actions[0],
            'next_initial_budget_multiplier' => $nextInitialBudgetMultiplier,
            'expand_source_types' => $missingSourceTypes,
            'demote_context_refs' => $this->contextRefLabels($noiseRefs),
            'defer_sections' => $this->deferSections(array_merge($unusedRefs, $noiseRefs)),
            'minimum_context_roi_target' => 0.70,
            'provider_safe' => true,
            'auto_apply' => false,
            'requires_review' => $actions !== ['keep_current_pack'],
        ];
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>|null
     */
    private function selectedContextRefEntry(array $item): ?array
    {
        return $this->contextRefEntry(
            (string) (($item['source_ref_hash'] ?? '') ?: ($item['candidate_hash'] ?? '')),
            (string) ($item['source_type'] ?? 'unknown'),
            'retrieval_selected',
            (string) ($item['source_ref_hash'] ?? ''),
            [
                'candidate_hash' => (string) ($item['candidate_hash'] ?? ''),
                'status' => (string) ($item['status'] ?? 'unknown'),
            ],
        );
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>|null
     */
    private function candidateContextRefEntry(array $item, string $basis): ?array
    {
        return $this->contextRefEntry(
            (string) (($item['source_ref_hash'] ?? '') ?: ($item['candidate_hash'] ?? '')),
            (string) ($item['source_type'] ?? 'unknown'),
            $basis,
            (string) ($item['source_ref_hash'] ?? ''),
            [
                'candidate_hash' => (string) ($item['candidate_hash'] ?? ''),
                'reason' => (string) ($item['reason'] ?? ''),
            ],
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function inputContextRefEntries(mixed $value, string $basis): array
    {
        $items = is_array($value) ? $value : [];
        $entries = [];
        foreach ($items as $item) {
            if (is_scalar($item)) {
                $entry = $this->contextRefEntry((string) $item, $this->inferredSourceType((string) $item), $basis);
            } elseif (is_array($item)) {
                $rawRef = (string) ($item['ref'] ?? $item['source_ref'] ?? $item['path'] ?? $item['id'] ?? $item['source_ref_hash'] ?? $item['ref_hash'] ?? '');
                $entry = $this->contextRefEntry(
                    $rawRef,
                    (string) ($item['source_type'] ?? $this->inferredSourceType($rawRef)),
                    $basis,
                    (string) ($item['source_ref_hash'] ?? $item['ref_hash'] ?? ''),
                    ['reason' => (string) ($item['reason'] ?? '')],
                );
            } else {
                $entry = null;
            }

            if ($entry !== null) {
                $entries[$entry['key']] = $entry;
            }
        }

        return array_values($entries);
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>|null
     */
    private function contextRefEntry(string $rawRef, string $sourceType, string $basis, string $sourceRefHash = '', array $extra = []): ?array
    {
        $rawRef = trim($rawRef);
        $sourceRefHash = trim($sourceRefHash);
        if ($rawRef === '' && $sourceRefHash === '') {
            return null;
        }

        $refHash = $sourceRefHash !== '' ? $sourceRefHash : MissionCanonicalHash::sha256($rawRef);
        $ref = $rawRef !== '' ? $this->providerSafeContextRef($rawRef) : 'hash:'.substr($refHash, 0, 24);
        $entry = [
            'key' => $sourceType.'|'.$refHash.'|'.$ref,
            'ref' => $ref,
            'ref_hash' => $refHash,
            'source_type' => $sourceType !== '' ? $sourceType : 'unknown',
            'basis' => $basis,
        ];

        foreach ($extra as $key => $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $entry[$key] = trim((string) $value);
            }
        }

        return $entry;
    }

    private function providerSafeContextRef(string $rawRef): string
    {
        $rawRef = trim($rawRef);
        if ($rawRef === '') {
            return '';
        }

        if (strlen($rawRef) > 160 || ! preg_match('/^[A-Za-z0-9_.:\/#@=\-]+$/', $rawRef)) {
            return 'hash:'.substr(MissionCanonicalHash::sha256($rawRef), 0, 24);
        }

        return $rawRef;
    }

    private function inferredSourceType(string $ref): string
    {
        $ref = strtolower(trim($ref));
        if ($ref === '') {
            return 'unknown';
        }

        if (str_contains($ref, ':')) {
            return strtok($ref, ':') ?: 'unknown';
        }

        return match (true) {
            str_contains($ref, 'test') => 'test',
            str_contains($ref, 'migration') => 'migration',
            str_contains($ref, 'doc') || str_contains($ref, '.md') => 'doc',
            str_contains($ref, 'route') => 'route',
            str_contains($ref, 'graph') => 'graph',
            default => 'context_ref',
        };
    }

    /**
     * @param  array<string,array<string,mixed>>  $refs
     * @return array<int,array<string,mixed>>
     */
    private function publicContextRefs(array $refs): array
    {
        return array_values(array_map(static function (array $entry): array {
            unset($entry['key']);

            return $entry;
        }, array_slice($refs, 0, self::MAX_CONTEXT_ATTRIBUTION_REFS)));
    }

    /**
     * @param  array<int,array<string,mixed>>  $refs
     * @return array<int,string>
     */
    private function contextRefLabels(array $refs): array
    {
        return AtlasContextStringListNormalizer::uniqueMappedStrings(
            $refs,
            static fn (mixed $item): mixed => is_array($item) ? ($item['ref'] ?? null) : null,
        );
    }

    /**
     * @param  array<int,array<string,mixed>>  $refs
     * @return array<int,string>
     */
    private function deferSections(array $refs): array
    {
        $sections = [];
        foreach ($refs as $ref) {
            $label = strtolower((string) ($ref['ref'] ?? '').' '.(string) ($ref['source_type'] ?? ''));
            if (str_contains($label, 'test')) {
                $sections[] = 'tests';
            }
            if (str_contains($label, 'doc') || str_contains($label, '.md')) {
                $sections[] = 'docs';
            }
            if (str_contains($label, 'graph')) {
                $sections[] = 'graph';
            }
            if (str_contains($label, 'migration')) {
                $sections[] = 'migrations';
            }
            if (str_contains($label, 'route')) {
                $sections[] = 'routes';
            }
        }

        return array_values(array_unique($sections));
    }

    /**
     * @return array<int,string>
     */
    private function scalarStringList(mixed $value): array
    {
        return AtlasContextStringListNormalizer::uniqueMappedStrings(
            is_array($value) ? $value : [],
            static fn (mixed $item): mixed => is_scalar($item) ? trim((string) $item) : null,
        );
    }
}

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

    public const MISSED_REF_SCHEMA = 'atlas.aucri.missed_ref_candidate.v1';

    public const NOISE_REF_SCHEMA = 'atlas.aucri.noise_ref_candidate.v1';

    public const LEARNING_CANDIDATE_SCHEMA = 'atlas.aucri.retrieval_learning_candidate.v1';

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
        $usedCount = $this->usedCount($selected, (array) ($input['used_ref_hashes'] ?? []), $outcomeStatus);
        $roi = $this->contextRoi($selected, $usedCount, count($noise), count($missed), $gate, $outcomeStatus, $input);
        $feedbackEvent = $this->feedbackEvent($gate, $roi, $missed, $noise, $outcomeStatus, $input);
        $persisted = $record ? $this->persistFeedback($feedbackEvent, $roi, $missed, $noise, $outcomeStatus, $input) : null;
        $learningCandidate = $this->learningCandidate($feedbackEvent, $roi, $missed, $noise, $persisted);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $this->status($gate, $roi, $missed, $noise, $outcomeStatus),
            'generated_at' => Carbon::now()->toIso8601String(),
            'feedback_event' => $feedbackEvent,
            'context_roi' => $roi,
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
     * @param  array<int,mixed>  $usedRefHashes
     */
    private function usedCount(array $selected, array $usedRefHashes, string $outcomeStatus): int
    {
        $used = array_values(array_filter(array_map(
            static fn (mixed $hash): string => is_scalar($hash) ? trim((string) $hash) : '',
            $usedRefHashes,
        )));

        if ($used !== []) {
            return count(array_filter($selected, static fn (array $item): bool => in_array((string) ($item['source_ref_hash'] ?? ''), $used, true)));
        }

        if ($outcomeStatus === 'passed') {
            return count($selected);
        }

        return max(0, (int) floor(count($selected) / 2));
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
    ): array {
        $included = count($selected);
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
            'source_utility' => $this->sourceUtility((array) data_get($gate, 'freshness_report.items', []), $noise),
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
            'next_retrieval_hint' => $this->nextRetrievalHint($missed, $noise, $roi),
            'run_outcome_id' => is_scalar($input['run_outcome_id'] ?? null) ? (string) $input['run_outcome_id'] : null,
            'payload' => [
                'schema_version' => self::SCHEMA_VERSION,
                'freshness_quality_gate_hash' => $feedbackEvent['freshness_quality_gate_hash'],
                'context_roi' => $roi,
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
     * @return array<string,mixed>
     */
    private function learningCandidate(array $feedbackEvent, array $roi, array $missed, array $noise, ?AiRagFeedbackEvent $persisted): array
    {
        $reasons = array_values(array_filter([
            $missed !== [] ? 'missed_required_sources' : null,
            $noise !== [] ? 'noise_context_detected' : null,
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
                'minimum_context_roi' => 0.70,
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @param  array<int,array<string,mixed>>  $noise
     * @return array<string,string>
     */
    private function sourceUtility(array $items, array $noise): array
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
    private function nextRetrievalHint(array $missed, array $noise, array $roi): ?array
    {
        if ($missed === [] && $noise === [] && (float) ($roi['roi_score'] ?? 1.0) >= 0.70) {
            return null;
        }

        return [
            'schema_version' => 'atlas.aucri.next_retrieval_hint.v1',
            'should_repromote_sources' => array_values(array_unique(array_map(static fn (array $item): string => (string) $item['source_type'], $missed))),
            'should_demote_count' => count($noise),
            'min_context_roi_target' => 0.70,
            'advisory' => true,
            'auto_apply' => false,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $missed
     * @param  array<int,array<string,mixed>>  $noise
     * @param  array<string,mixed>  $gate
     */
    private function status(array $gate, array $roi, array $missed, array $noise, string $outcomeStatus): string
    {
        if ((string) ($gate['status'] ?? 'blocked') === 'blocked' || $missed !== []) {
            return 'needs_review';
        }

        if ($noise !== [] || (float) ($roi['roi_score'] ?? 0.0) < 0.50 || $outcomeStatus !== 'passed') {
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
}

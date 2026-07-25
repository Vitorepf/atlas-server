<?php

declare(strict_types=1);

namespace App\Services\Ai\Context\Support;

use App\Services\Ai\Context\AtlasCanonicalContextRef;
use App\Services\Ai\Context\AtlasContextStringListNormalizer;
use App\Services\Ai\Context\AtlasRetrievalFeedbackLoopService as Host;
use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * Pure residual projection / scoring / ref-normalization helpers for
 * {@see \App\Services\Ai\Context\AtlasRetrievalFeedbackLoopService}.
 *
 * No I/O, no DI, no Eloquent, no config(), no clock, no filesystem, no provider.
 * Host keeps capture orchestration, delivered-pack ledger lookup, feedback
 * persistence, and prior-miss resolution (DB + signal policy).
 */
final class RetrievalFeedbackLoopSupport
{
    private function __construct()
    {
    }

    /**
     * @param  array<string,mixed>  $coverage
     * @param  array<int,mixed>  $explicitMisses
     * @return array<int,array<string,mixed>>
     */
    public static function missedRefCandidates(array $coverage, array $explicitMisses): array
    {
        $misses = [];
        foreach ($coverage as $source => $covered) {
            if ($covered === false) {
                $misses[] = [
                    'schema_version' => Host::MISSED_REF_SCHEMA,
                    'source_type' => (string) $source,
                    'reason' => 'required_source_not_covered',
                    'confidence' => 0.90,
                ];
            }
        }

        foreach ($explicitMisses as $miss) {
            $sourceType = is_scalar($miss) ? trim((string) $miss) : trim((string) data_get($miss, 'source_type', ''));
            if ($sourceType === '') {
                continue;
            }

            $misses[] = [
                'schema_version' => Host::MISSED_REF_SCHEMA,
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
     * @param  array<int,mixed>  $explicitNoiseRefs
     * @return array<int,array<string,mixed>>
     */
    public static function noiseRefCandidates(array $selected, array $explicitNoiseHashes, array $explicitNoiseRefs, string $outcomeStatus): array
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
                'schema_version' => Host::NOISE_REF_SCHEMA,
                'source_ref_hash' => $sourceRefHash,
                'candidate_hash' => (string) ($item['candidate_hash'] ?? ''),
                'source_type' => (string) ($item['source_type'] ?? 'unknown'),
                'reason' => $explicitlyNoisy ? 'explicit_noise_signal' : ($stale ? 'stale_context' : 'low_score_in_failed_outcome'),
                'confidence' => $explicitlyNoisy ? 0.90 : 0.68,
            ];
        }

        foreach (self::inputContextRefEntries($explicitNoiseRefs, 'explicit_noise') as $entry) {
            $noise[] = [
                'schema_version' => Host::NOISE_REF_SCHEMA,
                'source_ref_hash' => (string) ($entry['ref_hash'] ?? ''),
                'candidate_hash' => (string) ($entry['ref_hash'] ?? ''),
                'source_type' => (string) ($entry['source_type'] ?? 'unknown'),
                'reason' => 'explicit_noise_context_ref',
                'confidence' => 0.90,
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
    public static function contextRoi(
        array $selected,
        int $usedCount,
        int $noiseCount,
        int $missedCount,
        array $gate,
        string $outcomeStatus,
        array $input,
        ?int $includedCount = null,
        bool $attributionMeasured = false,
    ): array {
        $included = $includedCount ?? count($selected);
        $hasExplicitUtility = array_key_exists('post_execution_utility', $input) && is_numeric($input['post_execution_utility']);
        $utility = $hasExplicitUtility
            ? max(0, min(100, (int) round((float) $input['post_execution_utility'])))
            : null;
        $measured = $hasExplicitUtility && $attributionMeasured;
        $formulaVersion = $hasExplicitUtility ? self::utilityFormulaVersion($input) : null;
        $useRatio = $included === 0 ? 0.0 : $usedCount / $included;
        $noisePenalty = $included === 0 ? 0.0 : min(0.40, $noiseCount / max(1, $included));
        $missPenalty = min(0.35, $missedCount * 0.10);
        $roiScore = $measured
            ? max(0.0, min(1.0, (($utility ?? 0) / 100) * 0.60 + $useRatio * 0.40 - $noisePenalty - $missPenalty))
            : null;

        return [
            'schema_version' => Host::CONTEXT_ROI_SCHEMA,
            'measured' => $measured,
            'utility_basis' => $hasExplicitUtility ? 'explicit_post_execution_utility' : 'unmeasured_no_post_execution_utility',
            'included_sources' => $included,
            'used_sources' => $usedCount,
            'noise_sources' => $noiseCount,
            'missed_required_sources' => $missedCount,
            'context_sufficiency' => null,
            'context_sufficiency_basis' => $measured ? 'synthetic_rerank_status_only' : 'unmeasured',
            'synthetic_rerank' => [
                'status' => (string) data_get($gate, 'status', 'blocked'),
                'selected_count' => count($selected),
                'source' => 'freshness_quality_gate_auxiliary',
            ],
            'post_execution_utility' => $utility,
            'formula_version' => $formulaVersion,
            'use_ratio' => round($useRatio, 4),
            'roi_score' => $roiScore === null ? null : round($roiScore, 4),
            'quality_band' => match (true) {
                $roiScore === null => 'unmeasured',
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
    public static function feedbackEvent(
        array $gate,
        array $roi,
        array $missed,
        array $noise,
        array $contextRefAttribution,
        string $outcomeStatus,
        string $attributionQuality,
        array $input,
        array $deliveredPackLedger,
    ): array
    {
        $flowId = self::flowId($input);
        $measured = (bool) ($roi['measured'] ?? false) && (bool) ($contextRefAttribution['measured'] ?? false);

        return [
            'schema_version' => Host::FEEDBACK_EVENT_SCHEMA,
            'flow_id' => $flowId,
            'retrieval_receipt_id' => (string) ($input['retrieval_receipt_id'] ?? data_get($gate, 'ranking_ref.rerank_result_hash', '')),
            'query_plan_hash' => (string) data_get($gate, 'ranking_ref.rerank_result_hash', ''),
            'freshness_quality_gate_hash' => (string) ($gate['freshness_quality_gate_hash'] ?? ''),
            'outcome_status' => $outcomeStatus,
            'attribution_quality' => $attributionQuality,
            'included_sources' => (int) ($roi['included_sources'] ?? 0),
            'used_sources' => (int) ($roi['used_sources'] ?? 0),
            'noise_sources' => (int) ($roi['noise_sources'] ?? 0),
            'missed_required_sources' => array_values(array_map(static fn (array $item): string => (string) $item['source_type'], $missed)),
            'context_sufficiency' => $roi['context_sufficiency'] ?? null,
            'post_execution_utility' => $roi['post_execution_utility'] ?? null,
            'formula_version' => $roi['formula_version'] ?? null,
            'measured' => $measured,
            'usage_basis' => (string) ($contextRefAttribution['usage_basis'] ?? 'unknown'),
            'measurement_basis' => [
                'utility' => (string) ($roi['utility_basis'] ?? 'unknown'),
                'usage' => (string) ($contextRefAttribution['usage_basis'] ?? 'unknown'),
                'delivery' => (string) ($contextRefAttribution['delivery_basis'] ?? 'unknown'),
            ],
            'delivered_pack_hashes' => (array) ($deliveredPackLedger['hashes'] ?? []),
            'applied_policy_snapshot' => self::appliedPolicySnapshot($deliveredPackLedger),
            'source_utility' => self::sourceUtility((array) data_get($gate, 'freshness_report.items', []), $noise, $input),
            'failure_reason' => self::failureReason($input, $outcomeStatus, $missed, $noise),
        ];
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
    public static function learningCandidate(
        array $feedbackEvent,
        array $roi,
        array $missed,
        array $noise,
        array $contextRefAttribution,
        array $nextContextPolicy,
        ?string $persistedFeedbackHash = null,
    ): array {
        $roiMeasured = (bool) ($roi['measured'] ?? false);
        $attributionMeasured = (bool) ($contextRefAttribution['measured'] ?? false);
        $roiScore = $roi['roi_score'] ?? null;
        $reasons = array_values(array_filter([
            $missed !== [] ? 'missed_required_sources' : null,
            ((int) ($contextRefAttribution['noise_count'] ?? 0)) > 0 ? 'noise_context_detected' : null,
            $attributionMeasured && ((float) ($contextRefAttribution['waste_ratio'] ?? 0.0)) >= 0.40 ? 'context_waste_detected' : null,
            $roiMeasured && $roiScore !== null && (float) $roiScore < 0.50 ? 'low_context_roi' : null,
            ($feedbackEvent['outcome_status'] ?? 'unknown') !== 'passed' ? 'non_passing_outcome' : null,
        ]));

        return [
            'schema_version' => Host::LEARNING_CANDIDATE_SCHEMA,
            'status' => $reasons === [] ? 'none' : 'proposed',
            'auto_apply' => false,
            'requires_review' => $reasons !== [],
            'reasons' => $reasons,
            'evidence_refs' => array_values(array_filter([
                'retrieval_receipt:'.$feedbackEvent['retrieval_receipt_id'],
                'freshness_quality_gate:'.$feedbackEvent['freshness_quality_gate_hash'],
                $persistedFeedbackHash !== null && $persistedFeedbackHash !== '' ? 'rag_feedback:'.$persistedFeedbackHash : null,
            ])),
            'proposed_state' => [
                'repromote_source_types' => self::itemStringColumn($missed, 'source_type'),
                'demote_noise_source_hashes' => self::itemStringColumn($noise, 'source_ref_hash'),
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
    public static function sourceUtility(array $items, array $noise, array $input = []): array
    {
        $noiseHashes = self::itemStringColumn($noise, 'source_ref_hash');
        $explicitUsedKeys = self::explicitUsedUtilityKeys($input);
        $utility = [];

        foreach ($items as $item) {
            $compoundingRef = AtlasCanonicalContextRef::compoundingMemoryLiftRef($item);
            if ($compoundingRef !== null) {
                if (in_array((string) ($item['source_ref_hash'] ?? ''), $noiseHashes, true)) {
                    $utility[$compoundingRef] = 'noise';
                } elseif (isset($explicitUsedKeys[$compoundingRef])) {
                    $utility[$compoundingRef] = 'used';
                } else {
                    $utility[$compoundingRef] = 'included';
                }

                continue;
            }

            $hash = (string) ($item['source_ref_hash'] ?? '');
            if ($hash === '') {
                continue;
            }
            $utility[$hash] = in_array($hash, $noiseHashes, true) ? 'noise' : 'useful';
        }

        foreach (self::compoundingMemoryDeliveredRefs($input) as $ref) {
            if (! isset($utility[$ref])) {
                $utility[$ref] = isset($explicitUsedKeys[$ref]) ? 'used' : 'included';
            }
        }

        foreach (self::inputContextRefEntries($input['used_context_refs'] ?? [], 'explicit_used') as $entry) {
            $ref = (string) ($entry['lift_ref'] ?? $entry['ref'] ?? '');
            if ($ref !== '') {
                $utility[$ref] = 'used';
            }
        }

        foreach (self::scalarStringList($input['used_ref_hashes'] ?? []) as $hash) {
            $utility[$hash] = 'used';
        }

        foreach (self::inputContextRefEntries($input['noise_context_refs'] ?? [], 'explicit_noise') as $entry) {
            $ref = (string) ($entry['lift_ref'] ?? $entry['ref'] ?? '');
            if ($ref !== '') {
                $utility[$ref] = 'noise';
            }
        }

        return $utility;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,true>
     */
    public static function explicitUsedUtilityKeys(array $input): array
    {
        $keys = [];
        foreach (self::scalarStringList($input['used_ref_hashes'] ?? []) as $hash) {
            $keys[$hash] = true;
        }

        foreach (self::inputContextRefEntries($input['used_context_refs'] ?? [], 'explicit_used') as $entry) {
            $ref = (string) ($entry['lift_ref'] ?? $entry['ref'] ?? '');
            if ($ref !== '') {
                $keys[$ref] = true;
            }
        }

        foreach (self::scalarStringList($input['used_context_refs'] ?? []) as $raw) {
            $normalized = AtlasCanonicalContextRef::normalizeCompoundingMemoryRef($raw);
            if ($normalized !== '') {
                $keys[$normalized] = true;
            }
        }

        return $keys;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<int,string>
     */
    public static function compoundingMemoryDeliveredRefs(array $input): array
    {
        $refs = [];
        foreach (self::inputContextRefEntries($input['delivered_context_refs'] ?? [], 'explicit_delivered') as $entry) {
            $liftRef = (string) ($entry['lift_ref'] ?? '');
            if ($liftRef !== '') {
                $refs[] = $liftRef;
            }
        }

        foreach (self::scalarStringList($input['delivered_context_refs'] ?? []) as $raw) {
            $normalized = AtlasCanonicalContextRef::normalizeCompoundingMemoryRef($raw);
            if ($normalized !== '') {
                $refs[] = $normalized;
            }
        }

        return array_values(array_unique($refs));
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,string>
     */
    public static function itemStringColumn(array $items, string $key): array
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
    public static function failureReason(array $input, string $outcomeStatus, array $missed, array $noise): ?string
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
     * @param  array<string,mixed>  $input
     */
    public static function utilityFormulaVersion(array $input): string
    {
        foreach (['post_execution_utility_formula_version', 'formula_version'] as $key) {
            if (is_scalar($input[$key] ?? null) && trim((string) $input[$key]) !== '') {
                return trim((string) $input[$key]);
            }
        }

        return Host::EXPLICIT_UTILITY_FORMULA_VERSION;
    }

    /**
     * @param  array<int,array<string,mixed>>  $missed
     * @param  array<int,array<string,mixed>>  $noise
     * @param  array<string,mixed>  $roi
     * @return array<string,mixed>|null
     */
    public static function nextRetrievalHint(array $missed, array $noise, array $roi, array $nextContextPolicy): ?array
    {
        $roiScore = $roi['roi_score'] ?? null;
        if ($missed === [] && $noise === [] && ($roiScore === null || (float) $roiScore >= 0.70) && ($nextContextPolicy['actions'] ?? []) === ['keep_current_pack']) {
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
    public static function status(array $gate, array $roi, array $missed, array $noise, array $contextRefAttribution, string $outcomeStatus): string
    {
        if ((string) ($gate['status'] ?? 'blocked') === 'blocked' || $missed !== []) {
            return 'needs_review';
        }

        $roiScore = $roi['roi_score'] ?? null;
        if (
            $noise !== []
            || ((int) ($contextRefAttribution['noise_count'] ?? 0)) > 0
            || ((bool) ($contextRefAttribution['measured'] ?? false) && ((float) ($contextRefAttribution['waste_ratio'] ?? 0.0)) >= 0.40)
            || ((bool) ($roi['measured'] ?? false) && $roiScore !== null && (float) $roiScore < 0.50)
            || $outcomeStatus !== 'passed'
        ) {
            return 'learning_candidate';
        }

        return 'recorded';
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public static function flowId(array $input): string
    {
        if (is_scalar($input['flow_id'] ?? null) && trim((string) $input['flow_id']) !== '') {
            return trim((string) $input['flow_id']);
        }

        $domain = (string) ($input['domain'] ?? 'atlas');
        $taskType = (string) ($input['task_type'] ?? 'direct');

        return $domain.'.'.$taskType;
    }

    public static function outcomeStatus(string $status): string
    {
        return match ($status) {
            'passed', 'success', 'succeeded', 'ok' => 'passed',
            'partial', 'needs_review' => 'partial',
            'failed', 'blocked', 'error' => 'failed',
            default => 'unknown',
        };
    }

    public static function attributionQuality(string $quality): string
    {
        return match ($quality) {
            'gate_verified', 'transcript_inferred', 'operator_reported', 'cited', 'diffed', 'unmeasured', 'low' => $quality,
            default => 'low',
        };
    }

    /**
     * @param  array<int,string>  $deliveredRefs
     * @param  array<string,mixed>  $input
     * @return array<int,array<string,mixed>>
     */
    public static function deliveredLedgerNoiseRefCandidates(array $deliveredRefs, array $input): array
    {
        $delivered = self::contextRefEntriesFromRefs($deliveredRefs, 'delivered_pack_ledger');
        if ($delivered === []) {
            return [];
        }

        $usedKeys = self::usedContextRefKeys($delivered, $input);
        $hasExplicitUseSignal = self::hasExplicitUseSignal($input);
        $noise = [];

        if ($hasExplicitUseSignal) {
            foreach ($delivered as $key => $entry) {
                if (isset($usedKeys[$key])) {
                    continue;
                }
                $noise[$key] = self::noiseCandidateFromContextEntry($entry, 'delivered_ref_not_used_after_execution');
            }
        }

        foreach (self::inputContextRefEntries($input['noise_context_refs'] ?? [], 'explicit_noise') as $entry) {
            $key = self::matchingDeliveredKey($delivered, $entry);
            if ($key === null) {
                continue;
            }
            $noise[$key] = self::noiseCandidateFromContextEntry($delivered[$key], 'explicit_noise_context_ref');
        }

        return array_values($noise);
    }

    /**
     * @param  array<string,array<string,mixed>>  $entryByKey
     * @return array<string,true>
     */
    public static function usedContextRefKeys(array $entryByKey, array $input): array
    {
        $usedKeys = [];
        foreach (self::inputContextRefEntries($input['used_context_refs'] ?? [], 'explicit_used') as $entry) {
            $key = self::matchingDeliveredKey($entryByKey, $entry);
            if ($key !== null) {
                $usedKeys[$key] = true;
            }
        }

        foreach (self::scalarStringList($input['used_ref_hashes'] ?? []) as $hash) {
            foreach ($entryByKey as $key => $entry) {
                if (
                    $hash !== ''
                    && (
                        hash_equals((string) ($entry['ref_hash'] ?? ''), $hash)
                        || hash_equals((string) ($entry['ref'] ?? ''), $hash)
                    )
                ) {
                    $usedKeys[$key] = true;
                }
            }
        }

        return $usedKeys;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public static function hasExplicitUseSignal(array $input): bool
    {
        return self::inputContextRefEntries($input['used_context_refs'] ?? [], 'explicit_used') !== []
            || self::scalarStringList($input['used_ref_hashes'] ?? []) !== [];
    }

    /**
     * @param  array<string,array<string,mixed>>  $entryByKey
     * @param  array<string,mixed>  $entry
     */
    public static function matchingDeliveredKey(array $entryByKey, array $entry): ?string
    {
        $ref = (string) ($entry['ref'] ?? '');
        $refHash = (string) ($entry['ref_hash'] ?? '');

        foreach ($entryByKey as $key => $candidate) {
            if ($ref !== '' && hash_equals((string) ($candidate['ref'] ?? ''), $ref)) {
                return $key;
            }
            if ($refHash !== '' && hash_equals((string) ($candidate['ref_hash'] ?? ''), $refHash)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return array<string,mixed>
     */
    public static function noiseCandidateFromContextEntry(array $entry, string $reason): array
    {
        return [
            'schema_version' => Host::NOISE_REF_SCHEMA,
            'ref' => (string) ($entry['ref'] ?? ''),
            'source_ref_hash' => (string) ($entry['ref_hash'] ?? ''),
            'candidate_hash' => (string) ($entry['ref_hash'] ?? ''),
            'source_type' => (string) ($entry['source_type'] ?? 'unknown'),
            'reason' => $reason,
            'confidence' => 0.90,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $selected
     * @param  array<int,array<string,mixed>>  $missed
     * @param  array<int,array<string,mixed>>  $noise
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public static function contextRefAttribution(array $selected, array $missed, array $noise, string $outcomeStatus, array $input, array $deliveredPackLedger = []): array
    {
        $hasLedgerDeliveredRefs = (bool) ($deliveredPackLedger['hit'] ?? false);
        $delivered = $hasLedgerDeliveredRefs
            ? self::contextRefEntriesFromRefs((array) ($deliveredPackLedger['delivered_refs'] ?? []), 'delivered_pack_ledger')
            : [];

        if (! $hasLedgerDeliveredRefs) {
            foreach ($selected as $item) {
                $entry = self::selectedContextRefEntry($item);
                if ($entry !== null) {
                    $delivered[$entry['key']] = $entry;
                }
            }

            foreach (self::inputContextRefEntries($input['delivered_context_refs'] ?? [], 'explicit_delivered') as $entry) {
                $delivered[$entry['key']] = $entry;
            }
        }

        $usedSignals = self::inputContextRefEntries($input['used_context_refs'] ?? [], 'explicit_used');
        if (! $hasLedgerDeliveredRefs) {
            foreach ($usedSignals as $entry) {
                $delivered[$entry['key']] ??= $entry;
            }
        }

        $noiseEntries = [];
        foreach ($noise as $item) {
            $entry = self::candidateContextRefEntry($item, 'noise_candidate');
            if ($entry !== null) {
                $key = $hasLedgerDeliveredRefs ? self::matchingDeliveredKey($delivered, $entry) : $entry['key'];
                if ($key === null) {
                    continue;
                }
                $noiseEntries[$key] = $delivered[$key] ?? $entry;
                if (! $hasLedgerDeliveredRefs) {
                    $delivered[$entry['key']] ??= $entry;
                }
            }
        }

        foreach (self::inputContextRefEntries($input['noise_context_refs'] ?? [], 'explicit_noise') as $entry) {
            $key = $hasLedgerDeliveredRefs ? self::matchingDeliveredKey($delivered, $entry) : $entry['key'];
            if ($key === null) {
                continue;
            }
            $noiseEntries[$key] = $delivered[$key] ?? $entry;
            if (! $hasLedgerDeliveredRefs) {
                $delivered[$entry['key']] ??= $entry;
            }
        }

        $usedHashes = self::scalarStringList($input['used_ref_hashes'] ?? []);
        $usedKeys = [];
        $hasExplicitUseSignal = $usedSignals !== [] || $usedHashes !== [];
        if ($hasExplicitUseSignal) {
            foreach ($usedSignals as $entry) {
                $key = $hasLedgerDeliveredRefs ? self::matchingDeliveredKey($delivered, $entry) : $entry['key'];
                if ($key !== null) {
                    $usedKeys[$key] = true;
                }
            }

            foreach ($delivered as $key => $entry) {
                if (
                    in_array((string) ($entry['ref_hash'] ?? ''), $usedHashes, true)
                    || in_array((string) ($entry['ref'] ?? ''), $usedHashes, true)
                ) {
                    $usedKeys[$key] = true;
                }
            }
            $usageBasis = 'explicit_used_refs';
        } else {
            $usageBasis = 'unmeasured_no_explicit_used_refs';
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
        $attributionMeasured = $hasExplicitUseSignal && $usedCount > 0;

        return [
            'schema_version' => Host::CONTEXT_REF_ATTRIBUTION_SCHEMA,
            'measured' => $attributionMeasured,
            'delivery_basis' => $hasLedgerDeliveredRefs ? 'delivered_pack_ledger' : 'synthetic_rerank_or_explicit_input',
            'delivered_count' => $deliveredCount,
            'used_count' => $usedCount,
            'unused_count' => $unusedCount,
            'noise_count' => $noiseCount,
            'missed_count' => count($missed),
            'use_ratio' => round($useRatio, 4),
            'waste_ratio' => round($wasteRatio, 4),
            'usage_basis' => $usageBasis,
            'missing_source_types' => self::itemStringColumn($missed, 'source_type'),
            'delivered_refs' => self::publicContextRefs($delivered),
            'used_refs' => self::publicContextRefs($used),
            'unused_refs' => self::publicContextRefs($unused),
            'noise_refs' => self::publicContextRefs($noiseEntries),
            'source_policy' => [
                'ref_contract' => 'provider_safe_ref_or_hash_only',
                'raw_text_exposed' => false,
                'max_refs' => Host::MAX_CONTEXT_ATTRIBUTION_REFS,
                'auto_apply' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $contextRefAttribution
     * @param  array<string,mixed>  $roi
     * @return array<string,mixed>
     */
    public static function nextContextPolicy(array $contextRefAttribution, array $roi, bool $repromoteSpecificRefsEnabled = false): array
    {
        $actions = [];
        $missingSourceTypes = (array) ($contextRefAttribution['missing_source_types'] ?? []);
        $noiseRefs = (array) ($contextRefAttribution['noise_refs'] ?? []);
        $unusedRefs = (array) ($contextRefAttribution['unused_refs'] ?? []);
        $wasteRatio = (float) ($contextRefAttribution['waste_ratio'] ?? 0.0);
        $attributionMeasured = (bool) ($contextRefAttribution['measured'] ?? false);
        $roiMeasured = (bool) ($roi['measured'] ?? false);
        $roiScore = $roi['roi_score'] ?? null;

        if ($missingSourceTypes !== []) {
            $actions[] = 'expand_missing_source_types';
        }

        if ($noiseRefs !== []) {
            $actions[] = 'demote_noise_context_refs';
        }

        if ($attributionMeasured && $wasteRatio >= 0.40) {
            $actions[] = 'shrink_initial_context';
        }

        if ($roiMeasured && $roiScore !== null && (float) $roiScore < 0.50) {
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
            'schema_version' => Host::NEXT_CONTEXT_POLICY_SCHEMA,
            'initial_context_contract' => 'minimal_provider_safe_top_k',
            'expansion_contract' => 'on_demand_by_source_type_and_ref_handle',
            'actions' => array_values(array_unique($actions)),
            'recommended_action' => $actions[0],
            'next_initial_budget_multiplier' => $nextInitialBudgetMultiplier,
            'expand_source_types' => $missingSourceTypes,
            'demote_context_refs' => self::contextRefLabels($noiseRefs),
            'defer_sections' => self::deferSections(array_merge($unusedRefs, $noiseRefs)),
            'minimum_context_roi_target' => 0.70,
            'provider_safe' => true,
            'auto_apply' => false,
            'auto_apply_scope' => 'bounded_source_mix_only',
            'specific_ref_repromotion_enabled' => $repromoteSpecificRefsEnabled,
            'requires_review' => $actions !== ['keep_current_pack'],
        ];
    }

    /**
     * @param  array{hit:bool,hashes:array<int,string>,delivered_refs:array<int,string>,entries:array<int,array<string,mixed>>}  $deliveredPackLedger
     * @return array<string,mixed>
     */
    public static function appliedPolicySnapshot(array $deliveredPackLedger): array
    {
        $entries = (array) ($deliveredPackLedger['entries'] ?? []);
        if ($entries === []) {
            return [];
        }

        if (count($entries) === 1) {
            return (array) ($entries[0]['policy_snapshot'] ?? []);
        }

        return [
            'schema_version' => 'atlas.aobg.applied_policy_snapshot_union.v1',
            'pack_count' => count($entries),
            'snapshots' => array_values(array_filter(array_map(
                static fn (array $entry): array => (array) ($entry['policy_snapshot'] ?? []),
                $entries,
            ))),
        ];
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>|null
     */
    public static function selectedContextRefEntry(array $item): ?array
    {
        return self::contextRefEntry(
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
    public static function candidateContextRefEntry(array $item, string $basis): ?array
    {
        return self::contextRefEntry(
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
     * @param  array<int,string>  $refs
     * @return array<string,array<string,mixed>>
     */
    public static function contextRefEntriesFromRefs(array $refs, string $basis): array
    {
        $entries = [];
        foreach (AtlasCanonicalContextRef::uniqueStrings($refs) as $ref) {
            $entry = self::contextRefEntry($ref, self::inferredSourceType($ref), $basis);
            if ($entry !== null) {
                $entries[$entry['key']] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function inputContextRefEntries(mixed $value, string $basis): array
    {
        $items = is_array($value) ? $value : [];
        $entries = [];
        foreach ($items as $item) {
            if (is_scalar($item)) {
                $rawRef = (string) $item;
                $entry = self::contextRefEntry(
                    $rawRef,
                    self::inferredSourceType($rawRef),
                    $basis,
                );
            } elseif (is_array($item)) {
                $rawRef = (string) ($item['ref'] ?? $item['source_ref'] ?? $item['path'] ?? $item['id'] ?? $item['source_ref_hash'] ?? $item['ref_hash'] ?? '');
                $entry = self::contextRefEntry(
                    $rawRef,
                    (string) ($item['source_type'] ?? self::inferredSourceType($rawRef)),
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
    public static function contextRefEntry(string $rawRef, string $sourceType, string $basis, string $sourceRefHash = '', array $extra = []): ?array
    {
        $rawRef = trim($rawRef);
        $sourceRefHash = trim($sourceRefHash);
        if ($rawRef === '' && $sourceRefHash === '') {
            return null;
        }

        $refHash = $sourceRefHash !== '' ? $sourceRefHash : MissionCanonicalHash::sha256($rawRef);
        $liftRef = str_starts_with(strtolower($rawRef), 'compounding_memory:')
            ? AtlasCanonicalContextRef::normalizeCompoundingMemoryRef($rawRef)
            : '';
        $ref = $rawRef !== '' ? self::providerSafeContextRef($rawRef) : 'hash:'.substr($refHash, 0, 24);
        $entry = [
            'key' => $sourceType.'|'.$refHash.'|'.$ref,
            'ref' => $ref,
            'ref_hash' => $refHash,
            'source_type' => $sourceType !== '' ? $sourceType : 'unknown',
            'basis' => $basis,
        ];
        if ($liftRef !== '') {
            $entry['lift_ref'] = $liftRef;
        }

        foreach ($extra as $key => $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $entry[$key] = trim((string) $value);
            }
        }

        return $entry;
    }

    /**
     * @param  array<string,mixed>  $feedbackEvent
     * @return array<string,mixed>
     */
    public static function providerSafeFeedbackEvent(array $feedbackEvent): array
    {
        $sourceUtility = [];
        foreach ((array) ($feedbackEvent['source_utility'] ?? []) as $key => $value) {
            if (str_starts_with(strtolower((string) $key), 'compounding_memory:')) {
                continue;
            }
            $sourceUtility[$key] = $value;
        }
        $feedbackEvent['source_utility'] = $sourceUtility;

        return $feedbackEvent;
    }

    public static function providerSafeContextRef(string $rawRef): string
    {
        $rawRef = trim($rawRef);
        if ($rawRef === '') {
            return '';
        }

        if (str_starts_with(strtolower($rawRef), 'compounding_memory:')) {
            return 'hash:'.substr(MissionCanonicalHash::sha256($rawRef), 0, 24);
        }

        if (strlen($rawRef) > 160 || ! preg_match('/^[A-Za-z0-9_.:\/#@=\-]+$/', $rawRef)) {
            return 'hash:'.substr(MissionCanonicalHash::sha256($rawRef), 0, 24);
        }

        return $rawRef;
    }

    public static function inferredSourceType(string $ref): string
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
    public static function publicContextRefs(array $refs): array
    {
        return array_values(array_map(static function (array $entry): array {
            unset($entry['key'], $entry['lift_ref']);

            return $entry;
        }, array_slice($refs, 0, Host::MAX_CONTEXT_ATTRIBUTION_REFS)));
    }

    /**
     * @param  array<int,array<string,mixed>>  $refs
     * @return array<int,string>
     */
    public static function contextRefLabels(array $refs): array
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
    public static function deferSections(array $refs): array
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
    public static function scalarStringList(mixed $value): array
    {
        return AtlasContextStringListNormalizer::uniqueMappedStrings(
            is_array($value) ? $value : [],
            static fn (mixed $item): mixed => is_scalar($item) ? trim((string) $item) : null,
        );
    }

}

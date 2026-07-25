<?php

declare(strict_types=1);

namespace App\Services\Ai\Context\Support;

use App\Services\Ai\Context\AtlasContextRankingSystemService;
use App\Services\Ai\Context\AtlasContextStringListNormalizer;
use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * Pure AUCRI context ranking / score / feedback-impact projectors for
 * {@see AtlasContextRankingSystemService}.
 *
 * No DB, ledger, Carbon clock, config, WorldModel DI, or provider I/O.
 * Host keeps rank() orchestration, graphRanking DI, feedbackHint DB/global
 * hints, concentration demotion, and evidence ledger snapshot write.
 */
final class ContextRankingSystemSupport
{
    public const SCHEMA_VERSION = 'atlas.aucri.context_ranking_system.v1';

    public const RERANK_RESULT_SCHEMA = 'atlas.aucri.rerank_result.v1';

    public const CONTEXT_SCORE_SCHEMA = 'atlas.aucri.context_score.v1';

    public const EXCLUDED_REF_SCHEMA = 'atlas.aucri.excluded_ref.v1';

    public const FEEDBACK_IMPACT_REPORT_SCHEMA = 'atlas.aucri.feedback_impact_report.v1';

    private function __construct() {}

    /**
     * @param  array<int,array<string,mixed>>  $candidates
     * @return array<int,array<string,mixed>>
     */
    public static function refsForProfessionalRanker(array $candidates): array
    {
        return array_values(array_map(static fn (array $candidate): array => [
            'source' => (string) ($candidate['source_type'] ?? 'unknown'),
            'ref' => (string) ($candidate['source_ref'] ?? $candidate['candidate_id'] ?? ''),
            'score' => (float) ($candidate['score_hint'] ?? 0.5),
            'reason' => (string) ($candidate['reason'] ?? 'candidate'),
            // HONESTY CONTRACT (runtime_language_boundary canon): a `semantic_candidate` is only
            // called semantic when it actually IS. Two branches, decided upstream by
            // AtlasHybridRetrievalInfrastructureService::applyLocalSemanticScores():
            //   - `local_semantic_vector`: the candidate's score_hint is a REAL cosine score from
            //     the LOCAL Python semantic_rag runtime (SemanticRetrievalRuntime / real local
            //     embeddings, no provider, no external store). Marked by
            //     `score_origin => local_semantic_vector` stamped only when real scores were used.
            //     This channel legitimately qualifies for the reranker's +0.22 semantic-channel
            //     bonus at ProgrammingProfessionalReranker::score().
            //   - `manifest_pending_embedding`: the honest placeholder. The candidate is a chunked
            //     manifest of the objective from AtlasSemanticEmbeddingFoundationService::
            //     candidateSet() (mode `manifest_without_external_embedding`) carrying the STATIC
            //     score_hint of 0.60 — no vector, no cosine. The canon forbids labelling it
            //     "semantic"/"vector", and it deliberately does NOT reuse `lexical_token_overlap`
            //     (that label would wrongly trigger the reranker's +0.22 lexical bonus).
            'retrieval_channel' => self::retrievalChannel($candidate),
            'privacy' => (bool) ($candidate['provider_safe'] ?? false) ? 'provider_safe' : 'provider_unsafe',
            'freshness' => self::freshnessLabel((string) ($candidate['status'] ?? 'unknown')),
        ], $candidates));
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    public static function retrievalChannel(array $candidate): string
    {
        if (($candidate['source_type'] ?? null) !== 'semantic_candidate') {
            return 'aucri_hybrid_retrieval';
        }

        return ($candidate['score_origin'] ?? null) === 'local_semantic_vector'
            ? 'local_semantic_vector'
            : 'manifest_pending_embedding';
    }

    /**
     * @param  array<int,array<string,mixed>>  $candidates
     * @param  array<string,mixed>  $professional
     * @param  array<string,mixed>  $graphRanking
     * @param  array<string,mixed>  $feedbackHint
     * @return array<int,array<string,mixed>>
     */
    public static function rankedCandidates(array $candidates, array $professional, array $graphRanking, array $feedbackHint): array
    {
        $professionalByKey = collect((array) ($professional['ranked_refs'] ?? []))
            ->keyBy(static fn (array $ref): string => (string) ($ref['source'] ?? 'unknown').'|'.(string) ($ref['ref'] ?? ''))
            ->all();
        $graphSourceScore = self::graphSourceScores((array) ($graphRanking['ranked_sources'] ?? []));

        $ranked = [];
        foreach ($candidates as $candidate) {
            $sourceType = (string) ($candidate['source_type'] ?? 'unknown');
            $sourceRef = (string) ($candidate['source_ref'] ?? $candidate['candidate_id'] ?? '');
            $sourceRefHash = MissionCanonicalHash::sha256($sourceRef);
            $key = $sourceType.'|'.$sourceRef;
            $professionalScore = (float) data_get($professionalByKey, $key.'.score', $candidate['score_hint'] ?? 0.5);
            $feedbackImpact = self::feedbackImpact($candidate, $sourceRef, $sourceRefHash, $feedbackHint);
            $components = [
                'schema_version' => self::CONTEXT_SCORE_SCHEMA,
                'semantic' => round((float) ($candidate['score_hint'] ?? 0.5), 4),
                'professional_rerank' => round(min(1.0, $professionalScore / 2.0), 4),
                'authority' => self::authorityScore((string) ($candidate['authority_level'] ?? 'unknown')),
                'freshness' => self::freshnessScore((string) ($candidate['status'] ?? 'unknown')),
                'graph' => self::graphBoost($candidate, $graphSourceScore),
                'privacy' => (bool) ($candidate['provider_safe'] ?? false) ? 1.0 : 0.0,
            ];
            if ((bool) ($feedbackHint['active'] ?? false)) {
                $components['feedback_hint_delta'] = (float) $feedbackImpact['delta'];
            }

            $baseTotal = $components['semantic'] * 0.18
                + $components['professional_rerank'] * 0.24
                + $components['authority'] * 0.20
                + $components['freshness'] * 0.16
                + $components['graph'] * 0.12
                + $components['privacy'] * 0.10;
            $total = round(
                max(0.0, min(1.0, $baseTotal + (float) $feedbackImpact['delta'])),
                4
            );

            $ranked[] = [
                'candidate_id' => (string) ($candidate['candidate_id'] ?? ''),
                'source_type' => $sourceType,
                'source_ref_hash' => $sourceRefHash,
                'owner_doc' => (string) ($candidate['owner_doc'] ?? ''),
                'score' => $total,
                'score_components' => $components,
                'required' => (bool) ($candidate['required'] ?? false),
                'available' => (bool) ($candidate['available'] ?? false),
                'provider_safe' => (bool) ($candidate['provider_safe'] ?? false),
                'reasons' => self::reasons($candidate, $components, (array) $feedbackImpact['reasons']),
                'candidate_hash' => (string) ($candidate['candidate_hash'] ?? MissionCanonicalHash::sha256($candidate)),
            ];
        }

        usort(
            $ranked,
            static fn (array $a, array $b): int => $b['score'] <=> $a['score']
                ?: strcmp((string) $a['candidate_id'], (string) $b['candidate_id']),
        );

        return $ranked;
    }

    /**
     * @param  array<int,array<string,mixed>>  $rankedSources
     * @return array<string,float>
     */
    public static function graphSourceScores(array $rankedSources): array
    {
        $scores = [];
        foreach ($rankedSources as $source) {
            $path = (string) ($source['path'] ?? '');
            if ($path === '') {
                continue;
            }
            $scores[$path] = max(0.0, min(1.0, ((float) ($source['top_score'] ?? 0.0)) / 2.5));
        }

        return $scores;
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  array<string,float>  $graphSourceScore
     */
    public static function graphBoost(array $candidate, array $graphSourceScore): float
    {
        $ownerDoc = (string) ($candidate['owner_doc'] ?? '');
        if ($ownerDoc !== '' && isset($graphSourceScore[$ownerDoc])) {
            return round($graphSourceScore[$ownerDoc], 4);
        }

        return 0.0;
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  array<string,float|int|string>  $components
     * @param  array<int,string>  $feedbackReasons
     * @return array<int,string>
     */
    public static function reasons(array $candidate, array $components, array $feedbackReasons = []): array
    {
        $reasons = [(string) ($candidate['reason'] ?? 'candidate')];
        $reasons[] = 'authority:'.(string) ($candidate['authority_level'] ?? 'unknown');
        $reasons[] = 'freshness:'.self::freshnessLabel((string) ($candidate['status'] ?? 'unknown'));
        if ((float) ($components['graph'] ?? 0.0) > 0.0) {
            $reasons[] = 'graph_support';
        }
        if (! (bool) ($candidate['provider_safe'] ?? false)) {
            $reasons[] = 'provider_unsafe_penalty';
        }

        return array_values(array_unique(array_merge($reasons, $feedbackReasons)));
    }

    /**
     * @param  array<int,array<string,mixed>>  $ranked
     * @param  array<int,array<string,mixed>>  $selected
     * @param  array<int,array<string,mixed>>  $professionalExcluded
     * @return array<int,array<string,mixed>>
     */
    public static function excludedRefs(array $ranked, array $selected, array $professionalExcluded): array
    {
        $selectedIds = collect($selected)->pluck('candidate_id')->all();
        $excluded = [];

        foreach ($ranked as $candidate) {
            if (in_array($candidate['candidate_id'], $selectedIds, true)) {
                continue;
            }
            $excluded[] = [
                'schema_version' => self::EXCLUDED_REF_SCHEMA,
                'source_ref_hash' => (string) ($candidate['source_ref_hash'] ?? ''),
                'candidate_hash' => (string) ($candidate['candidate_hash'] ?? ''),
                'source_type' => (string) ($candidate['source_type'] ?? 'unknown'),
                'reason' => 'budget_trimmed',
            ];
        }

        foreach ($professionalExcluded as $ref) {
            $excluded[] = [
                'schema_version' => self::EXCLUDED_REF_SCHEMA,
                'source_ref_hash' => MissionCanonicalHash::sha256((string) ($ref['ref'] ?? '')),
                'candidate_hash' => '',
                'source_type' => (string) ($ref['source'] ?? 'unknown'),
                'reason' => (string) ($ref['reason'] ?? 'professional_reranker_excluded'),
            ];
        }

        return array_values($excluded);
    }

    /**
     * @param  array<int,array<string,mixed>>  $selected
     * @param  array<int,string>  $requiredSources
     * @return array<string,bool>
     */
    public static function requiredSourceCoverage(array $selected, array $requiredSources): array
    {
        return collect($requiredSources)
            ->mapWithKeys(fn (string $source): array => [$source => collect($selected)->contains('source_type', $source)])
            ->all();
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<int,array<string,mixed>>  $selected
     * @param  array<string,bool>  $coverage
     */
    public static function status(array $plan, array $selected, array $coverage): string
    {
        if ((string) ($plan['status'] ?? '') === 'blocked') {
            return 'blocked';
        }

        if ($selected === []) {
            return 'blocked';
        }

        if (in_array(false, $coverage, true)) {
            return 'degraded';
        }

        return (string) ($plan['status'] ?? '') === 'degraded' ? 'degraded' : 'ready';
    }

    /**
     * @param  array<int,array<string,mixed>>  $baselineRanked
     * @param  array<int,array<string,mixed>>  $currentRanked
     * @param  array<int,array<string,mixed>>  $baselineSelected
     * @param  array<int,array<string,mixed>>  $currentSelected
     * @param  array<string,bool>  $baselineCoverage
     * @param  array<string,bool>  $currentCoverage
     * @param  array<string,mixed>  $feedbackHint
     * @return array<string,mixed>
     */
    public static function feedbackImpactReport(
        array $baselineRanked,
        array $currentRanked,
        array $baselineSelected,
        array $currentSelected,
        array $baselineCoverage,
        array $currentCoverage,
        array $feedbackHint,
    ): array {
        if (! (bool) ($feedbackHint['active'] ?? false)) {
            return [
                'schema_version' => self::FEEDBACK_IMPACT_REPORT_SCHEMA,
                'status' => 'inactive',
                'source' => 'none',
                'selected_set_changed' => false,
                'rank_position_change_count' => 0,
                'coverage_delta' => [
                    'gained_required_sources' => [],
                    'lost_required_sources' => [],
                ],
                'policy' => self::feedbackImpactReportPolicy(),
            ];
        }

        $baselinePositions = self::rankPositionMap($baselineRanked);
        $currentPositions = self::rankPositionMap($currentRanked);
        $baselineSelectedKeys = self::rankKeys($baselineSelected);
        $currentSelectedKeys = self::rankKeys($currentSelected);
        $newlySelectedKeys = array_values(array_diff($currentSelectedKeys, $baselineSelectedKeys));
        $droppedKeys = array_values(array_diff($baselineSelectedKeys, $currentSelectedKeys));

        $unionKeys = array_values(array_unique(array_merge(array_keys($baselinePositions), array_keys($currentPositions))));
        $rankPositionChangeCount = 0;
        $scoreDeltaTotal = 0.0;
        foreach ($unionKeys as $key) {
            $before = $baselinePositions[$key] ?? null;
            $after = $currentPositions[$key] ?? null;
            if ($before === null || $after === null) {
                $rankPositionChangeCount++;

                continue;
            }

            if ((int) $before['rank'] !== (int) $after['rank']) {
                $rankPositionChangeCount++;
            }
            $scoreDeltaTotal += abs((float) $after['score'] - (float) $before['score']);
        }

        return [
            'schema_version' => self::FEEDBACK_IMPACT_REPORT_SCHEMA,
            'status' => 'active',
            'source' => (string) ($feedbackHint['source'] ?? 'unknown'),
            'selected_set_changed' => $newlySelectedKeys !== [] || $droppedKeys !== [],
            'rank_position_change_count' => $rankPositionChangeCount,
            'score_delta_total_abs' => round($scoreDeltaTotal, 4),
            'newly_selected_refs' => self::rankRefsForKeys($newlySelectedKeys, $currentPositions),
            'dropped_refs' => self::rankRefsForKeys($droppedKeys, $baselinePositions),
            'promoted_refs' => self::promotedRefs($baselinePositions, $currentPositions, $currentSelectedKeys),
            'demoted_refs' => self::demotedRefs($baselinePositions, $currentPositions, $baselineSelectedKeys),
            'coverage_delta' => self::coverageDelta($baselineCoverage, $currentCoverage),
            'policy' => self::feedbackImpactReportPolicy(),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $ranked
     * @return array<string,array<string,mixed>>
     */
    public static function rankPositionMap(array $ranked): array
    {
        $map = [];
        foreach (array_values($ranked) as $index => $ref) {
            $key = self::rankKey($ref);
            if ($key === '') {
                continue;
            }

            $map[$key] = [
                'candidate_key' => $key,
                'candidate_hash' => (string) ($ref['candidate_hash'] ?? ''),
                'source_ref_hash' => (string) ($ref['source_ref_hash'] ?? ''),
                'source_type' => (string) ($ref['source_type'] ?? 'unknown'),
                'rank' => $index + 1,
                'score' => round((float) ($ref['score'] ?? 0.0), 4),
                'required' => (bool) ($ref['required'] ?? false),
                'available' => (bool) ($ref['available'] ?? false),
            ];
        }

        return $map;
    }

    /**
     * @param  array<int,array<string,mixed>>  $refs
     * @return array<int,string>
     */
    public static function rankKeys(array $refs): array
    {
        return array_values(array_filter(array_map(fn (array $ref): string => self::rankKey($ref), $refs)));
    }

    /**
     * @param  array<string,mixed>  $ref
     */
    public static function rankKey(array $ref): string
    {
        $candidateHash = (string) ($ref['candidate_hash'] ?? '');
        if ($candidateHash !== '') {
            return 'candidate:'.$candidateHash;
        }

        $sourceRefHash = (string) ($ref['source_ref_hash'] ?? '');
        $sourceType = (string) ($ref['source_type'] ?? 'unknown');

        return $sourceRefHash !== '' ? 'source:'.$sourceType.':'.$sourceRefHash : '';
    }

    /**
     * @param  array<int,string>  $keys
     * @param  array<string,array<string,mixed>>  $positions
     * @return array<int,array<string,mixed>>
     */
    public static function rankRefsForKeys(array $keys, array $positions): array
    {
        $refs = [];
        foreach ($keys as $key) {
            if (! isset($positions[$key])) {
                continue;
            }

            $refs[] = self::publicRankRef($positions[$key]);
        }

        return array_slice($refs, 0, 12);
    }

    /**
     * @param  array<string,array<string,mixed>>  $baselinePositions
     * @param  array<string,array<string,mixed>>  $currentPositions
     * @param  array<int,string>  $currentSelectedKeys
     * @return array<int,array<string,mixed>>
     */
    public static function promotedRefs(array $baselinePositions, array $currentPositions, array $currentSelectedKeys): array
    {
        $refs = [];
        foreach ($currentSelectedKeys as $key) {
            if (! isset($baselinePositions[$key], $currentPositions[$key])) {
                continue;
            }

            if ((int) $currentPositions[$key]['rank'] >= (int) $baselinePositions[$key]['rank']) {
                continue;
            }

            $refs[] = self::publicRankRef($currentPositions[$key], $baselinePositions[$key]);
        }

        return array_slice($refs, 0, 12);
    }

    /**
     * @param  array<string,array<string,mixed>>  $baselinePositions
     * @param  array<string,array<string,mixed>>  $currentPositions
     * @param  array<int,string>  $baselineSelectedKeys
     * @return array<int,array<string,mixed>>
     */
    public static function demotedRefs(array $baselinePositions, array $currentPositions, array $baselineSelectedKeys): array
    {
        $refs = [];
        foreach ($baselineSelectedKeys as $key) {
            if (! isset($baselinePositions[$key], $currentPositions[$key])) {
                continue;
            }

            if ((int) $currentPositions[$key]['rank'] <= (int) $baselinePositions[$key]['rank']) {
                continue;
            }

            $refs[] = self::publicRankRef($currentPositions[$key], $baselinePositions[$key]);
        }

        return array_slice($refs, 0, 12);
    }

    /**
     * @param  array<string,mixed>  $current
     * @param  array<string,mixed>|null  $baseline
     * @return array<string,mixed>
     */
    public static function publicRankRef(array $current, ?array $baseline = null): array
    {
        $payload = [
            'source_type' => (string) ($current['source_type'] ?? 'unknown'),
            'source_ref_hash' => (string) ($current['source_ref_hash'] ?? ''),
            'candidate_hash' => (string) ($current['candidate_hash'] ?? ''),
            'rank' => (int) ($current['rank'] ?? 0),
            'score' => (float) ($current['score'] ?? 0.0),
        ];

        if ($baseline !== null) {
            $payload['baseline_rank'] = (int) ($baseline['rank'] ?? 0);
            $payload['baseline_score'] = (float) ($baseline['score'] ?? 0.0);
            $payload['rank_delta'] = (int) ($baseline['rank'] ?? 0) - (int) ($current['rank'] ?? 0);
            $payload['score_delta'] = round((float) ($current['score'] ?? 0.0) - (float) ($baseline['score'] ?? 0.0), 4);
        }

        return $payload;
    }

    /**
     * @param  array<string,bool>  $baselineCoverage
     * @param  array<string,bool>  $currentCoverage
     * @return array<string,array<int,string>>
     */
    public static function coverageDelta(array $baselineCoverage, array $currentCoverage): array
    {
        $sourceTypes = array_values(array_unique(array_merge(array_keys($baselineCoverage), array_keys($currentCoverage))));
        $gained = [];
        $lost = [];

        foreach ($sourceTypes as $sourceType) {
            $before = (bool) ($baselineCoverage[$sourceType] ?? false);
            $after = (bool) ($currentCoverage[$sourceType] ?? false);
            if (! $before && $after) {
                $gained[] = (string) $sourceType;
            }
            if ($before && ! $after) {
                $lost[] = (string) $sourceType;
            }
        }

        return [
            'gained_required_sources' => $gained,
            'lost_required_sources' => $lost,
        ];
    }

    /**
     * @return array<string,bool>
     */
    public static function feedbackImpactReportPolicy(): array
    {
        return [
            'provider_safe_only' => true,
            'raw_text_exposed' => false,
            'auto_apply_learning' => false,
            'writes' => false,
            'providers_invoked' => false,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $ranked
     * @param  array<int,array<string,mixed>>  $selected
     * @return array<string,mixed>
     */
    public static function rankingSnapshot(array $ranked, array $selected): array
    {
        return [
            'positions' => array_values(self::rankPositionMap($ranked)),
            'selected' => array_values(self::rankPositionMap($selected)),
        ];
    }

    public static function authorityScore(string $authorityLevel): float
    {
        return match ($authorityLevel) {
            'operator_request' => 0.90,
            'source_adapter' => 0.82,
            'explicit_ref' => 0.78,
            'source_observed' => 0.68,
            default => 0.55,
        };
    }

    public static function freshnessScore(string $status): float
    {
        return match (self::freshnessLabel($status)) {
            'current' => 1.0,
            'usable' => 0.76,
            'future_or_missing' => 0.20,
            default => 0.50,
        };
    }

    public static function freshnessLabel(string $status): string
    {
        return match ($status) {
            'implemented_ready', 'provided', 'current' => 'current',
            'implemented_partial', 'candidate_manifest_only' => 'usable',
            'future_governed', 'missing', 'blocked' => 'future_or_missing',
            default => 'unknown',
        };
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public static function inputFlow(array $input, string $domain, string $taskType): string
    {
        if (is_scalar($input['flow_id'] ?? null) && trim((string) $input['flow_id']) !== '') {
            return trim((string) $input['flow_id']);
        }

        return self::flow($domain, $taskType);
    }

    /**
     * @return array<string,mixed>
     */
    public static function inactiveFeedbackHint(): array
    {
        return [
            'active' => false,
            'source' => 'none',
            'feedback_scope' => 'none',
            'flow_id' => null,
            'repromote_source_types' => [],
            'demote_source_types' => [],
            'demote_source_hashes' => [],
            'demote_context_refs' => [],
            'event_available' => false,
            'auto_apply_learning' => false,
            'providers_invoked' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $feedbackHint
     * @return array<string,mixed>
     */
    public static function feedbackHintSummary(array $feedbackHint, bool $includeFeedbackScope = true): array
    {
        // Host passes includeFeedbackScope from atlas.context.feedback_global_hints
        // so flag-OFF output stays byte-identical to the pre-loop report.
        $scope = $includeFeedbackScope
            ? ['feedback_scope' => (string) ($feedbackHint['feedback_scope'] ?? 'none')]
            : [];

        return [
            'status' => (bool) ($feedbackHint['active'] ?? false) ? 'active' : 'inactive',
            'source' => (string) ($feedbackHint['source'] ?? 'none'),
        ] + $scope + [
            'flow_id' => $feedbackHint['flow_id'] ?? null,
            'repromote_source_types' => (array) ($feedbackHint['repromote_source_types'] ?? []),
            'demote_source_type_count' => count((array) ($feedbackHint['demote_source_types'] ?? [])),
            'demote_hash_count' => count((array) ($feedbackHint['demote_source_hashes'] ?? [])),
            'demote_context_ref_count' => count((array) ($feedbackHint['demote_context_refs'] ?? [])),
            'event_available' => (bool) ($feedbackHint['event_available'] ?? false),
            'auto_apply_learning' => false,
            'providers_invoked' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  array<string,mixed>  $feedbackHint
     * @return array{delta:float,reasons:array<int,string>}
     */
    public static function feedbackImpact(array $candidate, string $sourceRef, string $sourceRefHash, array $feedbackHint): array
    {
        if (! (bool) ($feedbackHint['active'] ?? false)) {
            return ['delta' => 0.0, 'reasons' => []];
        }

        $sourceType = (string) ($candidate['source_type'] ?? 'unknown');
        $delta = 0.0;
        $reasons = [];

        if (in_array($sourceType, (array) ($feedbackHint['repromote_source_types'] ?? []), true)) {
            $delta += 0.20;
            $reasons[] = 'feedback_repromote_source_type';
        }

        if (in_array($sourceType, (array) ($feedbackHint['demote_source_types'] ?? []), true)) {
            $delta -= 0.20;
            $reasons[] = 'feedback_demote_source_type';
        }

        if (in_array($sourceRefHash, (array) ($feedbackHint['demote_source_hashes'] ?? []), true)) {
            $delta -= 0.25;
            $reasons[] = 'feedback_demote_ref_hash';
        }

        if (in_array($sourceRef, (array) ($feedbackHint['demote_context_refs'] ?? []), true)) {
            $delta -= 0.15;
            $reasons[] = 'feedback_demote_context_ref';
        }

        return [
            'delta' => round(max(-0.35, min(0.25, $delta)), 4),
            'reasons' => $reasons,
        ];
    }

    /**
     * @return array<int,string>
     */
    public static function hintStrings(mixed ...$values): array
    {
        $strings = [];
        foreach ($values as $value) {
            foreach (self::flattenScalars($value) as $item) {
                if ($item !== '') {
                    $strings[] = $item;
                }
            }
        }

        return AtlasContextStringListNormalizer::uniqueTrimmedStrings($strings);
    }

    /**
     * @return array<int,string>
     */
    public static function flattenScalars(mixed $value): array
    {
        if (is_scalar($value)) {
            return [trim((string) $value)];
        }

        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            foreach (self::flattenScalars($item) as $nested) {
                $strings[] = $nested;
            }
        }

        return $strings;
    }

    public static function flow(string $domain, string $taskType): string
    {
        if (in_array($domain, ['developer', 'programming', 'atlas_programming'], true)) {
            return match ($taskType) {
                'debug', 'quality_repair' => 'programming.repair',
                'review' => 'programming.review',
                default => 'programming.dev',
            };
        }

        return $domain.'.'.$taskType;
    }
}

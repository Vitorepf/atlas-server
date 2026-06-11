<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Models\AiRagFeedbackEvent;
use App\Services\Ai\AutonomousEngineering\WorldModel\WorldModelGraphRanker;
use App\Services\Ai\AutonomousEngineering\WorldModel\WorldModelRankingQuery;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\ProgrammingProfessionalReranker;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;

final class AtlasContextRankingSystemService
{
    public const SCHEMA_VERSION = 'atlas.aucri.context_ranking_system.v1';

    public const RERANK_RESULT_SCHEMA = 'atlas.aucri.rerank_result.v1';

    public const CONTEXT_SCORE_SCHEMA = 'atlas.aucri.context_score.v1';

    public const EXCLUDED_REF_SCHEMA = 'atlas.aucri.excluded_ref.v1';

    public const FEEDBACK_IMPACT_REPORT_SCHEMA = 'atlas.aucri.feedback_impact_report.v1';

    public function __construct(
        private readonly AtlasAgenticRagFrameworkService $agenticRag,
        private readonly ProgrammingProfessionalReranker $programmingReranker,
        private readonly WorldModelGraphRanker $worldModelGraphRanker,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function rank(array $input): array
    {
        $objective = trim((string) ($input['objective'] ?? $input['prompt'] ?? $input['query'] ?? ''));
        $domain = (string) ($input['domain'] ?? 'atlas');
        $taskType = (string) ($input['task_type'] ?? 'direct');
        $risk = (string) ($input['risk_level'] ?? 'low');
        $maxRefs = max(1, min(50, (int) ($input['max_refs'] ?? 8)));
        $flow = $this->inputFlow($input, $domain, $taskType);
        $feedbackHint = $this->feedbackHint($input, $flow);

        $plan = $this->agenticRag->plan($input + [
            'objective' => $objective,
            'domain' => $domain,
            'task_type' => $taskType,
            'risk_level' => $risk,
        ]);
        $requiredSources = (array) data_get($plan, 'agentic_rag_plan.required_sources.sources', []);
        $candidates = (array) data_get($plan, 'retrieval_report.candidates', []);
        $graphRanking = $this->graphRanking($requiredSources, $domain, $taskType, $risk, $maxRefs);
        $professional = $this->programmingReranker->rerank(
            refs: $this->refsForProfessionalRanker($candidates),
            requiredSources: $requiredSources,
            flow: $flow,
            maxRefs: max($maxRefs, count($candidates)),
        );

        $baselineRanked = $this->rankedCandidates($candidates, $professional, $graphRanking, $this->inactiveFeedbackHint());
        $ranked = (bool) ($feedbackHint['active'] ?? false)
            ? $this->rankedCandidates($candidates, $professional, $graphRanking, $feedbackHint)
            : $baselineRanked;
        $selected = array_slice($ranked, 0, $maxRefs);
        $excluded = $this->excludedRefs($ranked, $selected, (array) ($professional['excluded_refs'] ?? []));
        $coverage = $this->requiredSourceCoverage($selected, $requiredSources);
        $baselineSelected = array_slice($baselineRanked, 0, $maxRefs);
        $baselineCoverage = $this->requiredSourceCoverage($baselineSelected, $requiredSources);
        $status = $this->status($plan, $selected, $coverage);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => Carbon::now()->toIso8601String(),
            'rerank_result' => [
                'schema_version' => self::RERANK_RESULT_SCHEMA,
                'agentic_rag_plan_hash' => (string) ($plan['agentic_rag_plan_hash'] ?? ''),
                'max_refs' => $maxRefs,
                'selected_refs' => $selected,
                'excluded_refs' => $excluded,
                'metrics' => [
                    'input_candidate_count' => count($candidates),
                    'selected_count' => count($selected),
                    'excluded_count' => count($excluded),
                    'required_source_coverage' => $coverage,
                    'graph_status' => (string) ($graphRanking['status'] ?? 'unknown'),
                    'professional_reranker' => data_get($professional, 'metrics.reranker', 'deterministic_professional_v1'),
                ],
                'feedback_impact_report' => $this->feedbackImpactReport(
                    $baselineRanked,
                    $ranked,
                    $baselineSelected,
                    $selected,
                    $baselineCoverage,
                    $coverage,
                    $feedbackHint,
                ),
            ],
            'source_ranking_inputs' => [
                'aarf_status' => (string) ($plan['status'] ?? 'unknown'),
                'gap_critic_status' => (string) data_get($plan, 'gap_critic.status', 'unknown'),
                'sufficiency_gate_status' => (string) data_get($plan, 'context_sufficiency_gate.status', 'unknown'),
                'graph_result_hash' => (string) ($graphRanking['result_hash'] ?? ''),
                'feedback_hint' => $this->feedbackHintSummary($feedbackHint),
            ],
            'policy' => [
                'provider_safe_only' => true,
                'raw_text_exposed' => false,
                'opaque_ranking_allowed' => false,
                'ranking_without_reason_allowed' => false,
                'writes' => false,
                'providers_invoked' => false,
            ],
        ];

        $hashPayload = $payload;
        unset($hashPayload['generated_at']);
        $payload['rerank_result_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /**
     * @param  array<int,string>  $requiredSources
     * @return array<string,mixed>
     */
    private function graphRanking(array $requiredSources, string $domain, string $taskType, string $risk, int $maxRefs): array
    {
        $query = WorldModelRankingQuery::fromArray([
            'textual_seeds' => AtlasContextStringListNormalizer::uniqueTrimmedStrings([$domain, $taskType]),
            'target_capabilities' => AtlasContextStringListNormalizer::uniqueTrimmedStrings($requiredSources),
            'target_flows' => [$this->flow($domain, $taskType)],
            'task_risk_level' => in_array($risk, ['high', 'irreversible'], true) ? 'high' : 'low',
            'boost_docs' => true,
            'boost_tests' => in_array($taskType, ['debug', 'review', 'quality_repair'], true),
            'max_results' => $maxRefs,
        ]);

        $result = $this->worldModelGraphRanker->rank($query);
        $result['status'] = ((array) ($result['ranked_sources'] ?? [])) === [] ? 'empty' : 'ready';

        return $result;
    }

    /**
     * @param  array<int,array<string,mixed>>  $candidates
     * @return array<int,array<string,mixed>>
     */
    private function refsForProfessionalRanker(array $candidates): array
    {
        return array_values(array_map(static fn (array $candidate): array => [
            'source' => (string) ($candidate['source_type'] ?? 'unknown'),
            'ref' => (string) ($candidate['source_ref'] ?? $candidate['candidate_id'] ?? ''),
            'score' => (float) ($candidate['score_hint'] ?? 0.5),
            'reason' => (string) ($candidate['reason'] ?? 'candidate'),
            // HONESTY CONTRACT (runtime_language_boundary canon): a `semantic_candidate` is NOT
            // backed by real embeddings. It is produced by AtlasHybridRetrievalInfrastructureService::
            // candidatesFromAsef() from AtlasSemanticEmbeddingFoundationService::candidateSet()
            // (mode `manifest_without_external_embedding`, `embedding_generated => false`,
            // `embedding_status => candidate_manifest_only`): a chunked manifest of the objective
            // carrying a STATIC score_hint of 0.60 — no vector, no cosine, not even a token-overlap
            // score (its only lexical artifact, `lexical_signature`, is never carried here or ranked).
            // Real embeddings live ONLY behind App\Services\Ai\RuntimeBoundary\SemanticRagRuntimeClient
            // and App\Services\Semantic\EmbeddingService (Python semantic_rag + pgvector), which this
            // path does not invoke. The canon forbids labelling a non-embedding manifest "semantic"
            // or "vector", so the channel is named for what it actually is — and it deliberately does
            // NOT reuse `lexical_token_overlap` (that label would wrongly trigger the reranker's +0.22
            // lexical bonus at ProgrammingProfessionalReranker::score()).
            'retrieval_channel' => ($candidate['source_type'] ?? null) === 'semantic_candidate'
                ? 'manifest_pending_embedding'
                : 'aucri_hybrid_retrieval',
            'privacy' => (bool) ($candidate['provider_safe'] ?? false) ? 'provider_safe' : 'provider_unsafe',
            'freshness' => self::freshnessLabel((string) ($candidate['status'] ?? 'unknown')),
        ], $candidates));
    }

    /**
     * @param  array<int,array<string,mixed>>  $candidates
     * @param  array<string,mixed>  $professional
     * @param  array<string,mixed>  $graphRanking
     * @param  array<string,mixed>  $feedbackHint
     * @return array<int,array<string,mixed>>
     */
    private function rankedCandidates(array $candidates, array $professional, array $graphRanking, array $feedbackHint): array
    {
        $professionalByKey = collect((array) ($professional['ranked_refs'] ?? []))
            ->keyBy(static fn (array $ref): string => (string) ($ref['source'] ?? 'unknown').'|'.(string) ($ref['ref'] ?? ''))
            ->all();
        $graphSourceScore = $this->graphSourceScores((array) ($graphRanking['ranked_sources'] ?? []));

        $ranked = [];
        foreach ($candidates as $candidate) {
            $sourceType = (string) ($candidate['source_type'] ?? 'unknown');
            $sourceRef = (string) ($candidate['source_ref'] ?? $candidate['candidate_id'] ?? '');
            $sourceRefHash = MissionCanonicalHash::sha256($sourceRef);
            $key = $sourceType.'|'.$sourceRef;
            $professionalScore = (float) data_get($professionalByKey, $key.'.score', $candidate['score_hint'] ?? 0.5);
            $feedbackImpact = $this->feedbackImpact($candidate, $sourceRef, $sourceRefHash, $feedbackHint);
            $components = [
                'schema_version' => self::CONTEXT_SCORE_SCHEMA,
                'semantic' => round((float) ($candidate['score_hint'] ?? 0.5), 4),
                'professional_rerank' => round(min(1.0, $professionalScore / 2.0), 4),
                'authority' => $this->authorityScore((string) ($candidate['authority_level'] ?? 'unknown')),
                'freshness' => $this->freshnessScore((string) ($candidate['status'] ?? 'unknown')),
                'graph' => $this->graphBoost($candidate, $graphSourceScore),
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
                'reasons' => $this->reasons($candidate, $components, (array) $feedbackImpact['reasons']),
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
    private function graphSourceScores(array $rankedSources): array
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
    private function graphBoost(array $candidate, array $graphSourceScore): float
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
    private function reasons(array $candidate, array $components, array $feedbackReasons = []): array
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
    private function excludedRefs(array $ranked, array $selected, array $professionalExcluded): array
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
    private function requiredSourceCoverage(array $selected, array $requiredSources): array
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
    private function status(array $plan, array $selected, array $coverage): string
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
    private function feedbackImpactReport(
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
                'policy' => $this->feedbackImpactReportPolicy(),
            ];
        }

        $baselinePositions = $this->rankPositionMap($baselineRanked);
        $currentPositions = $this->rankPositionMap($currentRanked);
        $baselineSelectedKeys = $this->rankKeys($baselineSelected);
        $currentSelectedKeys = $this->rankKeys($currentSelected);
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
            'newly_selected_refs' => $this->rankRefsForKeys($newlySelectedKeys, $currentPositions),
            'dropped_refs' => $this->rankRefsForKeys($droppedKeys, $baselinePositions),
            'promoted_refs' => $this->promotedRefs($baselinePositions, $currentPositions, $currentSelectedKeys),
            'demoted_refs' => $this->demotedRefs($baselinePositions, $currentPositions, $baselineSelectedKeys),
            'coverage_delta' => $this->coverageDelta($baselineCoverage, $currentCoverage),
            'policy' => $this->feedbackImpactReportPolicy(),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $ranked
     * @return array<string,array<string,mixed>>
     */
    private function rankPositionMap(array $ranked): array
    {
        $map = [];
        foreach (array_values($ranked) as $index => $ref) {
            $key = $this->rankKey($ref);
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
    private function rankKeys(array $refs): array
    {
        return array_values(array_filter(array_map(fn (array $ref): string => $this->rankKey($ref), $refs)));
    }

    /**
     * @param  array<string,mixed>  $ref
     */
    private function rankKey(array $ref): string
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
    private function rankRefsForKeys(array $keys, array $positions): array
    {
        $refs = [];
        foreach ($keys as $key) {
            if (! isset($positions[$key])) {
                continue;
            }

            $refs[] = $this->publicRankRef($positions[$key]);
        }

        return array_slice($refs, 0, 12);
    }

    /**
     * @param  array<string,array<string,mixed>>  $baselinePositions
     * @param  array<string,array<string,mixed>>  $currentPositions
     * @param  array<int,string>  $currentSelectedKeys
     * @return array<int,array<string,mixed>>
     */
    private function promotedRefs(array $baselinePositions, array $currentPositions, array $currentSelectedKeys): array
    {
        $refs = [];
        foreach ($currentSelectedKeys as $key) {
            if (! isset($baselinePositions[$key], $currentPositions[$key])) {
                continue;
            }

            if ((int) $currentPositions[$key]['rank'] >= (int) $baselinePositions[$key]['rank']) {
                continue;
            }

            $refs[] = $this->publicRankRef($currentPositions[$key], $baselinePositions[$key]);
        }

        return array_slice($refs, 0, 12);
    }

    /**
     * @param  array<string,array<string,mixed>>  $baselinePositions
     * @param  array<string,array<string,mixed>>  $currentPositions
     * @param  array<int,string>  $baselineSelectedKeys
     * @return array<int,array<string,mixed>>
     */
    private function demotedRefs(array $baselinePositions, array $currentPositions, array $baselineSelectedKeys): array
    {
        $refs = [];
        foreach ($baselineSelectedKeys as $key) {
            if (! isset($baselinePositions[$key], $currentPositions[$key])) {
                continue;
            }

            if ((int) $currentPositions[$key]['rank'] <= (int) $baselinePositions[$key]['rank']) {
                continue;
            }

            $refs[] = $this->publicRankRef($currentPositions[$key], $baselinePositions[$key]);
        }

        return array_slice($refs, 0, 12);
    }

    /**
     * @param  array<string,mixed>  $current
     * @param  array<string,mixed>|null  $baseline
     * @return array<string,mixed>
     */
    private function publicRankRef(array $current, ?array $baseline = null): array
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
    private function coverageDelta(array $baselineCoverage, array $currentCoverage): array
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
    private function feedbackImpactReportPolicy(): array
    {
        return [
            'provider_safe_only' => true,
            'raw_text_exposed' => false,
            'auto_apply_learning' => false,
            'writes' => false,
            'providers_invoked' => false,
        ];
    }

    private function authorityScore(string $authorityLevel): float
    {
        return match ($authorityLevel) {
            'operator_request' => 0.90,
            'source_adapter' => 0.82,
            'explicit_ref' => 0.78,
            'source_observed' => 0.68,
            default => 0.55,
        };
    }

    private function freshnessScore(string $status): float
    {
        return match (self::freshnessLabel($status)) {
            'current' => 1.0,
            'usable' => 0.76,
            'future_or_missing' => 0.20,
            default => 0.50,
        };
    }

    private static function freshnessLabel(string $status): string
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
    private function inputFlow(array $input, string $domain, string $taskType): string
    {
        if (is_scalar($input['flow_id'] ?? null) && trim((string) $input['flow_id']) !== '') {
            return trim((string) $input['flow_id']);
        }

        return $this->flow($domain, $taskType);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function feedbackHint(array $input, string $flow): array
    {
        $explicit = is_array($input['feedback_hint_input'] ?? null) ? $input['feedback_hint_input'] : [];
        $explicitProvided = $this->hintStrings(
            $explicit['repromote_source_types'] ?? [],
            $explicit['demote_source_types'] ?? [],
            $explicit['demote_source_hashes'] ?? [],
            $explicit['demote_ref_hashes'] ?? [],
            $explicit['demote_context_refs'] ?? [],
        ) !== [];
        $flowRequested = is_scalar($input['flow_id'] ?? null) && trim((string) $input['flow_id']) !== '';
        $event = $flowRequested && DatabaseTableAvailability::has('ai_rag_feedback_events')
            ? AiRagFeedbackEvent::query()
                ->where('flow_id', $flow)
                ->latest('created_at')
                ->first()
            : null;
        $eventPayload = $event instanceof AiRagFeedbackEvent ? (array) $event->payload : [];
        $nextHint = $event instanceof AiRagFeedbackEvent ? (array) $event->next_retrieval_hint : [];
        $noiseHashes = [];
        foreach (($event instanceof AiRagFeedbackEvent ? (array) $event->source_utility : []) as $ref => $utility) {
            if ((string) $utility === 'noise') {
                $noiseHashes[] = (string) $ref;
            }
        }

        $repromoteSourceTypes = $this->hintStrings(
            $explicit['repromote_source_types'] ?? [],
            $nextHint['should_repromote_sources'] ?? [],
            data_get($eventPayload, 'payload.next_context_policy.expand_source_types', []),
        );
        $demoteSourceTypes = $this->hintStrings(
            $explicit['demote_source_types'] ?? [],
            data_get($eventPayload, 'payload.context_ref_attribution.noise_refs.*.source_type', []),
        );
        $demoteSourceHashes = $this->hintStrings(
            $explicit['demote_source_hashes'] ?? [],
            $explicit['demote_ref_hashes'] ?? [],
            $noiseHashes,
            data_get($eventPayload, 'payload.context_ref_attribution.noise_refs.*.ref_hash', []),
        );
        $demoteContextRefs = $this->hintStrings(
            $explicit['demote_context_refs'] ?? [],
            data_get($eventPayload, 'payload.next_context_policy.demote_context_refs', []),
        );

        $active = $repromoteSourceTypes !== []
            || $demoteSourceTypes !== []
            || $demoteSourceHashes !== []
            || $demoteContextRefs !== [];

        return [
            'active' => $active,
            'source' => match (true) {
                $explicitProvided && $event instanceof AiRagFeedbackEvent => 'input_and_latest_flow_feedback',
                $explicitProvided => 'input_feedback_hint',
                $event instanceof AiRagFeedbackEvent => 'latest_flow_feedback',
                default => 'none',
            },
            'flow_id' => $flowRequested ? $flow : null,
            'repromote_source_types' => $repromoteSourceTypes,
            'demote_source_types' => $demoteSourceTypes,
            'demote_source_hashes' => $demoteSourceHashes,
            'demote_context_refs' => $demoteContextRefs,
            'event_available' => $event instanceof AiRagFeedbackEvent,
            'auto_apply_learning' => false,
            'providers_invoked' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function inactiveFeedbackHint(): array
    {
        return [
            'active' => false,
            'source' => 'none',
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
    private function feedbackHintSummary(array $feedbackHint): array
    {
        return [
            'status' => (bool) ($feedbackHint['active'] ?? false) ? 'active' : 'inactive',
            'source' => (string) ($feedbackHint['source'] ?? 'none'),
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
    private function feedbackImpact(array $candidate, string $sourceRef, string $sourceRefHash, array $feedbackHint): array
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
    private function hintStrings(mixed ...$values): array
    {
        $strings = [];
        foreach ($values as $value) {
            foreach ($this->flattenScalars($value) as $item) {
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
    private function flattenScalars(mixed $value): array
    {
        if (is_scalar($value)) {
            return [trim((string) $value)];
        }

        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            foreach ($this->flattenScalars($item) as $nested) {
                $strings[] = $nested;
            }
        }

        return $strings;
    }

    private function flow(string $domain, string $taskType): string
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

<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\AutonomousEngineering\WorldModel\WorldModelGraphRanker;
use App\Services\Ai\AutonomousEngineering\WorldModel\WorldModelRankingQuery;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\ProgrammingProfessionalReranker;
use Illuminate\Support\Carbon;

final class AtlasContextRankingSystemService
{
    public const SCHEMA_VERSION = 'atlas.aucri.context_ranking_system.v1';

    public const RERANK_RESULT_SCHEMA = 'atlas.aucri.rerank_result.v1';

    public const CONTEXT_SCORE_SCHEMA = 'atlas.aucri.context_score.v1';

    public const EXCLUDED_REF_SCHEMA = 'atlas.aucri.excluded_ref.v1';

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
            flow: $this->flow($domain, $taskType),
            maxRefs: max($maxRefs, count($candidates)),
        );

        $ranked = $this->rankedCandidates($candidates, $professional, $graphRanking);
        $selected = array_slice($ranked, 0, $maxRefs);
        $excluded = $this->excludedRefs($ranked, $selected, (array) ($professional['excluded_refs'] ?? []));
        $coverage = $this->requiredSourceCoverage($selected, $requiredSources);
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
            ],
            'source_ranking_inputs' => [
                'aarf_status' => (string) ($plan['status'] ?? 'unknown'),
                'gap_critic_status' => (string) data_get($plan, 'gap_critic.status', 'unknown'),
                'sufficiency_gate_status' => (string) data_get($plan, 'context_sufficiency_gate.status', 'unknown'),
                'graph_result_hash' => (string) ($graphRanking['result_hash'] ?? ''),
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
            'textual_seeds' => array_values(array_unique(array_filter([$domain, $taskType]))),
            'target_capabilities' => array_values(array_unique($requiredSources)),
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
     * @return array<int,array<string,mixed>>
     */
    private function rankedCandidates(array $candidates, array $professional, array $graphRanking): array
    {
        $professionalByKey = collect((array) ($professional['ranked_refs'] ?? []))
            ->keyBy(static fn (array $ref): string => (string) ($ref['source'] ?? 'unknown').'|'.(string) ($ref['ref'] ?? ''))
            ->all();
        $graphSourceScore = $this->graphSourceScores((array) ($graphRanking['ranked_sources'] ?? []));

        $ranked = [];
        foreach ($candidates as $candidate) {
            $sourceType = (string) ($candidate['source_type'] ?? 'unknown');
            $sourceRef = (string) ($candidate['source_ref'] ?? $candidate['candidate_id'] ?? '');
            $key = $sourceType.'|'.$sourceRef;
            $professionalScore = (float) data_get($professionalByKey, $key.'.score', $candidate['score_hint'] ?? 0.5);
            $components = [
                'schema_version' => self::CONTEXT_SCORE_SCHEMA,
                'semantic' => round((float) ($candidate['score_hint'] ?? 0.5), 4),
                'professional_rerank' => round(min(1.0, $professionalScore / 2.0), 4),
                'authority' => $this->authorityScore((string) ($candidate['authority_level'] ?? 'unknown')),
                'freshness' => $this->freshnessScore((string) ($candidate['status'] ?? 'unknown')),
                'graph' => $this->graphBoost($candidate, $graphSourceScore),
                'privacy' => (bool) ($candidate['provider_safe'] ?? false) ? 1.0 : 0.0,
            ];
            $total = round(
                $components['semantic'] * 0.18
                + $components['professional_rerank'] * 0.24
                + $components['authority'] * 0.20
                + $components['freshness'] * 0.16
                + $components['graph'] * 0.12
                + $components['privacy'] * 0.10,
                4,
            );

            $ranked[] = [
                'candidate_id' => (string) ($candidate['candidate_id'] ?? ''),
                'source_type' => $sourceType,
                'source_ref_hash' => MissionCanonicalHash::sha256($sourceRef),
                'owner_doc' => (string) ($candidate['owner_doc'] ?? ''),
                'score' => $total,
                'score_components' => $components,
                'required' => (bool) ($candidate['required'] ?? false),
                'available' => (bool) ($candidate['available'] ?? false),
                'provider_safe' => (bool) ($candidate['provider_safe'] ?? false),
                'reasons' => $this->reasons($candidate, $components),
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
     * @return array<int,string>
     */
    private function reasons(array $candidate, array $components): array
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

        return array_values(array_unique($reasons));
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

<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Models\AiRagFeedbackEvent;
use App\Services\Ai\AutonomousEngineering\WorldModel\WorldModelGraphRanker;
use App\Services\Ai\AutonomousEngineering\WorldModel\WorldModelRankingQuery;
use App\Services\Ai\Context\Support\ContextRankingSystemSupport;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Memory\AtlasMemoryRecallConcentrationDemotion;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\ProgrammingProfessionalReranker;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;

final class AtlasContextRankingSystemService
{
    public const SCHEMA_VERSION = ContextRankingSystemSupport::SCHEMA_VERSION;

    public const RERANK_RESULT_SCHEMA = ContextRankingSystemSupport::RERANK_RESULT_SCHEMA;

    public const CONTEXT_SCORE_SCHEMA = ContextRankingSystemSupport::CONTEXT_SCORE_SCHEMA;

    public const EXCLUDED_REF_SCHEMA = ContextRankingSystemSupport::EXCLUDED_REF_SCHEMA;

    public const FEEDBACK_IMPACT_REPORT_SCHEMA = ContextRankingSystemSupport::FEEDBACK_IMPACT_REPORT_SCHEMA;

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
        $feedbackImpactReport = $this->feedbackImpactReport(
            $baselineRanked,
            $ranked,
            $baselineSelected,
            $selected,
            $baselineCoverage,
            $coverage,
            $feedbackHint,
        );

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
                'feedback_impact_report' => $feedbackImpactReport,
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

        $this->recordFeedbackHintSnapshot(
            $input,
            $feedbackHint,
            $baselineRanked,
            $ranked,
            $baselineSelected,
            $selected,
            $feedbackImpactReport,
            $payload['rerank_result_hash'],
        );

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
        return ContextRankingSystemSupport::refsForProfessionalRanker($candidates);
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private static function retrievalChannel(array $candidate): string
    {
        return ContextRankingSystemSupport::retrievalChannel($candidate);
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
        return ContextRankingSystemSupport::rankedCandidates($candidates, $professional, $graphRanking, $feedbackHint);
    }

    /**
     * @param  array<int,array<string,mixed>>  $rankedSources
     * @return array<string,float>
     */
    private function graphSourceScores(array $rankedSources): array
    {
        return ContextRankingSystemSupport::graphSourceScores($rankedSources);
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  array<string,float>  $graphSourceScore
     */
    private function graphBoost(array $candidate, array $graphSourceScore): float
    {
        return ContextRankingSystemSupport::graphBoost($candidate, $graphSourceScore);
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  array<string,float|int|string>  $components
     * @param  array<int,string>  $feedbackReasons
     * @return array<int,string>
     */
    private function reasons(array $candidate, array $components, array $feedbackReasons = []): array
    {
        return ContextRankingSystemSupport::reasons($candidate, $components, $feedbackReasons);
    }

    /**
     * @param  array<int,array<string,mixed>>  $ranked
     * @param  array<int,array<string,mixed>>  $selected
     * @param  array<int,array<string,mixed>>  $professionalExcluded
     * @return array<int,array<string,mixed>>
     */
    private function excludedRefs(array $ranked, array $selected, array $professionalExcluded): array
    {
        return ContextRankingSystemSupport::excludedRefs($ranked, $selected, $professionalExcluded);
    }

    /**
     * @param  array<int,array<string,mixed>>  $selected
     * @param  array<int,string>  $requiredSources
     * @return array<string,bool>
     */
    private function requiredSourceCoverage(array $selected, array $requiredSources): array
    {
        return ContextRankingSystemSupport::requiredSourceCoverage($selected, $requiredSources);
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<int,array<string,mixed>>  $selected
     * @param  array<string,bool>  $coverage
     */
    private function status(array $plan, array $selected, array $coverage): string
    {
        return ContextRankingSystemSupport::status($plan, $selected, $coverage);
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
        return ContextRankingSystemSupport::feedbackImpactReport(
            $baselineRanked,
            $currentRanked,
            $baselineSelected,
            $currentSelected,
            $baselineCoverage,
            $currentCoverage,
            $feedbackHint,
        );
    }

    /**
     * @param  array<int,array<string,mixed>>  $ranked
     * @return array<string,array<string,mixed>>
     */
    private function rankPositionMap(array $ranked): array
    {
        return ContextRankingSystemSupport::rankPositionMap($ranked);
    }

    /**
     * @param  array<int,array<string,mixed>>  $refs
     * @return array<int,string>
     */
    private function rankKeys(array $refs): array
    {
        return ContextRankingSystemSupport::rankKeys($refs);
    }

    /**
     * @param  array<string,mixed>  $ref
     */
    private function rankKey(array $ref): string
    {
        return ContextRankingSystemSupport::rankKey($ref);
    }

    /**
     * @param  array<int,string>  $keys
     * @param  array<string,array<string,mixed>>  $positions
     * @return array<int,array<string,mixed>>
     */
    private function rankRefsForKeys(array $keys, array $positions): array
    {
        return ContextRankingSystemSupport::rankRefsForKeys($keys, $positions);
    }

    /**
     * @param  array<string,array<string,mixed>>  $baselinePositions
     * @param  array<string,array<string,mixed>>  $currentPositions
     * @param  array<int,string>  $currentSelectedKeys
     * @return array<int,array<string,mixed>>
     */
    private function promotedRefs(array $baselinePositions, array $currentPositions, array $currentSelectedKeys): array
    {
        return ContextRankingSystemSupport::promotedRefs($baselinePositions, $currentPositions, $currentSelectedKeys);
    }

    /**
     * @param  array<string,array<string,mixed>>  $baselinePositions
     * @param  array<string,array<string,mixed>>  $currentPositions
     * @param  array<int,string>  $baselineSelectedKeys
     * @return array<int,array<string,mixed>>
     */
    private function demotedRefs(array $baselinePositions, array $currentPositions, array $baselineSelectedKeys): array
    {
        return ContextRankingSystemSupport::demotedRefs($baselinePositions, $currentPositions, $baselineSelectedKeys);
    }

    /**
     * @param  array<string,mixed>  $current
     * @param  array<string,mixed>|null  $baseline
     * @return array<string,mixed>
     */
    private function publicRankRef(array $current, ?array $baseline = null): array
    {
        return ContextRankingSystemSupport::publicRankRef($current, $baseline);
    }

    /**
     * @param  array<string,bool>  $baselineCoverage
     * @param  array<string,bool>  $currentCoverage
     * @return array<string,array<int,string>>
     */
    private function coverageDelta(array $baselineCoverage, array $currentCoverage): array
    {
        return ContextRankingSystemSupport::coverageDelta($baselineCoverage, $currentCoverage);
    }

    /**
     * @return array<string,bool>
     */
    private function feedbackImpactReportPolicy(): array
    {
        return ContextRankingSystemSupport::feedbackImpactReportPolicy();
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $feedbackHint
     * @param  array<int,array<string,mixed>>  $baselineRanked
     * @param  array<int,array<string,mixed>>  $currentRanked
     * @param  array<int,array<string,mixed>>  $baselineSelected
     * @param  array<int,array<string,mixed>>  $currentSelected
     * @param  array<string,mixed>  $feedbackImpactReport
     */
    private function recordFeedbackHintSnapshot(
        array $input,
        array $feedbackHint,
        array $baselineRanked,
        array $currentRanked,
        array $baselineSelected,
        array $currentSelected,
        array $feedbackImpactReport,
        string $rerankResultHash,
    ): void {
        if (! (bool) ($feedbackHint['active'] ?? false)) {
            return;
        }

        try {
            app(AtlasEvidenceLedger::class)->record(LedgerEventType::ContextComposed, [
                'event_name' => 'context.ranking_hints.snapshot',
                'schema_version' => 'atlas.context.ranking_hints.snapshot.v1',
                'objective_hash' => MissionCanonicalHash::sha256((string) ($input['objective'] ?? $input['prompt'] ?? $input['query'] ?? '')),
                'domain' => (string) ($input['domain'] ?? 'atlas'),
                'task_type' => (string) ($input['task_type'] ?? 'direct'),
                'flow_id' => $feedbackHint['flow_id'] ?? null,
                'hint' => $this->feedbackHintSummary($feedbackHint),
                'snapshot' => [
                    'before' => $this->rankingSnapshot($baselineRanked, $baselineSelected),
                    'after' => $this->rankingSnapshot($currentRanked, $currentSelected),
                ],
                'delta' => $feedbackImpactReport,
                'hold' => [
                    'status' => 'observe',
                    'auto_revert' => false,
                    'reason' => ((int) ($feedbackImpactReport['rank_position_change_count'] ?? 0)) > 0
                        ? 'comparison_recorded'
                        : 'no_measured_improvement_yet',
                ],
                'rerank_result_hash' => $rerankResultHash,
                'policy' => [
                    'provider_safe_only' => true,
                    'raw_text_exposed' => false,
                    'read_only_command' => 'atlas:context:ranking-hints',
                    'auto_revert' => false,
                ],
            ], [
                'envelope_id' => 'context_ranking_hints',
                'correlation_id' => $rerankResultHash !== '' ? $rerankResultHash : 'context_ranking_hints',
                'emitter_stage' => 'atlas.context_ranking',
                'emitter_version' => self::SCHEMA_VERSION,
            ]);
        } catch (\Throwable) {
            // Evidence is observational. Missing ledger/schema must never alter ranking.
        }
    }

    /**
     * @param  array<int,array<string,mixed>>  $ranked
     * @param  array<int,array<string,mixed>>  $selected
     * @return array<string,mixed>
     */
    private function rankingSnapshot(array $ranked, array $selected): array
    {
        return ContextRankingSystemSupport::rankingSnapshot($ranked, $selected);
    }

    private function authorityScore(string $authorityLevel): float
    {
        return ContextRankingSystemSupport::authorityScore($authorityLevel);
    }

    private function freshnessScore(string $status): float
    {
        return ContextRankingSystemSupport::freshnessScore($status);
    }

    private static function freshnessLabel(string $status): string
    {
        return ContextRankingSystemSupport::freshnessLabel($status);
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function inputFlow(array $input, string $domain, string $taskType): string
    {
        return ContextRankingSystemSupport::inputFlow($input, $domain, $taskType);
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
        $global = $flowRequested ? null : $this->globalFeedbackHints();
        $globalActive = $global !== null && (
            $global['repromote_source_types'] !== []
            || $global['demote_source_types'] !== []
            || $global['demote_source_hashes'] !== []
            || ($global['demote_context_refs'] ?? []) !== []
        );
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
            $global['repromote_source_types'] ?? [],
        );
        $demoteSourceTypes = $this->hintStrings(
            $explicit['demote_source_types'] ?? [],
            data_get($eventPayload, 'payload.context_ref_attribution.noise_refs.*.source_type', []),
            $global['demote_source_types'] ?? [],
        );
        $demoteSourceHashes = $this->hintStrings(
            $explicit['demote_source_hashes'] ?? [],
            $explicit['demote_ref_hashes'] ?? [],
            $noiseHashes,
            data_get($eventPayload, 'payload.context_ref_attribution.noise_refs.*.ref_hash', []),
            $global['demote_source_hashes'] ?? [],
        );
        $demoteContextRefs = $this->hintStrings(
            $explicit['demote_context_refs'] ?? [],
            data_get($eventPayload, 'payload.next_context_policy.demote_context_refs', []),
            $global['demote_context_refs'] ?? [],
            $this->concentrationDemoteContextRefs(),
        );

        $active = $repromoteSourceTypes !== []
            || $demoteSourceTypes !== []
            || $demoteSourceHashes !== []
            || $demoteContextRefs !== [];

        return [
            'active' => $active,
            'source' => match (true) {
                $explicitProvided && $event instanceof AiRagFeedbackEvent => 'input_and_latest_flow_feedback',
                $explicitProvided && $globalActive => 'input_and_global_feedback',
                $explicitProvided => 'input_feedback_hint',
                $event instanceof AiRagFeedbackEvent => 'latest_flow_feedback',
                $globalActive => 'global_feedback',
                default => 'none',
            },
            'feedback_scope' => match (true) {
                $flowRequested => 'flow',
                $globalActive => 'global',
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
     * ARFL->ACRS closed loop, global half: when NO flow_id scopes the ranking,
     * aggregate the persisted retrieval-feedback events of the last 7 days
     * (cap 50) into GLOBAL hints. A source type / ref hash only acts when it
     * repeats across >=2 events — one bad session never demotes globally.
     * Flag-gated (atlas.context.feedback_global_hints) and fail-open: any
     * fault, missing table or empty window => null (pre-loop behavior).
     *
     * @return array{repromote_source_types:array<int,string>,demote_source_types:array<int,string>,demote_source_hashes:array<int,string>}|null
     */
    private function globalFeedbackHints(): ?array
    {
        if (! (bool) config('atlas.context.feedback_global_hints', true)) {
            return null;
        }
        if (! DatabaseTableAvailability::has('ai_rag_feedback_events')) {
            return null;
        }

        try {
            $events = AiRagFeedbackEvent::query()
                ->where('created_at', '>=', Carbon::now()->subDays(7))
                ->latest('created_at')
                ->limit(50)
                ->get();
        } catch (\Throwable) {
            return null;
        }

        if ($events->isEmpty()) {
            return null;
        }

        $repromote = [];
        $demoteTypes = [];
        $demoteHashes = [];
        foreach ($events as $event) {
            $payload = (array) $event->payload;
            $noiseHashes = [];
            foreach ((array) $event->source_utility as $ref => $utility) {
                if ((string) $utility === 'noise') {
                    $noiseHashes[] = (string) $ref;
                }
            }
            // hintStrings dedupes WITHIN the event, so each event votes at most once per signal.
            foreach ($this->hintStrings(
                data_get((array) $event->next_retrieval_hint, 'should_repromote_sources', []),
                data_get($payload, 'next_context_policy.expand_source_types', []),
                data_get($payload, 'payload.next_context_policy.expand_source_types', []),
            ) as $type) {
                $repromote[$type] = ($repromote[$type] ?? 0) + 1;
            }
            foreach ($this->hintStrings(
                data_get($payload, 'context_ref_attribution.noise_refs.*.source_type', []),
                data_get($payload, 'payload.context_ref_attribution.noise_refs.*.source_type', []),
            ) as $type) {
                $demoteTypes[$type] = ($demoteTypes[$type] ?? 0) + 1;
            }
            foreach ($this->hintStrings(
                $noiseHashes,
                data_get($payload, 'context_ref_attribution.noise_refs.*.ref_hash', []),
                data_get($payload, 'payload.context_ref_attribution.noise_refs.*.ref_hash', []),
            ) as $hash) {
                $demoteHashes[$hash] = ($demoteHashes[$hash] ?? 0) + 1;
            }
        }

        $recurring = static fn (array $counts): array => array_values(array_keys(
            array_filter($counts, static fn (int $count): bool => $count >= 2),
        ));

        return [
            'repromote_source_types' => $recurring($repromote),
            'demote_source_types' => $recurring($demoteTypes),
            'demote_source_hashes' => $recurring($demoteHashes),
            'demote_context_refs' => $this->concentrationDemoteContextRefs(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function concentrationDemoteContextRefs(): array
    {
        try {
            return (new AtlasMemoryRecallConcentrationDemotion)->demoteContextRefsForEntries(
                (new AtlasMemoryRecallConcentrationDemotion)->dominantEntryIds(),
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function inactiveFeedbackHint(): array
    {
        return ContextRankingSystemSupport::inactiveFeedbackHint();
    }

    /**
     * @param  array<string,mixed>  $feedbackHint
     * @return array<string,mixed>
     */
    private function feedbackHintSummary(array $feedbackHint): array
    {
        return ContextRankingSystemSupport::feedbackHintSummary(
            $feedbackHint,
            (bool) config('atlas.context.feedback_global_hints', true),
        );
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  array<string,mixed>  $feedbackHint
     * @return array{delta:float,reasons:array<int,string>}
     */
    private function feedbackImpact(array $candidate, string $sourceRef, string $sourceRefHash, array $feedbackHint): array
    {
        return ContextRankingSystemSupport::feedbackImpact($candidate, $sourceRef, $sourceRefHash, $feedbackHint);
    }

    /**
     * @return array<int,string>
     */
    private function hintStrings(mixed ...$values): array
    {
        return ContextRankingSystemSupport::hintStrings(...$values);
    }

    /**
     * @return array<int,string>
     */
    private function flattenScalars(mixed $value): array
    {
        return ContextRankingSystemSupport::flattenScalars($value);
    }

    private function flow(string $domain, string $taskType): string
    {
        return ContextRankingSystemSupport::flow($domain, $taskType);
    }
}

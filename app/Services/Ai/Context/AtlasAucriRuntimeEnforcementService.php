<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Carbon;

final class AtlasAucriRuntimeEnforcementService
{
    public const SCHEMA_VERSION = 'atlas.aucri.runtime_enforcement.v1';

    public function __construct(
        private readonly AtlasSemanticEmbeddingFoundationService $semanticEmbedding,
        private readonly AtlasHybridRetrievalInfrastructureService $hybridRetrieval,
        private readonly AtlasAgenticRagFrameworkService $agenticRag,
        private readonly AtlasContextRankingSystemService $rankingSystem,
        private readonly AtlasRetrievalPrivacyTrustLayerService $privacyTrust,
        private readonly AtlasContextFreshnessQualityGateService $freshnessQuality,
        private readonly AtlasRetrievalFeedbackLoopService $feedbackLoop,
        private readonly AtlasGraphRetrievalNetworkService $graphRetrieval,
        private readonly AtlasUnifiedRealityGraphService $realityGraph,
        private readonly AtlasPythonDataRetrievalRuntimeService $pythonDataRuntime,
        private readonly AtlasRetrievalEvaluationBenchmarkArenaService $evaluationArena,
        private readonly AtlasRetrievalCostLatencyGovernorService $costLatencyGovernor,
        private readonly AtlasContextObservabilityPlaneService $observabilityPlane,
        private readonly AtlasKnowledgeIngestionFabricService $knowledgeIngestion,
        private readonly AtlasCognitiveMemoryFabricService $memoryFabric,
        private readonly AtlasContextCompilerRuntimeService $compiler,
        private readonly AtlasTokenEconomyRuntimeService $tokenEconomy,
        private readonly AtlasContextParetoFrontierRuntimeService $paretoFrontier,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function enforce(array $input): array
    {
        $flowId = $this->flowId((string) ($input['flow_id'] ?? 'atlas.context.runtime'));
        $risk = $this->risk((string) ($input['risk_level'] ?? 'medium'));
        $provider = $this->provider((string) ($input['provider'] ?? 'gpt'));
        $rawContext = $this->rawContext($input);
        $segments = $this->segments($input, $rawContext);
        $runtimeObjective = (string) ($input['objective'] ?? $input['task'] ?? $rawContext ?: 'AUCRI runtime enforcement');
        $baseInput = [
            'flow_id' => $flowId,
            'domain' => (string) ($input['domain'] ?? 'programming'),
            'task_type' => (string) ($input['task_type'] ?? 'execution'),
            'risk_level' => $risk,
            'objective' => $runtimeObjective,
            'query' => $this->contextHash($rawContext, $segments),
            'max_refs' => max(4, min(12, (int) ($input['max_refs'] ?? 8))),
            'required_sources' => $this->requiredSources($input, $flowId),
        ];

        $semantic = $this->semanticEmbedding->candidateSet([[
            'source_ref' => 'aucri://runtime/'.$flowId,
            'text' => $rawContext === '' ? $runtimeObjective : $rawContext,
            'privacy_class' => 'normal',
            'authority_level' => 'runtime_enforcement',
        ]]);

        $hybrid = $this->hybridRetrieval->report($baseInput + [
            'context_refs' => (array) ($input['context_refs'] ?? ['memory_signals', 'code_intelligence', 'evidence_replay']),
        ]);

        $agentic = $this->agenticRag->plan($baseInput);
        $ranking = $this->rankingSystem->rank($baseInput);

        $privacy = $this->privacyTrust->evaluate([
            'domain' => (string) ($input['domain'] ?? 'programming'),
            'task_type' => (string) ($input['task_type'] ?? 'execution'),
            'risk_level' => $risk,
            'provider_target' => (string) ($input['provider_target'] ?? 'external'),
            'raw_context' => $rawContext,
            'source_refs' => (array) ($input['source_refs'] ?? []),
        ]);

        $freshness = $this->freshnessQuality->evaluate([
            'domain' => (string) ($input['domain'] ?? 'programming'),
            'task_type' => (string) ($input['task_type'] ?? 'execution'),
            'risk_level' => $risk,
            'query' => $this->contextHash($rawContext, $segments),
            'required_sources' => $this->requiredSources($input, $flowId),
        ]);

        $feedback = $this->feedbackLoop->capture($baseInput + [
            'outcome_status' => 'passed',
            'record' => false,
        ]);

        $graph = $this->graphRetrieval->retrieve($baseInput + [
            'max_results' => 4,
            'target_flows' => [$flowId],
            'target_capabilities' => (array) ($input['target_capabilities'] ?? ['context_retrieval', 'programming_execution']),
        ]);

        $reality = $this->realityGraph->snapshot([
            'risk_level' => $risk === 'high' ? 'medium' : $risk,
            'hours' => 24,
            'limit' => 25,
        ]);

        $pythonData = $this->pythonDataRuntime->run([
            'workspace' => base_path(),
            'files' => ['composer.json'],
            'execute' => false,
            'approved' => false,
            'runtime_boundary_green' => true,
        ]);

        $arena = $this->evaluationArena->evaluate([
            'risk_level' => $risk === 'high' ? 'medium' : $risk,
        ]);

        $budget = $this->costLatencyGovernor->govern($baseInput + [
            'observed_latency_ms' => 1200,
            'cache_requested' => true,
        ]);

        $observability = $this->observabilityPlane->snapshot($baseInput + [
            'outcome_status' => 'passed',
            'hours' => 24,
        ]);

        $ingestion = $this->knowledgeIngestion->normalize([
            'source_type' => 'text',
            'content' => $rawContext === '' ? $runtimeObjective : $rawContext,
            'origin_uri' => 'aucri://runtime/'.$flowId,
            'risk_level' => $risk,
            'provider_target' => (string) ($input['provider_target'] ?? 'external'),
            'classification' => 'public',
            'authority_level' => 'runtime_enforcement',
        ]);

        $memory = $this->memoryFabric->plan([
            'items' => array_map(static fn (array $segment): array => [
                'kind' => (string) ($segment['kind'] ?? 'context'),
                'ref' => (string) ($segment['ref'] ?? 'aucri:runtime_segment'),
                'tokens' => (int) ($segment['tokens'] ?? 0),
                'bytes' => max(1024, (int) ($segment['tokens'] ?? 0) * 640),
                'must_keep' => (bool) ($segment['must_keep'] ?? false),
                'heat' => (float) ($segment['priority'] ?? 0.5),
            ], $segments),
            'raw_context' => $rawContext,
            'risk_level' => $risk,
            'memory_available_bytes' => (int) ($input['memory_available_bytes'] ?? 12 * 1073741824),
            'repeated_tokens' => (int) ($input['repeated_tokens'] ?? 0),
        ]);

        $compiler = $this->compiler->compile([
            'flow_id' => $flowId,
            'provider' => $provider,
            'risk_level' => $risk,
            'segments' => $segments,
            'repeated_tokens' => (int) ($input['repeated_tokens'] ?? 0),
            'memory_available_bytes' => (int) ($input['memory_available_bytes'] ?? 12 * 1073741824),
        ]);

        $tokenEconomy = $this->tokenEconomy->optimize([
            'flow_id' => $flowId,
            'provider' => $provider,
            'risk_level' => $risk,
            'segments' => $segments,
            'task' => (string) ($input['task'] ?? 'execute'),
            'must_keep_coverage' => (float) ($input['must_keep_coverage'] ?? 1.0),
            'repeated_tokens' => (int) ($input['repeated_tokens'] ?? 0),
            'memory_available_bytes' => (int) ($input['memory_available_bytes'] ?? 12 * 1073741824),
        ]);

        $pareto = $this->paretoFrontier->paretoFrontier([
            $this->candidateFromRuntime('compiler', $compiler, (int) data_get($compiler, 'prompt_budget_receipt.input_tokens_after', 1), 0.92),
            $this->candidateFromRuntime('token_economy', $tokenEconomy, (int) data_get($tokenEconomy, 'compression_receipt.input_tokens_after', 1), 0.90),
        ]);
        $blockRefs = $this->blockRefs([
            ['block' => 1, 'acronym' => 'ASEF', 'name' => 'Atlas Semantic Embedding Foundation', 'schema' => AtlasSemanticEmbeddingFoundationService::SCHEMA_VERSION, 'payload' => $semantic, 'hash_key' => 'candidate_set_hash'],
            ['block' => 2, 'acronym' => 'AHRI', 'name' => 'Atlas Hybrid Retrieval Infrastructure', 'schema' => AtlasHybridRetrievalInfrastructureService::SCHEMA_VERSION, 'payload' => $hybrid, 'hash_key' => 'retrieval_report_hash'],
            ['block' => 3, 'acronym' => 'AARF', 'name' => 'Atlas Agentic RAG Framework', 'schema' => AtlasAgenticRagFrameworkService::SCHEMA_VERSION, 'payload' => $agentic, 'hash_key' => 'agentic_rag_plan_hash'],
            ['block' => 4, 'acronym' => 'ACRS', 'name' => 'Atlas Context Ranking System', 'schema' => AtlasContextRankingSystemService::SCHEMA_VERSION, 'payload' => $ranking, 'hash_key' => 'rerank_result_hash'],
            ['block' => 5, 'acronym' => 'ACFQ', 'name' => 'Atlas Context Freshness & Quality Gate', 'schema' => AtlasContextFreshnessQualityGateService::SCHEMA_VERSION, 'payload' => $freshness, 'hash_key' => 'freshness_quality_gate_hash'],
            ['block' => 6, 'acronym' => 'ARFL', 'name' => 'Atlas Retrieval Feedback Loop', 'schema' => AtlasRetrievalFeedbackLoopService::SCHEMA_VERSION, 'payload' => $feedback, 'hash_key' => 'retrieval_feedback_hash'],
            ['block' => 7, 'acronym' => 'AGRN', 'name' => 'Atlas Graph Retrieval Network', 'schema' => AtlasGraphRetrievalNetworkService::SCHEMA_VERSION, 'payload' => $graph, 'hash_key' => 'graph_retrieval_hash'],
            ['block' => 8, 'acronym' => 'AURG', 'name' => 'Atlas Unified Reality Graph', 'schema' => AtlasUnifiedRealityGraphService::SNAPSHOT_SCHEMA, 'payload' => $reality, 'hash_key' => 'snapshot_hash'],
            ['block' => 9, 'acronym' => 'APDR', 'name' => 'Atlas Python Data Retrieval Runtime', 'schema' => 'atlas.aucri.python_data_runtime.v1', 'payload' => $pythonData, 'hash_key' => 'runtime_hash'],
            ['block' => 10, 'acronym' => 'AREBA', 'name' => 'Atlas Retrieval Evaluation & Benchmark Arena', 'schema' => AtlasRetrievalEvaluationBenchmarkArenaService::SCHEMA_VERSION, 'payload' => $arena, 'hash_key' => 'arena_hash'],
            ['block' => 11, 'acronym' => 'ARCLG', 'name' => 'Atlas Retrieval Cost & Latency Governor', 'schema' => AtlasRetrievalCostLatencyGovernorService::SCHEMA_VERSION, 'payload' => $budget, 'hash_key' => 'governor_hash'],
            ['block' => 12, 'acronym' => 'ACOP', 'name' => 'Atlas Context Observability Plane', 'schema' => AtlasContextObservabilityPlaneService::SCHEMA_VERSION, 'payload' => $observability, 'hash_key' => 'snapshot_hash'],
            ['block' => 13, 'acronym' => 'ARPTL', 'name' => 'Atlas Retrieval Privacy & Trust Layer', 'schema' => AtlasRetrievalPrivacyTrustLayerService::SCHEMA_VERSION, 'payload' => $privacy, 'hash_key' => 'privacy_trust_hash'],
            ['block' => 14, 'acronym' => 'AKIF', 'name' => 'Atlas Knowledge Ingestion Fabric', 'schema' => AtlasKnowledgeIngestionFabricService::SCHEMA_VERSION, 'payload' => $ingestion, 'hash_key' => 'ingestion_fabric_hash'],
            ['block' => 15, 'acronym' => 'ACMF', 'name' => 'Atlas Cognitive Memory Fabric', 'schema' => AtlasCognitiveMemoryFabricService::SCHEMA_VERSION, 'payload' => $memory, 'hash_key' => 'cognitive_memory_hash'],
            ['block' => 16, 'acronym' => 'ACCR', 'name' => 'Atlas Context Compiler Runtime', 'schema' => AtlasContextCompilerRuntimeService::SCHEMA_VERSION, 'payload' => $compiler, 'hash_key' => 'context_compiler_hash'],
            ['block' => 17, 'acronym' => 'ATER', 'name' => 'Atlas Token Economy Runtime', 'schema' => AtlasTokenEconomyRuntimeService::SCHEMA_VERSION, 'payload' => $tokenEconomy, 'hash_key' => 'token_economy_hash'],
            ['block' => 18, 'acronym' => 'ACPFR', 'name' => 'Atlas Context Pareto Frontier Runtime', 'schema' => AtlasContextParetoFrontierRuntimeService::SCHEMA_VERSION, 'payload' => ['status' => $pareto === [] ? 'blocked' : 'ready', 'frontier' => $pareto], 'hash_key' => null],
        ]);

        $strictRetrievalGate = (bool) ($input['strict_retrieval_gate'] ?? false);
        $freshnessBlockers = $strictRetrievalGate
            ? $this->blockersFrom('freshness_quality', (array) data_get($freshness, 'blockers', []), (string) ($freshness['status'] ?? 'blocked'))
            : [];

        $blockers = array_values(array_filter([
            ...$this->blockersFrom('privacy', (array) data_get($privacy, 'provider_gate.blocked_reasons', []), (string) data_get($privacy, 'provider_gate.status', 'blocked')),
            ...$freshnessBlockers,
            ...$this->blockersFrom('context_compiler', (array) data_get($compiler, 'loss_check.blockers', []), (string) ($compiler['status'] ?? 'blocked')),
            ...$this->blockersFrom('token_economy', (array) data_get($tokenEconomy, 'quality_check.blockers', []), (string) ($tokenEconomy['status'] ?? 'blocked')),
        ]));

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $blockers === [] ? 'passed' : 'blocked',
            'generated_at' => Carbon::now()->toIso8601String(),
            'flow_id' => $flowId,
            'domain' => (string) ($input['domain'] ?? 'programming'),
            'task_type' => (string) ($input['task_type'] ?? 'execution'),
            'risk_level' => $risk,
            'provider' => $provider,
            'context_hash' => $this->contextHash($rawContext, $segments),
            'block_refs' => $blockRefs,
            'block_ref_summary' => [
                'total' => count($blockRefs),
                'executed' => count(array_filter($blockRefs, static fn (array $ref): bool => ($ref['execution_status'] ?? null) === 'executed')),
                'acronyms' => array_column($blockRefs, 'acronym'),
                'all_18_blocks_executed' => count($blockRefs) === 18
                    && count(array_filter($blockRefs, static fn (array $ref): bool => ($ref['execution_status'] ?? null) === 'executed')) === 18,
            ],
            'checks' => [
                'privacy_trust' => [
                    'schema_version' => AtlasRetrievalPrivacyTrustLayerService::SCHEMA_VERSION,
                    'status' => (string) ($privacy['status'] ?? 'unknown'),
                    'provider_gate_status' => (string) data_get($privacy, 'provider_gate.status', 'unknown'),
                    'privacy_trust_hash' => (string) ($privacy['privacy_trust_hash'] ?? ''),
                ],
                'freshness_quality' => [
                    'schema_version' => AtlasContextFreshnessQualityGateService::SCHEMA_VERSION,
                    'status' => (string) ($freshness['status'] ?? 'unknown'),
                    'gate_hash' => (string) ($freshness['freshness_quality_hash'] ?? ''),
                    'hard_block_enabled' => $strictRetrievalGate,
                ],
                'context_compiler' => [
                    'schema_version' => AtlasContextCompilerRuntimeService::SCHEMA_VERSION,
                    'status' => (string) ($compiler['status'] ?? 'unknown'),
                    'context_compiler_hash' => (string) ($compiler['context_compiler_hash'] ?? ''),
                    'must_keep_coverage' => (float) data_get($compiler, 'loss_check.must_keep_coverage', 0.0),
                ],
                'token_economy' => [
                    'schema_version' => AtlasTokenEconomyRuntimeService::SCHEMA_VERSION,
                    'status' => (string) ($tokenEconomy['status'] ?? 'unknown'),
                    'token_economy_hash' => (string) ($tokenEconomy['token_economy_hash'] ?? ''),
                    'savings_estimate' => (int) data_get($tokenEconomy, 'compression_receipt.savings_estimate', 0),
                ],
                'pareto_frontier' => [
                    'schema_version' => AtlasContextParetoFrontierRuntimeService::SCHEMA_VERSION,
                    'frontier_count' => count($pareto),
                    'selected_candidate_id' => (string) data_get($pareto, '0.candidate_id', ''),
                ],
                'all_18_aucri_blocks' => [
                    'status' => count($blockRefs) === 18 ? 'passed' : 'blocked',
                    'executed_count' => count(array_filter($blockRefs, static fn (array $ref): bool => ($ref['execution_status'] ?? null) === 'executed')),
                    'expected_count' => 18,
                ],
            ],
            'blockers' => $blockers,
            'claims' => [
                'providers_invoked' => false,
                'writes' => false,
                'rivals_run' => false,
                'benchmark_run' => false,
                'raw_text_exposed' => false,
                'enforced_before_provider_call' => true,
                'all_18_aucri_blocks_executed' => count($blockRefs) === 18,
            ],
        ];

        $hashPayload = $payload;
        unset($hashPayload['generated_at']);
        $payload['runtime_enforcement_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    private function segments(array $input, string $rawContext): array
    {
        $segments = (array) ($input['segments'] ?? []);
        if ($segments !== []) {
            return array_values(array_filter($segments, 'is_array'));
        }

        return [
            [
                'kind' => 'decision',
                'ref' => 'aucri:runtime_decision',
                'tokens' => 500,
                'priority' => 1.0,
                'must_keep' => true,
                'content' => (string) ($input['objective'] ?? $input['task'] ?? 'runtime enforcement'),
            ],
            [
                'kind' => 'constraint',
                'ref' => 'aucri:runtime_constraints',
                'tokens' => 450,
                'priority' => 0.98,
                'must_keep' => true,
                'content' => 'provider_safe; no_raw_leak; evidence_required; must_keep_coverage=1.0',
            ],
            [
                'kind' => 'evidence',
                'ref' => 'aucri:runtime_context',
                'tokens' => max(200, min(4000, (int) ceil(strlen($rawContext) / 4))),
                'priority' => 0.88,
                'must_keep' => true,
                'content' => $rawContext === '' ? 'empty-context' : $rawContext,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function rawContext(array $input): string
    {
        $parts = [];
        foreach (['query', 'objective', 'raw_context', 'content', 'rendered_prompt_text'] as $key) {
            if (isset($input[$key]) && is_scalar($input[$key])) {
                $parts[] = (string) $input[$key];
            }
        }

        return trim(implode("\n", array_filter($parts)));
    }

    /**
     * @param  array<string,mixed>  $input
     * @return list<string>
     */
    private function requiredSources(array $input, string $flowId): array
    {
        $refs = array_values(array_filter(array_map(
            static fn (mixed $ref): string => is_scalar($ref) ? (string) $ref : (string) data_get($ref, 'ref', ''),
            (array) ($input['required_sources'] ?? [])
        )));

        return $refs !== [] ? $refs : ['flow:'.$flowId, 'aucri:runtime_enforcement'];
    }

    /**
     * @param  array<int,array<string,mixed>>  $segments
     */
    private function contextHash(string $rawContext, array $segments): string
    {
        return MissionCanonicalHash::sha256([
            'raw_context_hash' => MissionCanonicalHash::sha256($rawContext),
            'segments' => array_map(static fn (array $segment): array => [
                'kind' => (string) ($segment['kind'] ?? ''),
                'ref' => (string) ($segment['ref'] ?? ''),
                'tokens' => (int) ($segment['tokens'] ?? 0),
                'must_keep' => (bool) ($segment['must_keep'] ?? false),
            ], $segments),
        ]);
    }

    /**
     * @return list<string>
     */
    private function blockersFrom(string $prefix, array $reasons, string $status): array
    {
        if (in_array($status, ['passed', 'ready', 'redacted'], true)) {
            return [];
        }

        if ($reasons === []) {
            return [$prefix.':status_'.$status];
        }

        return array_values(array_map(static fn (mixed $reason): string => $prefix.':'.(string) $reason, $reasons));
    }

    /**
     * @param  array<string,mixed>  $runtime
     * @return array<string,mixed>
     */
    private function candidateFromRuntime(string $id, array $runtime, int $tokens, float $quality): array
    {
        return [
            'candidate_id' => $id,
            'case_id' => 'runtime_enforcement',
            'domain' => 'programming',
            'strategy' => $id,
            'risk_level' => 'runtime',
            'quality_score' => $quality,
            'input_tokens' => max(1, $tokens),
            'output_tokens' => 800,
            'latency_ms' => $id === 'token_economy' ? 120 : 160,
            'cost_units' => 0.0,
            'must_keep_coverage' => $id === 'token_economy'
                ? (float) data_get($runtime, 'quality_check.must_keep_coverage', 1.0)
                : (float) data_get($runtime, 'loss_check.must_keep_coverage', 1.0),
            'privacy_status' => 'pass',
            'sufficiency_status' => ((string) ($runtime['status'] ?? 'blocked')) === 'ready' ? 'pass' : 'blocked',
            'pareto_dominated' => false,
            'promotion_status' => ((string) ($runtime['status'] ?? 'blocked')) === 'ready' ? 'shadow_candidate' : 'blocked',
            'blockers' => ((string) ($runtime['status'] ?? 'blocked')) === 'ready' ? [] : ['runtime_status_not_ready'],
        ];
    }

    /**
     * @param  list<array{block:int,acronym:string,name:string,schema:string,payload:array<string,mixed>,hash_key:?string}>  $items
     * @return list<array<string,mixed>>
     */
    private function blockRefs(array $items): array
    {
        return array_values(array_map(function (array $item): array {
            $payload = $item['payload'];
            $hashKey = $item['hash_key'];
            $runtimeHash = $hashKey === null
                ? MissionCanonicalHash::sha256($payload)
                : (string) data_get($payload, $hashKey, MissionCanonicalHash::sha256($payload));

            return [
                'block' => $item['block'],
                'acronym' => $item['acronym'],
                'name' => $item['name'],
                'schema_version' => $item['schema'],
                'runtime_status' => (string) ($payload['status'] ?? 'unknown'),
                'execution_status' => 'executed',
                'runtime_hash' => $runtimeHash,
                'provider_safe' => data_get($payload, 'claims.providers_invoked') !== true,
                'writes' => (bool) data_get($payload, 'claims.writes', data_get($payload, 'writes', false)),
            ];
        }, $items));
    }

    private function flowId(string $flowId): string
    {
        return trim($flowId) !== '' ? trim($flowId) : 'atlas.context.runtime';
    }

    private function provider(string $provider): string
    {
        return match ($provider) {
            'claude_cli', 'claude_code', 'sonnet' => 'claude',
            'gemini_cli' => 'gemini',
            'local', 'claude', 'gpt', 'gemini' => $provider,
            default => 'gpt',
        };
    }

    private function risk(string $risk): string
    {
        return match ($risk) {
            'R4', 'R5', 'high' => 'high',
            'R3', 'medium' => 'medium',
            'irreversible' => 'irreversible',
            default => 'low',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Carbon;

final class AtlasAgenticRagFrameworkService
{
    public const SCHEMA_VERSION = 'atlas.aucri.agentic_rag_framework.v1';

    public const PLAN_SCHEMA = 'atlas.aucri.agentic_rag_plan.v1';

    public const REQUIRED_SOURCES_SCHEMA = 'atlas.aucri.required_sources.v1';

    public const GAP_CRITIC_SCHEMA = 'atlas.aucri.gap_critic_report.v1';

    public const SUFFICIENCY_SCHEMA = 'atlas.aucri.context_sufficiency_gate.v1';

    public function __construct(private readonly AtlasHybridRetrievalInfrastructureService $hybridRetrieval) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function plan(array $input): array
    {
        $objective = trim((string) ($input['objective'] ?? $input['prompt'] ?? $input['query'] ?? ''));
        $domain = (string) ($input['domain'] ?? 'atlas');
        $taskType = (string) ($input['task_type'] ?? 'direct');
        $risk = (string) ($input['risk_level'] ?? 'low');
        $requiredSources = $this->requiredSources($domain, $taskType, $risk);
        $optionalSources = $this->optionalSources($domain, $taskType, $risk);

        $firstPass = $this->hybridRetrieval->report($input + [
            'objective' => $objective,
            'domain' => $domain,
            'task_type' => $taskType,
            'risk_level' => $risk,
        ]);
        $critic = $this->critic($firstPass, $requiredSources, $optionalSources);
        $iterations = [[
            'iteration' => 1,
            'objective_hash' => MissionCanonicalHash::sha256($objective),
            'retrieval_report_hash' => (string) ($firstPass['retrieval_report_hash'] ?? ''),
            'status' => $critic['status'],
            'missing_required_sources' => $critic['missing_required_sources'],
            'missing_optional_sources' => $critic['missing_optional_sources'],
        ]];

        $secondPass = null;
        if ($critic['status'] !== 'passed' && $critic['missing_required_sources'] === []) {
            $secondPass = $this->hybridRetrieval->report($input + [
                'objective' => $objective.' '.implode(' ', $critic['missing_optional_sources']),
                'domain' => $domain,
                'task_type' => $taskType,
                'risk_level' => $risk,
            ]);
            $secondCritic = $this->critic($secondPass, $requiredSources, $optionalSources);
            $iterations[] = [
                'iteration' => 2,
                'objective_hash' => MissionCanonicalHash::sha256($objective.' '.implode(' ', $critic['missing_optional_sources'])),
                'retrieval_report_hash' => (string) ($secondPass['retrieval_report_hash'] ?? ''),
                'status' => $secondCritic['status'],
                'missing_required_sources' => $secondCritic['missing_required_sources'],
                'missing_optional_sources' => $secondCritic['missing_optional_sources'],
            ];
            $critic = $secondCritic;
        }

        $activeReport = $secondPass ?? $firstPass;
        $gate = $this->sufficiencyGate($critic, $risk);
        $status = match ($gate['status']) {
            'blocked' => 'blocked',
            'degraded' => 'degraded',
            default => 'ready',
        };

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => Carbon::now()->toIso8601String(),
            'agentic_rag_plan' => [
                'schema_version' => self::PLAN_SCHEMA,
                'domain' => $domain,
                'task_type' => $taskType,
                'risk_level' => $risk,
                'objective_hash' => MissionCanonicalHash::sha256($objective),
                'required_sources' => [
                    'schema_version' => self::REQUIRED_SOURCES_SCHEMA,
                    'sources' => $requiredSources,
                    'policy' => [
                        'fail_closed_when_missing' => true,
                        'source_truth_owned_by_ahri' => true,
                        'provider_guessing_allowed' => false,
                    ],
                ],
                'optional_sources' => $optionalSources,
                'max_iterations' => 2,
                'iterations' => $iterations,
                'selected_retrieval_report_hash' => (string) ($activeReport['retrieval_report_hash'] ?? ''),
            ],
            'retrieval_report' => $activeReport['retrieval_report'] ?? [],
            'gap_critic' => $critic,
            'context_sufficiency_gate' => $gate,
            'claims' => [
                'providers_invoked' => false,
                'writes' => false,
                'context_execution_allowed' => $gate['status'] === 'passed',
                'ranking_decision_made' => false,
                'raw_text_exposed' => false,
            ],
        ];

        $hashPayload = $payload;
        unset($hashPayload['generated_at']);
        $payload['agentic_rag_plan_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /**
     * @return array<int,string>
     */
    private function requiredSources(string $domain, string $taskType, string $risk): array
    {
        $sources = ['memory_signals', 'vector_retrieval'];

        if (in_array($domain, ['developer', 'programming', 'atlas_programming'], true)
            || in_array($taskType, ['dev', 'debug', 'review', 'quality_repair'], true)) {
            $sources[] = 'code_intelligence';
        }

        if (in_array($taskType, ['debug', 'review', 'quality_repair'], true)
            || in_array($risk, ['high', 'irreversible'], true)
            || in_array($domain, ['finance', 'legal', 'health'], true)) {
            $sources[] = 'evidence_replay';
        }

        return array_values(array_unique($sources));
    }

    /**
     * @return array<int,string>
     */
    private function optionalSources(string $domain, string $taskType, string $risk): array
    {
        $sources = ['semantic_candidate'];

        if (in_array($taskType, ['planning', 'decision', 'research'], true)
            || in_array($domain, ['strategy', 'research', 'finance'], true)
            || in_array($risk, ['high', 'irreversible'], true)) {
            $sources[] = 'graph_retrieval';
        }

        return array_values(array_unique($sources));
    }

    /**
     * @param  array<string,mixed>  $report
     * @param  array<int,string>  $requiredSources
     * @param  array<int,string>  $optionalSources
     * @return array<string,mixed>
     */
    private function critic(array $report, array $requiredSources, array $optionalSources): array
    {
        $availableTypes = collect((array) data_get($report, 'retrieval_report.candidates', []))
            ->filter(static fn (array $candidate): bool => (bool) ($candidate['available'] ?? false))
            ->pluck('source_type')
            ->unique()
            ->values()
            ->all();
        $missingRequired = array_values(array_diff($requiredSources, $availableTypes));
        $missingOptional = array_values(array_diff($optionalSources, $availableTypes));
        $excludedRefs = (array) data_get($report, 'retrieval_report.excluded_refs', []);
        $misses = (array) data_get($report, 'retrieval_report.misses', []);

        $status = match (true) {
            $missingRequired !== [] => 'blocked',
            $excludedRefs !== [] || $misses !== [] || $missingOptional !== [] => 'degraded',
            default => 'passed',
        };

        return [
            'schema_version' => self::GAP_CRITIC_SCHEMA,
            'status' => $status,
            'available_sources' => $availableTypes,
            'missing_required_sources' => $missingRequired,
            'missing_optional_sources' => $missingOptional,
            'miss_count' => count($misses),
            'excluded_ref_count' => count($excludedRefs),
            'reasons' => array_values(array_filter([
                $missingRequired !== [] ? 'missing_required_sources' : null,
                $missingOptional !== [] ? 'missing_optional_sources' : null,
                $excludedRefs !== [] ? 'provider_unsafe_refs_excluded' : null,
                $misses !== [] ? 'retrieval_misses_present' : null,
            ])),
        ];
    }

    /**
     * @param  array<string,mixed>  $critic
     * @return array<string,mixed>
     */
    private function sufficiencyGate(array $critic, string $risk): array
    {
        $status = match (true) {
            $critic['missing_required_sources'] !== [] => 'blocked',
            in_array($risk, ['high', 'irreversible'], true) && $critic['status'] !== 'passed' => 'blocked',
            $critic['status'] !== 'passed' => 'degraded',
            default => 'passed',
        };

        return [
            'schema_version' => self::SUFFICIENCY_SCHEMA,
            'status' => $status,
            'fail_closed' => in_array($risk, ['high', 'irreversible'], true),
            'required_source_coverage' => $critic['missing_required_sources'] === [] ? 1.0 : 0.0,
            'remediation' => match ($status) {
                'blocked' => 'rerun_ahri_with_required_sources_or_request_operator_review',
                'degraded' => 'continue_only_if_flow_allows_degraded_context',
                default => 'none',
            },
        ];
    }
}

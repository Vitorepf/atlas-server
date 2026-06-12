<?php

namespace App\Services\Ai\Compounding;

use App\Models\AiBenchmarkCase;
use App\Models\AiCompoundingMemory;
use App\Models\AiHeuristicUpdate;
use App\Models\AiLearningCandidate;
use App\Models\AiRagFeedbackEvent;
use App\Models\AiRunOutcome;
use App\Models\AiTemporalCertification;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\File;

class AtlasCompoundingReadinessService
{
    public const SCHEMA_VERSION = 'atlas.ai.compounding.readiness.v1';

    /**
     * @return array<string,mixed>
     */
    public function inspect(): array
    {
        $tables = [
            'ai_run_outcomes',
            'ai_learning_candidates',
            'ai_compounding_memories',
            'ai_heuristic_updates',
            'ai_rag_feedback_events',
            'ai_benchmark_cases',
            'ai_temporal_certifications',
        ];
        $tableStatus = collect($tables)->mapWithKeys(fn (string $table): array => [$table => DatabaseTableAvailability::has($table)])->all();
        $contracts = [
            AtlasCompoundingOutcomeEvaluator::SCHEMA_VERSION,
            AtlasLearningDistiller::SCHEMA_VERSION,
            AtlasCompoundingMemoryService::SCHEMA_VERSION,
            AtlasHeuristicEvolutionService::SCHEMA_VERSION,
            AtlasRagFeedbackService::SCHEMA_VERSION,
            AtlasBenchmarkGeneratorService::SCHEMA_VERSION,
            AtlasTemporalCertificationService::SCHEMA_VERSION,
        ];
        $docPath = base_path('docs/engineering-knowledge-base/atlas-compounding-engineering-intelligence.md');
        $routerSource = $this->source(app_path('Services/Ai/Router/AtlasAiRouterService.php'));
        $specialistFlowSource = $this->source(app_path('Services/Ai/Router/AtlasAiSpecialistFlowExecutionService.php'));
        $hyperflowSource = $this->source(app_path('Services/Ai/Router/AtlasAiHyperflowRivalsBatteryService.php'));
        $gatewaySource = $this->source(app_path('Services/Ai/AiGatewayService.php'));
        $conductorSource = $this->source(app_path('Services/Ai/AtlasDecide/AtlasEngineeringRunConductorService.php'));
        $atlasDevRunSource = $this->source(app_path('Http/Controllers/AtlasDev/RunController.php'));
        $devToForgeSource = $this->source(app_path('Services/AtlasCode/DevToForgePromotionService.php'));
        $checks = [
            $this->check('canonical_doc', File::exists($docPath), ['path' => 'docs/engineering-knowledge-base/atlas-compounding-engineering-intelligence.md']),
            $this->check('persistence_tables', ! in_array(false, $tableStatus, true), ['tables' => $tableStatus]),
            $this->check('contracts_declared', count($contracts) === 7, ['contracts' => $contracts]),
            $this->check('outcome_evaluator', class_exists(AtlasCompoundingOutcomeEvaluator::class), ['class' => AtlasCompoundingOutcomeEvaluator::class]),
            $this->check('learning_distiller', class_exists(AtlasLearningDistiller::class), ['class' => AtlasLearningDistiller::class]),
            $this->check('compounding_memory', class_exists(AtlasCompoundingMemoryService::class), ['class' => AtlasCompoundingMemoryService::class]),
            $this->check('heuristic_evolution', class_exists(AtlasHeuristicEvolutionService::class), ['class' => AtlasHeuristicEvolutionService::class]),
            $this->check('rag_feedback', class_exists(AtlasRagFeedbackService::class), ['class' => AtlasRagFeedbackService::class]),
            $this->check('benchmark_generator', class_exists(AtlasBenchmarkGeneratorService::class), ['class' => AtlasBenchmarkGeneratorService::class]),
            $this->check('temporal_certification', class_exists(AtlasTemporalCertificationService::class), ['class' => AtlasTemporalCertificationService::class]),
            $this->check('router_consumes_approved_memory', str_contains($routerSource, 'approvedCompoundingMemories') && str_contains($routerSource, 'compounding_memories'), [
                'router_memory_context_present' => str_contains($routerSource, 'compounding_memories'),
            ]),
            $this->check('specialist_flows_emit_learning_signal_contract', str_contains($specialistFlowSource, 'learning_signal_contract') && str_contains($specialistFlowSource, 'atlas.ai.compounding.flow_learning_signal_contract.v1'), [
                'learning_signal_contract_present' => str_contains($specialistFlowSource, 'atlas.ai.compounding.flow_learning_signal_contract.v1'),
            ]),
            $this->check('hyperflow_records_compounding_outcome', str_contains($hyperflowSource, 'recordCompoundingOutcome') && str_contains($hyperflowSource, 'AtlasCompoundingRuntimeService'), [
                'hyperflow_bridge_present' => str_contains($hyperflowSource, 'recordCompoundingOutcome'),
            ]),
            // T1.2 (2026-06-11): o hook recordCompoundingFlowSignal do gateway fabricava um
            // learning signal boilerplate por interação (a fonte do ~94% de noise medido).
            // O contrato agora é o INVERSO: a fábrica deve estar ausente e o compounding
            // deve ser alimentado pelo caminho real (conductor learn→recall).
            $this->check('gateway_does_not_fabricate_learning_signals', ! str_contains($gatewaySource, 'recordCompoundingFlowSignal('), [
                'noise_factory_absent' => ! str_contains($gatewaySource, 'recordCompoundingFlowSignal('),
            ]),
            $this->check('conductor_feeds_compounding_runtime', str_contains($conductorSource, 'AtlasCompoundingRuntimeService') && str_contains($conductorSource, 'recordExecution') && str_contains($conductorSource, 'learning_signal') && str_contains($conductorSource, 'engineering_run_memory'), [
                'conductor_real_feed_present' => str_contains($conductorSource, 'recordExecution'),
                'substantive_learning_signal_present' => str_contains($conductorSource, 'learning_signal'),
            ]),
            $this->check('atlas_dev_records_compounding_outcome', str_contains($atlasDevRunSource, 'recordCompoundingLearningSignal') && str_contains($atlasDevRunSource, 'atlas.ai.compounding.atlas_dev_bridge.v1'), [
                'atlas_dev_bridge_present' => str_contains($atlasDevRunSource, 'atlas.ai.compounding.atlas_dev_bridge.v1'),
            ]),
            $this->check('forge_handoff_carries_learning_bundle', str_contains($devToForgeSource, 'compounding_learning_bundle') && str_contains($devToForgeSource, 'atlas.ai.compounding.dev_to_forge_learning_bundle.v1'), [
                'dev_to_forge_learning_bundle_present' => str_contains($devToForgeSource, 'atlas.ai.compounding.dev_to_forge_learning_bundle.v1'),
            ]),
        ];
        $failed = array_values(array_filter($checks, fn (array $check): bool => $check['status'] !== 'passed'));
        $runtimeCounts = $this->runtimeCounts();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $failed === [] ? 'passed' : 'blocked',
            'summary' => [
                'total' => count($checks),
                'passed' => count($checks) - count($failed),
                'failed' => count($failed),
            ],
            'checks' => $checks,
            'runtime_counts' => $runtimeCounts,
            'contracts' => $contracts,
            'claim_policy' => [
                'ready_to_claim_100x' => false,
                'ready_to_replace_claude_code_codex' => false,
                'requires_external_rival_battery' => true,
                'allows_compounding_runtime_claim' => $failed === [],
            ],
            'remaining_blockers' => array_map(fn (array $check): string => $check['id'], $failed),
            'writes' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function certify(): array
    {
        $readiness = $this->inspect();
        $certification = app(AtlasTemporalCertificationService::class)->certify();
        $runtimeCounts = $this->runtimeCounts();
        $runtimeBlockers = $this->runtimeEvidenceBlockers($runtimeCounts);
        $passed = $readiness['status'] === 'passed'
            && $certification->status === 'passed'
            && $runtimeBlockers === [];
        $status = $passed ? 'passed' : 'blocked';

        return [
            'schema_version' => 'atlas.ai.compounding.certification.v1',
            'status' => $status,
            'readiness' => $readiness,
            'runtime_evidence' => [
                'counts' => $runtimeCounts,
                'blockers' => $runtimeBlockers,
            ],
            'temporal_certification' => [
                'status' => $certification->status,
                'certification_hash' => $certification->certification_hash,
                'blockers' => $certification->blockers,
                'claim_policy' => $certification->claim_policy,
            ],
            'writes' => true,
        ];
    }

    /**
     * @param  array<string,int|null>  $counts
     * @return list<string>
     */
    private function runtimeEvidenceBlockers(array $counts): array
    {
        $required = [
            'outcomes',
            'learning_candidates',
            'compounding_memories',
            'heuristic_updates',
            'rag_feedback_events',
            'benchmark_cases',
        ];

        $blockers = [];
        foreach ($required as $key) {
            if (($counts[$key] ?? 0) < 1) {
                $blockers[] = 'missing_'.$key;
            }
        }

        return $blockers;
    }

    /**
     * @return array<string,int|null>
     */
    private function runtimeCounts(): array
    {
        if (! DatabaseTableAvailability::has('ai_run_outcomes')) {
            return [
                'outcomes' => null,
                'learning_candidates' => null,
                'compounding_memories' => null,
                'heuristic_updates' => null,
                'rag_feedback_events' => null,
                'benchmark_cases' => null,
                'temporal_certifications' => null,
            ];
        }

        return [
            'outcomes' => AiRunOutcome::query()->count(),
            'learning_candidates' => AiLearningCandidate::query()->count(),
            'compounding_memories' => AiCompoundingMemory::query()->count(),
            'heuristic_updates' => AiHeuristicUpdate::query()->count(),
            'rag_feedback_events' => AiRagFeedbackEvent::query()->count(),
            'benchmark_cases' => AiBenchmarkCase::query()->count(),
            'temporal_certifications' => AiTemporalCertification::query()->count(),
        ];
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    private function check(string $id, bool $passed, array $evidence): array
    {
        return [
            'id' => $id,
            'status' => $passed ? 'passed' : 'blocked',
            'evidence' => $evidence,
        ];
    }

    private function source(string $path): string
    {
        return File::exists($path) ? File::get($path) : '';
    }
}

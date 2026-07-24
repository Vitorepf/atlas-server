<?php

namespace App\Console\Commands;

use App\Services\Ai\Compounding\AtlasCompoundingReadinessService;
use App\Services\Ai\Compounding\AtlasCompoundingRuntimeService;
use App\Services\Ai\Compounding\AtlasTemporalCertificationService;
use App\Services\Ai\EngineeringKernel\Quality\QualityFoundryTemporalProjectionMaterializer;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use Illuminate\Console\Command;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAiCompoundingCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:compounding
        {action=certify : readiness, certify, simulate, run, temporal, temporal-rebuild}
        {--run-id= : Deterministic run id for simulate/run}
        {--flow-id=atlas_debug : Flow id for simulate/run}
        {--delivery-id= : Engineering delivery correlation for temporal-rebuild}
        {--status=failed : Outcome status for simulate/run}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run Atlas Compounding Engineering Intelligence readiness, certification and deterministic smoke simulation.';

    public function handle(
        AtlasCompoundingReadinessService $readiness,
        AtlasCompoundingRuntimeService $runtime,
    ): int {
        $action = strtolower(trim((string) $this->argument('action')));
        $payload = match ($action) {
            'readiness', 'status' => $readiness->inspect(),
            'simulate', 'smoke', 'run' => $runtime->recordExecution($this->sampleExecution()),
            'temporal' => [
                'schema_version' => 'atlas.ai.compounding.command.v1',
                'status' => 'recorded',
                'temporal_certification' => app(AtlasTemporalCertificationService::class)->certify()->toArray(),
                'writes' => true,
            ],
            'temporal-rebuild' => $this->rebuildTemporalProjection(),
            'certify', 'certification' => $readiness->certify(),
            default => [
                'schema_version' => 'atlas.ai.compounding.command.v1',
                'status' => 'failed',
                'error' => 'unsupported_action',
                'supported_actions' => ['readiness', 'certify', 'simulate', 'run', 'temporal', 'temporal-rebuild'],
                'writes' => false,
            ],
        };

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return $this->exitCodeFor($payload);
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Compounding</>', $action);
        $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Writes', YesNo::format($payload['writes'] ?? false));

        return $this->exitCodeFor($payload);
    }

    /**
     * @return array<string,mixed>
     */
    /** @return array<string,mixed> */
    private function rebuildTemporalProjection(): array
    {
        $deliveryId = trim((string) ($this->option('delivery-id') ?? ''));
        if ($deliveryId === '') {
            return [
                'schema_version' => 'atlas.ai.compounding.command.v1',
                'status' => 'blocked',
                'reason' => 'delivery_id_required',
                'writes' => false,
            ];
        }

        return app(QualityFoundryTemporalProjectionMaterializer::class)->materialize(
            app(AtlasEvidenceLedger::class),
            $deliveryId,
        );
    }

    private function sampleExecution(): array
    {
        return [
            'run_id' => (string) ($this->option('run-id') ?: 'compounding_smoke_'.now()->format('YmdHis')),
            'flow_id' => (string) ($this->option('flow-id') ?: 'atlas_debug'),
            'outcome_status' => (string) ($this->option('status') ?: 'failed'),
            'prompt' => 'isso aqui nao funciona',
            'flow_quality' => 62,
            'retrieval_quality' => 58,
            'execution_quality' => 55,
            'evidence_quality' => 92,
            'evidence_refs' => [
                'doc:docs/engineering-knowledge-base/atlas-compounding-engineering-intelligence.md',
                'test:tests/Feature/Ai/AtlasCompoundingEngineeringIntelligenceTest.php',
            ],
            'learning_signal' => [
                'claim' => 'Debug prompts with failure language need retrieval feedback before future execution.',
                'memory_type' => 'debug_memory',
                'scope' => 'atlas-server',
                'confidence' => 86,
                'flow_id' => 'atlas_debug',
                'evidence_refs' => [
                    'doc:docs/engineering-knowledge-base/atlas-compounding-engineering-intelligence.md',
                    'test:tests/Feature/Ai/AtlasCompoundingEngineeringIntelligenceTest.php',
                ],
            ],
            'rag_feedback' => [
                'retrieval_receipt_id' => 'retr_compounding_smoke',
                'query_plan_hash' => str_repeat('a', 64),
                'included_sources' => 4,
                'used_sources' => 3,
                'noise_sources' => 1,
                'missed_required_sources' => [],
                'context_sufficiency' => 82,
                'post_execution_utility' => 79,
                'source_utility' => [
                    'docs/engineering-knowledge-base/atlas-compounding-engineering-intelligence.md' => 'useful',
                ],
            ],
            'benchmark_case' => [
                'force' => true,
                'source' => 'real_user_run',
                'expected_flow' => 'atlas_debug',
                'required_evidence' => ['error_trace', 'related_file', 'test_result'],
                'rivals' => ['claude_code', 'codex'],
            ],
            'heuristic_update' => [
                'heuristic_key' => 'router.debug_failure_language',
                'flow_id' => 'atlas_debug',
                'before_state' => ['debug_signal_weight' => 0.55],
                'after_state' => ['debug_signal_weight' => 0.7],
                'evidence_refs' => ['outcome:compounding_smoke'],
                'rollback_plan' => ['restore_debug_signal_weight' => 0.55],
                'test_refs' => ['tests/Unit/Ai/Compounding/AtlasHeuristicEvolutionServiceTest.php'],
                'apply' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function exitCodeFor(array $payload): int
    {
        return ($payload['status'] ?? null) === 'failed' || ($payload['status'] ?? null) === 'blocked'
            ? self::FAILURE
            : self::SUCCESS;
    }
}

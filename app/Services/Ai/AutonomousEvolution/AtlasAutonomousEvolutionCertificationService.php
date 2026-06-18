<?php

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

final class AtlasAutonomousEvolutionCertificationService
{
    public const SCHEMA_VERSION = 'atlas.aael.certification.v1';

    public function __construct(
        private readonly AtlasAutonomousEvolutionLoopService $runtime,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function certify(): array
    {
        $checks = [
            $this->fileCheck('canonical_doc', 'docs/engineering-knowledge-base/atlas-autonomous-evolution-loop.md', ['Atlas Autonomous Evolution Loop', 'AAEL', 'Autonomous Evolution Portfolio OS']),
            $this->fileCheck('runtime_service', 'app/Services/Ai/AutonomousEvolution/AtlasAutonomousEvolutionLoopService.php', ['runCycle', 'strategicAlignmentGate', 'antiDriftDoctrineGate', 'autonomyBudget', 'decidePromotion', 'assistedExecutionBridge']),
            $this->fileCheck('persistence', 'database/migrations/2026_05_20_210000_create_atlas_aael_tables.php', ['atlas_aael_opportunities', 'atlas_aael_portfolio_cycles', 'atlas_aael_evolution_experiments', 'atlas_aael_promotion_decisions', 'atlas_aael_audit_reports']),
            $this->fileCheck('models', 'app/Models/AtlasAaelPortfolioCycle.php', ['AtlasAaelPortfolioCycle', 'experiments']),
            $this->runtimeSmoke(),
            $this->assistedExecutionBridgeSmoke(),
            $this->highRiskGateSmoke(),
            $this->doctrineBlockerSmoke(),
            $this->claimPolicy(),
            $this->fileCheck('commands', 'app/Console/Commands/AtlasAaelCommand.php', ['atlas:aael', 'cycle', 'control-plane']),
            $this->fileCheck('certify_command', 'app/Console/Commands/AtlasAaelCertifyCommand.php', ['atlas:aael:certify']),
            $this->fileCheck('tests', 'tests/Feature/Ai/AutonomousEvolution/AtlasAutonomousEvolutionLoopServiceTest.php', ['test_cycle_creates_portfolio_experiment_decision_and_audit', 'test_doctrine_gate_blocks_parallel_runtime_duplication', 'assisted_execution_quality']),
            $this->integrationWiring(),
            $this->fileCheck('control_plane', 'app/Services/Ai/ControlPlane/AtlasAiControlPlaneService.php', ['autonomous_evolution', 'aael_cycles_total']),
        ];
        $failed = array_values(array_filter($checks, fn (array $check): bool => ($check['status'] ?? null) !== 'pass'));
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $failed === [] ? 'passed' : 'failed',
            'summary' => [
                'total' => count($checks),
                'pass' => count($checks) - count($failed),
                'fail' => count($failed),
            ],
            'checks' => $checks,
            'blockers' => $failed,
            'claim_policy' => $this->runtime->claimPolicy(),
            'scope' => [
                'covers' => 'AAEL L1-L10 Autonomous Evolution Portfolio OS: observation, portfolio scoring, strategic gate, autonomy budget, impact simulation, experiment lane, promotion trust, memory, dormant capability activation and audit court.',
                'does_not_cover' => 'unsandboxed production mutation, autonomous provider spend, benchmark claims or human-signature bypass.',
            ],
        ];
        $payload['certification_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  list<string>  $tokens
     * @return array<string,mixed>
     */
    private function fileCheck(string $id, string $path, array $tokens): array
    {
        $full = base_path($path);
        $contents = File::exists($full) ? File::get($full) : '';
        $missing = array_values(array_filter($tokens, fn (string $token): bool => trim($token) === '' || ! str_contains($contents, $token)));

        return [
            'id' => $id,
            'status' => $missing === [] ? 'pass' : 'fail',
            'evidence' => [$path],
            'missing' => $missing,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimeSmoke(): array
    {
        return $this->runSmokeCycle('runtime_smoke', [
            'objective' => 'Improve Atlas Dev and Forge quality with evidence and rollback',
            'evidence_refs' => ['test:aael_runtime_smoke'],
        ], function (array $payload): array {
            $ok = ($payload['schema_version'] ?? null) === AtlasAutonomousEvolutionLoopService::CYCLE_SCHEMA
                && ($payload['portfolio_snapshot']['maturity_level'] ?? null) === AtlasAutonomousEvolutionLoopService::LEVEL_MAX
                && isset($payload['strategic_alignment_gate'], $payload['anti_drift_doctrine_gate'], $payload['audit_report']);

            return [
                'status' => $ok ? 'pass' : 'fail',
                'evidence' => ['cycle_hash' => $payload['cycle_hash'] ?? null],
            ];
        });
    }

    /**
     * @return array<string,mixed>
     */
    private function highRiskGateSmoke(): array
    {
        return $this->runSmokeCycle('high_risk_gate_smoke', [
            'opportunities' => [[
                'objective' => 'Change provider topology for Atlas router',
                'risk_level' => 'high',
                'strategic_alignment_score' => 0.9,
                'impact' => 0.9,
                'frequency' => 0.8,
                'effort' => 0.2,
            ]],
            'evidence_refs' => ['test:aael_high_risk'],
        ], function (array $payload): array {
            $ok = data_get($payload, 'operator_queue.0.action') === 'human_signature_required'
                && data_get($payload, 'promotion_decisions.0.trust_level') === 'signature_required';

            return [
                'status' => $ok ? 'pass' : 'fail',
                'evidence' => ['cycle_hash' => $payload['cycle_hash'] ?? null],
            ];
        });
    }

    /**
     * @return array<string,mixed>
     */
    private function assistedExecutionBridgeSmoke(): array
    {
        return $this->runSmokeCycle('assisted_execution_bridge', [
            'opportunities' => [[
                'objective' => 'Improve AAEL assisted execution bridge with evidence and rollback',
                'domain' => 'programming',
                'flow_id' => 'atlas_dev',
                'risk_level' => 'low',
                'strategic_alignment_score' => 0.95,
                'impact' => 0.9,
                'frequency' => 0.9,
                'effort' => 0.1,
            ]],
            'evidence_refs' => ['test:aael_assisted_execution_bridge'],
        ], function (array $payload): array {
            $bridge = data_get($payload, 'experiments.0.assisted_execution_quality', []);
            $promotionGateStatus = data_get($payload, 'promotion_decisions.0.promotion_gate.assisted_execution_quality_status');
            $ok = ($bridge['schema_version'] ?? null) === AtlasAutonomousEvolutionLoopService::ASSISTED_EXECUTION_BRIDGE_SCHEMA
                && ($bridge['status'] ?? null) === AtlasAutonomousEvolutionLoopService::STATUS_READY
                && ($bridge['aedpds_gate_status'] ?? null) === 'passed'
                && ($bridge['outcome_feedback_status'] ?? null) === 'recorded'
                && ($bridge['aemor_feedback_status'] ?? null) === 'ready_to_record'
                && $promotionGateStatus === AtlasAutonomousEvolutionLoopService::STATUS_READY;

            return [
                'status' => $ok ? 'pass' : 'fail',
                'evidence' => [
                    'bridge_hash' => $bridge['bridge_hash'] ?? null,
                    'promotion_gate_status' => $promotionGateStatus,
                ],
            ];
        });
    }

    /**
     * @return array<string,mixed>
     */
    private function doctrineBlockerSmoke(): array
    {
        return $this->runSmokeCycle('doctrine_blocker_smoke', [
            'opportunities' => [[
                'objective' => 'Create parallel self construction runtime and bypass evidence',
                'strategic_alignment_score' => 0.9,
                'impact' => 0.9,
                'frequency' => 0.9,
                'effort' => 0.1,
            ]],
        ], function (array $payload): array {
            $ok = data_get($payload, 'anti_drift_doctrine_gate.status') === AtlasAutonomousEvolutionLoopService::STATUS_BLOCKED
                && data_get($payload, 'portfolio_snapshot.selected_count') === 0;

            return [
                'status' => $ok ? 'pass' : 'fail',
                'evidence' => ['violations' => data_get($payload, 'anti_drift_doctrine_gate.violations')],
            ];
        });
    }

    /**
     * @return array<string,mixed>
     */
    private function claimPolicy(): array
    {
        $policy = $this->runtime->claimPolicy();
        $ok = ($policy['autonomous_core_mutation_allowed'] ?? true) === false
            && ($policy['provider_invoked_directly'] ?? true) === false
            && ($policy['requires_sandbox_before_promotion'] ?? false) === true
            && ($policy['uses_self_construction_for_atlas_building_atlas'] ?? false) === true;

        return ['id' => 'claim_policy', 'status' => $ok ? 'pass' : 'fail', 'evidence' => $policy];
    }

    /**
     * @return array<string,mixed>
     */
    private function integrationWiring(): array
    {
        return $this->fileCheck(
            'integration_wiring',
            'app/Services/Ai/AutonomousEvolution/AtlasAutonomousEvolutionLoopService.php',
            ['AtlasAutonomousWorkExecutionService', 'AtlasIntelligenceFactoryRuntimeService'],
        );
    }

    /**
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    private function withoutPersistingSmoke(callable $callback): mixed
    {
        DB::beginTransaction();

        try {
            $result = $callback();
            DB::rollBack();

            return $result;
        } catch (\Throwable $throwable) {
            DB::rollBack();

            throw $throwable;
        }
    }

    /**
     * Run a single AAEL smoke through the runtime's `runCycle(...)` and
     * convert any \Throwable escaping the call into a deterministic
     * `status => 'fail'` check payload, so one failing smoke never aborts
     * the whole `certify()` report.
     *
     * @param  array<string,mixed>  $cycleInput
     * @param  callable(array<string,mixed>):array{status:string,evidence?:array<string,mixed>}  $assert
     * @return array<string,mixed>
     */
    private function runSmokeCycle(string $id, array $cycleInput, callable $assert): array
    {
        try {
            $payload = $this->withoutPersistingSmoke(fn (): array => $this->runtime->runCycle($cycleInput));
            $verdict = $assert(is_array($payload) ? $payload : []);

            return [
                'id' => $id,
                'status' => ($verdict['status'] ?? null) === 'pass' ? 'pass' : 'fail',
                'evidence' => is_array($verdict['evidence'] ?? null) ? $verdict['evidence'] : [],
            ];
        } catch (\Throwable $throwable) {
            return [
                'id' => $id,
                'status' => 'fail',
                'evidence' => [
                    'error' => $throwable::class,
                    'message' => $throwable->getMessage(),
                ],
            ];
        }
    }

    private function contains(string $path, string $token): bool
    {
        $full = base_path($path);

        return File::exists($full) && str_contains((string) File::get($full), $token);
    }
}

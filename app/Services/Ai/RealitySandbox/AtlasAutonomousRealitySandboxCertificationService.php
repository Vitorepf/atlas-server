<?php

namespace App\Services\Ai\RealitySandbox;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

final class AtlasAutonomousRealitySandboxCertificationService
{
    public const SCHEMA_VERSION = 'atlas.aars.certification.v1';

    public function __construct(
        private readonly AtlasAutonomousRealitySandboxService $runtime,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function certify(): array
    {
        $checks = [
            $this->fileCheck('canonical_doc', 'docs/engineering-knowledge-base/atlas-autonomous-reality-sandbox.md', [
                'Atlas Autonomous Reality Sandbox',
                'AARS',
                'Atlas Simulation Chamber',
                'AtlasAutonomousRealitySandboxService',
            ]),
            $this->fileCheck('runtime_service', 'app/Services/Ai/RealitySandbox/AtlasAutonomousRealitySandboxService.php', [
                AtlasAutonomousRealitySandboxService::SCENARIO_SCHEMA,
                AtlasAutonomousRealitySandboxService::SIMULATION_SCHEMA,
                AtlasAutonomousRealitySandboxService::COUNTERFACTUAL_SCHEMA,
                AtlasAutonomousRealitySandboxService::RISK_SCHEMA,
            ]),
            $this->fileCheck('persistence', 'database/migrations/2026_05_20_220000_create_atlas_aars_tables.php', [
                'atlas_aars_scenarios',
                'atlas_aars_simulations',
                'atlas_aars_counterfactuals',
                'atlas_aars_risk_projections',
                'atlas_aars_certifications',
            ]),
            $this->fileCheck('models', 'app/Models/AtlasAarsScenario.php', ['AtlasAarsScenario']),
            $this->runtimeSmoke(),
            $this->criticalRiskSmoke(),
            $this->claimPolicyCheck(),
            $this->fileCheck('sidecar_integration', 'app/Services/Ai/RealitySandbox/AtlasAutonomousRealitySandboxService.php', [
                'AtlasStrategicRealityRuntimeService',
                'AtlasIntelligenceFactoryRuntimeService',
                'AtlasAutonomousEvolutionLoopService',
                'sidecarControlPlane',
            ]),
            $this->fileCheck('commands', 'app/Console/Commands/AtlasAarsCommand.php', ['atlas:aars']),
            $this->fileCheck('certify_command', 'app/Console/Commands/AtlasAarsCertifyCommand.php', ['atlas:aars:certify']),
            $this->fileCheck('tests', 'tests/Feature/Ai/RealitySandbox/AtlasAutonomousRealitySandboxServiceTest.php', ['test_run_creates_full_reality_sandbox']),
            $this->fileCheck('control_plane', 'app/Services/Ai/ControlPlane/AtlasAiControlPlaneService.php', ['autonomous_reality_sandbox', 'aars_scenarios_total']),
        ];
        $failed = collect($checks)->where('status', 'fail')->values()->all();
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $failed === [] ? 'passed' : 'blocked',
            'summary' => [
                'total' => count($checks),
                'pass' => count($checks) - count($failed),
                'fail' => count($failed),
            ],
            'checks' => $checks,
            'blockers' => $failed,
            'claim_policy' => $this->runtime->claimPolicy(),
            'scope' => [
                'covers' => 'AARS L1-L10: scenario, reality twin simulation, counterfactual replay, impact model, risk projection, certification, control plane and downstream-gate recommendation.',
                'does_not_cover' => 'real execution, provider calls, benchmark claims, external action or automatic production promotion.',
            ],
        ];
        $payload['certification_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<int,string>  $tokens
     * @return array<string,mixed>
     */
    private function fileCheck(string $id, string $path, array $tokens): array
    {
        $fullPath = base_path($path);
        $source = File::exists($fullPath) ? File::get($fullPath) : '';
        $missing = array_values(array_filter($tokens, fn (string $token): bool => ! str_contains($source, $token)));

        return [
            'id' => $id,
            'status' => File::exists($fullPath) && $missing === [] ? 'pass' : 'fail',
            'evidence' => [$path],
            'missing' => $missing,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimeSmoke(): array
    {
        if (! $this->tablesReady()) {
            return ['id' => 'runtime_smoke', 'status' => 'fail', 'reason' => 'aars_tables_missing'];
        }

        $result = DB::transaction(function (): array {
            $payload = $this->runtime->run([
                'objective' => 'simulate safe engineering improvement before execution',
                'domain' => 'programming',
                'evidence_refs' => ['certification:aars_runtime_smoke'],
            ]);
            DB::rollBack();

            return $payload;
        });

        $passed = ($result['status'] ?? null) === AtlasAutonomousRealitySandboxService::STATUS_READY
            && filled(data_get($result, 'simulation.simulation_hash'))
            && filled(data_get($result, 'counterfactual.counterfactual_hash'))
            && filled(data_get($result, 'risk_projection.risk_hash'))
            && filled(data_get($result, 'certification.certification_hash'));

        return [
            'id' => 'runtime_smoke',
            'status' => $passed ? 'pass' : 'fail',
            'evidence' => [
                'run_hash' => $result['run_hash'] ?? null,
                'simulation_hash' => data_get($result, 'simulation.simulation_hash'),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function criticalRiskSmoke(): array
    {
        if (! $this->tablesReady()) {
            return ['id' => 'critical_risk_smoke', 'status' => 'fail', 'reason' => 'aars_tables_missing'];
        }

        $result = DB::transaction(function (): array {
            $payload = $this->runtime->run([
                'objective' => 'simulate production payment deletion',
                'domain' => 'finance',
                'force_critical' => true,
                'evidence_refs' => ['certification:aars_critical_risk'],
            ]);
            DB::rollBack();

            return $payload;
        });
        $passed = ($result['status'] ?? null) === AtlasAutonomousRealitySandboxService::STATUS_BLOCKED
            && data_get($result, 'risk_projection.risk_level') === 'critical'
            && data_get($result, 'release_recommendation.real_execution_allowed') === false;

        return [
            'id' => 'critical_risk_smoke',
            'status' => $passed ? 'pass' : 'fail',
            'evidence' => [
                'run_hash' => $result['run_hash'] ?? null,
                'risk_level' => data_get($result, 'risk_projection.risk_level'),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function claimPolicyCheck(): array
    {
        $policy = $this->runtime->claimPolicy();
        $passed = ($policy['external_execution_performed'] ?? true) === false
            && ($policy['provider_invoked'] ?? true) === false
            && ($policy['benchmark_not_run'] ?? false) === true
            && ($policy['simulation_is_not_truth'] ?? false) === true
            && ($policy['requires_aver_before_real_execution'] ?? false) === true;

        return [
            'id' => 'claim_policy',
            'status' => $passed ? 'pass' : 'fail',
            'evidence' => $policy,
        ];
    }

    private function tablesReady(): bool
    {
        foreach ([
            'atlas_aars_scenarios',
            'atlas_aars_simulations',
            'atlas_aars_counterfactuals',
            'atlas_aars_risk_projections',
            'atlas_aars_certifications',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }
}

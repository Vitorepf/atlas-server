<?php

namespace App\Services\Ai\VerifiedExecution;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

final class AtlasVerifiedExecutionCertificationService
{
    public const SCHEMA_VERSION = 'atlas.aver.certification.v1';

    public function __construct(
        private readonly AtlasVerifiedExecutionRuntimeService $runtime,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function certify(): array
    {
        $checks = [
            $this->fileCheck('canonical_doc', 'docs/engineering-knowledge-base/atlas-verified-execution-runtime.md', ['Atlas Verified Execution Runtime', 'AVER', 'Atlas Execution Cockpit']),
            $this->fileCheck('runtime_service', 'app/Services/Ai/VerifiedExecution/AtlasVerifiedExecutionRuntimeService.php', ['executeFixtureCycle', 'planFromVerifiedEvolutionContract', 'safetyGateForCommand', 'certify']),
            $this->fileCheck('persistence', 'database/migrations/2026_05_20_200000_create_atlas_aver_tables.php', ['atlas_aver_executions', 'atlas_aver_command_ledgers', 'atlas_aver_certified_executions']),
            $this->fileCheck('models', 'app/Models/AtlasAverExecution.php', ['AtlasAverExecution', 'commandLedgers', 'certifiedExecution']),
            $this->runtimeSmoke(),
            $this->verifiedEvolutionContractSmoke(),
            $this->blockedCommandSmoke(),
            $this->failedTestRepairSmoke(),
            $this->certifiedFixtureSmoke(),
            $this->fileCheck('commands', 'app/Console/Commands/AtlasAverCommand.php', ['atlas:aver', 'plan-from-verified-evolution', 'fixture-cycle', 'run-command']),
            $this->fileCheck('certify_command', 'app/Console/Commands/AtlasAverCertifyCommand.php', ['atlas:aver:certify']),
            $this->fileCheck('tests', 'tests/Feature/Ai/VerifiedExecution/AtlasVerifiedExecutionRuntimeServiceTest.php', ['test_fixture_cycle_certifies_gold_execution', 'test_dangerous_command_is_blocked']),
            $this->integrationWiring(),
            $this->claimPolicy(),
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
                'covers' => 'AVER L1-L10 verified execution: safety gate, command ledger, diff ledger, test ledger, repair cycle, rollback plan, certification and control-plane readiness.',
                'does_not_cover' => 'unreviewed destructive commands, provider calls, external side effects or benchmark claims.',
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
        $missing = array_values(array_filter($tokens, fn (string $token): bool => ! str_contains($contents, $token)));

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
        $payload = $this->withoutPersistingSmoke(fn (): array => $this->runtime->plan([
            'objective' => 'AVER runtime smoke',
            'domain' => 'programming',
            'flow_id' => 'atlas_dev',
            'evidence_refs' => ['test:aver_runtime_smoke'],
        ]));
        $ok = ($payload['schema_version'] ?? null) === AtlasVerifiedExecutionRuntimeService::EXECUTION_SCHEMA
            && ($payload['maturity_level'] ?? null) === AtlasVerifiedExecutionRuntimeService::LEVEL_MAX
            && isset($payload['execution_contract'], $payload['patch_plan'], $payload['safety_gate'], $payload['verification_plan']);

        return ['id' => 'runtime_smoke', 'status' => $ok ? 'pass' : 'fail', 'evidence' => ['execution_hash' => $payload['execution_hash'] ?? null]];
    }

    /**
     * @return array<string,mixed>
     */
    private function verifiedEvolutionContractSmoke(): array
    {
        $contract = [
            'schema_version' => 'atlas.verified_evolution.execution_contract.v1',
            'status' => 'ready',
            'execution_contract' => [
                'status' => 'ready_for_aver_plan',
                'aver_plan_input' => [
                    'objective' => 'AVER from AVEOR certification smoke',
                    'domain' => 'programming',
                    'flow_id' => 'atlas_dev',
                    'surface_id' => 'atlas_ai',
                    'allowed_write_paths' => ['app/Services/Ai/VerifiedExecution/AtlasVerifiedExecutionRuntimeService.php'],
                    'evidence_refs' => ['test:aver_from_aveor'],
                    'verification_plan' => [
                        'required_gates' => ['git diff --check'],
                    ],
                ],
            ],
        ];
        $payload = $this->withoutPersistingSmoke(fn (): array => $this->runtime->planFromVerifiedEvolutionContract($contract));
        $ok = ($payload['schema_version'] ?? null) === AtlasVerifiedExecutionRuntimeService::EXECUTION_SCHEMA
            && ($payload['status'] ?? null) === AtlasVerifiedExecutionRuntimeService::STATUS_READY
            && data_get($payload, 'execution_contract.source_verified_evolution_schema') === 'atlas.verified_evolution.execution_contract.v1';

        return ['id' => 'verified_evolution_contract_smoke', 'status' => $ok ? 'pass' : 'fail', 'evidence' => ['execution_hash' => $payload['execution_hash'] ?? null]];
    }

    /**
     * @return array<string,mixed>
     */
    private function blockedCommandSmoke(): array
    {
        $payload = $this->withoutPersistingSmoke(function (): array {
            $execution = $this->runtime->plan(['objective' => 'blocked command smoke']);

            return $this->runtime->runCommand([
                'execution_id' => $execution['execution_id'] ?? null,
                'command' => 'rm -rf /',
                'cwd' => base_path(),
            ]);
        });

        return [
            'id' => 'blocked_command_smoke',
            'status' => ($payload['status'] ?? null) === AtlasVerifiedExecutionRuntimeService::STATUS_BLOCKED ? 'pass' : 'fail',
            'evidence' => ['blockers' => data_get($payload, 'safety_gate.blockers', [])],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function failedTestRepairSmoke(): array
    {
        $payload = $this->withoutPersistingSmoke(fn (): array => $this->runtime->executeFixtureCycle(['simulate_test_failure' => true]));
        $ok = ($payload['status'] ?? null) === AtlasVerifiedExecutionRuntimeService::STATUS_BLOCKED
            && data_get($payload, 'repair_cycle.schema_version') === AtlasVerifiedExecutionRuntimeService::REPAIR_CYCLE_SCHEMA;

        return ['id' => 'failed_test_repair_smoke', 'status' => $ok ? 'pass' : 'fail', 'evidence' => ['repair_hash' => data_get($payload, 'repair_cycle.repair_hash')]];
    }

    /**
     * @return array<string,mixed>
     */
    private function certifiedFixtureSmoke(): array
    {
        $payload = $this->withoutPersistingSmoke(fn (): array => $this->runtime->executeFixtureCycle());
        $ok = ($payload['status'] ?? null) === AtlasVerifiedExecutionRuntimeService::STATUS_PASSED
            && data_get($payload, 'certification.certification_level') === 'gold'
            && data_get($payload, 'sandbox.workspace_cleaned') === true;

        return ['id' => 'certified_fixture_smoke', 'status' => $ok ? 'pass' : 'fail', 'evidence' => ['certification_hash' => data_get($payload, 'certification.certification_hash')]];
    }

    /**
     * @return array<string,mixed>
     */
    private function integrationWiring(): array
    {
        $aweos = base_path('app/Services/Ai/AutonomousWorkExecution/AtlasAutonomousWorkExecutionService.php');
        $controlPlane = base_path('app/Services/Ai/ControlPlane/AtlasAiControlPlaneService.php');
        $ok = $this->contains($aweos, 'AtlasVerifiedExecutionRuntimeService')
            && $this->contains($aweos, 'verified_execution')
            && $this->contains($controlPlane, 'verified_execution');

        return [
            'id' => 'integration_wiring',
            'status' => $ok ? 'pass' : 'fail',
            'evidence' => [
                'app/Services/Ai/AutonomousWorkExecution/AtlasAutonomousWorkExecutionService.php',
                'app/Services/Ai/ControlPlane/AtlasAiControlPlaneService.php',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function claimPolicy(): array
    {
        $policy = $this->runtime->claimPolicy();
        $ok = ($policy['executes_only_after_safety_gate'] ?? false) === true
            && ($policy['provider_invoked_directly'] ?? true) === false
            && ($policy['destructive_commands_blocked'] ?? false) === true
            && ($policy['completion_requires_diff_command_test_evidence'] ?? false) === true;

        return ['id' => 'claim_policy', 'status' => $ok ? 'pass' : 'fail', 'evidence' => $policy];
    }

    /**
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    private function withoutPersistingSmoke(callable $callback): mixed
    {
        return DB::transaction(function () use ($callback): mixed {
            $result = $callback();
            DB::rollBack();

            return $result;
        });
    }

    private function contains(string $path, string $token): bool
    {
        return File::exists($path) && str_contains((string) File::get($path), $token);
    }
}

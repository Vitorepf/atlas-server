<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousWorkExecution;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

final class AtlasAutonomousWorkExecutionCertificationService
{
    public const SCHEMA_VERSION = 'atlas.aweos.certification.v1';

    /**
     * @return array<string,mixed>
     */
    public function certify(): array
    {
        $checks = [
            $this->fileCheck('canonical_doc', 'docs/engineering-knowledge-base/atlas-autonomous-work-execution-os.md', ['Atlas Autonomous Work Execution OS', 'AWEOS', 'Atlas Mission Control']),
            $this->fileCheck('runtime_service', 'app/Services/Ai/AutonomousWorkExecution/AtlasAutonomousWorkExecutionService.php', ['AtlasAutonomousWorkExecutionService', 'Perfect Context Spine', 'certifyOutcome']),
            $this->fileCheck('persistence', 'database/migrations/2026_05_20_190000_create_atlas_aweos_tables.php', ['atlas_aweos_executions', 'atlas_aweos_certified_outcomes']),
            $this->fileCheck('models', 'app/Models/AtlasAweosExecution.php', ['AtlasAweosExecution', 'certifiedOutcome']),
            $this->runtimeSmoke(),
            $this->blockedOutcomeSmoke(),
            $this->certifiedOutcomeSmoke(),
            $this->fileCheck('commands', 'app/Console/Commands/AtlasAweosCommand.php', ['atlas:aweos', 'run', 'certify-outcome']),
            $this->fileCheck('certify_command', 'app/Console/Commands/AtlasAweosCertifyCommand.php', ['atlas:aweos:certify']),
            $this->fileCheck('tests', 'tests/Feature/Ai/AutonomousWorkExecution/AtlasAutonomousWorkExecutionServiceTest.php', ['test_run_creates_maximum_aweos_cycle', 'test_certified_outcome_requires_evidence']),
            $this->integrationWiringCheck(),
            $this->claimPolicyCheck(),
        ];

        $failed = array_values(array_filter($checks, fn (array $check): bool => $check['status'] !== 'pass'));
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
            'claim_policy' => app(AtlasAutonomousWorkExecutionService::class)->claimPolicy(),
            'scope' => [
                'covers' => 'AWEOS L1-L10 planning loop, APCR/AREG/AAWR/AEMOR orchestration, mission control, continuation, certification and control-plane readiness.',
                'does_not_cover' => 'direct provider execution, external side effects, benchmark claims or bypassing Dev/Forge.',
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
        $payload = $this->withoutPersistingSmoke(fn (): array => app(AtlasAutonomousWorkExecutionService::class)->run([
            'objective' => 'implementar smoke AWEOS com contexto, workcell, verificacao e certificacao',
            'domain' => 'programming',
            'flow_id' => 'atlas_dev',
            'evidence_refs' => ['test:aweos_runtime_smoke'],
        ]));

        $ok = ($payload['schema_version'] ?? null) === AtlasAutonomousWorkExecutionService::EXECUTION_SCHEMA
            && ($payload['maturity_level'] ?? null) === AtlasAutonomousWorkExecutionService::LEVEL_MAX
            && isset($payload['persistent_context'], $payload['runtime_efficiency'], $payload['agentic_workcell'], $payload['aemor_episode'], $payload['execution_plan']);

        return [
            'id' => 'runtime_smoke',
            'status' => $ok ? 'pass' : 'fail',
            'evidence' => [
                'status' => $payload['status'] ?? null,
                'execution_hash' => $payload['execution_hash'] ?? null,
                'writes' => $payload['writes'] ?? null,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function blockedOutcomeSmoke(): array
    {
        $outcome = $this->withoutPersistingSmoke(function (): array {
            $runtime = app(AtlasAutonomousWorkExecutionService::class);
            $execution = $runtime->run(['objective' => 'AWEOS blocked outcome smoke', 'domain' => 'research']);

            return $runtime->certifyOutcome(['execution_id' => $execution['execution_id'] ?? null, 'claim' => 'blocked smoke']);
        });

        return [
            'id' => 'blocked_outcome_smoke',
            'status' => ($outcome['status'] ?? null) === AtlasAutonomousWorkExecutionService::STATUS_BLOCKED ? 'pass' : 'fail',
            'evidence' => ['certification_level' => $outcome['certification_level'] ?? null],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function certifiedOutcomeSmoke(): array
    {
        $outcome = $this->withoutPersistingSmoke(function (): array {
            $runtime = app(AtlasAutonomousWorkExecutionService::class);
            $execution = $runtime->run(['objective' => 'AWEOS certified outcome smoke', 'domain' => 'programming', 'evidence_refs' => ['test:initial']]);

            return $runtime->certifyOutcome([
                'execution_id' => $execution['execution_id'] ?? null,
                'claim' => 'AWEOS certified smoke completed.',
                'evidence_refs' => ['test:aweos_certified_outcome'],
                'command_ledger' => [['command' => 'php artisan test --filter=AWEOS', 'exit_code' => 0]],
                'test_impact' => [['test' => 'AWEOS smoke', 'status' => 'passed']],
                'quality_score' => 0.93,
            ]);
        });

        return [
            'id' => 'certified_outcome_smoke',
            'status' => ($outcome['certification_level'] ?? null) === 'gold' ? 'pass' : 'fail',
            'evidence' => ['outcome_hash' => $outcome['outcome_hash'] ?? null],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function claimPolicyCheck(): array
    {
        $policy = app(AtlasAutonomousWorkExecutionService::class)->claimPolicy();
        $ok = ($policy['provider_invoked_directly'] ?? true) === false
            && ($policy['external_execution_performed_directly'] ?? true) === false
            && ($policy['benchmark_not_run'] ?? false) === true
            && ($policy['completion_requires_certified_outcome'] ?? false) === true;

        return [
            'id' => 'claim_policy',
            'status' => $ok ? 'pass' : 'fail',
            'evidence' => $policy,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function integrationWiringCheck(): array
    {
        $hyperflow = 'app/Services/Ai/RouterRuntime/AtlasHyperflowEntryService.php';
        $controlPlane = 'app/Services/Ai/ControlPlane/AtlasAiControlPlaneService.php';
        $test = 'tests/Feature/Ai/AutonomousWorkExecution/AtlasAutonomousWorkExecutionServiceTest.php';

        $ok = $this->contains(base_path($hyperflow), 'buildAutonomousWorkExecution')
            && $this->contains(base_path($hyperflow), 'autonomous_work_execution')
            && $this->contains(base_path($controlPlane), 'autonomous_work_execution')
            && $this->contains(base_path($test), 'test_control_plane_exposes_aweos_runtime_section');

        return [
            'id' => 'integration_wiring',
            'status' => $ok ? 'pass' : 'fail',
            'evidence' => [$hyperflow, $controlPlane, $test],
        ];
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

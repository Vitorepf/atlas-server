<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use Illuminate\Support\Facades\File;

final class AtlasSoftwareTwinVerifiedEvolutionCertificationService
{
    public const SCHEMA_VERSION = 'atlas.software_twin_verified_evolution.certification.v1';

    public function __construct(
        private readonly AtlasSoftwareTwinRuntimeService $softwareTwin,
        private readonly AtlasVerifiedEvolutionRuntimeService $verifiedEvolution,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function certify(): array
    {
        $target = 'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php';
        $objective = 'certify Atlas Software Twin and Verified Evolution Runtime';
        $checks = [
            $this->fileCheck('canonical_doc', 'docs/engineering-knowledge-base/atlas-software-twin-verified-evolution-runtime.md'),
            $this->fileCheck('software_twin_service', 'app/Services/Engineering/AtlasSoftwareTwinRuntimeService.php'),
            $this->fileCheck('verified_evolution_service', 'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php'),
            $this->fileCheck('software_twin_command', 'app/Console/Commands/AtlasSoftwareTwinCommand.php'),
            $this->fileCheck('verified_evolution_command', 'app/Console/Commands/AtlasVerifiedEvolutionCommand.php'),
            $this->fileCheck('feature_tests', 'tests/Feature/Engineering/AtlasSoftwareTwinVerifiedEvolutionRuntimeServiceTest.php'),
            $this->fileCheck('software_twin_snapshot_persistence', 'database/migrations/2026_05_21_190000_create_atlas_software_twin_snapshots_table.php', ['atlas_software_twin_snapshots', 'snapshot_hash']),
            $this->fileCheck('aver_consumes_verified_evolution_contract', 'app/Services/Ai/VerifiedExecution/AtlasVerifiedExecutionRuntimeService.php', ['planFromVerifiedEvolutionContract', 'source_verified_evolution_contract_hash']),
            $this->runtimeCheck('software_twin_quality', $this->softwareTwin->qualityScore()),
            $this->runtimeCheck('software_twin_impact', $this->softwareTwin->impact($target)),
            $this->runtimeCheck('verified_evolution_boundary', $this->verifiedEvolution->boundaryContract($objective, $target)),
            $this->runtimeCheck('verified_evolution_proof_plan', $this->verifiedEvolution->proofPlan($objective, $target)),
            $this->runtimeCheck('verified_evolution_execution_contract', $this->verifiedEvolution->executionContract($objective, $target)),
            $this->runtimeCheck('verified_evolution_scope_drift_watch', $this->verifiedEvolution->driftWatch($objective, $target, [$target])),
            $this->runtimeCheck('verified_evolution_patch_simulation', $this->verifiedEvolution->patchSimulation($objective, $target, [$target])),
            $this->outcomeBridgeShapeCheck($this->verifiedEvolution->outcomeBridge($objective, $target)),
            $this->runtimeCheck('verified_evolution_quality', $this->verifiedEvolution->qualityScore($objective, $target)),
        ];
        $failed = array_values(array_filter($checks, static fn (array $check): bool => $check['status'] !== 'passed'));

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $failed === [] ? 'ready' : 'blocked',
            'summary' => [
                'total' => count($checks),
                'passed' => count($checks) - count($failed),
                'failed' => count($failed),
            ],
            'checks' => $checks,
            'remaining_blockers' => array_map(static fn (array $check): string => (string) $check['id'], $failed),
            'claim_policy' => [
                'read_only' => true,
                'providers_invoked' => false,
                'rivals_run' => false,
                'executes_commands' => false,
                'authorizes_mutation' => false,
                'declares_full_autonomous_mutation' => false,
                'scope' => 'read_only_software_twin_and_verified_evolution_runtime_gate',
            ],
            'writes' => false,
        ];
        $payload['certification_hash'] = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function fileCheck(string $id, string $path, array $tokens = []): array
    {
        $fullPath = base_path($path);
        $contents = File::exists($fullPath) ? (string) File::get($fullPath) : '';
        $missing = array_values(array_filter($tokens, static fn (string $token): bool => ! str_contains($contents, $token)));

        return [
            'id' => $id,
            'status' => File::exists($fullPath) && $missing === [] ? 'passed' : 'failed',
            'evidence' => [
                'path' => $path,
                'exists' => File::exists($fullPath),
                'missing_tokens' => $missing,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function runtimeCheck(string $id, array $payload): array
    {
        return [
            'id' => $id,
            'status' => ($payload['status'] ?? null) === 'ready' ? 'passed' : 'failed',
            'evidence' => [
                'schema_version' => $payload['schema_version'] ?? null,
                'runtime_status' => $payload['status'] ?? null,
                'writes' => $payload['writes'] ?? null,
                'hash' => $payload['certification_hash'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function outcomeBridgeShapeCheck(array $payload): array
    {
        $ok = ($payload['schema_version'] ?? null) === AtlasVerifiedEvolutionRuntimeService::OUTCOME_BRIDGE_SCHEMA_VERSION
            && ($payload['status'] ?? null) === 'blocked'
            && data_get($payload, 'outcome_bridge.reason') === 'missing_evidence_refs'
            && ($payload['writes'] ?? false) === false;

        return [
            'id' => 'verified_evolution_outcome_bridge_shape',
            'status' => $ok ? 'passed' : 'failed',
            'evidence' => [
                'schema_version' => $payload['schema_version'] ?? null,
                'runtime_status' => $payload['status'] ?? null,
                'reason' => data_get($payload, 'outcome_bridge.reason'),
                'writes' => $payload['writes'] ?? null,
                'hash' => $payload['certification_hash'] ?? null,
            ],
        ];
    }
}

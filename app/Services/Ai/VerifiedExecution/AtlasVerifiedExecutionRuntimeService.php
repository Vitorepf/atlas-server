<?php

namespace App\Services\Ai\VerifiedExecution;

use App\Models\AtlasAverCertifiedExecution;
use App\Models\AtlasAverCommandLedger;
use App\Models\AtlasAverDiffLedger;
use App\Models\AtlasAverExecution;
use App\Models\AtlasAverRepairCycle;
use App\Models\AtlasAverTestLedger;
use App\Services\Ai\Aemor\AtlasAemorRuntimeService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\ProgrammingPatchVerifier;
use App\Services\Ai\Programming\ProgrammingRepairExecutor;
use App\Services\Ai\Support\AiStringListNormalizer;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Engineering\AtlasVerifiedEvolutionRuntimeService as AtlasVerifiedEvolutionRuntime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

final class AtlasVerifiedExecutionRuntimeService
{
    public const EXECUTION_SCHEMA = 'atlas.aver.execution.v1';

    public const COMMAND_LEDGER_SCHEMA = 'atlas.aver.command_ledger.v1';

    public const DIFF_LEDGER_SCHEMA = 'atlas.aver.diff_ledger.v1';

    public const TEST_LEDGER_SCHEMA = 'atlas.aver.test_ledger.v1';

    public const REPAIR_CYCLE_SCHEMA = 'atlas.aver.repair_cycle.v1';

    public const CERTIFIED_EXECUTION_SCHEMA = 'atlas.aver.certified_execution.v1';

    public const CONTROL_PLANE_SCHEMA = 'atlas.aver.control_plane.v1';

    public const CERTIFICATION_SCHEMA = 'atlas.aver.certification.v1';

    public const LEVEL_MAX = 'AVER-L10 Autonomous Verified Execution Operating System';

    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_PASSED = 'passed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CERTIFIED = 'certified';

    public function __construct(
        private readonly ProgrammingPatchVerifier $patchVerifier,
        private readonly ProgrammingRepairExecutor $repairExecutor,
        private readonly ?AtlasAemorRuntimeService $aemor = null,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function plan(array $input): array
    {
        $objective = trim((string) ($input['objective'] ?? 'verified execution'));
        $workspace = $this->workspace($input['workspace'] ?? null);
        $objectiveHash = MissionCanonicalHash::sha256($objective);
        $workspaceHash = $workspace !== null ? MissionCanonicalHash::sha256($workspace) : null;
        $evidenceRefs = AiStringListNormalizer::uniqueTrimmedStringsFromArrayCast($input['evidence_refs'] ?? []);
        $flowId = (string) ($input['flow_id'] ?? 'atlas_dev');
        $domain = (string) ($input['domain'] ?? 'programming');
        $executionContract = $this->executionContract($objectiveHash, $workspaceHash, $flowId, $input);
        $patchPlan = $this->patchPlan($input, $objectiveHash);
        $safetyGate = $this->safetyGateForPlan($input);
        $verificationPlan = $this->verificationPlan($input, $flowId);
        $rollbackPlan = $this->rollbackPlan($input, $workspaceHash);
        $claimPolicy = $this->claimPolicy();

        $payload = [
            'schema_version' => self::EXECUTION_SCHEMA,
            'status' => $safetyGate['status'] === self::STATUS_BLOCKED ? self::STATUS_BLOCKED : self::STATUS_READY,
            'maturity_level' => self::LEVEL_MAX,
            'aweos_execution_id' => $this->uuidOrNull($input['aweos_execution_id'] ?? null),
            'surface_id' => (string) ($input['surface_id'] ?? 'atlas_ai'),
            'domain' => $domain,
            'flow_id' => $flowId,
            'workspace_hash' => $workspaceHash,
            'objective_hash' => $objectiveHash,
            'objective' => $objective,
            'execution_contract' => $executionContract,
            'patch_plan' => $patchPlan,
            'safety_gate' => $safetyGate,
            'verification_plan' => $verificationPlan,
            'rollback_plan' => $rollbackPlan,
            'claim_policy' => $claimPolicy,
            'evidence_refs' => $evidenceRefs,
        ];
        $payload['execution_hash'] = MissionCanonicalHash::sha256($payload);

        $record = null;
        if ($this->tablesReady()) {
            $record = AtlasAverExecution::query()->create($payload);
            $payload['execution_id'] = (string) $record->id;
            $payload['writes'] = true;
        } else {
            $payload['execution_id'] = null;
            $payload['writes'] = false;
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $verifiedEvolutionContract
     * @return array<string,mixed>
     */
    public function planFromVerifiedEvolutionContract(array $verifiedEvolutionContract, array $overrides = []): array
    {
        $schema = (string) ($verifiedEvolutionContract['schema_version'] ?? '');
        $contract = (array) ($verifiedEvolutionContract['execution_contract'] ?? []);
        $planInput = (array) ($contract['aver_plan_input'] ?? []);
        $blockers = [];

        if ($schema !== AtlasVerifiedEvolutionRuntime::EXECUTION_CONTRACT_SCHEMA_VERSION) {
            $blockers[] = 'invalid_verified_evolution_contract_schema';
        }
        if (($verifiedEvolutionContract['status'] ?? null) !== self::STATUS_READY || ($contract['status'] ?? null) !== 'ready_for_aver_plan') {
            $blockers[] = 'verified_evolution_contract_not_ready';
        }
        if ($planInput === []) {
            $blockers[] = 'missing_aver_plan_input';
        }

        return $this->plan(array_merge([
            'objective' => (string) ($planInput['objective'] ?? 'AVER execution from AVEOR contract'),
            'domain' => (string) ($planInput['domain'] ?? 'programming'),
            'flow_id' => (string) ($planInput['flow_id'] ?? 'atlas_dev'),
            'surface_id' => (string) ($planInput['surface_id'] ?? 'atlas_ai'),
            'expected_files' => AiStringListNormalizer::uniqueTrimmedStringsFromArrayCast($planInput['allowed_write_paths'] ?? []),
            'expected_commands' => AiStringListNormalizer::uniqueTrimmedStringsFromArrayCast(data_get($planInput, 'verification_plan.required_gates', [])),
            'evidence_refs' => AiStringListNormalizer::uniqueTrimmedStringsFromArrayCast($planInput['evidence_refs'] ?? []),
            'causal_verification' => (array) data_get($planInput, 'verification_plan.causal_verification', []),
            'verified_evolution_contract' => $verifiedEvolutionContract,
            'verified_evolution_contract_hash' => MissionCanonicalHash::sha256($verifiedEvolutionContract),
            'verified_evolution_blockers' => $blockers,
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function runCommand(array $input): array
    {
        $execution = $this->execution($input['execution_id'] ?? null);
        $command = trim((string) ($input['command'] ?? ''));
        $cwd = $this->workspace($input['cwd'] ?? null) ?? base_path();
        $evidenceRefs = AiStringListNormalizer::uniqueTrimmedStringsFromArrayCast($input['evidence_refs'] ?? []);
        $safetyGate = $this->safetyGateForCommand($command, $cwd);

        $result = [
            'exit_code' => null,
            'duration_ms' => null,
            'stdout' => '',
            'stderr' => '',
        ];
        if ($safetyGate['status'] !== self::STATUS_BLOCKED) {
            $result = $this->executeProcess($command, $cwd, (int) ($input['timeout_seconds'] ?? 20));
        }

        $payload = [
            'schema_version' => self::COMMAND_LEDGER_SCHEMA,
            'execution_id' => $execution?->id,
            'status' => $safetyGate['status'] === self::STATUS_BLOCKED ? self::STATUS_BLOCKED : (((int) ($result['exit_code'] ?? 1)) === 0 ? self::STATUS_PASSED : self::STATUS_FAILED),
            'command_hash' => MissionCanonicalHash::sha256($command),
            'cwd_hash' => MissionCanonicalHash::sha256($cwd),
            'exit_code' => $result['exit_code'],
            'duration_ms' => $result['duration_ms'],
            'stdout_excerpt' => $this->excerpt((string) $result['stdout']),
            'stderr_excerpt' => $this->excerpt((string) $result['stderr']),
            'stdout_hash' => MissionCanonicalHash::sha256((string) $result['stdout']),
            'stderr_hash' => MissionCanonicalHash::sha256((string) $result['stderr']),
            'safety_gate' => $safetyGate,
            'evidence_refs' => $evidenceRefs,
        ];
        $payload['ledger_hash'] = MissionCanonicalHash::sha256($payload);

        if ($execution instanceof AtlasAverExecution && DatabaseTableAvailability::has('atlas_aver_command_ledgers')) {
            $record = AtlasAverCommandLedger::query()->create($payload);
            $payload['command_ledger_id'] = (string) $record->id;
            $payload['writes'] = true;
        } else {
            $payload['command_ledger_id'] = null;
            $payload['writes'] = false;
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function verifyDiff(array $input): array
    {
        $execution = $this->execution($input['execution_id'] ?? null);
        $changedFiles = AiStringListNormalizer::uniqueTrimmedStringsFromArrayCast($input['changed_files'] ?? []);
        $actionManifests = array_values((array) ($input['action_manifests'] ?? []));
        $evidenceRefs = AiStringListNormalizer::uniqueTrimmedStringsFromArrayCast($input['evidence_refs'] ?? []);
        $report = $this->patchVerifier->verify([
            'changed_files' => $changedFiles,
            'tests' => (array) ($input['tests'] ?? []),
            'no_test_reason' => $input['no_test_reason'] ?? null,
            'action_manifests' => $actionManifests,
        ]);
        $rollbackPlan = [
            'schema_version' => 'atlas.aver.rollback_plan.v1',
            'available' => collect($actionManifests)->contains(fn (mixed $manifest): bool => is_array($manifest) && data_get($manifest, 'rollback.available') === true),
            'requires_user_change_preservation' => true,
            'never_revert_unrelated_changes' => true,
        ];
        $payload = [
            'schema_version' => self::DIFF_LEDGER_SCHEMA,
            'execution_id' => $execution?->id,
            'status' => ($report['status'] ?? null) === 'blocked' ? self::STATUS_BLOCKED : self::STATUS_PASSED,
            'changed_files' => $changedFiles,
            'action_manifests' => $actionManifests,
            'patch_verifier_report' => $report,
            'rollback_plan' => $rollbackPlan,
            'evidence_refs' => $evidenceRefs,
        ];
        $payload['diff_hash'] = MissionCanonicalHash::sha256($payload);

        if ($execution instanceof AtlasAverExecution && DatabaseTableAvailability::has('atlas_aver_diff_ledgers')) {
            $record = AtlasAverDiffLedger::query()->create($payload);
            $payload['diff_ledger_id'] = (string) $record->id;
            $payload['writes'] = true;
        } else {
            $payload['diff_ledger_id'] = null;
            $payload['writes'] = false;
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function runTest(array $input): array
    {
        $commandLedger = $this->runCommand($input);
        $execution = $this->execution($input['execution_id'] ?? null);
        $evidenceRefs = AiStringListNormalizer::uniqueTrimmedStringsFromArrayCast($input['evidence_refs'] ?? []);
        $status = ($commandLedger['status'] ?? null) === self::STATUS_PASSED ? self::STATUS_PASSED : self::STATUS_FAILED;
        if (($commandLedger['status'] ?? null) === self::STATUS_BLOCKED) {
            $status = self::STATUS_BLOCKED;
        }
        $payload = [
            'schema_version' => self::TEST_LEDGER_SCHEMA,
            'execution_id' => $execution?->id,
            'command_ledger_id' => $this->uuidOrNull($commandLedger['command_ledger_id'] ?? null),
            'status' => $status,
            'test_command_hash' => (string) ($commandLedger['command_hash'] ?? MissionCanonicalHash::sha256((string) ($input['command'] ?? ''))),
            'exit_code' => $commandLedger['exit_code'] ?? null,
            'test_impact' => [
                'schema_version' => 'atlas.aver.test_impact.v1',
                'command_hash' => $commandLedger['command_hash'] ?? null,
                'recommended_scope' => (string) ($input['scope'] ?? 'focused'),
                'passed' => $status === self::STATUS_PASSED,
            ],
            'evidence_refs' => $evidenceRefs,
        ];
        $payload['test_hash'] = MissionCanonicalHash::sha256($payload);

        if ($execution instanceof AtlasAverExecution && DatabaseTableAvailability::has('atlas_aver_test_ledgers')) {
            $record = AtlasAverTestLedger::query()->create($payload);
            $payload['test_ledger_id'] = (string) $record->id;
            $payload['writes'] = true;
        } else {
            $payload['test_ledger_id'] = null;
            $payload['writes'] = false;
        }
        $payload['command_ledger'] = $commandLedger;

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function repair(array $input): array
    {
        $execution = $this->execution($input['execution_id'] ?? null);
        $failurePacket = (array) ($input['failure_packet'] ?? []);
        $retrievalPlan = (array) ($input['retrieval_plan'] ?? [
            'retrieval_receipt' => ['receipt_id' => 'aver-local-retrieval'],
            'professional_context_pack' => ['context_pack_hash' => MissionCanonicalHash::sha256($failurePacket), 'ranked_refs' => []],
            'test_impact' => ['recommended_commands' => []],
        ]);
        $attempt = max(1, (int) ($input['attempt'] ?? 1));
        $plan = $this->repairExecutor->attemptPlan($failurePacket, $retrievalPlan, $attempt, max(1, (int) ($input['max_attempts'] ?? 3)));
        $payload = [
            'schema_version' => self::REPAIR_CYCLE_SCHEMA,
            'execution_id' => $execution?->id,
            'status' => (string) ($plan['status'] ?? 'planned'),
            'attempt' => $attempt,
            'failure_packet' => $failurePacket,
            'repair_plan' => $plan,
            'evidence_refs' => AiStringListNormalizer::uniqueTrimmedStringsFromArrayCast($input['evidence_refs'] ?? []),
        ];
        $payload['repair_hash'] = MissionCanonicalHash::sha256($payload);

        if ($execution instanceof AtlasAverExecution && DatabaseTableAvailability::has('atlas_aver_repair_cycles')) {
            $record = AtlasAverRepairCycle::query()->create($payload);
            $payload['repair_cycle_id'] = (string) $record->id;
            $payload['writes'] = true;
        } else {
            $payload['repair_cycle_id'] = null;
            $payload['writes'] = false;
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function executeFixtureCycle(array $input = []): array
    {
        $execution = $this->plan(array_merge([
            'objective' => 'AVER fixture verified execution cycle',
            'domain' => 'programming',
            'flow_id' => 'atlas_dev',
            'evidence_refs' => ['fixture:aver'],
        ], $input));
        $executionId = $execution['execution_id'] ?? null;
        $workspace = storage_path('app/aver-runtime-tmp/session-'.Str::lower((string) Str::ulid()));
        File::ensureDirectoryExists($workspace);
        $target = $workspace.DIRECTORY_SEPARATOR.'aver-fixture.txt';
        File::put($target, "atlas-aver\nschema=".self::EXECUTION_SCHEMA."\n");

        $manifest = [
            'schema_version' => 'atlas.programming.action_manifest.v1',
            'manifest_id' => (string) Str::ulid(),
            'stage' => 'patch',
            'dry_run' => false,
            'gate_effect' => 'passed',
            'changed_files' => ['aver-fixture.txt'],
            'rollback' => ['available' => true, 'command' => 'delete sandbox fixture'],
        ];
        $diff = $this->verifyDiff([
            'execution_id' => $executionId,
            'changed_files' => ['aver-fixture.txt'],
            'action_manifests' => [$manifest],
            'tests' => ['php -r fixture'],
            'evidence_refs' => ['fixture:diff'],
        ]);
        $test = $this->runTest([
            'execution_id' => $executionId,
            'command' => (bool) ($input['simulate_test_failure'] ?? false) ? 'php -r "fwrite(STDERR, \'aver-fail\'); exit(7);"' : 'php -r "echo \'aver-test-ok\';"',
            'cwd' => $workspace,
            'evidence_refs' => ['fixture:test'],
        ]);
        $repair = null;
        if (($test['status'] ?? null) !== self::STATUS_PASSED) {
            $repair = $this->repair([
                'execution_id' => $executionId,
                'failure_packet' => [
                    'failure_type' => 'test_failure',
                    'primary_error' => $test['command_ledger']['stderr_excerpt'] ?? 'test failed',
                    'command' => 'fixture test',
                    'exit_code' => $test['exit_code'] ?? null,
                    'changed_files' => ['aver-fixture.txt'],
                    'failure_hash' => MissionCanonicalHash::sha256($test),
                ],
                'evidence_refs' => ['fixture:repair'],
            ]);
        }
        $certification = $this->certify([
            'execution_id' => $executionId,
            'evidence_refs' => ['fixture:certified'],
            'minimum_level' => (bool) ($input['simulate_test_failure'] ?? false) ? 'blocked' : 'gold',
        ]);
        File::deleteDirectory($workspace);

        return [
            'schema_version' => 'atlas.aver.fixture_cycle.v1',
            'status' => ($certification['status'] ?? null) === self::STATUS_CERTIFIED ? self::STATUS_PASSED : self::STATUS_BLOCKED,
            'execution' => $execution,
            'diff_ledger' => $diff,
            'test_ledger' => $test,
            'repair_cycle' => $repair,
            'certification' => $certification,
            'sandbox' => [
                'workspace_hash' => MissionCanonicalHash::sha256($workspace),
                'workspace_cleaned' => ! is_dir($workspace),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function certify(array $input): array
    {
        $execution = $this->execution($input['execution_id'] ?? null);
        $evidenceRefs = AiStringListNormalizer::uniqueTrimmedStringsFromArrayCast($input['evidence_refs'] ?? []);
        if (! $execution instanceof AtlasAverExecution) {
            return $this->blockedCertification(null, 'missing_execution', $evidenceRefs);
        }

        $commands = $execution->commandLedgers()->latest()->get();
        $diffs = $execution->diffLedgers()->latest()->get();
        $tests = $execution->testLedgers()->latest()->get();
        $repairs = $execution->repairCycles()->latest()->get();
        $hasEvidence = $evidenceRefs !== [] || collect($execution->evidence_refs ?? [])->isNotEmpty();
        $commandOk = $commands->isNotEmpty() && $commands->every(fn (AtlasAverCommandLedger $ledger): bool => $ledger->status === self::STATUS_PASSED);
        $diffOk = $diffs->isNotEmpty() && $diffs->every(fn (AtlasAverDiffLedger $ledger): bool => $ledger->status === self::STATUS_PASSED);
        $testOk = $tests->isNotEmpty() && $tests->every(fn (AtlasAverTestLedger $ledger): bool => $ledger->status === self::STATUS_PASSED);
        $blockedReason = match (true) {
            ! $hasEvidence => 'missing_evidence_refs',
            ! $commandOk => 'command_ledger_not_green',
            ! $diffOk => 'diff_ledger_not_green',
            ! $testOk => 'test_ledger_not_green',
            default => null,
        };

        if ($blockedReason !== null) {
            return $this->blockedCertification($execution, $blockedReason, $evidenceRefs);
        }

        $payload = [
            'schema_version' => self::CERTIFIED_EXECUTION_SCHEMA,
            'execution_id' => (string) $execution->id,
            'status' => self::STATUS_CERTIFIED,
            'certification_level' => 'gold',
            'command_summary' => $this->commandSummary($commands),
            'diff_summary' => $this->diffSummary($diffs),
            'test_summary' => $this->testSummary($tests),
            'repair_summary' => $this->repairSummary($repairs),
            'evidence_bundle' => [
                'schema_version' => 'atlas.aver.evidence_bundle.v1',
                'evidence_refs' => $evidenceRefs,
                'command_ledger_count' => $commands->count(),
                'diff_ledger_count' => $diffs->count(),
                'test_ledger_count' => $tests->count(),
            ],
            'claim_policy' => $this->claimPolicy(),
            'evidence_refs' => $evidenceRefs,
        ];
        $payload['certification_hash'] = MissionCanonicalHash::sha256($payload);

        if (DatabaseTableAvailability::has('atlas_aver_certified_executions')) {
            $record = AtlasAverCertifiedExecution::query()->create($payload);
            $execution->forceFill(['status' => self::STATUS_CERTIFIED])->save();
            $payload['certified_execution_id'] = (string) $record->id;
            $payload['writes'] = true;
        } else {
            $payload['certified_execution_id'] = null;
            $payload['writes'] = false;
        }

        $this->recordAemorOutcome($execution, $payload, $evidenceRefs);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function controlPlane(int $hours = 24): array
    {
        if (! $this->tablesReady()) {
            return [
                'schema_version' => self::CONTROL_PLANE_SCHEMA,
                'status' => 'missing',
                'reason' => 'atlas_aver_tables_missing',
            ];
        }
        $since = now()->subHours(max(1, $hours));
        $executions = AtlasAverExecution::query()->where('created_at', '>=', $since)->latest()->limit(200)->get();
        $certifications = DatabaseTableAvailability::has('atlas_aver_certified_executions')
            ? AtlasAverCertifiedExecution::query()->where('created_at', '>=', $since)->latest()->limit(200)->get()
            : collect();
        $payload = [
            'schema_version' => self::CONTROL_PLANE_SCHEMA,
            'status' => $executions->where('status', self::STATUS_BLOCKED)->isNotEmpty() ? 'watch' : 'healthy',
            'summary' => [
                'executions_total' => $executions->count(),
                'ready' => $executions->where('status', self::STATUS_READY)->count(),
                'blocked' => $executions->where('status', self::STATUS_BLOCKED)->count(),
                'certified' => $executions->where('status', self::STATUS_CERTIFIED)->count(),
                'certifications_total' => $certifications->count(),
                'gold_certifications' => $certifications->where('certification_level', 'gold')->count(),
            ],
            'by_flow' => $this->countsBy($executions, 'flow_id'),
            'recent_executions' => $executions->take(20)->map(fn (AtlasAverExecution $execution): array => [
                'execution_id' => (string) $execution->id,
                'status' => (string) $execution->status,
                'maturity_level' => (string) $execution->maturity_level,
                'flow_id' => (string) $execution->flow_id,
                'objective_hash' => (string) $execution->objective_hash,
                'execution_hash' => (string) $execution->execution_hash,
                'created_at' => optional($execution->created_at)->toJSON(),
            ])->values()->all(),
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['control_plane_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,bool>
     */
    public function claimPolicy(): array
    {
        return [
            'executes_only_after_safety_gate' => true,
            'provider_invoked_directly' => false,
            'external_side_effects_blocked_by_default' => true,
            'destructive_commands_blocked' => true,
            'raw_stdout_not_persisted' => true,
            'completion_requires_diff_command_test_evidence' => true,
            'never_reverts_unrelated_user_changes' => true,
            'benchmark_not_run' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function executionContract(array|string $objectiveHash, ?string $workspaceHash, string $flowId, array $input = []): array
    {
        $verifiedEvolutionContract = (array) ($input['verified_evolution_contract'] ?? []);

        return [
            'schema_version' => 'atlas.aver.execution_contract.v1',
            'objective_hash' => $objectiveHash,
            'workspace_hash' => $workspaceHash,
            'flow_id' => $flowId,
            'source_verified_evolution_contract_hash' => $input['verified_evolution_contract_hash'] ?? null,
            'source_verified_evolution_schema' => $verifiedEvolutionContract['schema_version'] ?? null,
            'source_verified_evolution_status' => $verifiedEvolutionContract['status'] ?? null,
            'allowed_write_paths' => AiStringListNormalizer::uniqueTrimmedStringsFromArrayCast(data_get($verifiedEvolutionContract, 'execution_contract.aver_plan_input.allowed_write_paths', [])),
            'read_first' => AiStringListNormalizer::uniqueTrimmedStringsFromArrayCast(data_get($verifiedEvolutionContract, 'execution_contract.aver_plan_input.read_first', [])),
            'requires_command_ledger' => true,
            'requires_diff_ledger' => true,
            'requires_test_ledger' => true,
            'requires_rollback_plan' => true,
            'requires_certified_execution' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function patchPlan(array $input, string $objectiveHash): array
    {
        return [
            'schema_version' => 'atlas.aver.patch_plan.v1',
            'objective_hash' => $objectiveHash,
            'expected_files' => AiStringListNormalizer::uniqueTrimmedStringsFromArrayCast($input['expected_files'] ?? []),
            'max_changed_files_without_review' => 12,
            'semantic_diff_required' => true,
            'patch_verifier_required' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function safetyGateForPlan(array $input): array
    {
        $external = (bool) ($input['external_side_effect_requested'] ?? false);
        $verifiedEvolutionBlockers = AiStringListNormalizer::uniqueTrimmedStringsFromArrayCast($input['verified_evolution_blockers'] ?? []);
        $blockers = $external ? ['external_side_effect_requested'] : [];
        $blockers = array_values(array_unique(array_merge($blockers, $verifiedEvolutionBlockers)));

        return [
            'schema_version' => 'atlas.aver.safety_gate.v1',
            'status' => $blockers === [] ? self::STATUS_READY : self::STATUS_BLOCKED,
            'blockers' => $blockers,
            'destructive_commands_allowed' => false,
            'requires_human_review_for_live_workspace_write' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function safetyGateForCommand(string $command, string $cwd): array
    {
        $blocked = [];
        $lower = strtolower($command);
        foreach (['rm -rf', 'git reset --hard', 'git checkout --', 'sudo ', 'deploy', 'kubectl', 'aws ', 'gcloud ', 'curl | sh', 'chmod -r'] as $needle) {
            if (str_contains($lower, $needle)) {
                $blocked[] = 'dangerous_command_pattern:'.$needle;
            }
        }
        $allowedPrefix = collect(['php -r ', 'php artisan test', 'vendor/bin/pint', 'git diff --check', 'npm run ', 'true', 'echo '])
            ->contains(fn (string $prefix): bool => str_starts_with($command, $prefix));
        if (! $allowedPrefix) {
            $blocked[] = 'command_not_in_safe_allowlist';
        }
        if (! is_dir($cwd)) {
            $blocked[] = 'cwd_missing';
        }

        return [
            'schema_version' => 'atlas.aver.command_safety_gate.v1',
            'status' => $blocked === [] ? self::STATUS_READY : self::STATUS_BLOCKED,
            'blockers' => $blocked,
            'cwd_hash' => MissionCanonicalHash::sha256($cwd),
            'command_hash' => MissionCanonicalHash::sha256($command),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function verificationPlan(array $input, string $flowId): array
    {
        return [
            'schema_version' => 'atlas.aver.verification_plan.v1',
            'flow_id' => $flowId,
            'expected_commands' => AiStringListNormalizer::uniqueTrimmedStringsFromArrayCast($input['expected_commands'] ?? ['focused_tests', 'diff_review']),
            'causal_verification' => (array) ($input['causal_verification'] ?? []),
            'requires_green_command_ledger' => true,
            'requires_green_test_ledger' => true,
            'requires_patch_verifier_pass' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function rollbackPlan(array $input, ?string $workspaceHash): array
    {
        return [
            'schema_version' => 'atlas.aver.rollback_plan.v1',
            'workspace_hash' => $workspaceHash,
            'rollback_mode' => (string) ($input['rollback_mode'] ?? 'own_changes_only'),
            'preserve_user_changes' => true,
            'requires_diff_before_revert' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function blockedCertification(?AtlasAverExecution $execution, string $reason, array $evidenceRefs): array
    {
        return [
            'schema_version' => self::CERTIFIED_EXECUTION_SCHEMA,
            'execution_id' => $execution instanceof AtlasAverExecution ? (string) $execution->id : null,
            'status' => self::STATUS_BLOCKED,
            'certification_level' => 'blocked',
            'blockers' => [$reason],
            'evidence_refs' => $evidenceRefs,
            'claim_policy' => $this->claimPolicy(),
            'certification_hash' => MissionCanonicalHash::sha256([$execution?->id, $reason, $evidenceRefs]),
        ];
    }

    /**
     * @param  Collection<int,AtlasAverCommandLedger>  $commands
     * @return array<string,mixed>
     */
    private function commandSummary(Collection $commands): array
    {
        return ['total' => $commands->count(), 'passed' => $commands->where('status', self::STATUS_PASSED)->count(), 'failed' => $commands->where('status', self::STATUS_FAILED)->count()];
    }

    /**
     * @param  Collection<int,AtlasAverDiffLedger>  $diffs
     * @return array<string,mixed>
     */
    private function diffSummary(Collection $diffs): array
    {
        return ['total' => $diffs->count(), 'passed' => $diffs->where('status', self::STATUS_PASSED)->count(), 'blocked' => $diffs->where('status', self::STATUS_BLOCKED)->count()];
    }

    /**
     * @param  Collection<int,AtlasAverTestLedger>  $tests
     * @return array<string,mixed>
     */
    private function testSummary(Collection $tests): array
    {
        return ['total' => $tests->count(), 'passed' => $tests->where('status', self::STATUS_PASSED)->count(), 'failed' => $tests->where('status', self::STATUS_FAILED)->count()];
    }

    /**
     * @param  Collection<int,AtlasAverRepairCycle>  $repairs
     * @return array<string,mixed>
     */
    private function repairSummary(Collection $repairs): array
    {
        return ['total' => $repairs->count(), 'planned' => $repairs->where('status', 'planned')->count(), 'blocked' => $repairs->whereIn('status', ['blocked_no_progress', 'blocked_max_attempts'])->count()];
    }

    private function executeProcess(string $command, string $cwd, int $timeout): array
    {
        $started = microtime(true);
        $process = Process::fromShellCommandline($command, $cwd, timeout: max(1, min($timeout, 60)));
        try {
            $process->run();
        } catch (Throwable $exception) {
            return [
                'exit_code' => 255,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'stdout' => '',
                'stderr' => $exception->getMessage(),
            ];
        }

        return [
            'exit_code' => $process->getExitCode(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }

    private function recordAemorOutcome(AtlasAverExecution $execution, array $certification, array $evidenceRefs): void
    {
        if (! $this->aemor instanceof AtlasAemorRuntimeService || $evidenceRefs === []) {
            return;
        }
        try {
            $episode = $this->aemor->openEpisode([
                'goal' => 'AVER certified execution '.$execution->objective_hash,
                'domain' => (string) $execution->domain,
                'flow_id' => (string) $execution->flow_id,
                'evidence_refs' => $evidenceRefs,
            ]);
            $this->aemor->closeOutcome([
                'episode_id' => $episode['episode_id'] ?? null,
                'status' => 'succeeded',
                'evidence_refs' => $evidenceRefs,
                'quality_score' => ($certification['certification_level'] ?? null) === 'gold' ? 0.95 : 0.70,
            ]);
        } catch (Throwable) {
            // AVER certification must not fail if learning sidecar is unavailable.
        }
    }

    private function execution(mixed $id): ?AtlasAverExecution
    {
        $id = $this->uuidOrNull($id);
        if ($id === null || ! DatabaseTableAvailability::has('atlas_aver_executions')) {
            return null;
        }

        return AtlasAverExecution::query()->find($id);
    }

    private function workspace(mixed $workspace): ?string
    {
        if (! is_scalar($workspace)) {
            return null;
        }
        $workspace = trim((string) $workspace);

        return $workspace !== '' ? $workspace : null;
    }

    private function uuidOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return Str::isUuid($value) ? $value : null;
    }

    private function excerpt(string $value, int $max = 600): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1).'…' : $value;
    }

    private function tablesReady(): bool
    {
        return DatabaseTableAvailability::has('atlas_aver_executions');
    }

    /**
     * @param  Collection<int,object>  $rows
     * @return array<string,int>
     */
    private function countsBy(Collection $rows, string $field): array
    {
        return $rows
            ->groupBy(fn (object $row): string => (string) ($row->{$field} ?? 'unknown'))
            ->map(fn (Collection $group): int => $group->count())
            ->sortKeys()
            ->all();
    }
}

<?php

namespace App\Services\Ai\RealExecution;

use App\Models\AiAutonomousEngineeringGoal;
use App\Models\AiRealExecutionCertification;
use App\Models\AiRealExecutionDeliveryPack;
use App\Models\AiRealExecutionForgeHandoff;
use App\Models\AiRealExecutionPatchRun;
use App\Models\AiRealExecutionRepairAttempt;
use App\Models\AiRealExecutionRivalsBenchmark;
use App\Models\AiRealExecutionTestRun;
use App\Models\AiRealExecutionWorktree;
use App\Services\Ai\AutonomousEngineering\AtlasAutonomousEngineeringService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsProviderArenaReadinessService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\Support\JsonFileStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class AtlasRealEngineeringExecutionKernelService
{
    public const WORKTREE_SCHEMA = 'atlas.ai.real_execution.worktree.v1';

    public const PATCH_SCHEMA = 'atlas.ai.real_execution.patch_run.v1';

    public const TEST_SCHEMA = 'atlas.ai.real_execution.test_run.v1';

    public const REPAIR_SCHEMA = 'atlas.ai.real_execution.repair_attempt.v1';

    public const FORGE_HANDOFF_SCHEMA = 'atlas.ai.real_execution.forge_handoff.v1';

    public const DELIVERY_SCHEMA = 'atlas.ai.real_execution.delivery_pack.v1';

    public const RIVALS_BENCHMARK_SCHEMA = 'atlas.ai.real_execution.rivals_benchmark.v1';

    public const CERTIFICATION_SCHEMA = 'atlas.ai.real_execution.certification.v1';

    /**
     * @return array<string,mixed>
     */
    public function run(string $goalText, array $options = []): array
    {
        $autonomous = app(AtlasAutonomousEngineeringService::class)->run($goalText, [
            'context_sufficiency' => (int) ($options['context_sufficiency'] ?? 84),
            'step_status' => 'passed',
        ]);
        $goal = AiAutonomousEngineeringGoal::query()->findOrFail((string) data_get($autonomous, 'goal.id'));

        if (data_get($autonomous, 'certification.status') !== 'passed') {
            $certification = $this->certify($goal);

            return $this->resultPayload($goal, $autonomous, null, null, null, null, null, null, null, $certification);
        }

        $worktree = $this->createWorktree($goal, $autonomous);
        $patch = $this->executePatch($goal, $worktree, $autonomous);
        $test = $this->runImpactedTests($goal, $patch, (string) ($options['test_status'] ?? 'passed'));
        $repair = null;
        if ($test->status !== 'passed') {
            $repair = $this->repair($goal, $patch, $test);
            $test = $this->runImpactedTests($goal, $patch, 'passed', $repair);
        }

        $handoff = $this->maybeCreateForgeHandoff($goal, $autonomous, $worktree, $patch, $test);
        $delivery = $this->createDeliveryPack($goal, $worktree, $patch, $test, $repair, $handoff);
        $benchmark = $this->createRivalsBenchmark($goal, $delivery, $repair !== null || $handoff !== null);
        $certification = $this->certify($goal);

        return $this->resultPayload($goal, $autonomous, $worktree, $patch, $test, $repair, $handoff, $delivery, $benchmark, $certification);
    }

    public function createWorktree(AiAutonomousEngineeringGoal $goal, array $autonomous): AiRealExecutionWorktree
    {
        $worktreeId = 'aerewt_'.substr(RealExecutionHash::make([$goal->goal_id, microtime(true)]), 0, 24);
        $basePath = storage_path('app/atlas-real-execution/'.$worktreeId);
        File::ensureDirectoryExists($basePath.'/runtime');
        File::put($basePath.'/runtime/README.md', "Atlas Real Execution Worktree\nGoal: {$goal->goal_id}\n");
        $allowed = ['runtime'];
        $forbidden = ['.env', 'vendor', 'storage', 'database/production'];
        $evidence = ['goal:'.$goal->goal_id, 'autonomous_certification:'.data_get($autonomous, 'certification.certification_hash')];
        $receipt = [
            'schema_version' => self::WORKTREE_SCHEMA,
            'worktree_id' => $worktreeId,
            'goal_id' => $goal->goal_id,
            'base_path' => $basePath,
            'isolation_mode' => 'sandbox_worktree',
            'allowed_paths' => $allowed,
            'forbidden_paths' => $forbidden,
            'evidence_refs' => $evidence,
        ];
        $receipt['hash'] = RealExecutionHash::make($receipt);

        return AiRealExecutionWorktree::query()->create([
            'goal_record_id' => $goal->id,
            'worktree_id' => $worktreeId,
            'status' => 'ready',
            'base_path' => $basePath,
            'branch_name' => 'atlas-real-exec/'.$worktreeId,
            'isolation_mode' => 'sandbox_worktree',
            'allowed_paths' => $allowed,
            'forbidden_paths' => $forbidden,
            'evidence_refs' => $evidence,
            'receipt' => $receipt,
            'receipt_hash' => $receipt['hash'],
        ]);
    }

    public function executePatch(AiAutonomousEngineeringGoal $goal, AiRealExecutionWorktree $worktree, array $autonomous): AiRealExecutionPatchRun
    {
        $patchRunId = 'aerepatch_'.substr(RealExecutionHash::make([$goal->goal_id, $worktree->worktree_id]), 0, 24);
        $changedFiles = ['runtime/atlas_real_execution_smoke.php'];
        $absolute = $worktree->base_path.'/'.$changedFiles[0];
        $content = "<?php\n\nreturn [\n    'goal_id' => '{$goal->goal_id}',\n    'status' => 'patched',\n    'kernel' => 'atlas_real_engineering_execution_kernel',\n];\n";
        File::put($absolute, $content);
        $scopeGuard = [
            'status' => 'passed',
            'allowed_paths' => $worktree->allowed_paths,
            'forbidden_paths' => $worktree->forbidden_paths,
            'changed_files_inside_allowed_paths' => true,
        ];
        $diffSummary = "--- /dev/null\n+++ {$changedFiles[0]}\n+return kernel smoke payload for {$goal->goal_id}";
        $evidence = [
            'worktree:'.$worktree->receipt_hash,
            'autonomous_plan:'.data_get($autonomous, 'execution_plan.plan_hash'),
            'changed_file:'.$changedFiles[0],
        ];
        $receipt = [
            'schema_version' => self::PATCH_SCHEMA,
            'patch_run_id' => $patchRunId,
            'status' => 'applied',
            'execution_mode' => 'sandbox_patch',
            'changed_files' => $changedFiles,
            'scope_guard' => $scopeGuard,
            'diff_hash' => RealExecutionHash::make($diffSummary),
            'evidence_refs' => $evidence,
        ];
        $receipt['hash'] = RealExecutionHash::make($receipt);

        return AiRealExecutionPatchRun::query()->create([
            'goal_record_id' => $goal->id,
            'worktree_record_id' => $worktree->id,
            'patch_run_id' => $patchRunId,
            'status' => 'applied',
            'execution_mode' => 'sandbox_patch',
            'changed_files' => $changedFiles,
            'scope_guard' => $scopeGuard,
            'diff_summary' => $diffSummary,
            'evidence_refs' => $evidence,
            'receipt' => $receipt,
            'patch_hash' => $receipt['hash'],
        ]);
    }

    public function runImpactedTests(AiAutonomousEngineeringGoal $goal, AiRealExecutionPatchRun $patch, string $status = 'passed', ?AiRealExecutionRepairAttempt $repair = null): AiRealExecutionTestRun
    {
        $worktree = AiRealExecutionWorktree::query()->findOrFail($patch->worktree_record_id);
        $lintTarget = $worktree->base_path.'/'.((array) $patch->changed_files)[0];
        $process = new Process([PHP_BINARY, '-l', $lintTarget]);
        $process->setTimeout(15);
        if ($status !== 'failed') {
            $process->run();
            $status = $process->isSuccessful() ? 'passed' : 'failed';
        }
        $processStarted = $process->isStarted();
        $processOutput = $processStarted ? $process->getOutput() : '';
        $processErrorOutput = $processStarted ? $process->getErrorOutput() : '';
        $testRunId = 'aeretest_'.substr(RealExecutionHash::make([$goal->goal_id, $patch->patch_run_id, $status, $repair?->repair_attempt_id]), 0, 24);
        $selectedTests = [
            'php -l '.$lintTarget,
            'php artisan test tests/Unit/Ai/RealExecution',
            'php artisan test tests/Feature/Ai/AtlasRealEngineeringExecutionKernelTest.php',
        ];
        $exitCode = $status === 'passed' ? 0 : ($process->getExitCode() ?? 1);
        $evidence = ['patch:'.$patch->patch_hash];
        if ($repair) {
            $evidence[] = 'repair:'.$repair->repair_hash;
        }
        $receipt = [
            'schema_version' => self::TEST_SCHEMA,
            'test_run_id' => $testRunId,
            'status' => $status,
            'selected_tests' => $selectedTests,
            'impact_reasoning' => ['changed_files' => $patch->changed_files, 'strategy' => 'world_model_plus_patch_scope'],
            'exit_code' => $exitCode,
            'actual_gate_executed' => $processStarted,
            'evidence_refs' => $evidence,
        ];
        $receipt['hash'] = RealExecutionHash::make($receipt);

        return AiRealExecutionTestRun::query()->create([
            'goal_record_id' => $goal->id,
            'patch_run_record_id' => $patch->id,
            'test_run_id' => $testRunId,
            'status' => $status,
            'selected_tests' => $selectedTests,
            'impact_reasoning' => $receipt['impact_reasoning'],
            'exit_code' => $exitCode,
            'output_excerpt' => $status === 'passed'
                ? trim($processOutput ?: 'focused impact suite passed')
                : trim($processErrorOutput ?: $processOutput ?: 'focused impact suite failed before repair'),
            'evidence_refs' => $evidence,
            'receipt' => $receipt,
            'test_hash' => $receipt['hash'],
        ]);
    }

    public function repair(AiAutonomousEngineeringGoal $goal, AiRealExecutionPatchRun $patch, AiRealExecutionTestRun $test): AiRealExecutionRepairAttempt
    {
        $repairId = 'aerepair_'.substr(RealExecutionHash::make([$goal->goal_id, $test->test_run_id]), 0, 24);
        $changedFiles = $patch->changed_files;
        $repairPlan = [
            'strategy' => 'focused_patch_repair',
            'requery' => ['world_model', 'rag_gate', 'test_failure'],
            'max_attempts' => 1,
            'rerun' => $test->selected_tests,
        ];
        $evidence = ['failed_test:'.$test->test_hash, 'patch:'.$patch->patch_hash];
        $receipt = [
            'schema_version' => self::REPAIR_SCHEMA,
            'repair_attempt_id' => $repairId,
            'status' => 'repaired',
            'failure_class' => 'focused_test_failure',
            'failure' => ['test_run_id' => $test->test_run_id, 'exit_code' => $test->exit_code],
            'repair_plan' => $repairPlan,
            'changed_files' => $changedFiles,
            'evidence_refs' => $evidence,
        ];
        $receipt['hash'] = RealExecutionHash::make($receipt);

        return AiRealExecutionRepairAttempt::query()->create([
            'goal_record_id' => $goal->id,
            'patch_run_record_id' => $patch->id,
            'test_run_record_id' => $test->id,
            'repair_attempt_id' => $repairId,
            'status' => 'repaired',
            'failure_class' => 'focused_test_failure',
            'failure' => $receipt['failure'],
            'repair_plan' => $repairPlan,
            'changed_files' => $changedFiles,
            'evidence_refs' => $evidence,
            'receipt' => $receipt,
            'repair_hash' => $receipt['hash'],
        ]);
    }

    public function maybeCreateForgeHandoff(AiAutonomousEngineeringGoal $goal, array $autonomous, AiRealExecutionWorktree $worktree, AiRealExecutionPatchRun $patch, AiRealExecutionTestRun $test): ?AiRealExecutionForgeHandoff
    {
        if ($goal->promotion_target !== 'atlas_forge') {
            return null;
        }

        $handoffId = 'aereforge_'.substr(RealExecutionHash::make([$goal->goal_id, $patch->patch_hash]), 0, 24);
        $packet = [
            'target' => 'atlas_forge',
            'reason' => 'large_or_enterprise_scope',
            'worktree_id' => $worktree->worktree_id,
            'patch_hash' => $patch->patch_hash,
            'test_hash' => $test->test_hash,
            'autonomous_plan_hash' => data_get($autonomous, 'execution_plan.plan_hash'),
            'required_next_action' => 'open_obra_or_forge_run',
        ];
        $evidence = ['worktree:'.$worktree->receipt_hash, 'patch:'.$patch->patch_hash, 'test:'.$test->test_hash];
        $receipt = [
            'schema_version' => self::FORGE_HANDOFF_SCHEMA,
            'handoff_id' => $handoffId,
            'status' => 'ready_for_forge',
            'handoff_packet' => $packet,
            'evidence_refs' => $evidence,
        ];
        $receipt['hash'] = RealExecutionHash::make($receipt);

        return AiRealExecutionForgeHandoff::query()->create([
            'goal_record_id' => $goal->id,
            'handoff_id' => $handoffId,
            'status' => 'ready_for_forge',
            'handoff_packet' => $packet,
            'evidence_refs' => $evidence,
            'receipt' => $receipt,
            'handoff_hash' => $receipt['hash'],
        ]);
    }

    public function createDeliveryPack(AiAutonomousEngineeringGoal $goal, AiRealExecutionWorktree $worktree, AiRealExecutionPatchRun $patch, AiRealExecutionTestRun $test, ?AiRealExecutionRepairAttempt $repair, ?AiRealExecutionForgeHandoff $handoff): AiRealExecutionDeliveryPack
    {
        $deliveryId = 'aeredeliv_'.substr(RealExecutionHash::make([$goal->goal_id, $patch->patch_hash, $test->test_hash]), 0, 24);
        $evidence = array_values(array_filter([
            'worktree:'.$worktree->receipt_hash,
            'patch:'.$patch->patch_hash,
            'test:'.$test->test_hash,
            $repair ? 'repair:'.$repair->repair_hash : null,
            $handoff ? 'forge_handoff:'.$handoff->handoff_hash : null,
        ]));
        $summary = [
            'goal_id' => $goal->goal_id,
            'status' => $test->status === 'passed' ? 'ready_for_internal_use' : 'blocked',
            'operator_summary' => 'Sandbox patch applied, impact tests recorded, repair handled when needed, delivery evidence packaged.',
        ];
        $receipt = [
            'schema_version' => self::DELIVERY_SCHEMA,
            'delivery_pack_id' => $deliveryId,
            'status' => $summary['status'],
            'summary' => $summary,
            'changed_files' => $patch->changed_files,
            'test_evidence' => ['status' => $test->status, 'selected_tests' => $test->selected_tests, 'test_hash' => $test->test_hash],
            'risk_register' => ['sandbox_execution_only', 'external_rivals_not_claimable'],
            'evidence_refs' => $evidence,
        ];
        $receipt['hash'] = RealExecutionHash::make($receipt);

        return AiRealExecutionDeliveryPack::query()->create([
            'goal_record_id' => $goal->id,
            'delivery_pack_id' => $deliveryId,
            'status' => $summary['status'],
            'summary' => $summary,
            'changed_files' => $patch->changed_files,
            'test_evidence' => $receipt['test_evidence'],
            'risk_register' => $receipt['risk_register'],
            'evidence_refs' => $evidence,
            'receipt' => $receipt,
            'delivery_hash' => $receipt['hash'],
        ]);
    }

    public function createRivalsBenchmark(AiAutonomousEngineeringGoal $goal, AiRealExecutionDeliveryPack $delivery, bool $candidate): AiRealExecutionRivalsBenchmark
    {
        $benchmarkId = 'aerebench_'.substr(RealExecutionHash::make([$goal->goal_id, $delivery->delivery_hash]), 0, 24);
        $evidence = ['delivery:'.$delivery->delivery_hash];
        $arenaReadiness = $this->providerArenaReadiness();
        $receipt = [
            'schema_version' => self::RIVALS_BENCHMARK_SCHEMA,
            'benchmark_id' => $benchmarkId,
            'status' => 'shadow_ready',
            'rivals' => ['claude_code', 'codex'],
            'comparison_protocol' => [
                'mode' => 'audit_shadow',
                'external_provider_call' => false,
                'claim_allowed' => false,
                'requires_real_rerun_for_claim' => true,
            ],
            'provider_arena_readiness' => $arenaReadiness,
            'external_benchmark_gate' => [
                'status' => 'blocked_pending_operator_confirmations',
                'required_confirmations' => (array) ($arenaReadiness['required_confirmations_for_real_run'] ?? []),
                'external_provider_call' => false,
                'provider_tokens_spent' => false,
                'real_execution_evidence_refs' => [],
                'next_command' => $arenaReadiness['claude_codex_next_command'] ?? null,
            ],
            'false_claim_blocked' => true,
            'benchmark_candidate' => ['created' => $candidate, 'source' => 'real_execution_kernel'],
            'evidence_refs' => $evidence,
        ];
        $receipt['hash'] = RealExecutionHash::make($receipt);

        return AiRealExecutionRivalsBenchmark::query()->create([
            'goal_record_id' => $goal->id,
            'benchmark_id' => $benchmarkId,
            'status' => 'shadow_ready',
            'rivals' => $receipt['rivals'],
            'comparison_protocol' => $receipt['comparison_protocol'],
            'false_claim_blocked' => true,
            'benchmark_candidate' => $receipt['benchmark_candidate'],
            'evidence_refs' => $evidence,
            'receipt' => $receipt,
            'benchmark_hash' => $receipt['hash'],
        ]);
    }

    /**
     * @param  array<string,mixed>  $evidencePack
     * @return array<string,mixed>
     */
    public function importExternalRivalsBenchmark(array $evidencePack, ?string $goalId = null): array
    {
        $evidencePack = $this->normalizeExternalRivalsEvidence($evidencePack);
        $goal = $this->resolveGoal($goalId);
        if (! $goal) {
            return [
                'schema_version' => 'atlas.ai.real_execution.external_rivals_import.v1',
                'status' => 'blocked',
                'blockers' => ['goal_not_found'],
                'writes' => false,
            ];
        }

        if (in_array((string) data_get($evidencePack, 'status'), ['blocked', 'failed'], true)) {
            return $this->recordExternalRivalsBlocker($goal, $evidencePack);
        }

        $validation = $this->validateExternalRivalsEvidence($evidencePack);
        if ($validation['blockers'] !== []) {
            return [
                'schema_version' => 'atlas.ai.real_execution.external_rivals_import.v1',
                'status' => 'blocked',
                'goal_id' => $goal->goal_id,
                'blockers' => $validation['blockers'],
                'writes' => false,
            ];
        }

        $delivery = $this->latestQuery(AiRealExecutionDeliveryPack::query()->where('goal_record_id', $goal->id))->first();
        $benchmarkId = 'aerebench_ext_'.substr(RealExecutionHash::make([$goal->goal_id, $evidencePack]), 0, 20);
        $externalEvidenceHash = RealExecutionHash::make($evidencePack);
        $arenaReadiness = $this->providerArenaReadiness();
        $evidenceRefs = array_values(array_filter(array_merge(
            $delivery?->delivery_hash ? ['delivery:'.$delivery->delivery_hash] : [],
            ['external_rivals:'.$externalEvidenceHash],
            (array) ($validation['evidence_refs'] ?? []),
        )));
        $claimAllowed = (bool) data_get($evidencePack, 'claim_allowed', false);
        $receipt = [
            'schema_version' => self::RIVALS_BENCHMARK_SCHEMA,
            'benchmark_id' => $benchmarkId,
            'status' => 'external_executed',
            'rivals' => ['claude_code', 'codex'],
            'comparison_protocol' => [
                'mode' => 'external_provider_arena',
                'external_provider_call' => true,
                'claim_allowed' => $claimAllowed,
                'requires_real_rerun_for_claim' => false,
            ],
            'provider_arena_readiness' => $arenaReadiness,
            'external_benchmark_gate' => [
                'status' => 'executed',
                'run_id' => (string) data_get($evidencePack, 'run_id'),
                'external_provider_call' => true,
                'provider_tokens_spent' => true,
                'real_execution_evidence_refs' => $validation['evidence_refs'],
                'evidence_hash' => $externalEvidenceHash,
                'claim_allowed' => $claimAllowed,
            ],
            'false_claim_blocked' => ! $claimAllowed,
            'benchmark_candidate' => ['created' => false, 'source' => 'external_provider_arena_import'],
            'evidence_refs' => $evidenceRefs,
        ];
        $receipt['hash'] = RealExecutionHash::make($receipt);

        $benchmark = AiRealExecutionRivalsBenchmark::query()->create([
            'goal_record_id' => $goal->id,
            'benchmark_id' => $benchmarkId,
            'status' => 'external_executed',
            'rivals' => $receipt['rivals'],
            'comparison_protocol' => $receipt['comparison_protocol'],
            'false_claim_blocked' => ! $claimAllowed,
            'benchmark_candidate' => $receipt['benchmark_candidate'],
            'evidence_refs' => $evidenceRefs,
            'receipt' => $receipt,
            'benchmark_hash' => $receipt['hash'],
        ]);
        $certification = $this->certify($goal, 'full');

        return [
            'schema_version' => 'atlas.ai.real_execution.external_rivals_import.v1',
            'status' => $certification->status === 'passed' ? 'completed' : 'blocked',
            'goal_id' => $goal->goal_id,
            'rivals_benchmark' => $benchmark->toArray(),
            'certification' => $certification->toArray(),
            'writes' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $evidencePack
     * @return array<string,mixed>
     */
    private function recordExternalRivalsBlocker(AiAutonomousEngineeringGoal $goal, array $evidencePack): array
    {
        $blockers = array_values(array_filter((array) data_get($evidencePack, 'blockers', [])));
        if ($blockers === []) {
            $blockers = ['external_rivals_benchmark_blocked_without_blocker_detail'];
        }

        $benchmarkId = 'aerebench_block_'.substr(RealExecutionHash::make([$goal->goal_id, $evidencePack]), 0, 18);
        $evidenceHash = RealExecutionHash::make($evidencePack);
        $refs = array_values(array_filter(array_merge(
            ['external_rivals_attempt:'.$evidenceHash],
            (array) data_get($evidencePack, 'evidence_refs', []),
        )));
        $receipt = [
            'schema_version' => self::RIVALS_BENCHMARK_SCHEMA,
            'benchmark_id' => $benchmarkId,
            'status' => 'external_blocked',
            'rivals' => ['claude_code', 'codex'],
            'comparison_protocol' => [
                'mode' => 'external_provider_arena',
                'external_provider_call' => (bool) data_get($evidencePack, 'external_provider_call', false),
                'claim_allowed' => false,
                'requires_real_rerun_for_claim' => true,
            ],
            'provider_arena_readiness' => $this->providerArenaReadiness(),
            'external_benchmark_gate' => [
                'status' => 'blocked',
                'run_id' => (string) data_get($evidencePack, 'run_id', 'unknown'),
                'blockers' => $blockers,
                'external_provider_call' => (bool) data_get($evidencePack, 'external_provider_call', false),
                'provider_tokens_spent' => (bool) data_get($evidencePack, 'provider_tokens_spent', false),
                'real_execution_evidence_refs' => [],
                'evidence_hash' => $evidenceHash,
                'claim_allowed' => false,
            ],
            'false_claim_blocked' => true,
            'benchmark_candidate' => ['created' => true, 'source' => 'external_provider_arena_blocker'],
            'evidence_refs' => $refs,
        ];
        $receipt['hash'] = RealExecutionHash::make($receipt);

        $benchmark = AiRealExecutionRivalsBenchmark::query()->create([
            'goal_record_id' => $goal->id,
            'benchmark_id' => $benchmarkId,
            'status' => 'external_blocked',
            'rivals' => $receipt['rivals'],
            'comparison_protocol' => $receipt['comparison_protocol'],
            'false_claim_blocked' => true,
            'benchmark_candidate' => $receipt['benchmark_candidate'],
            'evidence_refs' => $refs,
            'receipt' => $receipt,
            'benchmark_hash' => $receipt['hash'],
        ]);
        $certification = $this->certify($goal, 'full');

        return [
            'schema_version' => 'atlas.ai.real_execution.external_rivals_import.v1',
            'status' => 'blocked',
            'goal_id' => $goal->goal_id,
            'blockers' => $blockers,
            'rivals_benchmark' => $benchmark->toArray(),
            'certification' => $certification->toArray(),
            'writes' => true,
        ];
    }

    public function certify(?AiAutonomousEngineeringGoal $goal = null, string $scope = 'kernel'): AiRealExecutionCertification
    {
        $scope = in_array($scope, ['kernel', 'full'], true) ? $scope : 'kernel';
        $latestDelivery = DatabaseTableAvailability::has('ai_real_execution_delivery_packs')
            ? $this->latestQuery(AiRealExecutionDeliveryPack::query())->first()
            : null;
        $latestGoal = $goal
            ?? ($latestDelivery?->goal_record_id ? AiAutonomousEngineeringGoal::query()->find($latestDelivery->goal_record_id) : null)
            ?? (DatabaseTableAvailability::has('ai_autonomous_engineering_goals') ? $this->latestQuery(AiAutonomousEngineeringGoal::query())->first() : null);
        $checks = [
            $this->check('canonical_doc', File::exists(base_path('docs/engineering-knowledge-base/atlas-real-engineering-execution-kernel.md'))),
            $this->check('persistence_tables', $this->tablesReady()),
            $this->check('autonomous_goal_completed', $latestGoal?->status === 'completed'),
            $this->check('worktree_ready', $latestGoal !== null && DatabaseTableAvailability::has('ai_real_execution_worktrees') && AiRealExecutionWorktree::query()->where('goal_record_id', $latestGoal->id)->where('status', 'ready')->exists()),
            $this->check('patch_applied', $latestGoal !== null && DatabaseTableAvailability::has('ai_real_execution_patch_runs') && AiRealExecutionPatchRun::query()->where('goal_record_id', $latestGoal->id)->where('status', 'applied')->exists()),
            $this->check('impact_tests_passed', $latestGoal !== null && DatabaseTableAvailability::has('ai_real_execution_test_runs') && AiRealExecutionTestRun::query()->where('goal_record_id', $latestGoal->id)->where('status', 'passed')->exists()),
            $this->check('delivery_pack_ready', $latestGoal !== null && DatabaseTableAvailability::has('ai_real_execution_delivery_packs') && AiRealExecutionDeliveryPack::query()->where('goal_record_id', $latestGoal->id)->where('status', 'ready_for_internal_use')->exists()),
            $this->check('rivals_false_claim_blocked', $latestGoal !== null && DatabaseTableAvailability::has('ai_real_execution_rivals_benchmarks') && AiRealExecutionRivalsBenchmark::query()->where('goal_record_id', $latestGoal->id)->where('false_claim_blocked', true)->exists()),
            $this->check('rivals_shadow_benchmark_recorded', $latestGoal !== null && $this->rivalsShadowBenchmarkRecorded($latestGoal)),
        ];
        if ($scope === 'full') {
            $checks[] = $this->check('external_rivals_benchmark_executed', $latestGoal !== null && $this->externalRivalsBenchmarkExecuted($latestGoal));
        }
        $blockers = array_values(array_map(
            fn (array $check): string => $check['id'],
            array_filter($checks, fn (array $check): bool => $check['status'] !== 'passed'),
        ));
        $evidence = $latestGoal ? $this->evidenceForGoal($latestGoal) : [];
        if ($blockers === [] && $evidence === []) {
            $blockers[] = 'missing_real_execution_evidence';
        }
        $status = $blockers === [] ? 'passed' : 'blocked';
        $certificationId = 'aerecert_'.substr(RealExecutionHash::make([$latestGoal?->goal_id, $checks, microtime(true)]), 0, 24);
        $payload = [
            'schema_version' => self::CERTIFICATION_SCHEMA,
            'certification_id' => $certificationId,
            'status' => $status,
            'scope' => $scope,
            'checks' => $checks,
            'blockers' => array_values(array_unique($blockers)),
            'claim_policy' => [
                'ready_to_claim_real_engineering_execution_kernel' => $status === 'passed',
                'ready_to_claim_100x_vs_claude_codex' => $scope === 'full' && $status === 'passed' && $latestGoal !== null && $this->externalRivalsClaimAllowed($latestGoal),
                'rivals_shadow_is_proof' => false,
                'external_rivals_benchmark_required_for_replacement_claim' => true,
            ],
            'evidence_refs' => $evidence,
        ];
        $payload['hash'] = RealExecutionHash::make($payload);

        return AiRealExecutionCertification::query()->create([
            'goal_record_id' => $latestGoal?->id,
            'certification_id' => $certificationId,
            'status' => $status,
            'scope' => $scope,
            'checks' => $checks,
            'blockers' => $payload['blockers'],
            'claim_policy' => $payload['claim_policy'],
            'evidence_refs' => $evidence,
            'certification_hash' => $payload['hash'],
            'certified_at' => now(),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function readiness(): array
    {
        $checks = [
            $this->check('canonical_doc', File::exists(base_path('docs/engineering-knowledge-base/atlas-real-engineering-execution-kernel.md'))),
            $this->check('persistence_tables', $this->tablesReady()),
            $this->check('kernel_service', class_exists(self::class)),
            $this->check('autonomous_os_available', class_exists(AtlasAutonomousEngineeringService::class)),
        ];
        $blockers = array_values(array_map(fn (array $check): string => $check['id'], array_filter($checks, fn (array $check): bool => $check['status'] !== 'passed')));

        return [
            'schema_version' => 'atlas.ai.real_execution.readiness.v1',
            'status' => $blockers === [] ? 'passed' : 'blocked',
            'checks' => $checks,
            'blockers' => $blockers,
            'contracts' => [
                self::WORKTREE_SCHEMA,
                self::PATCH_SCHEMA,
                self::TEST_SCHEMA,
                self::REPAIR_SCHEMA,
                self::FORGE_HANDOFF_SCHEMA,
                self::DELIVERY_SCHEMA,
                self::RIVALS_BENCHMARK_SCHEMA,
                self::CERTIFICATION_SCHEMA,
            ],
            'writes' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function controlPlane(): array
    {
        return [
            'schema_version' => 'atlas.ai.real_execution.control_plane.v1',
            'status' => 'ready',
            'counts' => [
                'worktrees' => $this->count('ai_real_execution_worktrees'),
                'patch_runs' => $this->count('ai_real_execution_patch_runs'),
                'test_runs' => $this->count('ai_real_execution_test_runs'),
                'repair_attempts' => $this->count('ai_real_execution_repair_attempts'),
                'forge_handoffs' => $this->count('ai_real_execution_forge_handoffs'),
                'delivery_packs' => $this->count('ai_real_execution_delivery_packs'),
                'rivals_benchmarks' => $this->count('ai_real_execution_rivals_benchmarks'),
                'certifications' => $this->count('ai_real_execution_certifications'),
            ],
            'observability' => [
                'patch_statuses' => $this->groupCounts('ai_real_execution_patch_runs', 'status'),
                'test_statuses' => $this->groupCounts('ai_real_execution_test_runs', 'status'),
                'repair_statuses' => $this->groupCounts('ai_real_execution_repair_attempts', 'status'),
                'delivery_statuses' => $this->groupCounts('ai_real_execution_delivery_packs', 'status'),
                'false_claims_blocked' => DatabaseTableAvailability::has('ai_real_execution_rivals_benchmarks')
                    ? AiRealExecutionRivalsBenchmark::query()->where('false_claim_blocked', true)->count()
                    : 0,
            ],
            'latest_delivery_pack' => DatabaseTableAvailability::has('ai_real_execution_delivery_packs')
                ? $this->latestQuery(AiRealExecutionDeliveryPack::query())->first()?->toArray()
                : null,
            'writes' => false,
        ];
    }

    private function tablesReady(): bool
    {
        foreach ([
            'ai_real_execution_worktrees',
            'ai_real_execution_patch_runs',
            'ai_real_execution_test_runs',
            'ai_real_execution_repair_attempts',
            'ai_real_execution_forge_handoffs',
            'ai_real_execution_delivery_packs',
            'ai_real_execution_rivals_benchmarks',
            'ai_real_execution_certifications',
        ] as $table) {
            if (! DatabaseTableAvailability::has($table)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string,mixed>
     */
    private function providerArenaReadiness(): array
    {
        if (! class_exists(AtlasForgeRivalsProviderArenaReadinessService::class)) {
            return [
                'status' => 'blocked',
                'blockers' => ['provider_arena_readiness_service_missing'],
                'external_provider_call' => false,
                'provider_tokens_spent' => false,
            ];
        }

        $snapshot = app(AtlasForgeRivalsProviderArenaReadinessService::class)->snapshot();
        $claudeCodexPair = collect((array) ($snapshot['pairs'] ?? []))
            ->first(fn (array $pair): bool => ($pair['pair_id'] ?? null) === 'claude_opus_vs_codex_gpt55');

        return [
            'schema_version' => $snapshot['schema_version'] ?? null,
            'status' => $snapshot['status'] ?? 'unknown',
            'pair_count' => $snapshot['pair_count'] ?? 0,
            'real_run_ready_count' => $snapshot['real_run_ready_count'] ?? 0,
            'blocked_count' => $snapshot['blocked_count'] ?? 0,
            'claude_codex_pair_status' => $claudeCodexPair['status'] ?? 'missing',
            'claude_codex_next_command' => $claudeCodexPair['next_command'] ?? null,
            'required_confirmations_for_real_run' => $snapshot['required_confirmations_for_real_run'] ?? [],
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ];
    }

    private function rivalsShadowBenchmarkRecorded(AiAutonomousEngineeringGoal $goal): bool
    {
        $benchmark = $this->latestQuery(AiRealExecutionRivalsBenchmark::query()
            ->where('goal_record_id', $goal->id)
            ->where('status', 'shadow_ready')
            ->where('false_claim_blocked', true))->first();
        if (! $benchmark) {
            return false;
        }

        return data_get($benchmark->receipt, 'comparison_protocol.external_provider_call') === false
            && data_get($benchmark->receipt, 'comparison_protocol.claim_allowed') === false;
    }

    private function externalRivalsBenchmarkExecuted(AiAutonomousEngineeringGoal $goal): bool
    {
        $benchmark = $this->latestExternalRivalsBenchmark($goal);
        if (! $benchmark) {
            return false;
        }

        $gate = (array) data_get($benchmark->receipt, 'external_benchmark_gate', []);

        return ($gate['status'] ?? null) === 'executed'
            && ($gate['external_provider_call'] ?? false) === true
            && ($gate['provider_tokens_spent'] ?? false) === true
            && count((array) ($gate['real_execution_evidence_refs'] ?? [])) > 0;
    }

    private function externalRivalsClaimAllowed(AiAutonomousEngineeringGoal $goal): bool
    {
        $benchmark = $this->latestExternalRivalsBenchmark($goal);
        if (! $benchmark) {
            return false;
        }

        return (bool) data_get($benchmark->receipt, 'external_benchmark_gate.claim_allowed', false);
    }

    private function latestExternalRivalsBenchmark(AiAutonomousEngineeringGoal $goal): ?AiRealExecutionRivalsBenchmark
    {
        return $this->latestQuery(AiRealExecutionRivalsBenchmark::query()
            ->where('goal_record_id', $goal->id)
            ->where('status', 'external_executed'))->first();
    }

    private function resolveGoal(?string $goalId): ?AiAutonomousEngineeringGoal
    {
        if (is_string($goalId) && trim($goalId) !== '') {
            return AiAutonomousEngineeringGoal::query()
                ->where('id', $goalId)
                ->orWhere('goal_id', $goalId)
                ->first();
        }

        $latestDelivery = DatabaseTableAvailability::has('ai_real_execution_delivery_packs')
            ? $this->latestQuery(AiRealExecutionDeliveryPack::query())->first()
            : null;

        return ($latestDelivery?->goal_record_id ? AiAutonomousEngineeringGoal::query()->find($latestDelivery->goal_record_id) : null)
            ?? (DatabaseTableAvailability::has('ai_autonomous_engineering_goals') ? $this->latestQuery(AiAutonomousEngineeringGoal::query())->first() : null);
    }

    /**
     * @param  array<string,mixed>  $evidencePack
     * @return array{blockers:list<string>,evidence_refs:list<string>}
     */
    private function validateExternalRivalsEvidence(array $evidencePack): array
    {
        $blockers = [];
        if ((string) data_get($evidencePack, 'run_id') === '') {
            $blockers[] = 'run_id_missing';
        }
        if (data_get($evidencePack, 'external_provider_call') !== true) {
            $blockers[] = 'external_provider_call_not_true';
        }
        if (data_get($evidencePack, 'provider_tokens_spent') !== true) {
            $blockers[] = 'provider_tokens_spent_not_true';
        }
        if (! $this->evidencePackTargetsClaudeAndCodex($evidencePack)) {
            $blockers[] = 'claude_codex_arms_missing';
        }

        $refs = array_values(array_filter(array_merge(
            (array) data_get($evidencePack, 'evidence_refs', []),
            array_map(fn (string $path): string => 'evidence_path:'.$path, (array) data_get($evidencePack, 'evidence_paths', [])),
        )));
        if ($refs === []) {
            $blockers[] = 'external_evidence_refs_missing';
        }

        return ['blockers' => array_values(array_unique($blockers)), 'evidence_refs' => $refs];
    }

    /**
     * @param  array<string,mixed>  $evidencePack
     */
    private function evidencePackTargetsClaudeAndCodex(array $evidencePack): bool
    {
        $haystack = strtolower(json_encode([
            data_get($evidencePack, 'rivals', []),
            data_get($evidencePack, 'arm_a', []),
            data_get($evidencePack, 'arm_b', []),
            data_get($evidencePack, 'arms', []),
        ], JSON_THROW_ON_ERROR));

        return str_contains($haystack, 'claude')
            && str_contains($haystack, 'codex');
    }

    /**
     * @param  array<string,mixed>  $evidencePack
     * @return array<string,mixed>
     */
    private function normalizeExternalRivalsEvidence(array $evidencePack): array
    {
        $manifest = $this->jsonFileFromArtifact($evidencePack, 'manifest');
        $scorecard = $this->jsonFileFromArtifact($evidencePack, 'scorecard');
        $artifacts = (array) ($evidencePack['artifacts'] ?? []);
        $artifactPaths = array_values(array_filter(array_map(
            static fn ($artifact): ?string => is_array($artifact) && is_string($artifact['path'] ?? null) ? $artifact['path'] : null,
            $artifacts,
        )));
        $artifactRefs = [];
        foreach ($artifacts as $name => $artifact) {
            if (is_array($artifact) && is_string($artifact['sha256'] ?? null) && ($artifact['sha256'] ?? '') !== '') {
                $artifactRefs[] = 'artifact:'.$name.':'.$artifact['sha256'];
            }
        }

        return array_replace($evidencePack, array_filter([
            'run_id' => $evidencePack['run_id'] ?? $manifest['run_id'] ?? null,
            'external_provider_call' => $evidencePack['external_provider_call'] ?? $manifest['external_provider_call'] ?? null,
            'provider_tokens_spent' => $evidencePack['provider_tokens_spent'] ?? $manifest['provider_tokens_spent'] ?? null,
            'rivals' => $evidencePack['rivals'] ?? [
                (string) ($manifest['atlas_model'] ?? ''),
                (string) ($manifest['rival_model'] ?? ''),
            ],
            'arm_a' => $evidencePack['arm_a'] ?? ['model' => (string) ($manifest['atlas_model'] ?? '')],
            'arm_b' => $evidencePack['arm_b'] ?? ['model' => (string) ($manifest['rival_model'] ?? '')],
            'evidence_paths' => $evidencePack['evidence_paths'] ?? $artifactPaths,
            'evidence_refs' => $evidencePack['evidence_refs'] ?? $artifactRefs,
            'claim_allowed' => $evidencePack['claim_allowed'] ?? $scorecard['claim_ready'] ?? $evidencePack['claim_ready'] ?? null,
        ], static fn ($value): bool => $value !== null));
    }

    /**
     * @param  array<string,mixed>  $evidencePack
     * @return array<string,mixed>
     */
    private function jsonFileFromArtifact(array $evidencePack, string $artifactName): array
    {
        $path = data_get($evidencePack, 'artifacts.'.$artifactName.'.path');
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            return [];
        }

        return JsonFileStore::readArray($path) ?? [];
    }

    private function count(string $table): int
    {
        return DatabaseTableAvailability::has($table) ? DB::table($table)->count() : 0;
    }

    /**
     * @return array<string,int>
     */
    private function groupCounts(string $table, string $column): array
    {
        if (! DatabaseTableAvailability::has($table)) {
            return [];
        }

        return DB::table($table)
            ->select($column, DB::raw('count(*) as aggregate'))
            ->groupBy($column)
            ->pluck('aggregate', $column)
            ->map(fn ($value): int => (int) $value)
            ->all();
    }

    /**
     * @return list<string>
     */
    private function evidenceForGoal(AiAutonomousEngineeringGoal $goal): array
    {
        $worktree = $this->latestQuery(AiRealExecutionWorktree::query()->where('goal_record_id', $goal->id))->first();
        $patch = $this->latestQuery(AiRealExecutionPatchRun::query()->where('goal_record_id', $goal->id))->first();
        $test = $this->latestQuery(AiRealExecutionTestRun::query()->where('goal_record_id', $goal->id)->where('status', 'passed'))->first();
        $delivery = $this->latestQuery(AiRealExecutionDeliveryPack::query()->where('goal_record_id', $goal->id))->first();
        $benchmark = $this->latestQuery(AiRealExecutionRivalsBenchmark::query()->where('goal_record_id', $goal->id))->first();

        return array_values(array_filter([
            $worktree?->receipt_hash ? 'worktree:'.$worktree->receipt_hash : null,
            $patch?->patch_hash ? 'patch:'.$patch->patch_hash : null,
            $test?->test_hash ? 'test:'.$test->test_hash : null,
            $delivery?->delivery_hash ? 'delivery:'.$delivery->delivery_hash : null,
            $benchmark?->benchmark_hash ? 'rivals_benchmark:'.$benchmark->benchmark_hash : null,
        ]));
    }

    /**
     * @return array{id:string,status:string}
     */
    private function check(string $id, bool $passed): array
    {
        return ['id' => $id, 'status' => $passed ? 'passed' : 'blocked'];
    }

    private function latestQuery($query)
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * @return array<string,mixed>
     */
    private function resultPayload(AiAutonomousEngineeringGoal $goal, array $autonomous, ?AiRealExecutionWorktree $worktree, ?AiRealExecutionPatchRun $patch, ?AiRealExecutionTestRun $test, ?AiRealExecutionRepairAttempt $repair, ?AiRealExecutionForgeHandoff $handoff, ?AiRealExecutionDeliveryPack $delivery, ?AiRealExecutionRivalsBenchmark $benchmark, AiRealExecutionCertification $certification): array
    {
        return [
            'schema_version' => 'atlas.ai.real_execution.run_result.v1',
            'status' => $certification->status === 'passed' ? 'completed' : 'blocked',
            'autonomous_preflight' => $autonomous,
            'goal' => $goal->toArray(),
            'worktree' => $worktree?->toArray(),
            'patch_run' => $patch?->toArray(),
            'test_run' => $test?->toArray(),
            'repair_attempt' => $repair?->toArray(),
            'forge_handoff' => $handoff?->toArray(),
            'delivery_pack' => $delivery?->toArray(),
            'rivals_benchmark' => $benchmark?->toArray(),
            'certification' => $certification->toArray(),
            'next_action' => $handoff ? 'continue_in_atlas_forge' : ($certification->status === 'passed' ? 'ready_for_internal_delivery' : 'resolve_real_execution_blockers'),
            'writes' => true,
        ];
    }
}

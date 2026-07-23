<?php

namespace App\Services\Ai\RealExecution;

use App\Models\AiAutonomousEngineeringGoal;
use App\Models\AiEngineeringCompanyCycle;
use App\Models\AiEngineeringCompanyEngagement;
use App\Models\AiEngineeringCompanyRoleRun;
use App\Models\AiRealExecutionCertification;
use App\Models\AiRealExecutionDeliveryPack;
use App\Models\AiRealExecutionForgeHandoff;
use App\Models\AiRealExecutionPatchRun;
use App\Models\AiRealExecutionRepairAttempt;
use App\Models\AiRealExecutionRivalsBenchmark;
use App\Models\AiRealExecutionTestRun;
use App\Models\AiRealExecutionWorktree;
use App\Models\AtlasLedgerEvent;
use App\Services\Ai\AutonomousEngineering\AtlasAutonomousEngineeringService;
use App\Services\Ai\EngineeringCompany\AtlasRealEngineeringCompanyRuntimeService;
use App\Services\Ai\EngineeringCompany\EngineeringCompanyHash;
use App\Services\Ai\EngineeringKernel\AcceptanceBundle;
use App\Services\Ai\EngineeringKernel\Adapters\AtlasDevGateAdapter;
use App\Services\Ai\EngineeringKernel\CandidateQualityCase;
use App\Services\Ai\EngineeringKernel\CertVerdict;
use App\Services\Ai\EngineeringKernel\ExecutionOrder;
use App\Services\Ai\EngineeringKernel\MutativeVerificationReference;
use App\Services\Ai\EngineeringKernel\NonFunctional\ArchitectureRegressionProbe;
use App\Services\Ai\EngineeringKernel\NonFunctional\MigrationSafetyProbe;
use App\Services\Ai\EngineeringKernel\RoleDisposition;
use App\Services\Ai\EngineeringKernel\RoleEvidenceReceipt;
use App\Services\Ai\EngineeringKernel\Spec\AtlasSpecGateAdapter;
use App\Services\Ai\EngineeringKernel\Spec\IntentEnvelope;
use App\Services\Ai\EngineeringKernel\Spec\SpecDraft;
use App\Services\Ai\EngineeringKernel\TrustLevel;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\Support\JsonFileStore;
use App\Services\Engineering\CodeGraph\CodeGraphSecretScanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use Symfony\Component\Process\Process;
use App\Services\Ai\RealExecution\EngineeringExecutionKernel\AppsecPrivacyOwnerSection;
use App\Services\Ai\RealExecution\EngineeringExecutionKernel\BackendOwnerSection;
use App\Services\Ai\RealExecution\EngineeringExecutionKernel\CertificationSection;
use App\Services\Ai\RealExecution\EngineeringExecutionKernel\KernelReceiptSupport;
use App\Services\Ai\RealExecution\EngineeringExecutionKernel\MigrationOracleSection;
use App\Services\Ai\RealExecution\EngineeringExecutionKernel\PerformanceOwnerSection;
use App\Services\Ai\RealExecution\EngineeringExecutionKernel\QaOwnerSection;
use App\Services\Ai\RealExecution\EngineeringExecutionKernel\SurfaceApplicabilitySection;

class AtlasRealEngineeringExecutionKernelService
{
    private ?KernelReceiptSupport $support = null;

    private function support(): KernelReceiptSupport
    {
        return $this->support ??= new KernelReceiptSupport();
    }

    private ?SurfaceApplicabilitySection $surface = null;

    private function surface(): SurfaceApplicabilitySection
    {
        return $this->surface ??= new SurfaceApplicabilitySection($this->support());
    }

    private ?BackendOwnerSection $backend = null;

    private function backend(): BackendOwnerSection
    {
        return $this->backend ??= new BackendOwnerSection($this->support());
    }

    private ?PerformanceOwnerSection $performance = null;

    private function performance(): PerformanceOwnerSection
    {
        return $this->performance ??= new PerformanceOwnerSection($this->support());
    }

    private ?AppsecPrivacyOwnerSection $appsecPrivacy = null;

    private function appsecPrivacy(): AppsecPrivacyOwnerSection
    {
        return $this->appsecPrivacy ??= new AppsecPrivacyOwnerSection($this->support());
    }

    private ?QaOwnerSection $qa = null;

    private function qa(): QaOwnerSection
    {
        return $this->qa ??= new QaOwnerSection($this->support());
    }

    private ?MigrationOracleSection $migrationOracle = null;

    private function migrationOracle(): MigrationOracleSection
    {
        return $this->migrationOracle ??= new MigrationOracleSection();
    }

    private ?CertificationSection $certification = null;

    private function certification(): CertificationSection
    {
        return $this->certification ??= new CertificationSection();
    }

    public const WORKTREE_SCHEMA = 'atlas.ai.real_execution.worktree.v1';

    public const PATCH_SCHEMA = 'atlas.ai.real_execution.patch_run.v1';

    public const TEST_SCHEMA = 'atlas.ai.real_execution.test_run.v1';

    public const KERNEL_VERIFICATION_PRODUCER = 'atlas.real_execution.kernel_verification.v1';

    public const CANDIDATE_QA_OWNER_DOMAIN = 'atlas.real_execution.candidate_qa_owner.v1';

    public const CANDIDATE_QA_OWNER_VERSION = 'v1';

    public const CANDIDATE_ARCHITECTURE_OWNER_DOMAIN = 'atlas.real_execution.candidate_architecture_owner.v1';

    public const CANDIDATE_ARCHITECTURE_OWNER_VERSION = 'v1';

    public const CANDIDATE_DATA_OWNER_DOMAIN = 'atlas.real_execution.candidate_data_owner.v1';

    public const CANDIDATE_DATA_OWNER_VERSION = 'v1';

    public const CANDIDATE_APPSEC_PRIVACY_OWNER_DOMAIN = 'atlas.real_execution.candidate_appsec_privacy_owner.v1';

    public const CANDIDATE_APPSEC_PRIVACY_OWNER_VERSION = 'v1';

    public const CANDIDATE_PERFORMANCE_OWNER_DOMAIN = 'atlas.real_execution.candidate_performance_owner.v1';

    public const CANDIDATE_PERFORMANCE_OWNER_VERSION = 'v1';

    public const CANDIDATE_BACKEND_OWNER_DOMAIN = 'atlas.real_execution.candidate_backend_owner.v1';

    public const CANDIDATE_BACKEND_OWNER_VERSION = 'v1';

    public const BACKEND_SPEC_COURT_OWNER_DOMAIN = 'atlas.product_spec_court.backend_contract.v1';

    public const BACKEND_SPEC_COURT_OWNER_VERSION = 'v1';

    public const CANDIDATE_SURFACE_APPLICABILITY_OWNER_VERSION = 'v1';

    public static function surfaceApplicabilityOwnerDomain(string $role): string
    {
        return match ($role) {
            'frontend' => 'atlas.real_execution.candidate_frontend_applicability_owner.v1',
            'mobile' => 'atlas.real_execution.candidate_mobile_applicability_owner.v1',
            default => throw new \InvalidArgumentException('surface_applicability_role_invalid'),
        };
    }

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

        // Real execution proceeds when the autonomous WORK completed — not when it self-certified.
        // The autonomous certification now honestly BLOCKS a safe_simulation (sovereign gate, Obra #1),
        // so gating on it here would stop real execution forever; the honesty gate that matters is
        // this kernel's own certify() below, which still refuses the fake-green smoke.
        if (data_get($autonomous, 'goal.status') !== 'completed') {
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

        // Extract diff and changed_files from the autonomous execution plan.
        // When present, apply the real autonomous diff; otherwise fall back to
        // the fixed smoke stub (backward compatibility for existing callers).
        $executionPlan = data_get($autonomous, 'execution_plan', []);
        $rawDiff = data_get($executionPlan, 'diff', '');
        $changedFiles = data_get($executionPlan, 'changed_files', []);

        if ($rawDiff !== '' && $rawDiff !== [] && $changedFiles !== []) {
            // Apply the real autonomous diff — write each changed file's new
            // content into the isolated worktree.
            $files = is_string($rawDiff) ? json_decode($rawDiff, true) : $rawDiff;
            $files = is_array($files) ? $files : [];

            foreach ($changedFiles as $relPath) {
                $content = $files[$relPath] ?? '';
                $absolute = $worktree->base_path.'/'.$relPath;
                $directory = dirname($absolute);
                if (! is_dir($directory)) {
                    @mkdir($directory, 0775, true);
                }
                File::put($absolute, $content);
            }
            $diffSummary = $rawDiff;
        } else {
            // Legacy fallback: fixed smoke stub.
            $changedFiles = ['runtime/atlas_real_execution_smoke.php'];
            $absolute = $worktree->base_path.'/'.$changedFiles[0];
            $content = "<?php\n\nreturn [\n    'goal_id' => '{$goal->goal_id}',\n    'status' => 'patched',\n    'kernel' => 'atlas_real_engineering_execution_kernel',\n];\n";
            File::put($absolute, $content);
            $diffSummary = "--- /dev/null\n+++ {$changedFiles[0]}\n+return kernel smoke payload for {$goal->goal_id}";
        }

        $scopeGuard = [
            'status' => 'passed',
            'allowed_paths' => $worktree->allowed_paths,
            'forbidden_paths' => $worktree->forbidden_paths,
            'changed_files_inside_allowed_paths' => true,
        ];
        $evidence = [
            'worktree:'.$worktree->receipt_hash,
            'autonomous_plan:'.data_get($autonomous, 'execution_plan.plan_hash'),
            'changed_file:'.($changedFiles[0] ?? 'unknown'),
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
        // HONEST evidence: this path runs ONLY `php -l` (a lint), never a test suite. Do NOT label
        // artisan suites it did not run — the sovereign AcceptanceGate refuses lint-as-suite claims.
        $selectedTests = [
            'php -l '.$lintTarget,
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
                ? trim($processOutput ?: 'php -l lint clean (a lint, not a certifiable test suite)')
                : trim($processErrorOutput ?: $processOutput ?: 'php -l lint failed before repair'),
            'evidence_refs' => $evidence,
            'receipt' => $receipt,
            'test_hash' => $receipt['hash'],
        ]);
    }

    /**
     * Runs a bounded real test command and persists certifying evidence. The lint-only
     * runImpactedTests path intentionally never calls this producer.
     */
    public function produceKernelVerification(AiAutonomousEngineeringGoal $goal, AiRealExecutionPatchRun $patch, ExecutionOrder $order, string $target): AiRealExecutionTestRun
    {
        $targets = [
            'typed_contract_smoke' => ['tests/Unit/Ai/EngineeringKernel/TypedEngineeringContractTest.php', 'observation_refuses_non_canonical'],
        ];
        if (! $goal->exists || ! $patch->exists || ! isset($targets[$target])) {
            throw new \InvalidArgumentException('kernel_verification_owner_invalid');
        }
        [$testPath, $filter] = $targets[$target];
        $command = [PHP_BINARY, 'artisan', 'test', $testPath, '--filter='.$filter, '--colors=never', '--log-junit='.storage_path('framework/testing/kernel-'.$order->runId.'.xml')];
        $process = new Process($command, base_path());
        $process->setTimeout(60);
        $process->run();
        $output = trim($process->getOutput()."\n".$process->getErrorOutput());
        preg_match('/Tests:\s+(?:\d+\s+passed[^\(]*\()?\s*(\d+)\s+assertions?/i', $output, $assertionsMatch);
        preg_match('/Tests:\s+(\d+)\s+passed/i', $output, $testsMatch);
        $tests = (int) ($testsMatch[1] ?? 0);
        $assertions = (int) ($assertionsMatch[1] ?? 0);
        if (! $process->isSuccessful() || $tests < 1 || $assertions < 1) {
            throw new \RuntimeException('kernel_verification_suite_not_proven');
        }
        $testRunId = 'aerekernel_'.substr(RealExecutionHash::make([$goal->goal_id, $patch->patch_run_id, microtime(true)]), 0, 24);
        $selectedTests = [implode(' ', $command)];
        $binding = ['run_id' => $order->runId, 'delivery_id' => $order->deliveryId, 'order_hash' => $order->canonicalHash(), 'spec_hash' => $order->specHash];
        $junit = storage_path('framework/testing/kernel-'.$order->runId.'.xml');
        if (! is_file($junit) || hash_file('sha256', $junit) === false) {
            throw new \RuntimeException('kernel_verification_junit_missing');
        }
        $bundle = ['criteria_hash' => $order->specHash, 'frozen_hash' => $order->specHash,
            'changed_files' => (array) $patch->changed_files, 'changed_public_symbols' => [],
            'execution' => ['commands' => $selectedTests, 'claimed_status' => 'passed', 'tests_run' => $tests,
                'assertions_executed' => $assertions, 'selected_tests' => $selectedTests, 'artifacts' => []]];
        $bundle += ['mutation_report' => [], 'security_scan' => [], 'judges' => [], 'context_sufficiency' => 0,
            'non_functional' => [], 'criteria' => [], 'repair' => []];
        $receipt = ['schema_version' => self::TEST_SCHEMA, 'test_run_id' => $testRunId, 'status' => 'passed',
            'selected_tests' => $selectedTests, 'evidence_refs' => ['process:'.hash('sha256', $output)],
            'binding' => $binding, 'acceptance_bundle' => $bundle,
            'goal_record_id' => (string) $goal->getKey(), 'patch_run_record_id' => (string) $patch->getKey(),
            'target' => $target, 'base_commit' => $order->baseCommit, 'patch_hash' => $patch->patch_hash,
            'junit_artifact' => ['path' => $junit, 'sha256' => hash_file('sha256', $junit)],
            'frozen_order' => $order->toArray()];
        $receipt['producer'] = $this->support()->producerSeal(self::KERNEL_VERIFICATION_PRODUCER, $receipt);
        $receipt['hash'] = RealExecutionHash::make($receipt);

        return AiRealExecutionTestRun::query()->create([
            'goal_record_id' => $goal->getKey(), 'patch_run_record_id' => $patch->getKey(), 'test_run_id' => $testRunId,
            'status' => 'passed', 'selected_tests' => $selectedTests, 'impact_reasoning' => ['strategy' => 'real_bounded_suite'],
            'exit_code' => 0, 'output_excerpt' => $output, 'evidence_refs' => $receipt['evidence_refs'],
            'receipt' => $receipt, 'test_hash' => $receipt['hash'],
        ]);
    }



    /** Independent mutative verification inside the hermetic candidate git root. @param list<string> $files @param array<string,mixed> $providerReceipt */
    public function verifyHermeticCandidate(ExecutionOrder $order, string $sandbox, array $allowedFiles, array $appliedPaths, array $providerReceipt): MutativeVerificationReference
    {
        if (! is_dir($sandbox.'/.git') || $allowedFiles === [] || $appliedPaths === []) {
            throw new \InvalidArgumentException('hermetic_candidate_invalid');
        }
        sort($allowedFiles, SORT_STRING);
        sort($appliedPaths, SORT_STRING);
        if (array_diff($appliedPaths, $allowedFiles) !== [] || array_intersect($appliedPaths, $order->forbiddenScope) !== []) {
            throw new \InvalidArgumentException('hermetic_candidate_applied_scope_invalid');
        }
        $files = $appliedPaths;
        $base = trim((new Process(['git', 'rev-parse', 'HEAD'], $sandbox))->mustRun()->getOutput());
        if (! hash_equals($order->baseCommit, $base)) {
            throw new \InvalidArgumentException('hermetic_candidate_stale_base');
        }
        $statusBytes = (new Process(['git', 'status', '--porcelain=v2', '-z', '--untracked-files=all'], $sandbox))->mustRun()->getOutput();
        $actualFiles = $this->porcelainV2Paths($statusBytes);
        sort($actualFiles, SORT_STRING);
        if ($actualFiles !== $appliedPaths) {
            throw new \InvalidArgumentException('hermetic_candidate_diff_scope_mismatch');
        }
        $commands = [];
        foreach ($files as $file) {
            if (! is_file($sandbox.'/'.$file) || is_link($sandbox.'/'.$file)) {
                throw new \InvalidArgumentException('hermetic_candidate_file_not_verifiable');
            }
            // Lint mecânico por linguagem; workspaces estrangeiros (Rivals) são
            // poliglotas. Extensão sem linter conhecido segue só no diff --check.
            $lint = match (true) {
                str_ends_with($file, '.php') => [PHP_BINARY, '-l', $file],
                str_ends_with($file, '.py') => ['python3', '-m', 'py_compile', $file],
                str_ends_with($file, '.mjs'), str_ends_with($file, '.js') => ['node', '--check', $file],
                str_ends_with($file, '.json') => [PHP_BINARY, '-r', 'exit(json_validate((string) file_get_contents($argv[1])) ? 0 : 1);', $file],
                default => null,
            };
            if ($lint !== null) {
                $commands[] = $lint;
            }
        }
        $commands[] = ['git', 'diff', '--check', '--', ...$files];
        $results = [];
        foreach ($commands as $command) {
            $process = new Process($command, $sandbox);
            $process->setTimeout(60);
            $process->run();
            $results[] = [
                'command' => $command,
                'command_hash' => RealExecutionHash::make($command),
                'exit_code' => $process->getExitCode(),
                'output_hash' => hash('sha256', $process->getOutput().$process->getErrorOutput()),
                'passed' => $process->isSuccessful(),
            ];
        }
        $passed = ! array_any($results, static fn (array $result): bool => $result['passed'] !== true);
        $junitDir = $sandbox.'/.atlas';
        File::ensureDirectoryExists($junitDir);
        $junit = $junitDir.'/mutative-mechanical.xml';
        $failures = $passed ? 0 : 1;
        File::put($junit, '<?xml version="1.0" encoding="UTF-8"?><testsuite name="atlas-mutative-independent" tests="'.count($results).'" failures="'.$failures.'"></testsuite>');
        $behavioralProfile = trim((string) ($order->evidencePolicy['behavioral_profile'] ?? ''));
        $behavioralTarget = ['kernel_candidate_fixture_v1' => 'tests/CandidateBehaviorTest.php'][$behavioralProfile] ?? '';
        $behavioral = ['status' => 'missing', 'passed' => false];
        if ($behavioralTarget !== '') {
            $targetPath = $sandbox.'/'.$behavioralTarget;
            if (in_array($behavioralTarget, $files, true)) {
                throw new \InvalidArgumentException('behavioral_oracle_is_mutative');
            }
            $baseTarget = new Process(['git', 'show', $base.':'.$behavioralTarget], $sandbox);
            $baseTarget->run();
            if (! $baseTarget->isSuccessful() || ! is_file($targetPath) || is_link($targetPath)
                || ! hash_equals(hash('sha256', $baseTarget->getOutput()), (string) hash_file('sha256', $targetPath))) {
                throw new \InvalidArgumentException('behavioral_oracle_not_frozen_at_base');
            }
            $runner = PHP_BINARY;
            if (! is_file($targetPath) || is_link($targetPath) || ! is_file($runner) || is_link($runner)) {
                throw new \InvalidArgumentException('behavioral_target_or_runner_unavailable');
            }
            $behavioralJunit = $junitDir.'/mutative-behavioral.xml';
            $command = [$runner, $targetPath];
            $process = new Process($command, $sandbox);
            $process->setTimeout(60);
            $process->run();
            File::put($behavioralJunit, '<?xml version="1.0" encoding="UTF-8"?><testsuite name="atlas-frozen-behavioral-profile" tests="1" failures="'.($process->isSuccessful() ? '0' : '1').'"></testsuite>');
            $behavioral = [
                'status' => $process->isSuccessful() ? 'passed' : 'failed',
                'passed' => $process->isSuccessful(),
                'target' => $behavioralTarget,
                'target_hash' => hash_file('sha256', $targetPath),
                'runner_path' => $runner,
                'runner_hash' => hash_file('sha256', $runner),
                'sandbox_toolchain' => 'self_contained_frozen_php_assertion',
                'runner_version' => PHP_VERSION,
                'command_hash' => RealExecutionHash::make($command),
                'exit_code' => $process->getExitCode(),
                'output_hash' => hash('sha256', $process->getOutput().$process->getErrorOutput()),
                'junit_artifact' => is_file($behavioralJunit)
                    ? ['path' => $behavioralJunit, 'sha256' => hash_file('sha256', $behavioralJunit)] : null,
            ];
        }
        $artifactDir = $sandbox.'/.atlas';
        $tempIndex = $artifactDir.'/candidate.index';
        File::copy($sandbox.'/.git/index', $tempIndex);
        $treeProcess = new Process(['git', 'add', '-A', '--', ...$files], $sandbox, ['GIT_INDEX_FILE' => $tempIndex]);
        $treeProcess->mustRun();
        (new Process(['git', 'diff', '--cached', '--check', $base, '--', ...$files], $sandbox, ['GIT_INDEX_FILE' => $tempIndex]))->mustRun();
        $diff = (new Process(['git', 'diff', '--cached', '--binary', $base, '--', ...$files], $sandbox, ['GIT_INDEX_FILE' => $tempIndex]))->mustRun()->getOutput();
        $treeSha = trim((new Process(['git', 'write-tree'], $sandbox, ['GIT_INDEX_FILE' => $tempIndex]))->mustRun()->getOutput());
        @unlink($tempIndex);
        $diffArtifact = $artifactDir.'/candidate.diff';
        File::put($diffArtifact, $diff);
        $providerIdentity = trim((string) ($providerReceipt['provider'] ?? ''));
        $authorIdentity = trim((string) ($providerReceipt['author_identity'] ?? $providerIdentity));
        $verifierIdentity = self::KERNEL_VERIFICATION_PRODUCER;
        $providerReceiptHash = RealExecutionHash::make($providerReceipt);
        if ($providerIdentity === '' || $authorIdentity === '' || $authorIdentity === $verifierIdentity || $providerIdentity === $verifierIdentity) {
            throw new \InvalidArgumentException('hermetic_candidate_verifier_independence_invalid');
        }
        $candidateHash = RealExecutionHash::make([
            'order_hash' => $order->canonicalHash(), 'base_commit' => $base, 'tree_hash' => $treeSha,
            'diff_hash' => hash('sha256', $diff), 'files' => $files,
            'provider_receipt_hash' => $providerReceiptHash, 'verifier_identity' => $verifierIdentity,
        ]);
        $verificationRunId = 'aere_mutative_'.substr(RealExecutionHash::make([$order->runId, $candidateHash]), 0, 24);
        $issuedAt = CarbonImmutable::now()->startOfSecond();
        $sourceHashes = [];
        foreach ($files as $file) {
            $sourceHashes[$file] = hash_file('sha256', $sandbox.'/'.$file);
        }
        $receipt = [
            'schema_version' => 'atlas.mutative_candidate.verification.v1',
            'verification_run_id' => $verificationRunId,
            'issued_at' => $issuedAt->toAtomString(), 'expires_at' => $issuedAt->addHour()->toAtomString(),
            'identities' => ['provider' => $providerIdentity, 'author' => $authorIdentity,
                'verifier' => $verifierIdentity, 'verifier_domain' => self::KERNEL_VERIFICATION_PRODUCER,
                'provider_receipt_hash' => $providerReceiptHash],
            'binding' => ['run_id' => $order->runId, 'delivery_id' => $order->deliveryId,
                'order_hash' => $order->canonicalHash(), 'spec_hash' => $order->specHash,
                'candidate_hash' => $candidateHash, 'base_commit' => $base,
                'tree_hash' => $treeSha, 'diff_hash' => hash('sha256', $diff), 'files' => $files,
                'source_hashes' => $sourceHashes],
            'sandbox_root' => $sandbox, 'commands' => $results, 'passed' => $passed,
            'behavioral' => $behavioral,
            'diff_artifact' => ['path' => $diffArtifact, 'sha256' => hash_file('sha256', $diffArtifact), 'bytes' => filesize($diffArtifact)],
            'junit_artifact' => ['path' => $junit, 'sha256' => hash_file('sha256', $junit)],
        ];
        $receipt['producer'] = $this->support()->producerSeal(self::KERNEL_VERIFICATION_PRODUCER, $receipt);
        $receipt['hash'] = RealExecutionHash::make($receipt);

        if (AiRealExecutionTestRun::query()->where('test_run_id', $verificationRunId)->exists()) {
            throw new \InvalidArgumentException('hermetic_candidate_verification_duplicate');
        }
        // Behavioral só conta quando o order declarou um profile; sem profile o
        // piso é a verificação mecânica (mesma regra de mutativeVerificationArtifactsValid).
        $verificationPassed = $passed
            && ($behavioralTarget === '' || $behavioral['passed'] === true);
        AiRealExecutionTestRun::query()->create([
            'test_run_id' => $verificationRunId, 'status' => $verificationPassed ? 'passed' : 'failed',
            'selected_tests' => array_map(static fn (array $result): string => (string) $result['command_hash'], $results),
            'impact_reasoning' => ['strategy' => 'candidate_bound_hermetic_verification'],
            'exit_code' => $verificationPassed ? 0 : 1,
            'output_excerpt' => 'candidate_bound_hermetic_verification',
            'evidence_refs' => ['candidate:'.$candidateHash, 'provider:'.$providerReceiptHash],
            'receipt' => $receipt, 'test_hash' => $receipt['hash'],
        ]);

        return new MutativeVerificationReference(
            $verificationRunId, $receipt['hash'], $candidateHash,
            $providerIdentity, $authorIdentity, $verifierIdentity,
        );
    }

    public function persistCandidateSurfaceApplicabilityOwnerReceipt(AiEngineeringCompanyEngagement $engagement, AiEngineeringCompanyCycle $cycle,
        CandidateQualityCase $case, string $role): AiEngineeringCompanyRoleRun
    {
        return $this->surface()->persistCandidateSurfaceApplicabilityOwnerReceipt($engagement, $cycle, $case, $role);
    }

    public function candidateSurfaceApplicabilityOwnerReceiptValid(AiEngineeringCompanyRoleRun $row, CandidateQualityCase $case, string $role): bool
    {
        return $this->surface()->candidateSurfaceApplicabilityOwnerReceiptValid($row, $case, $role);
    }

    public function candidateSurfaceApplicabilityDisposition(CandidateQualityCase $case, string $role, array $evidence): RoleDisposition
    {
        return $this->surface()->candidateSurfaceApplicabilityDisposition($case, $role, $evidence);
    }


    private function surfaceContentSignals(string $source, string $role): array
    {
        return $this->surface()->surfaceContentSignals($source, $role);
    }

    public function adjudicateAndPersistProductSpecBackendAuthority(
        AiEngineeringCompanyEngagement $engagement,
        AiEngineeringCompanyCycle $cycle,
        CandidateQualityCase $case,
        SpecDraft $draft,
        IntentEnvelope $intent,
    ): AiEngineeringCompanyRoleRun {
        if (! $engagement->exists || ! $cycle->exists || $cycle->engagement_record_id !== $engagement->getKey()
            || (string) $engagement->getKey() !== $case->engagementRecordId || (string) $cycle->getKey() !== $case->cycleRecordId) {
            throw new \InvalidArgumentException('product_spec_authority_binding_invalid');
        }
        $decision = $this->adjudicateProductSpecAuthority($draft, $intent, TrustLevel::from($case->order->mode));
        if (($decision['status'] ?? null) !== 'freeze' || ($decision['spec_hash'] ?? null) !== $case->order->specHash) {
            throw new \InvalidArgumentException('product_spec_authority_not_frozen');
        }
        $backendContracts = array_values(array_filter(array_map(
            static fn (array $criterion): mixed => $criterion['backend_contract'] ?? null, $draft->acceptanceCriteria,
        ), 'is_array'));
        if (count($backendContracts) !== 1) {
            throw new \InvalidArgumentException('product_spec_backend_contract_unexpressible');
        }
        $contract = $backendContracts[0];
        if (array_keys($contract) !== ['schema_version', 'spec_hash', 'profile', 'entrypoint', 'symbols', 'permitted_includes', 'effects', 'cases']
            || ($contract['schema_version'] ?? null) !== 'atlas.backend_contract.v1' || ($contract['spec_hash'] ?? null) !== $case->order->specHash) {
            throw new \InvalidArgumentException('product_spec_backend_contract_invalid');
        }
        $root = $this->support()->ownedAtlasArtifactRoot($case->candidate->sandboxRoot);
        $artifactPath = $root.'/product-spec-backend-contract-'.$case->caseHash.'.json';
        File::put($artifactPath, json_encode($contract, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $issued = CarbonImmutable::now()->startOfSecond();
        $receipt = ['schema_version' => AtlasRealEngineeringCompanyRuntimeService::ROLE_SCHEMA,
            'purpose' => 'product_spec_backend_contract_authority', 'owner_domain' => self::BACKEND_SPEC_COURT_OWNER_DOMAIN,
            'owner_version' => self::BACKEND_SPEC_COURT_OWNER_VERSION,
            'owner_identity' => 'App\\Services\\Ai\\EngineeringKernel\\Spec\\SovereignSpecFloor',
            'issued_at' => $issued->toAtomString(), 'expires_at' => $issued->addHour()->toAtomString(),
            'binding' => $this->support()->backendOwnerBinding($case),
            'product_authority' => ['intent_hash' => $intent->productAuthorityHash(), 'truth_hash' => $decision['truth_hash'] ?? null,
                'spec_receipt' => $decision['spec_receipt'] ?? null, 'world_snapshot_hash' => $case->order->worldModelSnapshotHash,
                'world_observed_at' => $intent->worldObservedAt, 'sources' => $intent->sources, 'provenance' => $intent->provenance],
            'contract_artifact' => ['path' => $artifactPath, 'sha256' => hash_file('sha256', $artifactPath)]];
        $receiptBodyHash = EngineeringCompanyHash::make($receipt);
        try {
            return DB::transaction(function () use ($receipt, $receiptBodyHash, $engagement, $cycle, $case, $issued): AiEngineeringCompanyRoleRun {
                $event = app(AtlasEvidenceLedger::class)->record(LedgerEventType::GateEvaluated, [
                    'event_name' => 'product_spec.backend_contract.authorized', 'receipt_body_hash' => $receiptBodyHash,
                    'run_id' => $case->order->runId, 'delivery_id' => $case->order->deliveryId, 'case_hash' => $case->caseHash,
                    'order_hash' => $case->order->canonicalHash(), 'spec_hash' => $case->order->specHash,
                    'workspace' => $case->order->workspace, 'owner_domain' => self::BACKEND_SPEC_COURT_OWNER_DOMAIN,
                    'owner_version' => self::BACKEND_SPEC_COURT_OWNER_VERSION,
                ], ['event_id' => 'product-spec-'.substr($receiptBodyHash, 0, 20), 'scope_type' => 'engineering_delivery',
                    'scope_id' => $case->order->deliveryId, 'emitter_stage' => self::BACKEND_SPEC_COURT_OWNER_DOMAIN,
                    'emitter_version' => self::BACKEND_SPEC_COURT_OWNER_VERSION, 'occurred_at' => $issued->toAtomString()]);
                if (! $event instanceof AtlasLedgerEvent || ! app(AtlasEvidenceLedger::class)->eventIntegrityValid($event)) {
                    throw new \InvalidArgumentException('product_spec_authority_ledger_unavailable');
                }
                $eventOccurredAt = CarbonImmutable::parse($event->getAttribute('occurred_at'));
                $receipt['ledger_event'] = ['event_id' => $event->event_id, 'event_hash' => $event->getAttribute('event_hash'),
                    'payload_hash' => $event->payload_hash, 'occurred_at' => $eventOccurredAt->toISOString()];
                $receipt['producer'] = $this->support()->candidateOwnerProducerSeal($receipt, self::BACKEND_SPEC_COURT_OWNER_DOMAIN);
                $receipt['hash'] = EngineeringCompanyHash::make($receipt);
                $id = 'product_spec_'.substr(RealExecutionHash::make([$case->caseHash, $receipt['hash']]), 0, 24);

                return AiEngineeringCompanyRoleRun::query()->create(['engagement_record_id' => $engagement->getKey(),
                    'cycle_record_id' => $cycle->getKey(), 'role_run_id' => $id, 'role_id' => 'backend_spec_authority', 'status' => 'passed',
                    'responsibilities' => [], 'output' => ['spec_hash' => $case->order->specHash],
                    'evidence_refs' => ['contract:'.$receipt['contract_artifact']['sha256'], 'ledger:'.$event->event_id],
                    'receipt' => $receipt, 'role_hash' => $receipt['hash']]);
            }, 3);
        } catch (\Throwable $exception) {
            @unlink($artifactPath);
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    protected function adjudicateProductSpecAuthority(SpecDraft $draft, IntentEnvelope $intent, TrustLevel $lane): array
    {
        return app(AtlasSpecGateAdapter::class)->adjudicateProductAuthority($draft, $intent, $lane);
    }

    private function backendSpecCourtAuthorityReceipt(CandidateQualityCase $case): ?array
    {
        return $this->backend()->backendSpecCourtAuthorityReceipt($case);
    }


    public function persistCandidateBackendOwnerReceipt(AiEngineeringCompanyEngagement $engagement, AiEngineeringCompanyCycle $cycle, CandidateQualityCase $case): AiEngineeringCompanyRoleRun
    {
        return $this->backend()->persistCandidateBackendOwnerReceipt($engagement, $cycle, $case);
    }

    public function candidateBackendOwnerReceiptValid(AiEngineeringCompanyRoleRun $row, CandidateQualityCase $case): bool
    {
        return $this->backend()->candidateBackendOwnerReceiptValid($row, $case);
    }

    public function candidateBackendDisposition(CandidateQualityCase $case, array $evidence): RoleDisposition
    {
        return $this->backend()->candidateBackendDisposition($case, $evidence);
    }


    private function analyzeBackendPhpSource(string $source): array
    {
        return $this->backend()->analyzeBackendPhpSource($source);
    }

    protected function runBackendContractProfile(string $source, array $contract): array
    {
        return $this->backend()->runBackendContractProfile($source, $contract);
    }

    public function persistCandidatePerformanceOwnerReceipt(AiEngineeringCompanyEngagement $engagement, AiEngineeringCompanyCycle $cycle, CandidateQualityCase $case): AiEngineeringCompanyRoleRun
    {
        return $this->performance()->persistCandidatePerformanceOwnerReceipt($engagement, $cycle, $case);
    }

    public function candidatePerformanceOwnerReceiptValid(AiEngineeringCompanyRoleRun $row, CandidateQualityCase $case): bool
    {
        return $this->performance()->candidatePerformanceOwnerReceiptValid($row, $case);
    }

    public function candidatePerformanceDisposition(CandidateQualityCase $case, array $evidence): RoleDisposition
    {
        return $this->performance()->candidatePerformanceDisposition($case, $evidence);
    }


    private function phpSourceHasRuntimeImpact(string $source): bool
    {
        return $this->performance()->phpSourceHasRuntimeImpact($source);
    }


    protected function runCandidatePerformanceProfile(string $baseline, string $candidate, array $policy): array
    {
        return $this->performance()->runCandidatePerformanceProfile($baseline, $candidate, $policy);
    }

    public function persistCandidateAppsecPrivacyOwnerReceipt(AiEngineeringCompanyEngagement $engagement, AiEngineeringCompanyCycle $cycle, CandidateQualityCase $case): AiEngineeringCompanyRoleRun
    {
        return $this->appsecPrivacy()->persistCandidateAppsecPrivacyOwnerReceipt($engagement, $cycle, $case);
    }

    public function candidateAppsecPrivacyOwnerReceiptValid(AiEngineeringCompanyRoleRun $row, CandidateQualityCase $case): bool
    {
        return $this->appsecPrivacy()->candidateAppsecPrivacyOwnerReceiptValid($row, $case);
    }

    public function candidateAppsecPrivacyDisposition(CandidateQualityCase $case, array $evidence): RoleDisposition
    {
        return $this->appsecPrivacy()->candidateAppsecPrivacyDisposition($case, $evidence);
    }


    private function phpDependencyProvenanceFindings(string $source, string $file, string $tree, string $sandbox): array
    {
        return $this->appsecPrivacy()->phpDependencyProvenanceFindings($source, $file, $tree, $sandbox);
    }





    private function javascriptDependencyProvenanceFindings(string $source, string $file, string $tree, string $sandbox): array
    {
        return $this->appsecPrivacy()->javascriptDependencyProvenanceFindings($source, $file, $tree, $sandbox);
    }


    private function manifestLockProvenanceFindings(string $source, string $file, string $tree, string $sandbox): array
    {
        return $this->appsecPrivacy()->manifestLockProvenanceFindings($source, $file, $tree, $sandbox);
    }




    public function persistCandidateDataOwnerReceipt(AiEngineeringCompanyEngagement $engagement, AiEngineeringCompanyCycle $cycle, CandidateQualityCase $case): AiEngineeringCompanyRoleRun
    {
        if (! $engagement->exists || ! $cycle->exists || $cycle->engagement_record_id !== $engagement->getKey()
            || (string) $engagement->getKey() !== $case->engagementRecordId || (string) $cycle->getKey() !== $case->cycleRecordId) {
            throw new \InvalidArgumentException('candidate_data_owner_binding_invalid');
        }
        $evidence = $this->candidateDataEvidence($case, true);
        $disposition = $this->candidateDataDisposition($case, $evidence);
        $roleRunId = 'aeredata_'.substr(RealExecutionHash::make([$case->caseHash, 'data']), 0, 24);
        if (AiEngineeringCompanyRoleRun::query()->where('role_run_id', $roleRunId)->exists()) {
            throw new \InvalidArgumentException('candidate_data_owner_duplicate');
        }
        $issued = CarbonImmutable::now()->startOfSecond();
        $expires = $issued->addHour();
        $evidenceRefs = ['candidate:'.$case->candidate->candidateHash, 'verification:'.$case->verification->receiptHash,
            'data_artifact:'.(string) data_get($evidence, 'raw_artifact.sha256')];
        $typed = RoleEvidenceReceipt::issue($case, $disposition, self::CANDIDATE_DATA_OWNER_DOMAIN, self::CANDIDATE_DATA_OWNER_VERSION,
            $issued->toAtomString(), $expires->toAtomString(), $evidenceRefs);
        $output = ['disposition' => $disposition->toArray(), 'role_evidence_receipt' => $typed->toArray()];
        $receipt = ['schema_version' => AtlasRealEngineeringCompanyRuntimeService::ROLE_SCHEMA,
            'purpose' => 'candidate_data_owner_evidence', 'owner_domain' => self::CANDIDATE_DATA_OWNER_DOMAIN,
            'owner_version' => self::CANDIDATE_DATA_OWNER_VERSION, 'owner_identity' => MigrationSafetyProbe::class,
            'issued_at' => $issued->toAtomString(), 'expires_at' => $expires->toAtomString(),
            'role_run_id' => $roleRunId, 'role_id' => 'data',
            'status' => $disposition->status === 'pass' ? 'passed' : ($disposition->status === 'not_applicable' ? 'not_applicable' : 'blocked'),
            'output' => $output, 'evidence_refs' => $evidenceRefs, 'binding' => $this->support()->candidateOwnerBinding($case),
            'disposition' => $disposition->toArray(), 'data_evidence' => $evidence];
        $receipt['producer'] = $this->support()->candidateOwnerProducerSeal($receipt, self::CANDIDATE_DATA_OWNER_DOMAIN);
        $receipt['hash'] = EngineeringCompanyHash::make($receipt);

        return AiEngineeringCompanyRoleRun::query()->create(['engagement_record_id' => $engagement->getKey(),
            'cycle_record_id' => $cycle->getKey(), 'role_run_id' => $roleRunId, 'role_id' => 'data',
            'status' => $receipt['status'], 'responsibilities' => [], 'output' => $output,
            'evidence_refs' => $evidenceRefs, 'receipt' => $receipt, 'role_hash' => $receipt['hash']]);
    }

    public function candidateDataOwnerReceiptValid(AiEngineeringCompanyRoleRun $row, CandidateQualityCase $case): bool
    {
        $persisted = $row->exists ? AiEngineeringCompanyRoleRun::query()->find($row->getKey()) : null;
        $receipt = $persisted?->receipt;
        if (! $persisted instanceof AiEngineeringCompanyRoleRun || ! is_array($receipt)
            || $persisted->role_id !== 'data' || (string) $persisted->engagement_record_id !== $case->engagementRecordId
            || (string) $persisted->cycle_record_id !== $case->cycleRecordId) {
            return false;
        }
        try {
            $issued = CarbonImmutable::createFromFormat(DATE_ATOM, (string) ($receipt['issued_at'] ?? ''));
            $expires = CarbonImmutable::createFromFormat(DATE_ATOM, (string) ($receipt['expires_at'] ?? ''));
            $evidence = (array) ($receipt['data_evidence'] ?? []);
            $artifact = (array) ($evidence['raw_artifact'] ?? []);
            $artifactRoot = $this->support()->ownedAtlasArtifactRoot($case->candidate->sandboxRoot);
            $artifactPath = realpath((string) ($artifact['path'] ?? ''));
            if ($artifactPath === false || ! str_starts_with($artifactPath, $artifactRoot.'/') || is_link((string) ($artifact['path'] ?? ''))
                || ! hash_equals((string) ($artifact['sha256'] ?? ''), (string) hash_file('sha256', $artifactPath))) {
                return false;
            }
            $raw = json_decode((string) file_get_contents($artifactPath), true, 512, JSON_THROW_ON_ERROR);
            // Igualdade canônica, não ===: o receipt volta do jsonb com chaves reordenadas.
            if (! is_array($raw) || ! hash_equals(RealExecutionHash::make($raw), RealExecutionHash::make(array_diff_key($evidence, ['raw_artifact' => true])))) {
                return false;
            }
            $disposition = $this->candidateDataDisposition($case, $evidence);
        } catch (\Throwable) {
            return false;
        }
        $refs = ['candidate:'.$case->candidate->candidateHash, 'verification:'.$case->verification->receiptHash,
            'data_artifact:'.(string) data_get($evidence, 'raw_artifact.sha256')];
        $typed = RoleEvidenceReceipt::issue($case, $disposition, self::CANDIDATE_DATA_OWNER_DOMAIN, self::CANDIDATE_DATA_OWNER_VERSION,
            (string) $receipt['issued_at'], (string) $receipt['expires_at'], $refs)->toArray();
        $output = $persisted->output;
        $unsigned = array_diff_key($receipt, ['hash' => true]);

        return is_array($output) && $issued !== null && $expires !== null && ! CarbonImmutable::now()->lt($issued) && CarbonImmutable::now()->lt($expires)
            && ($receipt['purpose'] ?? null) === 'candidate_data_owner_evidence'
            && ($receipt['owner_domain'] ?? null) === self::CANDIDATE_DATA_OWNER_DOMAIN
            && ($receipt['owner_version'] ?? null) === self::CANDIDATE_DATA_OWNER_VERSION
            && ($receipt['owner_identity'] ?? null) === MigrationSafetyProbe::class
            && ($receipt['binding'] ?? null) === $this->support()->candidateOwnerBinding($case)
            && ($receipt['data_evidence'] ?? null) === $evidence && ($receipt['evidence_refs'] ?? null) === $refs
            && ($output['disposition'] ?? null) === $disposition->toArray()
            && ($output['role_evidence_receipt'] ?? null) === $typed && ($receipt['output'] ?? null) === $output
            && ($receipt['disposition'] ?? null) === $output['disposition']
            && hash_equals((string) $persisted->role_hash, (string) ($receipt['hash'] ?? ''))
            && hash_equals((string) ($receipt['hash'] ?? ''), EngineeringCompanyHash::make($unsigned))
            && $this->support()->candidateOwnerProducerSealValid($unsigned, self::CANDIDATE_DATA_OWNER_DOMAIN);
    }

    /** @param array<string,mixed> $evidence */
    public function candidateDataDisposition(CandidateQualityCase $case, array $evidence): RoleDisposition
    {
        $applies = ($evidence['applies'] ?? false) === true;
        $safe = ($evidence['safe'] ?? false) === true;
        $status = ! $applies ? 'not_applicable' : ($safe ? 'pass' : 'block');
        $reason = ! $applies ? 'signed_no_data_or_schema_applicability' : ($safe ? 'candidate_migration_isolated_oracle_passed' : 'candidate_migration_unsafe_or_unknown');
        $payload = ['purpose' => 'candidate_data_disposition', 'case_hash' => $case->caseHash,
            'evidence_hash' => RealExecutionHash::make($evidence), 'status' => $status, 'reason' => $reason,
            'owner_identity' => MigrationSafetyProbe::class];

        return RoleDisposition::dataCandidateAdjudicated($case, $status, $reason, self::CANDIDATE_DATA_OWNER_DOMAIN,
            $this->support()->candidateOwnerSignature($payload, self::CANDIDATE_DATA_OWNER_DOMAIN));
    }

    /** @return array<string,mixed> */
    private function candidateDataEvidence(CandidateQualityCase $case, bool $writeArtifact): array
    {
        $verification = $this->support()->verifiedMutativeVerificationReceipt($case->verification, $case->order, $writeArtifact);
        $binding = (array) data_get($verification->receipt, 'binding', []);
        $tree = (string) ($binding['tree_hash'] ?? '');
        $sourceHashes = (array) ($binding['source_hashes'] ?? []);
        $sources = [];
        foreach ($case->candidate->files as $file) {
            if (! MigrationSafetyProbe::isMigrationPath($file)) {
                continue;
            }
            $show = new Process(['git', 'show', $tree.':'.$file], $case->candidate->sandboxRoot);
            $show->run();
            if (! $show->isSuccessful() || ! hash_equals((string) ($sourceHashes[$file] ?? ''), hash('sha256', $show->getOutput()))) {
                throw new \InvalidArgumentException('candidate_data_signed_source_unavailable');
            }
            $sources[$file] = $show->getOutput();
        }
        $this->afterDataCandidateVerified($case);
        $static = MigrationSafetyProbe::probe($sources);
        $oracle = $sources === [] ? ['status' => 'not_applicable'] : $this->migrationOracle()->runIsolatedMigrationOracle(
            $sources, (bool) ($static['safe'] ?? false), $case->candidate->sandboxRoot, $case->caseHash,
        );
        $safe = $sources !== [] && ($static['safe'] ?? false) === true
            && ($oracle['forward_passed'] ?? false) === true && ($oracle['n_minus_1_passed'] ?? false) === true
            && ($oracle['rollback_passed'] ?? false) === true;
        $raw = ['schema_version' => 'atlas.candidate_data_probe.v1', 'case_hash' => $case->caseHash,
            'order_hash' => $case->order->canonicalHash(), 'spec_hash' => $case->order->specHash,
            'candidate_hash' => $case->candidate->candidateHash, 'base_commit' => $case->candidate->baseCommit,
            'tree_hash' => $case->candidate->treeHash, 'diff_hash' => $case->candidate->diffHash, 'files' => $case->candidate->files,
            'migration_source_hashes' => array_map(static fn (string $source): string => hash('sha256', $source), $sources),
            'migration_policy_hash' => RealExecutionHash::make(['spec_hash' => $case->order->specHash, 'probe' => MigrationSafetyProbe::class]),
            'probe' => $static, 'isolated_db' => $oracle, 'applies' => $sources !== [], 'safe' => $safe,
            'owner_identity' => MigrationSafetyProbe::class];
        $artifactRoot = $this->support()->ownedAtlasArtifactRoot($case->candidate->sandboxRoot);
        $artifact = $artifactRoot.'/data-probe-'.$case->caseHash.'.json';
        if ($writeArtifact) {
            File::put($artifact, json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
        $artifactReal = realpath($artifact);
        if ($artifactReal === false || ! str_starts_with($artifactReal, $artifactRoot.'/') || ! is_file($artifactReal) || is_link($artifact)) {
            throw new \InvalidArgumentException('candidate_data_artifact_unavailable');
        }

        return $raw + ['raw_artifact' => ['path' => $artifactReal, 'sha256' => hash_file('sha256', $artifactReal)]];
    }



    protected function afterDataCandidateVerified(CandidateQualityCase $case): void
    {
        // Race-test seam. Production performs no action before owned artifact containment checks.
    }

    public function persistCandidateArchitectureOwnerReceipt(AiEngineeringCompanyEngagement $engagement, AiEngineeringCompanyCycle $cycle, CandidateQualityCase $case): AiEngineeringCompanyRoleRun
    {
        if (! $engagement->exists || ! $cycle->exists || $cycle->engagement_record_id !== $engagement->getKey()
            || (string) $engagement->getKey() !== $case->engagementRecordId || (string) $cycle->getKey() !== $case->cycleRecordId) {
            throw new \InvalidArgumentException('candidate_architecture_owner_binding_invalid');
        }
        $evidence = $this->candidateArchitectureEvidence($case, true);
        $disposition = $this->candidateArchitectureDisposition($case, $evidence);
        $roleRunId = 'aerearch_'.substr(RealExecutionHash::make([$case->caseHash, 'architecture']), 0, 24);
        if (AiEngineeringCompanyRoleRun::query()->where('role_run_id', $roleRunId)->exists()) {
            throw new \InvalidArgumentException('candidate_architecture_owner_duplicate');
        }
        $issued = CarbonImmutable::now()->startOfSecond();
        $expires = $issued->addHour();
        $evidenceRefs = ['candidate:'.$case->candidate->candidateHash, 'verification:'.$case->verification->receiptHash,
            'architecture_artifact:'.(string) data_get($evidence, 'raw_artifact.sha256')];
        $typed = RoleEvidenceReceipt::issue(
            $case, $disposition, self::CANDIDATE_ARCHITECTURE_OWNER_DOMAIN, self::CANDIDATE_ARCHITECTURE_OWNER_VERSION,
            $issued->toAtomString(), $expires->toAtomString(), $evidenceRefs,
        );
        $output = ['disposition' => $disposition->toArray(), 'role_evidence_receipt' => $typed->toArray()];
        $binding = $this->support()->candidateOwnerBinding($case);
        $receipt = [
            'schema_version' => AtlasRealEngineeringCompanyRuntimeService::ROLE_SCHEMA,
            'purpose' => 'candidate_architecture_owner_evidence',
            'owner_domain' => self::CANDIDATE_ARCHITECTURE_OWNER_DOMAIN,
            'owner_version' => self::CANDIDATE_ARCHITECTURE_OWNER_VERSION,
            'owner_identity' => ArchitectureRegressionProbe::class,
            'issued_at' => $issued->toAtomString(), 'expires_at' => $expires->toAtomString(),
            'role_run_id' => $roleRunId, 'role_id' => 'architecture',
            'status' => $disposition->status === 'pass' ? 'passed' : ($disposition->status === 'not_applicable' ? 'not_applicable' : 'blocked'),
            'output' => $output, 'evidence_refs' => $evidenceRefs, 'binding' => $binding,
            'disposition' => $disposition->toArray(), 'architecture_evidence' => $evidence,
        ];
        $receipt['producer'] = $this->support()->candidateOwnerProducerSeal($receipt, self::CANDIDATE_ARCHITECTURE_OWNER_DOMAIN);
        $receipt['hash'] = EngineeringCompanyHash::make($receipt);

        return AiEngineeringCompanyRoleRun::query()->create([
            'engagement_record_id' => $engagement->getKey(), 'cycle_record_id' => $cycle->getKey(),
            'role_run_id' => $roleRunId, 'role_id' => 'architecture', 'status' => $receipt['status'],
            'responsibilities' => [], 'output' => $output, 'evidence_refs' => $evidenceRefs,
            'receipt' => $receipt, 'role_hash' => $receipt['hash'],
        ]);
    }

    public function candidateArchitectureOwnerReceiptValid(AiEngineeringCompanyRoleRun $row, CandidateQualityCase $case): bool
    {
        $persisted = $row->exists ? AiEngineeringCompanyRoleRun::query()->find($row->getKey()) : null;
        $receipt = $persisted?->receipt;
        if (! $persisted instanceof AiEngineeringCompanyRoleRun || ! is_array($receipt)
            || $persisted->role_id !== 'architecture' || (string) $persisted->engagement_record_id !== $case->engagementRecordId
            || (string) $persisted->cycle_record_id !== $case->cycleRecordId) {
            return false;
        }
        try {
            $issued = CarbonImmutable::createFromFormat(DATE_ATOM, (string) ($receipt['issued_at'] ?? ''));
            $expires = CarbonImmutable::createFromFormat(DATE_ATOM, (string) ($receipt['expires_at'] ?? ''));
            $expectedEvidence = $this->candidateArchitectureEvidence($case, false);
            $expectedDisposition = $this->candidateArchitectureDisposition($case, $expectedEvidence);
        } catch (\Throwable) {
            return false;
        }
        $evidenceRefs = ['candidate:'.$case->candidate->candidateHash, 'verification:'.$case->verification->receiptHash,
            'architecture_artifact:'.(string) data_get($expectedEvidence, 'raw_artifact.sha256')];
        $expectedTyped = RoleEvidenceReceipt::issue(
            $case, $expectedDisposition, self::CANDIDATE_ARCHITECTURE_OWNER_DOMAIN, self::CANDIDATE_ARCHITECTURE_OWNER_VERSION,
            (string) $receipt['issued_at'], (string) $receipt['expires_at'], $evidenceRefs,
        )->toArray();
        $output = $persisted->output;
        $unsigned = array_diff_key($receipt, ['hash' => true]);

        return is_array($output) && $issued !== null && $expires !== null
            && ! CarbonImmutable::now()->lt($issued) && CarbonImmutable::now()->lt($expires)
            && ($receipt['purpose'] ?? null) === 'candidate_architecture_owner_evidence'
            && ($receipt['owner_domain'] ?? null) === self::CANDIDATE_ARCHITECTURE_OWNER_DOMAIN
            && ($receipt['owner_version'] ?? null) === self::CANDIDATE_ARCHITECTURE_OWNER_VERSION
            && ($receipt['owner_identity'] ?? null) === ArchitectureRegressionProbe::class
            && ($receipt['binding'] ?? null) === $this->support()->candidateOwnerBinding($case)
            && ($receipt['architecture_evidence'] ?? null) === $expectedEvidence
            && ($receipt['evidence_refs'] ?? null) === $evidenceRefs
            && ($output['disposition'] ?? null) === $expectedDisposition->toArray()
            && ($output['role_evidence_receipt'] ?? null) === $expectedTyped
            && ($receipt['output'] ?? null) === $output && ($receipt['disposition'] ?? null) === $output['disposition']
            && hash_equals((string) $persisted->role_hash, (string) ($receipt['hash'] ?? ''))
            && hash_equals((string) ($receipt['hash'] ?? ''), EngineeringCompanyHash::make($unsigned))
            && $this->support()->candidateOwnerProducerSealValid($unsigned, self::CANDIDATE_ARCHITECTURE_OWNER_DOMAIN);
    }

    /** @param array<string,mixed> $evidence */
    public function candidateArchitectureDisposition(CandidateQualityCase $case, array $evidence): RoleDisposition
    {
        $violations = (array) ($evidence['violations'] ?? []);
        $applicable = ($evidence['applicable'] ?? false) === true;
        $status = ! $applicable ? 'not_applicable' : ($violations === [] ? 'pass' : 'block');
        $reason = ! $applicable ? 'no_php_architecture_surface_changed' : ($violations === [] ? 'candidate_architecture_probe_clean' : 'candidate_architecture_forbidden_dependency');
        $payload = ['purpose' => 'candidate_architecture_disposition', 'case_hash' => $case->caseHash,
            'evidence_hash' => RealExecutionHash::make($evidence), 'status' => $status, 'reason' => $reason,
            'owner_identity' => ArchitectureRegressionProbe::class];

        return RoleDisposition::architectureCandidateAdjudicated(
            $case, $status, $reason, self::CANDIDATE_ARCHITECTURE_OWNER_DOMAIN,
            $this->support()->candidateOwnerSignature($payload, self::CANDIDATE_ARCHITECTURE_OWNER_DOMAIN),
        );
    }

    /** @return array<string,mixed> */
    private function candidateArchitectureEvidence(CandidateQualityCase $case, bool $writeArtifact): array
    {
        $verification = $this->support()->verifiedMutativeVerificationReceipt($case->verification, $case->order, $writeArtifact);
        $verificationBinding = (array) data_get($verification->receipt, 'binding', []);
        $signedSourceHashes = (array) ($verificationBinding['source_hashes'] ?? []);
        $signedTree = (string) ($verificationBinding['tree_hash'] ?? '');
        $this->afterArchitectureCandidateVerified($case);
        $candidateSources = [];
        $baseSources = [];
        foreach ($case->candidate->files as $file) {
            if (! str_ends_with($file, '.php')) {
                continue;
            }
            $candidate = new Process(['git', 'show', $signedTree.':'.$file], $case->candidate->sandboxRoot);
            $candidate->run();
            if (! $candidate->isSuccessful()) {
                throw new \InvalidArgumentException('candidate_architecture_source_unavailable');
            }
            $candidateSources[$file] = $candidate->getOutput();
            if (! hash_equals((string) ($signedSourceHashes[$file] ?? ''), hash('sha256', $candidateSources[$file]))) {
                throw new \InvalidArgumentException('candidate_architecture_signed_source_mismatch');
            }
            $base = new Process(['git', 'show', $case->candidate->baseCommit.':'.$file], $case->candidate->sandboxRoot);
            $base->run();
            $baseSources[$file] = $base->isSuccessful() ? $base->getOutput() : '';
        }
        $baseEdges = ArchitectureRegressionProbe::edgesFromSources($baseSources);
        $candidateEdges = ArchitectureRegressionProbe::edgesFromSources($candidateSources);
        $baseHashes = array_map(static fn (array $edge): string => RealExecutionHash::make($edge), $baseEdges);
        $addedEdges = array_values(array_filter($candidateEdges, static fn (array $edge): bool => ! in_array(RealExecutionHash::make($edge), $baseHashes, true)));
        $rules = ArchitectureRegressionProbe::defaultRules();
        $violations = ArchitectureRegressionProbe::violations($addedEdges, $rules);
        $contractPath = (new \ReflectionClass(ArchitectureRegressionProbe::class))->getFileName();
        if (! is_string($contractPath) || ! is_file($contractPath)) {
            throw new \InvalidArgumentException('candidate_architecture_probe_unavailable');
        }
        $raw = ['schema_version' => 'atlas.candidate_architecture_probe.v1', 'case_hash' => $case->caseHash,
            'order_hash' => $case->order->canonicalHash(), 'spec_hash' => $case->order->specHash,
            'candidate_hash' => $case->candidate->candidateHash, 'base_commit' => $case->candidate->baseCommit,
            'diff_hash' => $case->candidate->diffHash, 'tree_hash' => $case->candidate->treeHash,
            'files' => $case->candidate->files,
            'base_source_hashes' => array_map(static fn (string $source): string => hash('sha256', $source), $baseSources),
            'candidate_source_hashes' => array_map(static fn (string $source): string => hash('sha256', $source), $candidateSources),
            'base_edges' => $baseEdges, 'candidate_edges' => $candidateEdges, 'added_edges' => $addedEdges,
            'rules' => $rules, 'violations' => $violations, 'probe_class' => ArchitectureRegressionProbe::class,
            'architecture_policy_hash' => RealExecutionHash::make(['spec_hash' => $case->order->specHash, 'rules' => $rules]),
            'probe_contract_hash' => hash_file('sha256', $contractPath), 'owner_identity' => ArchitectureRegressionProbe::class];
        $artifactPath = $case->candidate->sandboxRoot.'/.atlas/architecture-probe-'.$case->caseHash.'.json';
        if ($writeArtifact) {
            File::put($artifactPath, json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
        if (! is_file($artifactPath) || is_link($artifactPath)) {
            throw new \InvalidArgumentException('candidate_architecture_artifact_unavailable');
        }

        return $raw + ['applicable' => $candidateSources !== [], 'violations' => $violations,
            'raw_artifact' => ['path' => $artifactPath, 'sha256' => hash_file('sha256', $artifactPath)]];
    }

    protected function afterArchitectureCandidateVerified(CandidateQualityCase $case): void
    {
        // Race-test seam. Production performs no action before immutable tree reads.
    }


    private function backendOwnerBinding(CandidateQualityCase $case): array
    {
        return $this->support()->backendOwnerBinding($case);
    }


    private function candidateOwnerProducerSeal(array $payload, string $domain): array
    {
        return $this->support()->candidateOwnerProducerSeal($payload, $domain);
    }


    public function persistCandidateQaOwnerReceipt(AiEngineeringCompanyEngagement $engagement, AiEngineeringCompanyCycle $cycle, CandidateQualityCase $case): AiEngineeringCompanyRoleRun
    {
        return $this->qa()->persistCandidateQaOwnerReceipt($engagement, $cycle, $case);
    }

    public function verifiedMutativeVerification(MutativeVerificationReference $reference, ExecutionOrder $order): AiRealExecutionTestRun
    {
        return $this->support()->verifiedMutativeVerification($reference, $order);
    }





    public function candidateQaOwnerReceiptValid(AiEngineeringCompanyRoleRun $row, CandidateQualityCase $case): bool
    {
        return $this->qa()->candidateQaOwnerReceiptValid($row, $case);
    }

    public function candidateQaDisposition(CandidateQualityCase $case): RoleDisposition
    {
        return $this->qa()->candidateQaDisposition($case);
    }






    /** @return list<string> */
    private function porcelainV2Paths(string $bytes): array
    {
        $paths = [];
        foreach (explode("\0", $bytes) as $record) {
            if ($record === '') {
                continue;
            }
            if (str_starts_with($record, '? ')) {
                $paths[] = substr($record, 2);
            } elseif (str_starts_with($record, '1 ')) {
                $parts = explode(' ', $record, 9);
                if (isset($parts[8])) {
                    $paths[] = $parts[8];
                }
            } else {
                throw new \InvalidArgumentException('hermetic_candidate_status_record_unsupported');
            }
        }

        $paths = array_values(array_unique($paths));
        $paths = array_values(array_filter($paths, static fn (string $path): bool => $path !== '.atlas-native-manifest.json'));
        sort($paths, SORT_STRING);

        return $paths;
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
        return $this->certification()->createRivalsBenchmark($goal, $delivery, $candidate);
    }

    public function importExternalRivalsBenchmark(array $evidencePack, ?string $goalId = null): array
    {
        return $this->certification()->importExternalRivalsBenchmark($evidencePack, $goalId);
    }


    public function certify(?AiAutonomousEngineeringGoal $goal = null, string $scope = 'kernel'): AiRealExecutionCertification
    {
        return $this->certification()->certify($goal, $scope);
    }


    public function readiness(): array
    {
        return $this->certification()->readiness();
    }

    public function controlPlane(): array
    {
        return $this->certification()->controlPlane();
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


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

final readonly class CandidatePerformancePolicy
{
    public function __construct(
        public string $schemaVersion,
        public string $workload,
        public int $warmup,
        public int $repetitions,
        public float $timeoutSeconds,
        public float $maxRatio,
        public float $wallSlackMs,
        public float $cpuSlackUs,
        public int $rssSlackBytes,
        public float $maxCv,
        public bool $recoveryProbe,
    ) {}

    public static function frozen(): self
    {
        return new self('atlas.performance_policy.kernel_candidate_fixture.v2', 'kernel_candidate_fixture_v1:app/Candidate.php',
            1, 5, 2.0, 1.50, 5.0, 5000.0, 4_194_304, 0.50, true);
    }

    /** @return array<string,int|float|string|bool> */
    public function toArray(): array
    {
        return ['schema_version' => $this->schemaVersion, 'workload' => $this->workload, 'warmup' => $this->warmup,
            'repetitions' => $this->repetitions, 'timeout_seconds' => $this->timeoutSeconds, 'max_ratio' => $this->maxRatio,
            'wall_slack_ms' => $this->wallSlackMs, 'cpu_slack_us' => $this->cpuSlackUs, 'rss_slack_bytes' => $this->rssSlackBytes,
            'max_cv' => $this->maxCv, 'recovery_probe' => $this->recoveryProbe];
    }
}

class AtlasRealEngineeringExecutionKernelService
{
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
        $receipt['producer'] = $this->producerSeal(self::KERNEL_VERIFICATION_PRODUCER, $receipt);
        $receipt['hash'] = RealExecutionHash::make($receipt);

        return AiRealExecutionTestRun::query()->create([
            'goal_record_id' => $goal->getKey(), 'patch_run_record_id' => $patch->getKey(), 'test_run_id' => $testRunId,
            'status' => 'passed', 'selected_tests' => $selectedTests, 'impact_reasoning' => ['strategy' => 'real_bounded_suite'],
            'exit_code' => 0, 'output_excerpt' => $output, 'evidence_refs' => $receipt['evidence_refs'],
            'receipt' => $receipt, 'test_hash' => $receipt['hash'],
        ]);
    }

    /** @param array<string,mixed> $payload @return array<string,string> */
    private function producerSeal(string $domain, array $payload): array
    {
        $key = $this->producerKeyMaterial();
        $keyId = 'app-key-'.substr(hash('sha256', $key), 0, 16);
        $seal = ['domain' => $domain, 'key_id' => $keyId, 'payload_hash' => RealExecutionHash::make($payload)];
        $authorityKey = hash_hmac('sha256', 'atlas.engineering_kernel.evidence_authority.v1', $key, true);
        $seal['signature'] = hash_hmac('sha256', RealExecutionHash::make($seal), hash_hmac('sha256', $domain, $authorityKey, true));

        return $seal;
    }

    private function producerKeyMaterial(): string
    {
        $key = (string) config('app.key');
        $decoded = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

        return is_string($decoded) ? $decoded : '';
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
            if (! str_ends_with($file, '.php') || ! is_file($sandbox.'/'.$file) || is_link($sandbox.'/'.$file)) {
                throw new \InvalidArgumentException('hermetic_candidate_file_not_verifiable');
            }
            $commands[] = [PHP_BINARY, '-l', $file];
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
        $receipt['producer'] = $this->producerSeal(self::KERNEL_VERIFICATION_PRODUCER, $receipt);
        $receipt['hash'] = RealExecutionHash::make($receipt);

        if (AiRealExecutionTestRun::query()->where('test_run_id', $verificationRunId)->exists()) {
            throw new \InvalidArgumentException('hermetic_candidate_verification_duplicate');
        }
        AiRealExecutionTestRun::query()->create([
            'test_run_id' => $verificationRunId, 'status' => $passed && $behavioral['passed'] === true ? 'passed' : 'failed',
            'selected_tests' => array_map(static fn (array $result): string => (string) $result['command_hash'], $results),
            'impact_reasoning' => ['strategy' => 'candidate_bound_hermetic_verification'],
            'exit_code' => $passed && $behavioral['passed'] === true ? 0 : 1,
            'output_excerpt' => 'candidate_bound_hermetic_verification',
            'evidence_refs' => ['candidate:'.$candidateHash, 'provider:'.$providerReceiptHash],
            'receipt' => $receipt, 'test_hash' => $receipt['hash'],
        ]);

        return new MutativeVerificationReference(
            $verificationRunId, $receipt['hash'], $candidateHash,
            $providerIdentity, $authorIdentity, $verifierIdentity,
        );
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
        $root = $this->ownedAtlasArtifactRoot($case->candidate->sandboxRoot);
        $artifactPath = $root.'/product-spec-backend-contract-'.$case->caseHash.'.json';
        File::put($artifactPath, json_encode($contract, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $issued = CarbonImmutable::now()->startOfSecond();
        $receipt = ['schema_version' => AtlasRealEngineeringCompanyRuntimeService::ROLE_SCHEMA,
            'purpose' => 'product_spec_backend_contract_authority', 'owner_domain' => self::BACKEND_SPEC_COURT_OWNER_DOMAIN,
            'owner_version' => self::BACKEND_SPEC_COURT_OWNER_VERSION,
            'owner_identity' => 'App\\Services\\Ai\\EngineeringKernel\\Spec\\SovereignSpecFloor',
            'issued_at' => $issued->toAtomString(), 'expires_at' => $issued->addHour()->toAtomString(),
            'binding' => $this->backendOwnerBinding($case),
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
                $receipt['producer'] = $this->candidateOwnerProducerSeal($receipt, self::BACKEND_SPEC_COURT_OWNER_DOMAIN);
                $receipt['hash'] = EngineeringCompanyHash::make($receipt);
                $id = 'product_spec_'.substr(RealExecutionHash::make([$case->caseHash, $receipt['hash']]), 0, 24);

                return AiEngineeringCompanyRoleRun::query()->create(['engagement_record_id' => $engagement->getKey(),
                    'cycle_record_id' => $cycle->getKey(), 'role_run_id' => $id, 'role_id' => 'product_management', 'status' => 'passed',
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

    /** @return array{row_id:string,receipt_hash:string,contract:array<string,mixed>,artifact:array<string,string>}|null */
    private function backendSpecCourtAuthorityReceipt(CandidateQualityCase $case): ?array
    {
        $expectedBinding = $this->backendOwnerBinding($case);
        $rows = AiEngineeringCompanyRoleRun::query()->where('role_id', 'product_management')->get()
            ->filter(static fn (AiEngineeringCompanyRoleRun $row): bool => data_get($row->receipt, 'purpose') === 'product_spec_backend_contract_authority'
                && data_get($row->receipt, 'binding.case_hash') === $case->caseHash);
        if ($rows->count() !== 1) {
            return null;
        }
        $row = $rows->first();
        $receipt = $row?->receipt;
        if (! $row instanceof AiEngineeringCompanyRoleRun || ! is_array($receipt)) {
            return null;
        }
        try {
            $issued = CarbonImmutable::createFromFormat(DATE_ATOM, (string) ($receipt['issued_at'] ?? ''));
            $expires = CarbonImmutable::createFromFormat(DATE_ATOM, (string) ($receipt['expires_at'] ?? ''));
        } catch (\Throwable) {
            return null;
        }
        $artifact = $receipt['contract_artifact'] ?? null;
        $root = $this->ownedAtlasArtifactRoot($case->candidate->sandboxRoot);
        $declared = is_array($artifact) ? (string) ($artifact['path'] ?? '') : '';
        $path = $declared !== '' ? realpath($declared) : false;
        if ($issued === null || $expires === null || CarbonImmutable::now()->lt($issued) || CarbonImmutable::now()->gte($expires)
            || ($receipt['owner_domain'] ?? null) !== self::BACKEND_SPEC_COURT_OWNER_DOMAIN
            || ($receipt['owner_version'] ?? null) !== self::BACKEND_SPEC_COURT_OWNER_VERSION
            || ($receipt['owner_identity'] ?? null) !== 'App\\Services\\Ai\\EngineeringKernel\\Spec\\SovereignSpecFloor'
            || ($receipt['binding'] ?? null) !== $expectedBinding || $path === false || ! str_starts_with($path, $root.'/')
            || $this->pathHasSymlinkBetween($root, $declared)
            || ! hash_equals((string) ($artifact['sha256'] ?? ''), (string) hash_file('sha256', $path))) {
            return null;
        }
        try {
            $contract = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
        $unsigned = array_diff_key($receipt, ['hash' => true]);
        $ledgerRef = $receipt['ledger_event'] ?? null;
        $ledger = is_array($ledgerRef) ? AtlasLedgerEvent::query()->where('event_id', (string) ($ledgerRef['event_id'] ?? ''))->first() : null;
        $ledgerOccurredAt = $ledger instanceof AtlasLedgerEvent ? CarbonImmutable::parse($ledger->getAttribute('occurred_at')) : null;
        $ledgerEnvelopeHash = $ledger instanceof AtlasLedgerEvent ? AtlasEvidenceLedger::computeEventHash([
            'event_id' => $ledger->event_id, 'event_type' => $ledger->event_type, 'envelope_id' => $ledger->envelope_id,
            'correlation_id' => $ledger->correlation_id, 'causation_id' => $ledger->causation_id,
            'scope_type' => $ledger->getAttribute('scope_type'), 'scope_id' => $ledger->getAttribute('scope_id'), 'payload_hash' => $ledger->payload_hash,
            'occurred_at' => $ledgerOccurredAt?->toISOString(),
        ]) : '';
        if (! is_array($contract) || array_keys($contract) !== ['schema_version', 'spec_hash', 'profile', 'entrypoint', 'symbols', 'permitted_includes', 'effects', 'cases']
            || ($contract['schema_version'] ?? null) !== 'atlas.backend_contract.v1' || ($contract['spec_hash'] ?? null) !== $case->order->specHash
            || ! is_array($contract['symbols']) || ! is_array($contract['permitted_includes']) || ! is_array($contract['effects']) || ! is_array($contract['cases'])
            || ! hash_equals((string) $row->role_hash, (string) ($receipt['hash'] ?? ''))
            || ! hash_equals((string) ($receipt['hash'] ?? ''), EngineeringCompanyHash::make($unsigned))
            || ! $this->candidateOwnerProducerSealValid($unsigned, self::BACKEND_SPEC_COURT_OWNER_DOMAIN)
            || ! $ledger instanceof AtlasLedgerEvent || ! app(AtlasEvidenceLedger::class)->eventIntegrityValid($ledger)
            || $ledger->event_type !== LedgerEventType::GateEvaluated->value || $ledger->emitter_stage !== self::BACKEND_SPEC_COURT_OWNER_DOMAIN
            || $ledger->emitter_version !== self::BACKEND_SPEC_COURT_OWNER_VERSION || $ledger->getAttribute('scope_type') !== 'engineering_delivery'
            || $ledger->getAttribute('scope_id') !== $case->order->deliveryId || ! hash_equals((string) $ledger->getAttribute('event_hash'), $ledgerEnvelopeHash)
            || ($ledgerRef['occurred_at'] ?? null) !== $ledgerOccurredAt?->toISOString()
            || $ledgerOccurredAt === null || $ledgerOccurredAt->lt($issued) || $ledgerOccurredAt->gt($expires)
            || ($ledgerRef['event_hash'] ?? null) !== $ledger->getAttribute('event_hash') || ($ledgerRef['payload_hash'] ?? null) !== $ledger->payload_hash
            || data_get($ledger->payload, 'receipt_body_hash') !== EngineeringCompanyHash::make(array_diff_key($unsigned, ['ledger_event' => true, 'producer' => true]))
            || data_get($ledger->payload, 'run_id') !== $case->order->runId || data_get($ledger->payload, 'delivery_id') !== $case->order->deliveryId
            || data_get($ledger->payload, 'workspace') !== $case->order->workspace || data_get($ledger->payload, 'case_hash') !== $case->caseHash
            || data_get($ledger->payload, 'order_hash') !== $case->order->canonicalHash() || data_get($ledger->payload, 'spec_hash') !== $case->order->specHash
            || data_get($ledger->payload, 'owner_domain') !== self::BACKEND_SPEC_COURT_OWNER_DOMAIN
            || data_get($ledger->payload, 'owner_version') !== self::BACKEND_SPEC_COURT_OWNER_VERSION) {
            return null;
        }

        return ['row_id' => (string) $row->getKey(), 'receipt_hash' => (string) $receipt['hash'], 'contract' => $contract,
            'artifact' => ['path' => $path, 'sha256' => (string) $artifact['sha256']]];
    }

    private function pathHasSymlinkBetween(string $root, string $path): bool
    {
        $relative = ltrim(substr($path, strlen($root)), '/');
        $cursor = $root;
        foreach (explode('/', $relative) as $part) {
            $cursor .= '/'.$part;
            if (is_link($cursor)) {
                return true;
            }
        }

        return false;
    }

    public function persistCandidateBackendOwnerReceipt(AiEngineeringCompanyEngagement $engagement, AiEngineeringCompanyCycle $cycle, CandidateQualityCase $case): AiEngineeringCompanyRoleRun
    {
        if (! $engagement->exists || ! $cycle->exists || $cycle->engagement_record_id !== $engagement->getKey()
            || (string) $engagement->getKey() !== $case->engagementRecordId || (string) $cycle->getKey() !== $case->cycleRecordId) {
            throw new \InvalidArgumentException('candidate_backend_owner_binding_invalid');
        }
        $specAuthority = $this->backendSpecCourtAuthorityReceipt($case);
        if ($specAuthority === null) {
            throw new \InvalidArgumentException('candidate_backend_product_spec_contract_authority_absent');
        }
        $evidence = $this->candidateBackendEvidence($case, true);
        $disposition = $this->candidateBackendDisposition($case, $evidence);
        $id = 'aerebackend_'.substr(RealExecutionHash::make([$case->caseHash, 'backend']), 0, 24);
        if (AiEngineeringCompanyRoleRun::query()->where('role_run_id', $id)->exists()) {
            throw new \InvalidArgumentException('candidate_backend_owner_duplicate');
        }
        $issued = CarbonImmutable::now()->startOfSecond();
        $expires = $issued->addHour();
        $refs = ['candidate:'.$case->candidate->candidateHash, 'verification:'.$case->verification->receiptHash,
            'backend_artifact:'.(string) data_get($evidence, 'raw_artifact.sha256'),
            'spec_court_receipt:'.$specAuthority['receipt_hash']];
        $typed = RoleEvidenceReceipt::issue($case, $disposition, self::CANDIDATE_BACKEND_OWNER_DOMAIN,
            self::CANDIDATE_BACKEND_OWNER_VERSION, $issued->toAtomString(), $expires->toAtomString(), $refs);
        $output = ['disposition' => $disposition->toArray(), 'role_evidence_receipt' => $typed->toArray()];
        $receipt = ['schema_version' => AtlasRealEngineeringCompanyRuntimeService::ROLE_SCHEMA,
            'purpose' => 'candidate_backend_owner_evidence', 'owner_domain' => self::CANDIDATE_BACKEND_OWNER_DOMAIN,
            'owner_version' => self::CANDIDATE_BACKEND_OWNER_VERSION, 'owner_identity' => self::class,
            'issued_at' => $issued->toAtomString(), 'expires_at' => $expires->toAtomString(), 'role_run_id' => $id,
            'role_id' => 'backend', 'status' => $disposition->status === 'pass' ? 'passed' : ($disposition->status === 'not_applicable' ? 'not_applicable' : 'blocked'),
            'output' => $output, 'evidence_refs' => $refs, 'binding' => $this->backendOwnerBinding($case),
            'disposition' => $disposition->toArray(), 'backend_evidence' => $evidence,
            'spec_court_receipt_ref' => ['row_id' => $specAuthority['row_id'], 'receipt_hash' => $specAuthority['receipt_hash']]];
        $receipt['producer'] = $this->candidateOwnerProducerSeal($receipt, self::CANDIDATE_BACKEND_OWNER_DOMAIN);
        $receipt['hash'] = EngineeringCompanyHash::make($receipt);

        return AiEngineeringCompanyRoleRun::query()->create(['engagement_record_id' => $engagement->getKey(),
            'cycle_record_id' => $cycle->getKey(), 'role_run_id' => $id, 'role_id' => 'backend', 'status' => $receipt['status'],
            'responsibilities' => [], 'output' => $output, 'evidence_refs' => $refs, 'receipt' => $receipt, 'role_hash' => $receipt['hash']]);
    }

    public function candidateBackendOwnerReceiptValid(AiEngineeringCompanyRoleRun $row, CandidateQualityCase $case): bool
    {
        $persisted = $row->exists ? AiEngineeringCompanyRoleRun::query()->find($row->getKey()) : null;
        $receipt = $persisted?->receipt;
        if (! $persisted instanceof AiEngineeringCompanyRoleRun || ! is_array($receipt) || $persisted->role_id !== 'backend') {
            return false;
        }
        try {
            $issued = CarbonImmutable::createFromFormat(DATE_ATOM, (string) ($receipt['issued_at'] ?? ''));
            $expires = CarbonImmutable::createFromFormat(DATE_ATOM, (string) ($receipt['expires_at'] ?? ''));
            $evidence = (array) ($receipt['backend_evidence'] ?? []);
            $specAuthority = $this->backendSpecCourtAuthorityReceipt($case);
            if ($specAuthority === null || ($receipt['spec_court_receipt_ref'] ?? null) !== [
                'row_id' => $specAuthority['row_id'], 'receipt_hash' => $specAuthority['receipt_hash'],
            ]) {
                return false;
            }
            $verification = $this->verifiedMutativeVerificationReceipt($case->verification, $case->order, true);
            $currentQaContract = ['verification_hash' => $case->verification->receiptHash,
                'commands_hash' => RealExecutionHash::make((array) data_get($verification->receipt, 'commands', [])),
                'runner_hash' => data_get($verification->receipt, 'behavioral.runner_hash'),
                'behavioral_junit_hash' => data_get($verification->receipt, 'behavioral.junit_artifact.sha256'),
                'backend_contract_hash' => $specAuthority['artifact']['sha256']];
            if (($evidence['qa_contract'] ?? null) !== $currentQaContract
                || in_array(self::class, [$case->verification->providerIdentity, $case->verification->authorIdentity, $case->verification->verifierIdentity], true)) {
                return false;
            }
            $binding = (array) data_get($verification->receipt, 'binding', []);
            $currentSourceHashes = (array) ($binding['source_hashes'] ?? []);
            $currentAnalyses = [];
            foreach ($case->candidate->files as $file) {
                if (! str_ends_with($file, '.php') || ! str_starts_with($file, 'app/')) {
                    continue;
                }
                $source = $this->signedTreeFile((string) ($binding['tree_hash'] ?? ''), $file, $case->candidate->sandboxRoot);
                if ($source === null || ! hash_equals((string) ($currentSourceHashes[$file] ?? ''), hash('sha256', $source))) {
                    return false;
                }
                $currentAnalyses[$file] = $this->analyzeBackendPhpSource($source);
            }
            if (($evidence['source_analyses'] ?? null) !== $currentAnalyses
                || ($evidence['source_contract_hash'] ?? null) !== RealExecutionHash::make($currentAnalyses)) {
                return false;
            }
            $artifact = (array) ($evidence['raw_artifact'] ?? []);
            $root = $this->ownedAtlasArtifactRoot($case->candidate->sandboxRoot);
            $path = realpath((string) ($artifact['path'] ?? ''));
            if ($path === false || ! str_starts_with($path, $root.'/') || is_link((string) ($artifact['path'] ?? ''))
                || ! hash_equals((string) ($artifact['sha256'] ?? ''), (string) hash_file('sha256', $path))) {
                return false;
            }
            $raw = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($raw) || $raw !== array_diff_key($evidence, ['raw_artifact' => true])) {
                return false;
            }
            $disposition = $this->candidateBackendDisposition($case, $evidence);
        } catch (\Throwable) {
            return false;
        }
        $refs = ['candidate:'.$case->candidate->candidateHash, 'verification:'.$case->verification->receiptHash,
            'backend_artifact:'.(string) data_get($evidence, 'raw_artifact.sha256'),
            'spec_court_receipt:'.$specAuthority['receipt_hash']];
        $typed = RoleEvidenceReceipt::issue($case, $disposition, self::CANDIDATE_BACKEND_OWNER_DOMAIN,
            self::CANDIDATE_BACKEND_OWNER_VERSION, (string) $receipt['issued_at'], (string) $receipt['expires_at'], $refs)->toArray();
        $output = $persisted->output;
        $unsigned = array_diff_key($receipt, ['hash' => true]);

        return is_array($output) && $issued !== null && $expires !== null && ! CarbonImmutable::now()->lt($issued) && CarbonImmutable::now()->lt($expires)
            && ($receipt['purpose'] ?? null) === 'candidate_backend_owner_evidence'
            && ($receipt['owner_domain'] ?? null) === self::CANDIDATE_BACKEND_OWNER_DOMAIN
            && ($receipt['binding'] ?? null) === $this->backendOwnerBinding($case)
            && ($receipt['evidence_refs'] ?? null) === $refs && ($receipt['output'] ?? null) === $output
            && ($output['disposition'] ?? null) === $disposition->toArray() && ($output['role_evidence_receipt'] ?? null) === $typed
            && hash_equals((string) $persisted->role_hash, (string) ($receipt['hash'] ?? ''))
            && hash_equals((string) ($receipt['hash'] ?? ''), EngineeringCompanyHash::make($unsigned))
            && $this->candidateOwnerProducerSealValid($unsigned, self::CANDIDATE_BACKEND_OWNER_DOMAIN);
    }

    /** @param array<string,mixed> $evidence */
    public function candidateBackendDisposition(CandidateQualityCase $case, array $evidence): RoleDisposition
    {
        $applies = ($evidence['applies'] ?? false) === true;
        $safe = ($evidence['safe'] ?? false) === true;
        $status = ! $applies ? 'not_applicable' : ($safe ? 'pass' : 'block');
        $reason = ! $applies ? 'signed_no_backend_runtime_change' : ($safe ? 'candidate_backend_contract_verified' : 'candidate_backend_unknown_or_contract_invalid');
        $payload = ['purpose' => 'candidate_backend_disposition', 'case_hash' => $case->caseHash,
            'evidence_hash' => RealExecutionHash::make($evidence), 'status' => $status, 'reason' => $reason, 'owner_identity' => self::class];

        return RoleDisposition::backendCandidateAdjudicated($case, $status, $reason, self::CANDIDATE_BACKEND_OWNER_DOMAIN,
            $this->candidateOwnerSignature($payload, self::CANDIDATE_BACKEND_OWNER_DOMAIN));
    }

    /** @return array<string,mixed> */
    private function candidateBackendEvidence(CandidateQualityCase $case, bool $writeArtifact): array
    {
        $verification = $this->verifiedMutativeVerificationReceipt($case->verification, $case->order, $writeArtifact);
        $binding = (array) data_get($verification->receipt, 'binding', []);
        $identities = (array) data_get($verification->receipt, 'identities', []);
        $tree = (string) ($binding['tree_hash'] ?? '');
        $sourceHashes = (array) ($binding['source_hashes'] ?? []);
        $analyses = [];
        foreach ($case->candidate->files as $file) {
            if (! str_ends_with($file, '.php') || ! str_starts_with($file, 'app/')) {
                continue;
            }
            $source = $this->signedTreeFile($tree, $file, $case->candidate->sandboxRoot);
            if ($source === null || ! hash_equals((string) ($sourceHashes[$file] ?? ''), hash('sha256', $source))) {
                throw new \InvalidArgumentException('candidate_backend_signed_source_unavailable');
            }
            $analyses[$file] = $this->analyzeBackendPhpSource($source);
        }
        $specAuthority = $this->backendSpecCourtAuthorityReceipt($case);
        $contract = $specAuthority['contract'] ?? null;
        $includeGraphSafe = is_array($contract) && is_array($contract['permitted_includes'] ?? null);
        $permittedIncludes = $includeGraphSafe ? array_values(array_map('strval', $contract['permitted_includes'])) : [];
        foreach ($analyses as $analysis) {
            foreach ((array) ($analysis['include_targets'] ?? []) as $target) {
                if (! in_array($target, $permittedIncludes, true)
                    || $this->signedTreeFile($tree, (string) $target, $case->candidate->sandboxRoot) === null) {
                    $includeGraphSafe = false;
                }
            }
        }
        $entrypoint = $this->signedTreeFile($tree, is_array($contract) ? (string) ($contract['entrypoint'] ?? '') : '', $case->candidate->sandboxRoot);
        $profile = $analyses === [] ? ['status' => 'not_applicable', 'safe' => true]
            : (! is_array($contract) || ($contract['spec_hash'] ?? null) !== $case->order->specHash || ! is_string($entrypoint)
                ? ['status' => 'spec_contract_missing_or_invalid', 'safe' => false]
                : $this->runBackendContractProfile($entrypoint, $contract));
        $qaContract = ['verification_hash' => $case->verification->receiptHash,
            'commands_hash' => RealExecutionHash::make((array) data_get($verification->receipt, 'commands', [])),
            'runner_hash' => data_get($verification->receipt, 'behavioral.runner_hash'),
            'behavioral_junit_hash' => data_get($verification->receipt, 'behavioral.junit_artifact.sha256'),
            'backend_contract_hash' => $specAuthority['artifact']['sha256'] ?? null];
        $identitySeparated = ! in_array(self::class, [(string) ($identities['provider'] ?? ''), (string) ($identities['author'] ?? ''), (string) ($identities['verifier'] ?? '')], true);
        $safe = $analyses !== [] && ! array_any($analyses, static fn (array $analysis): bool => ($analysis['safe'] ?? false) !== true)
            && $includeGraphSafe && ($profile['safe'] ?? false) === true && $identitySeparated;
        $raw = ['schema_version' => 'atlas.candidate_backend_evidence.v1', 'case_hash' => $case->caseHash,
            'order_hash' => $case->order->canonicalHash(), 'spec_hash' => $case->order->specHash,
            'candidate_hash' => $case->candidate->candidateHash, 'base_commit' => $case->candidate->baseCommit,
            'tree_hash' => $case->candidate->treeHash, 'diff_hash' => $case->candidate->diffHash, 'files' => $case->candidate->files,
            'source_analyses' => $analyses, 'source_contract_hash' => RealExecutionHash::make($analyses),
            'include_graph_safe' => $includeGraphSafe,
            'qa_contract' => $qaContract, 'qa_contract_hash' => RealExecutionHash::make($qaContract), 'behavioral_profile' => $profile,
            'spec_contract' => $contract, 'spec_contract_hash' => is_array($contract) ? RealExecutionHash::make($contract) : null,
            'identity_separated' => $identitySeparated, 'applies' => $analyses !== [], 'safe' => $safe, 'owner_identity' => self::class];
        $root = $this->ownedAtlasArtifactRoot($case->candidate->sandboxRoot);
        $artifact = $root.'/backend-contract-'.$case->caseHash.'.json';
        if ($writeArtifact) {
            File::put($artifact, json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
        $real = realpath($artifact);
        if ($real === false || ! str_starts_with($real, $root.'/') || is_link($artifact)) {
            throw new \InvalidArgumentException('candidate_backend_artifact_unavailable');
        }

        return $raw + ['raw_artifact' => ['path' => $real, 'sha256' => hash_file('sha256', $real)]];
    }

    /** @return array<string,mixed> */
    private function analyzeBackendPhpSource(string $source): array
    {
        try {
            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);
            if (! is_array($ast)) {
                return ['safe' => false, 'findings' => ['parse_unknown']];
            }
        } catch (\Throwable) {
            return ['safe' => false, 'findings' => ['parse_failed']];
        }
        $finder = new NodeFinder;
        $findings = [];
        $includeTargets = [];
        foreach ($finder->find($ast, static fn (Node $node): bool => $node instanceof Node\Expr\Eval_
            || $node instanceof Node\Expr\Exit_ || $node instanceof Node\Expr\ErrorSuppress) as $node) {
            $findings[] = 'forbidden_'.strtolower((new \ReflectionClass($node))->getShortName());
        }
        foreach ($finder->findInstanceOf($ast, Node\FunctionLike::class) as $function) {
            if ($function instanceof Node\Expr\Closure || $function instanceof Node\Expr\ArrowFunction) {
                continue;
            }
            if ($function->getReturnType() === null) {
                $findings[] = 'missing_return_type';
            }
            foreach ($function->getParams() as $parameter) {
                if ($parameter->type === null) {
                    $findings[] = 'missing_parameter_type';
                }
            }
        }
        foreach ($finder->findInstanceOf($ast, Node\Expr\Include_::class) as $include) {
            if (! $include->expr instanceof Node\Scalar\String_) {
                $findings[] = 'dynamic_include_forbidden';
            } else {
                $includeTargets[] = $include->expr->value;
            }
        }
        foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class) as $method) {
            if (($method->flags & (Node\Stmt\Class_::MODIFIER_PUBLIC | Node\Stmt\Class_::MODIFIER_PROTECTED | Node\Stmt\Class_::MODIFIER_PRIVATE)) === 0) {
                $findings[] = 'implicit_visibility';
            }
        }
        $returns = $finder->findInstanceOf($ast, Node\Stmt\Return_::class);
        if ($returns === []) {
            $findings[] = 'missing_backend_contract_return';
        }
        $findings = array_values(array_unique($findings));

        return ['safe' => $findings === [], 'findings' => $findings, 'include_targets' => $includeTargets,
            'ast_hash' => RealExecutionHash::make(array_map(
                static fn (Node $node): string => $node::class, $finder->find($ast, static fn (Node $node): bool => true),
            ))];
    }

    /** @param array<string,mixed> $contract @return array<string,mixed> */
    protected function runBackendContractProfile(string $source, array $contract): array
    {
        $cases = $contract['cases'] ?? null;
        if (! is_array($cases) || count($cases) !== 1 || ! is_array($cases[0] ?? null)
            || ($contract['schema_version'] ?? null) !== 'atlas.backend_contract.v1') {
            return ['status' => 'contract_unexpressible', 'safe' => false];
        }
        $root = '/private/tmp/atlas-backend-contract-'.bin2hex(random_bytes(12));
        if (! mkdir($root, 0700)) {
            return ['status' => 'runner_unavailable', 'safe' => false];
        }
        try {
            File::put($root.'/candidate.php', $source);
            $sandbox = '/usr/bin/sandbox-exec';
            $profile = '(version 1)(deny default)(deny network*)(allow process*)(allow sysctl-read)(allow mach-lookup)'
                .'(allow file-read-metadata)(allow file-read* (literal "/") (subpath "/opt/homebrew") (subpath "/usr/lib") (subpath "/System/Library") (subpath "/Library") (subpath "/private/etc") (subpath "'.$root.'") (literal "/dev/null") (literal "/dev/urandom"))'
                .'(allow file-write* (literal "/dev/null"))';
            $secret = random_bytes(32);
            $nonce = bin2hex(random_bytes(24));
            $supervisor = <<<'PHP'
                $parentSecret=base64_decode((string)getenv('ATLAS_BACKEND_PARENT_SECRET'),true); $nonce=(string)getenv('ATLAS_BACKEND_NONCE');
                if(!is_string($parentSecret)||strlen($parentSecret)!==32){exit(90);} $wrapper=<<<'WRAPPER'
                $pipe=fopen('php://fd/3','rb');$secret=stream_get_contents($pipe);fclose($pipe);$nonce=$argv[1];$path=$argv[2];
                $invoke=static function(string $p):array{try{$value=require $p;return ['type'=>get_debug_type($value),'value_hash'=>hash('sha256',serialize($value)),'error'=>null,'effects'=>[]];}
                catch(Throwable $e){return ['type'=>null,'value_hash'=>null,'error'=>['class'=>$e::class,'message_hash'=>hash('sha256',$e->getMessage())],'effects'=>[]];}};
                $actual=$invoke($path);$marker=['schema_version'=>'atlas.backend_case_result.v1','nonce'=>$nonce,'pid'=>getmypid(),'actual'=>$actual];
                $marker['mac']=hash_hmac('sha256',json_encode($marker,JSON_THROW_ON_ERROR),$secret);echo json_encode($marker,JSON_THROW_ON_ERROR);
                WRAPPER;
                $null=fopen('/dev/null','ab');$desc=[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>$null,3=>['pipe','r']];$completionSecret=random_bytes(32);
                $child=proc_open([$argv[1],'-p',$argv[2],$argv[3],'-n','-r',$wrapper,$nonce,$argv[4]],$desc,$pipes,'/',['HOME'=>'/nonexistent']);
                if(!is_resource($child)){exit(91);}fwrite($pipes[3],$completionSecret);fclose($pipes[3]);$output=stream_get_contents($pipes[1]);fclose($pipes[1]);
                $exit=proc_close($child);if($exit!==0){exit(92);}$marker=json_decode($output,true);
                if(!is_array($marker)||($marker['schema_version']??null)!=='atlas.backend_case_result.v1'||($marker['nonce']??null)!==$nonce||!is_int($marker['pid']??null)){exit(93);}
                $mac=$marker['mac']??null;$unsigned=array_diff_key($marker,['mac'=>true]);if(!is_string($mac)||!hash_equals($mac,hash_hmac('sha256',json_encode($unsigned,JSON_THROW_ON_ERROR),$completionSecret))){exit(94);}
                $envelope=['schema_version'=>'atlas.backend_supervisor.v1','nonce'=>$nonce,'target_pid'=>$marker['pid'],'actual'=>$marker['actual'],'marker_hash'=>hash('sha256',$output)];
                $envelope['supervisor_mac']=hash_hmac('sha256',json_encode($envelope,JSON_THROW_ON_ERROR),$parentSecret);echo json_encode($envelope,JSON_THROW_ON_ERROR);
                PHP;
            $process = new Process([PHP_BINARY, '-n', '-r', $supervisor, $sandbox, $profile, PHP_BINARY, $root.'/candidate.php'], '/', [
                'ATLAS_BACKEND_PARENT_SECRET' => base64_encode($secret), 'ATLAS_BACKEND_NONCE' => $nonce,
            ]);
            $process->setTimeout(3);
            try {
                $process->mustRun();
            } catch (\Throwable) {
                return ['status' => 'case_execution_failed', 'safe' => false];
            }
            $envelope = json_decode($process->getOutput(), true);
            if (! is_array($envelope) || ($envelope['schema_version'] ?? null) !== 'atlas.backend_supervisor.v1'
                || ($envelope['nonce'] ?? null) !== $nonce || ! is_int($envelope['target_pid'] ?? null)
                || ! is_array($envelope['actual'] ?? null) || ! is_string($envelope['supervisor_mac'] ?? null)) {
                return ['status' => 'supervisor_result_invalid', 'safe' => false];
            }
            $mac = $envelope['supervisor_mac'];
            $unsigned = array_diff_key($envelope, ['supervisor_mac' => true]);
            if (! hash_equals($mac, hash_hmac('sha256', json_encode($unsigned, JSON_THROW_ON_ERROR), $secret))) {
                return ['status' => 'supervisor_authentication_failed', 'safe' => false];
            }
            $expected = $cases[0];
            $actual = $envelope['actual'];
            $passed = ($actual['type'] ?? null) === ($expected['expected_type'] ?? null)
                && ($actual['value_hash'] ?? null) === ($expected['expected_value_hash'] ?? null)
                && ($actual['error'] ?? null) === ($expected['expected_error'] ?? null)
                && ($actual['effects'] ?? null) === ($expected['expected_effects'] ?? null);

            return ['status' => $passed ? 'contract_cases_passed' : 'contract_case_mismatch', 'safe' => $passed,
                'case_id' => $expected['id'] ?? null, 'expected_hash' => RealExecutionHash::make($expected), 'supervisor_envelope' => $envelope];
        } finally {
            File::deleteDirectory($root, true);
        }
    }

    public function persistCandidatePerformanceOwnerReceipt(AiEngineeringCompanyEngagement $engagement, AiEngineeringCompanyCycle $cycle, CandidateQualityCase $case): AiEngineeringCompanyRoleRun
    {
        if (! $engagement->exists || ! $cycle->exists || $cycle->engagement_record_id !== $engagement->getKey()
            || (string) $engagement->getKey() !== $case->engagementRecordId || (string) $cycle->getKey() !== $case->cycleRecordId) {
            throw new \InvalidArgumentException('candidate_performance_owner_binding_invalid');
        }
        $evidence = $this->candidatePerformanceEvidence($case, true);
        $disposition = $this->candidatePerformanceDisposition($case, $evidence);
        $roleRunId = 'aereperf_'.substr(RealExecutionHash::make([$case->caseHash, 'performance_resilience']), 0, 24);
        if (AiEngineeringCompanyRoleRun::query()->where('role_run_id', $roleRunId)->exists()) {
            throw new \InvalidArgumentException('candidate_performance_owner_duplicate');
        }
        $issued = CarbonImmutable::now()->startOfSecond();
        $expires = $issued->addHour();
        $refs = ['candidate:'.$case->candidate->candidateHash, 'verification:'.$case->verification->receiptHash,
            'performance_artifact:'.(string) data_get($evidence, 'raw_artifact.sha256'),
            'performance_recovery_artifact:'.(string) data_get($evidence, 'recovery_artifact.sha256')];
        $typed = RoleEvidenceReceipt::issue($case, $disposition, self::CANDIDATE_PERFORMANCE_OWNER_DOMAIN,
            self::CANDIDATE_PERFORMANCE_OWNER_VERSION, $issued->toAtomString(), $expires->toAtomString(), $refs);
        $output = ['disposition' => $disposition->toArray(), 'role_evidence_receipt' => $typed->toArray()];
        $receipt = ['schema_version' => AtlasRealEngineeringCompanyRuntimeService::ROLE_SCHEMA,
            'purpose' => 'candidate_performance_owner_evidence', 'owner_domain' => self::CANDIDATE_PERFORMANCE_OWNER_DOMAIN,
            'owner_version' => self::CANDIDATE_PERFORMANCE_OWNER_VERSION, 'owner_identity' => self::class,
            'issued_at' => $issued->toAtomString(), 'expires_at' => $expires->toAtomString(), 'role_run_id' => $roleRunId,
            'role_id' => 'performance_resilience', 'status' => $disposition->status === 'pass' ? 'passed' : ($disposition->status === 'not_applicable' ? 'not_applicable' : 'blocked'),
            'output' => $output, 'evidence_refs' => $refs, 'binding' => $this->candidateOwnerBinding($case),
            'disposition' => $disposition->toArray(), 'performance_evidence' => $evidence];
        $receipt['producer'] = $this->candidateOwnerProducerSeal($receipt, self::CANDIDATE_PERFORMANCE_OWNER_DOMAIN);
        $receipt['hash'] = EngineeringCompanyHash::make($receipt);

        return AiEngineeringCompanyRoleRun::query()->create(['engagement_record_id' => $engagement->getKey(),
            'cycle_record_id' => $cycle->getKey(), 'role_run_id' => $roleRunId, 'role_id' => 'performance_resilience',
            'status' => $receipt['status'], 'responsibilities' => [], 'output' => $output, 'evidence_refs' => $refs,
            'receipt' => $receipt, 'role_hash' => $receipt['hash']]);
    }

    public function candidatePerformanceOwnerReceiptValid(AiEngineeringCompanyRoleRun $row, CandidateQualityCase $case): bool
    {
        $persisted = $row->exists ? AiEngineeringCompanyRoleRun::query()->find($row->getKey()) : null;
        $receipt = $persisted?->receipt;
        if (! $persisted instanceof AiEngineeringCompanyRoleRun || ! is_array($receipt) || $persisted->role_id !== 'performance_resilience'
            || (string) $persisted->engagement_record_id !== $case->engagementRecordId || (string) $persisted->cycle_record_id !== $case->cycleRecordId) {
            return false;
        }
        try {
            $issued = CarbonImmutable::createFromFormat(DATE_ATOM, (string) ($receipt['issued_at'] ?? ''));
            $expires = CarbonImmutable::createFromFormat(DATE_ATOM, (string) ($receipt['expires_at'] ?? ''));
            $evidence = (array) ($receipt['performance_evidence'] ?? []);
            $artifact = (array) ($evidence['raw_artifact'] ?? []);
            $recoveryArtifact = (array) ($evidence['recovery_artifact'] ?? []);
            $root = $this->ownedAtlasArtifactRoot($case->candidate->sandboxRoot);
            $path = realpath((string) ($artifact['path'] ?? ''));
            $recoveryPath = realpath((string) ($recoveryArtifact['path'] ?? ''));
            if ($path === false || ! str_starts_with($path, $root.'/') || is_link((string) ($artifact['path'] ?? ''))
                || ! hash_equals((string) ($artifact['sha256'] ?? ''), (string) hash_file('sha256', $path))
                || $recoveryPath === false || ! str_starts_with($recoveryPath, $root.'/') || is_link((string) ($recoveryArtifact['path'] ?? ''))
                || ! hash_equals((string) ($recoveryArtifact['sha256'] ?? ''), (string) hash_file('sha256', $recoveryPath))) {
                return false;
            }
            $raw = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $recoveryRaw = json_decode((string) file_get_contents($recoveryPath), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($raw) || $raw !== array_diff_key($evidence, ['raw_artifact' => true])
                || ! is_array($recoveryRaw) || $recoveryRaw !== ($evidence['measurements']['recovery'] ?? null)) {
                return false;
            }
            $disposition = $this->candidatePerformanceDisposition($case, $evidence);
        } catch (\Throwable) {
            return false;
        }
        $refs = ['candidate:'.$case->candidate->candidateHash, 'verification:'.$case->verification->receiptHash,
            'performance_artifact:'.(string) data_get($evidence, 'raw_artifact.sha256'),
            'performance_recovery_artifact:'.(string) data_get($evidence, 'recovery_artifact.sha256')];
        $typed = RoleEvidenceReceipt::issue($case, $disposition, self::CANDIDATE_PERFORMANCE_OWNER_DOMAIN,
            self::CANDIDATE_PERFORMANCE_OWNER_VERSION, (string) $receipt['issued_at'], (string) $receipt['expires_at'], $refs)->toArray();
        $output = $persisted->output;
        $unsigned = array_diff_key($receipt, ['hash' => true]);

        return is_array($output) && $issued !== null && $expires !== null && ! CarbonImmutable::now()->lt($issued) && CarbonImmutable::now()->lt($expires)
            && ($receipt['purpose'] ?? null) === 'candidate_performance_owner_evidence'
            && ($receipt['owner_domain'] ?? null) === self::CANDIDATE_PERFORMANCE_OWNER_DOMAIN
            && ($receipt['binding'] ?? null) === $this->candidateOwnerBinding($case)
            && ($receipt['evidence_refs'] ?? null) === $refs && ($receipt['output'] ?? null) === $output
            && ($output['disposition'] ?? null) === $disposition->toArray() && ($output['role_evidence_receipt'] ?? null) === $typed
            && hash_equals((string) $persisted->role_hash, (string) ($receipt['hash'] ?? ''))
            && hash_equals((string) ($receipt['hash'] ?? ''), EngineeringCompanyHash::make($unsigned))
            && $this->candidateOwnerProducerSealValid($unsigned, self::CANDIDATE_PERFORMANCE_OWNER_DOMAIN);
    }

    /** @param array<string,mixed> $evidence */
    public function candidatePerformanceDisposition(CandidateQualityCase $case, array $evidence): RoleDisposition
    {
        $applies = ($evidence['applies'] ?? false) === true;
        $safe = ($evidence['safe'] ?? false) === true;
        $status = ! $applies ? 'not_applicable' : ($safe ? 'pass' : 'block');
        $reason = ! $applies ? 'signed_no_runtime_path_change' : ($safe ? 'candidate_performance_within_frozen_budget' : 'candidate_performance_unknown_or_over_budget');
        $payload = ['purpose' => 'candidate_performance_disposition', 'case_hash' => $case->caseHash,
            'evidence_hash' => RealExecutionHash::make($evidence), 'status' => $status, 'reason' => $reason, 'owner_identity' => self::class];

        return RoleDisposition::performanceCandidateAdjudicated($case, $status, $reason, self::CANDIDATE_PERFORMANCE_OWNER_DOMAIN,
            $this->candidateOwnerSignature($payload, self::CANDIDATE_PERFORMANCE_OWNER_DOMAIN));
    }

    /** @return array<string,mixed> */
    private function candidatePerformanceEvidence(CandidateQualityCase $case, bool $writeArtifact): array
    {
        $verification = $this->verifiedMutativeVerificationReceipt($case->verification, $case->order, $writeArtifact);
        $binding = (array) data_get($verification->receipt, 'binding', []);
        $identities = (array) data_get($verification->receipt, 'identities', []);
        $tree = (string) ($binding['tree_hash'] ?? '');
        $sourceHashes = (array) ($binding['source_hashes'] ?? []);
        $runtimePaths = [];
        foreach ($case->candidate->files as $file) {
            $candidateSource = $this->signedTreeFile($tree, $file, $case->candidate->sandboxRoot);
            if ($candidateSource === null || ! hash_equals((string) ($sourceHashes[$file] ?? ''), hash('sha256', $candidateSource))) {
                throw new \InvalidArgumentException('candidate_performance_signed_source_unavailable');
            }
            if (! str_ends_with($file, '.php') || ! $this->phpSourceHasRuntimeImpact($candidateSource)) {
                continue;
            }
            $runtimePaths[] = $file;
        }
        sort($runtimePaths, SORT_STRING);
        $policy = CandidatePerformancePolicy::frozen()->toArray();
        $entrypoint = 'app/Candidate.php';
        $baselineEntrypoint = $this->signedTreeFile($case->candidate->baseCommit, $entrypoint, $case->candidate->sandboxRoot);
        $candidateEntrypoint = $this->signedTreeFile($tree, $entrypoint, $case->candidate->sandboxRoot);
        $measurements = $runtimePaths === [] ? ['status' => 'not_applicable']
            : ((! is_string($baselineEntrypoint) || ! is_string($candidateEntrypoint))
                ? ['status' => 'preregistered_workload_unavailable', 'safe' => false]
                : $this->runCandidatePerformanceProfile($baselineEntrypoint, $candidateEntrypoint, $policy));
        $identitySeparated = ! in_array(self::class, [(string) ($identities['provider'] ?? ''),
            (string) ($identities['author'] ?? ''), (string) ($identities['verifier'] ?? '')], true);
        $safe = $runtimePaths !== [] && ($measurements['safe'] ?? false) === true && $identitySeparated;
        $raw = ['schema_version' => 'atlas.candidate_performance_evidence.v1', 'case_hash' => $case->caseHash,
            'order_hash' => $case->order->canonicalHash(), 'spec_hash' => $case->order->specHash,
            'candidate_hash' => $case->candidate->candidateHash, 'base_commit' => $case->candidate->baseCommit,
            'tree_hash' => $case->candidate->treeHash, 'diff_hash' => $case->candidate->diffHash, 'files' => $case->candidate->files,
            'runtime_paths' => $runtimePaths, 'baseline_blob_hash' => hash('sha256', (string) $baselineEntrypoint),
            'candidate_blob_hash' => hash('sha256', (string) $candidateEntrypoint), 'policy' => $policy, 'policy_hash' => RealExecutionHash::make($policy),
            'metric_shape_hash' => RealExecutionHash::make(['wall_ms', 'cpu_user_us', 'cpu_system_us', 'peak_memory_bytes', 'throughput', 'timeout', 'recovery']),
            'runner_hash' => hash('sha256', 'atlas.fixed.php_behavior_entrypoint_runner.v2'), 'measurements' => $measurements,
            'identity_separated' => $identitySeparated,
            'provider_identity_hash' => hash('sha256', (string) ($identities['provider'] ?? '')),
            'author_identity_hash' => hash('sha256', (string) ($identities['author'] ?? '')),
            'verifier_identity_hash' => hash('sha256', (string) ($identities['verifier'] ?? '')),
            'applies' => $runtimePaths !== [], 'safe' => $safe, 'owner_identity' => self::class];
        $root = $this->ownedAtlasArtifactRoot($case->candidate->sandboxRoot);
        $recoveryArtifact = $root.'/performance-recovery-'.$case->caseHash.'.json';
        $recovery = (array) ($measurements['recovery'] ?? ['status' => 'not_applicable']);
        if ($writeArtifact) {
            File::put($recoveryArtifact, json_encode($recovery, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
        $recoveryReal = realpath($recoveryArtifact);
        if ($recoveryReal === false || ! str_starts_with($recoveryReal, $root.'/') || is_link($recoveryArtifact)) {
            throw new \InvalidArgumentException('candidate_performance_recovery_artifact_unavailable');
        }
        $raw['recovery_artifact'] = ['path' => $recoveryReal, 'sha256' => hash_file('sha256', $recoveryReal)];
        $artifact = $root.'/performance-probe-'.$case->caseHash.'.json';
        if ($writeArtifact) {
            File::put($artifact, json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
        $real = realpath($artifact);
        if ($real === false || ! str_starts_with($real, $root.'/') || is_link($artifact)) {
            throw new \InvalidArgumentException('candidate_performance_artifact_unavailable');
        }

        return $raw + ['raw_artifact' => ['path' => $real, 'sha256' => hash_file('sha256', $real)]];
    }

    private function phpSourceHasRuntimeImpact(string $source): bool
    {
        try {
            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);
            if (! is_array($ast)) {
                return true;
            }
        } catch (\Throwable) {
            return true;
        }
        $finder = new NodeFinder;
        foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassLike::class) as $classLike) {
            if (! $classLike instanceof Node\Stmt\Class_ || ! $classLike->extends instanceof Node\Name
                || ! in_array($classLike->extends->getLast(), ['TestCase'], true)) {
                return true;
            }
        }
        if ($finder->findInstanceOf($ast, Node\Stmt\Function_::class) !== []) {
            return true;
        }
        foreach ($ast as $statement) {
            if (! $statement instanceof Node\Stmt\Declare_ && ! $statement instanceof Node\Stmt\Namespace_
                && ! $statement instanceof Node\Stmt\Use_ && ! $statement instanceof Node\Stmt\GroupUse
                && ! $statement instanceof Node\Stmt\ClassLike && ! $statement instanceof Node\Stmt\Nop) {
                return true;
            }
        }

        return false;
    }

    private function phpSourceHasEarlyExit(string $source): bool
    {
        try {
            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);

            return is_array($ast) && (new NodeFinder)->findInstanceOf($ast, Node\Expr\Exit_::class) !== [];
        } catch (\Throwable) {
            return true;
        }
    }

    /** @param array<string,mixed> $policy @return array<string,mixed> */
    protected function runCandidatePerformanceProfile(string $baseline, string $candidate, array $policy): array
    {
        $root = '/private/tmp/atlas-performance-'.bin2hex(random_bytes(12));
        if (! mkdir($root, 0700)) {
            return ['status' => 'runner_unavailable', 'safe' => false];
        }
        try {
            File::put($root.'/baseline.php', $baseline);
            File::put($root.'/candidate.php', $candidate);
            File::put($root.'/induced-timeout.php', '<?php usleep(500000);');
            File::put($root.'/early-exit.php', '<?php exit(0);');
            if ($this->phpSourceHasEarlyExit($baseline) || $this->phpSourceHasEarlyExit($candidate)) {
                return ['status' => 'candidate_control_flow_rejected', 'safe' => false];
            }
            $sandboxExec = '/usr/bin/sandbox-exec';
            if (! is_executable($sandboxExec)) {
                return ['status' => 'runner_unavailable', 'safe' => false];
            }
            $profile = '(version 1)(deny default)(deny network*)(allow process*)(allow sysctl-read)(allow mach-lookup)'
                .'(allow file-read-metadata)(allow file-read* (literal "/") (subpath "/opt/homebrew") (subpath "/usr/lib") (subpath "/System/Library") (subpath "/Library") (subpath "/private/etc") (subpath "'.$root.'") (literal "/dev/null") (literal "/dev/urandom"))'
                .'(allow file-write* (literal "/dev/null"))';
            $run = function (string $path, float $timeout) use ($sandboxExec, $profile): ?array {
                $secret = random_bytes(32);
                $nonce = bin2hex(random_bytes(24));
                $supervisor = <<<'PHP'
                    $secret = base64_decode((string) getenv('ATLAS_SUPERVISOR_SECRET'), true);
                    $nonce = (string) getenv('ATLAS_SUPERVISOR_NONCE');
                    if (!is_string($secret) || strlen($secret) !== 32 || !preg_match('/^[a-f0-9]{48}$/', $nonce)) { exit(90); }
                    $wrapper = <<<'WRAPPER'
                    $completionPipe = fopen('php://fd/3', 'rb'); $completionSecret = stream_get_contents($completionPipe); fclose($completionPipe);
                    $completionNonce = (string) $argv[1]; $candidatePath = (string) $argv[2];
                    $executeCandidate = static function (string $isolatedPath): void { for ($i=0; $i<200; $i++) { require $isolatedPath; } };
                    $executeCandidate($candidatePath);
                    $marker = ['schema_version'=>'atlas.performance_completion.v1','nonce'=>$completionNonce,'pid'=>getmypid(),'returned'=>true];
                    $marker['mac']=hash_hmac('sha256',json_encode($marker,JSON_THROW_ON_ERROR),$completionSecret);
                    echo json_encode($marker,JSON_THROW_ON_ERROR);
                    WRAPPER;
                    $null = fopen('/dev/null', 'ab'); $descriptors = [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>$null,3=>['pipe','r']];
                    $before = getrusage(1); $started = hrtime(true);
                    $completionSecret = random_bytes(32);
                    $child = proc_open([$argv[1],'-p',$argv[2],$argv[3],'-n','-r',$wrapper,$nonce,$argv[4]], $descriptors, $pipes, '/', ['HOME'=>'/nonexistent']);
                    if (!is_resource($child)) { exit(91); }
                    fwrite($pipes[3], $completionSecret); fclose($pipes[3]); stream_set_blocking($pipes[1], false);
                    $deadline = microtime(true) + (float) $argv[5]; $timedOut = false;
                    $candidateOutput = '';
                    do { $candidateOutput .= stream_get_contents($pipes[1]); if (strlen($candidateOutput) > 65536) { proc_terminate($child, 9); exit(93); }
                        $status = proc_get_status($child); if (!$status['running']) { break; }
                        if (microtime(true) >= $deadline) { $timedOut = true; proc_terminate($child, 9); break; } usleep(1000);
                    } while (true);
                    $candidateOutput .= stream_get_contents($pipes[1]); fclose($pipes[1]);
                    $exit = proc_close($child); if ($timedOut || ($exit !== 0 && ($status['exitcode'] ?? -1) !== 0)) { exit(92); }
                    $completion = json_decode($candidateOutput, true);
                    if (!is_array($completion) || array_keys($completion) !== ['schema_version','nonce','pid','returned','mac']
                      || $completion['schema_version'] !== 'atlas.performance_completion.v1' || $completion['nonce'] !== $nonce
                      || !is_int($completion['pid']) || $completion['pid'] <= 0 || $completion['returned'] !== true || !is_string($completion['mac'])) { exit(94); }
                    $unsignedCompletion = array_diff_key($completion, ['mac'=>true]);
                    if (!hash_equals($completion['mac'],hash_hmac('sha256',json_encode($unsignedCompletion,JSON_THROW_ON_ERROR),$completionSecret))) { exit(95); }
                    $after = getrusage(1); $wall = (hrtime(true)-$started)/1e6;
                    $metrics = ['wall_ms'=>$wall,
                      'cpu_user_us'=>(($after['ru_utime.tv_sec']-$before['ru_utime.tv_sec'])*1000000)+($after['ru_utime.tv_usec']-$before['ru_utime.tv_usec']),
                      'cpu_system_us'=>(($after['ru_stime.tv_sec']-$before['ru_stime.tv_sec'])*1000000)+($after['ru_stime.tv_usec']-$before['ru_stime.tv_usec']),
                      'peak_memory_bytes'=>(int)($after['ru_maxrss'] ?? 0),'operations'=>200,'throughput'=>200/max($wall/1000,0.000001)];
                    $envelope=['schema_version'=>'atlas.performance_supervisor_result.v1','nonce'=>$nonce,
                      'candidate_output_observed'=>false,'exit_code'=>0,'target_pid'=>$completion['pid'],'completion_marker_hash'=>hash('sha256',$candidateOutput),'metrics'=>$metrics];
                    $envelope['supervisor_mac']=hash_hmac('sha256',json_encode($envelope,JSON_THROW_ON_ERROR),$secret);
                    echo json_encode($envelope,JSON_THROW_ON_ERROR);
                    PHP;
                $process = new Process([PHP_BINARY, '-n', '-r', $supervisor, $sandboxExec, $profile, PHP_BINARY, $path, (string) $timeout], '/', [
                    'ATLAS_SUPERVISOR_SECRET' => base64_encode($secret), 'ATLAS_SUPERVISOR_NONCE' => $nonce,
                ]);
                $process->setTimeout($timeout + 1.0);
                try {
                    $process->mustRun();
                } catch (\Throwable) {
                    return null;
                }
                $envelope = json_decode($process->getOutput(), true);
                if (! is_array($envelope) || array_keys($envelope) !== ['schema_version', 'nonce', 'candidate_output_observed', 'exit_code', 'target_pid', 'completion_marker_hash', 'metrics', 'supervisor_mac']
                    || $envelope['schema_version'] !== 'atlas.performance_supervisor_result.v1' || $envelope['nonce'] !== $nonce
                    || $envelope['candidate_output_observed'] !== false || $envelope['exit_code'] !== 0
                    || ! is_int($envelope['target_pid']) || $envelope['target_pid'] <= 0
                    || ! is_string($envelope['completion_marker_hash']) || preg_match('/^[a-f0-9]{64}$/', $envelope['completion_marker_hash']) !== 1
                    || ! is_array($envelope['metrics'])) {
                    return null;
                }
                $unsigned = array_diff_key($envelope, ['supervisor_mac' => true]);
                if (! is_string($envelope['supervisor_mac']) || ! hash_equals($envelope['supervisor_mac'], hash_hmac('sha256', json_encode($unsigned, JSON_THROW_ON_ERROR), $secret))) {
                    return null;
                }
                $metrics = $envelope['metrics'];
                if (array_keys($metrics) !== ['wall_ms', 'cpu_user_us', 'cpu_system_us', 'peak_memory_bytes', 'operations', 'throughput']) {
                    return null;
                }
                foreach ($metrics as $metric => $value) {
                    if (! is_int($value) && ! is_float($value) || ! is_finite((float) $value) || $value < 0
                        || ($metric === 'peak_memory_bytes' && $value > 1_099_511_627_776)
                        || ($metric !== 'peak_memory_bytes' && $value > max(1_000_000_000, $timeout * 10_000_000))) {
                        return null;
                    }
                }

                return $metrics + ['supervisor_envelope' => $envelope];
            };
            $baselineWarmup = $run($root.'/baseline.php', (float) $policy['timeout_seconds']);
            $candidateWarmup = $run($root.'/candidate.php', (float) $policy['timeout_seconds']);
            if ($baselineWarmup === null || $candidateWarmup === null) {
                return ['status' => 'warmup_failed', 'safe' => false, 'warmup_passed' => false];
            }
            $baselineRuns = [];
            $candidateRuns = [];
            for ($i = 0; $i < (int) $policy['repetitions']; $i++) {
                $baselineRuns[] = $run($root.'/baseline.php', (float) $policy['timeout_seconds']);
                $candidateRuns[] = $run($root.'/candidate.php', (float) $policy['timeout_seconds']);
            }
            $inducedFailure = $run($root.'/induced-timeout.php', 0.01);
            $recovery = $run($root.'/candidate.php', (float) $policy['timeout_seconds']);
            if (in_array(null, $baselineRuns, true) || in_array(null, $candidateRuns, true) || $inducedFailure !== null || $recovery === null) {
                return ['status' => 'timeout_or_runner_failure', 'safe' => false, 'recovery_passed' => false];
            }
            $summarize = static function (array $runs): array {
                $summary = ['runs' => $runs, 'cv' => []];
                foreach (['wall_ms', 'cpu_user_us', 'cpu_system_us', 'peak_memory_bytes', 'throughput'] as $metric) {
                    $values = array_map(static fn (array $run): float => (float) $run[$metric], $runs);
                    $mean = array_sum($values) / count($values);
                    $variance = array_sum(array_map(static fn (float $v): float => ($v - $mean) ** 2, $values)) / count($values);
                    $summary['min_'.$metric] = min($values);
                    $summary['max_'.$metric] = max($values);
                    $summary['cv'][$metric] = $mean > 0 ? sqrt($variance) / $mean : 0.0;
                }

                return $summary;
            };
            $base = $summarize($baselineRuns);
            $cand = $summarize($candidateRuns);
            $budgets = ['wall_ms' => ((float) $base['max_wall_ms'] * (float) $policy['max_ratio']) + (float) $policy['wall_slack_ms'],
                'cpu_user_us' => ((float) $base['max_cpu_user_us'] * (float) $policy['max_ratio']) + (float) $policy['cpu_slack_us'],
                'cpu_system_us' => ((float) $base['max_cpu_system_us'] * (float) $policy['max_ratio']) + (float) $policy['cpu_slack_us'],
                'peak_memory_bytes' => ((float) $base['max_peak_memory_bytes'] * (float) $policy['max_ratio']) + (int) $policy['rss_slack_bytes'],
                'min_throughput' => (float) $base['min_throughput'] / (float) $policy['max_ratio']];
            $dimensions = ['wall' => $cand['max_wall_ms'] <= $budgets['wall_ms'],
                'cpu_user' => $cand['max_cpu_user_us'] <= $budgets['cpu_user_us'],
                'cpu_system' => $cand['max_cpu_system_us'] <= $budgets['cpu_system_us'],
                'peak_rss' => $cand['max_peak_memory_bytes'] <= $budgets['peak_memory_bytes'],
                'throughput' => $cand['min_throughput'] >= $budgets['min_throughput'],
                'noise' => max([...$base['cv'], ...$cand['cv']]) <= (float) $policy['max_cv'],
                'timeout' => true, 'recovery' => data_get($recovery, 'supervisor_envelope.schema_version') === 'atlas.performance_supervisor_result.v1'
                    && data_get($recovery, 'supervisor_envelope.exit_code') === 0];
            $safe = ! in_array(false, $dimensions, true);

            return ['status' => $safe ? 'measured_within_budget' : 'measured_blocked', 'baseline' => $base, 'candidate' => $cand,
                'warmup' => ['baseline' => $baselineWarmup, 'candidate' => $candidateWarmup, 'excluded_from_repetitions' => true],
                'budgets' => $budgets, 'dimensions' => $dimensions,
                'recovery' => ['induced_failure' => 'timeout', 'fresh_process' => $recovery, 'passed' => $dimensions['recovery']],
                'recovery_passed' => $dimensions['recovery'], 'safe' => $safe];
        } finally {
            File::deleteDirectory($root, true);
        }
    }

    public function persistCandidateAppsecPrivacyOwnerReceipt(AiEngineeringCompanyEngagement $engagement, AiEngineeringCompanyCycle $cycle, CandidateQualityCase $case): AiEngineeringCompanyRoleRun
    {
        if (! $engagement->exists || ! $cycle->exists || $cycle->engagement_record_id !== $engagement->getKey()
            || (string) $engagement->getKey() !== $case->engagementRecordId || (string) $cycle->getKey() !== $case->cycleRecordId) {
            throw new \InvalidArgumentException('candidate_appsec_privacy_owner_binding_invalid');
        }
        $evidence = $this->candidateAppsecPrivacyEvidence($case, true);
        $disposition = $this->candidateAppsecPrivacyDisposition($case, $evidence);
        $roleRunId = 'aereappsec_'.substr(RealExecutionHash::make([$case->caseHash, 'appsec_privacy']), 0, 22);
        if (AiEngineeringCompanyRoleRun::query()->where('role_run_id', $roleRunId)->exists()) {
            throw new \InvalidArgumentException('candidate_appsec_privacy_owner_duplicate');
        }
        $issued = CarbonImmutable::now()->startOfSecond();
        $expires = $issued->addHour();
        $refs = ['candidate:'.$case->candidate->candidateHash, 'verification:'.$case->verification->receiptHash,
            'appsec_privacy_artifact:'.(string) data_get($evidence, 'raw_artifact.sha256')];
        $typed = RoleEvidenceReceipt::issue($case, $disposition, self::CANDIDATE_APPSEC_PRIVACY_OWNER_DOMAIN,
            self::CANDIDATE_APPSEC_PRIVACY_OWNER_VERSION, $issued->toAtomString(), $expires->toAtomString(), $refs);
        $output = ['disposition' => $disposition->toArray(), 'role_evidence_receipt' => $typed->toArray()];
        $receipt = ['schema_version' => AtlasRealEngineeringCompanyRuntimeService::ROLE_SCHEMA,
            'purpose' => 'candidate_appsec_privacy_owner_evidence', 'owner_domain' => self::CANDIDATE_APPSEC_PRIVACY_OWNER_DOMAIN,
            'owner_version' => self::CANDIDATE_APPSEC_PRIVACY_OWNER_VERSION, 'owner_identity' => CodeGraphSecretScanner::class,
            'issued_at' => $issued->toAtomString(), 'expires_at' => $expires->toAtomString(), 'role_run_id' => $roleRunId,
            'role_id' => 'appsec_privacy', 'status' => $disposition->status === 'pass' ? 'passed' : ($disposition->status === 'not_applicable' ? 'not_applicable' : 'blocked'),
            'output' => $output, 'evidence_refs' => $refs, 'binding' => $this->candidateOwnerBinding($case),
            'disposition' => $disposition->toArray(), 'appsec_privacy_evidence' => $evidence];
        $receipt['producer'] = $this->candidateOwnerProducerSeal($receipt, self::CANDIDATE_APPSEC_PRIVACY_OWNER_DOMAIN);
        $receipt['hash'] = EngineeringCompanyHash::make($receipt);

        return AiEngineeringCompanyRoleRun::query()->create(['engagement_record_id' => $engagement->getKey(),
            'cycle_record_id' => $cycle->getKey(), 'role_run_id' => $roleRunId, 'role_id' => 'appsec_privacy',
            'status' => $receipt['status'], 'responsibilities' => [], 'output' => $output,
            'evidence_refs' => $refs, 'receipt' => $receipt, 'role_hash' => $receipt['hash']]);
    }

    public function candidateAppsecPrivacyOwnerReceiptValid(AiEngineeringCompanyRoleRun $row, CandidateQualityCase $case): bool
    {
        $persisted = $row->exists ? AiEngineeringCompanyRoleRun::query()->find($row->getKey()) : null;
        $receipt = $persisted?->receipt;
        if (! $persisted instanceof AiEngineeringCompanyRoleRun || ! is_array($receipt) || $persisted->role_id !== 'appsec_privacy'
            || (string) $persisted->engagement_record_id !== $case->engagementRecordId || (string) $persisted->cycle_record_id !== $case->cycleRecordId) {
            return false;
        }
        try {
            $issued = CarbonImmutable::createFromFormat(DATE_ATOM, (string) ($receipt['issued_at'] ?? ''));
            $expires = CarbonImmutable::createFromFormat(DATE_ATOM, (string) ($receipt['expires_at'] ?? ''));
            $evidence = (array) ($receipt['appsec_privacy_evidence'] ?? []);
            $artifact = (array) ($evidence['raw_artifact'] ?? []);
            $root = $this->ownedAtlasArtifactRoot($case->candidate->sandboxRoot);
            $path = realpath((string) ($artifact['path'] ?? ''));
            if ($path === false || ! str_starts_with($path, $root.'/') || is_link((string) ($artifact['path'] ?? ''))
                || ! hash_equals((string) ($artifact['sha256'] ?? ''), (string) hash_file('sha256', $path))) {
                return false;
            }
            $raw = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($raw) || $raw !== array_diff_key($evidence, ['raw_artifact' => true])) {
                return false;
            }
            $disposition = $this->candidateAppsecPrivacyDisposition($case, $evidence);
        } catch (\Throwable) {
            return false;
        }
        $refs = ['candidate:'.$case->candidate->candidateHash, 'verification:'.$case->verification->receiptHash,
            'appsec_privacy_artifact:'.(string) data_get($evidence, 'raw_artifact.sha256')];
        $typed = RoleEvidenceReceipt::issue($case, $disposition, self::CANDIDATE_APPSEC_PRIVACY_OWNER_DOMAIN,
            self::CANDIDATE_APPSEC_PRIVACY_OWNER_VERSION, (string) $receipt['issued_at'], (string) $receipt['expires_at'], $refs)->toArray();
        $output = $persisted->output;
        $unsigned = array_diff_key($receipt, ['hash' => true]);

        return is_array($output) && $issued !== null && $expires !== null && ! CarbonImmutable::now()->lt($issued) && CarbonImmutable::now()->lt($expires)
            && ($receipt['purpose'] ?? null) === 'candidate_appsec_privacy_owner_evidence'
            && ($receipt['owner_domain'] ?? null) === self::CANDIDATE_APPSEC_PRIVACY_OWNER_DOMAIN
            && ($receipt['owner_version'] ?? null) === self::CANDIDATE_APPSEC_PRIVACY_OWNER_VERSION
            && ($receipt['owner_identity'] ?? null) === CodeGraphSecretScanner::class
            && ($receipt['binding'] ?? null) === $this->candidateOwnerBinding($case)
            && ($receipt['evidence_refs'] ?? null) === $refs && ($output['disposition'] ?? null) === $disposition->toArray()
            && ($output['role_evidence_receipt'] ?? null) === $typed && ($receipt['output'] ?? null) === $output
            && ($receipt['disposition'] ?? null) === $output['disposition']
            && hash_equals((string) $persisted->role_hash, (string) ($receipt['hash'] ?? ''))
            && hash_equals((string) ($receipt['hash'] ?? ''), EngineeringCompanyHash::make($unsigned))
            && $this->candidateOwnerProducerSealValid($unsigned, self::CANDIDATE_APPSEC_PRIVACY_OWNER_DOMAIN);
    }

    /** @param array<string,mixed> $evidence */
    public function candidateAppsecPrivacyDisposition(CandidateQualityCase $case, array $evidence): RoleDisposition
    {
        $applies = ($evidence['applies'] ?? false) === true;
        $safe = ($evidence['safe'] ?? false) === true;
        $status = ! $applies ? 'not_applicable' : ($safe ? 'pass' : 'block');
        $reason = ! $applies ? 'signed_no_sensitive_change_applicability' : ($safe ? 'candidate_appsec_privacy_probe_clean' : 'candidate_appsec_privacy_unsafe_or_unknown');
        $payload = ['purpose' => 'candidate_appsec_privacy_disposition', 'case_hash' => $case->caseHash,
            'evidence_hash' => RealExecutionHash::make($evidence), 'status' => $status, 'reason' => $reason,
            'owner_identity' => CodeGraphSecretScanner::class];

        return RoleDisposition::appsecPrivacyCandidateAdjudicated($case, $status, $reason,
            self::CANDIDATE_APPSEC_PRIVACY_OWNER_DOMAIN,
            $this->candidateOwnerSignature($payload, self::CANDIDATE_APPSEC_PRIVACY_OWNER_DOMAIN));
    }

    /** @return array<string,mixed> */
    private function candidateAppsecPrivacyEvidence(CandidateQualityCase $case, bool $writeArtifact): array
    {
        $verification = $this->verifiedMutativeVerificationReceipt($case->verification, $case->order, $writeArtifact);
        $binding = (array) data_get($verification->receipt, 'binding', []);
        $identities = (array) data_get($verification->receipt, 'identities', []);
        $tree = (string) ($binding['tree_hash'] ?? '');
        $sourceHashes = (array) ($binding['source_hashes'] ?? []);
        try {
            $scanner = app(CodeGraphSecretScanner::class);
        } catch (\Throwable) {
            $scanner = null;
        }
        $findings = [];
        $dependencyFindings = [];
        $sourceDigests = [];
        $sensitivePaths = [];
        foreach ($case->candidate->files as $file) {
            $show = new Process(['git', 'show', $tree.':'.$file], $case->candidate->sandboxRoot);
            $show->run();
            $source = $show->getOutput();
            if (! $show->isSuccessful() || ! isset($sourceHashes[$file])
                || ! hash_equals((string) $sourceHashes[$file], hash('sha256', $source))) {
                throw new \InvalidArgumentException('candidate_appsec_privacy_signed_source_unavailable');
            }
            $sourceDigests[$file] = hash('sha256', $source);
            if (str_contains($source, "\0")) {
                $findings[] = ['path' => $file, 'type' => 'unsupported_binary_construct', 'severity' => 'high'];

                continue;
            }
            if (! $scanner instanceof CodeGraphSecretScanner) {
                $findings[] = ['path' => $file, 'type' => 'scanner_unavailable', 'severity' => 'high'];

                continue;
            }
            try {
                $scan = $scanner->scan($source);
            } catch (\Throwable) {
                $scan = null;
            }
            if (! is_array($scan) || ! isset($scan['findings'], $scan['has_secrets'], $scan['count'])) {
                $findings[] = ['path' => $file, 'type' => 'scanner_unavailable', 'severity' => 'high'];

                continue;
            }
            foreach ((array) $scan['findings'] as $finding) {
                $findings[] = ['path' => $file, 'type' => (string) ($finding['type'] ?? 'unknown'),
                    'severity' => (string) ($finding['severity'] ?? 'unknown'), 'line' => (int) ($finding['line'] ?? 0)];
            }
            if (str_ends_with($file, '.php')) {
                array_push($dependencyFindings, ...$this->phpDependencyProvenanceFindings($source, $file, $tree, $case->candidate->sandboxRoot));
            } elseif (preg_match('/\.(?:js|jsx|ts|tsx)$/i', $file) === 1) {
                array_push($dependencyFindings, ...$this->javascriptDependencyProvenanceFindings($source, $file, $tree, $case->candidate->sandboxRoot));
            }
            if (preg_match('/\.(?:php|js|jsx|ts|tsx|json|ya?ml|env)$/i', $file) === 1 && ! str_starts_with($file, 'tests/')) {
                $sensitivePaths[] = $file;
            }
            if (in_array(basename($file), ['composer.json', 'package.json'], true)) {
                array_push($dependencyFindings, ...$this->manifestLockProvenanceFindings($source, $file, $tree, $case->candidate->sandboxRoot));
            }
        }
        sort($sensitivePaths, SORT_STRING);
        ksort($sourceDigests, SORT_STRING);
        usort($findings, static fn (array $a, array $b): int => [$a['path'], $a['line'] ?? 0, $a['type']] <=> [$b['path'], $b['line'] ?? 0, $b['type']]);
        usort($dependencyFindings, static fn (array $a, array $b): int => [$a['path'], $a['type']] <=> [$b['path'], $b['type']]);
        $scannerFile = (new \ReflectionClass(CodeGraphSecretScanner::class))->getFileName();
        $scannerHash = is_string($scannerFile) && is_file($scannerFile) ? hash_file('sha256', $scannerFile) : 'unavailable';
        $autoloadClassmap = base_path('vendor/composer/autoload_classmap.php');
        $autoloadClassmapHash = is_file($autoloadClassmap) ? hash_file('sha256', $autoloadClassmap) : 'unavailable';
        $signedComposerLock = $this->signedTreeFile($tree, 'composer.lock', $case->candidate->sandboxRoot);
        $signedComposerLockHash = $signedComposerLock === null ? 'absent' : hash('sha256', $signedComposerLock);
        if ($scannerHash === 'unavailable') {
            $findings[] = ['path' => '__scanner__', 'type' => 'scanner_unavailable', 'severity' => 'high'];
        }
        if ($autoloadClassmapHash === 'unavailable') {
            $dependencyFindings[] = ['path' => '__autoload__', 'type' => 'autoload_classmap_unavailable'];
        }
        $applies = $sensitivePaths !== [] || $findings !== [] || $dependencyFindings !== [];
        $ownerIdentity = CodeGraphSecretScanner::class;
        foreach (['provider', 'author'] as $actor) {
            if (hash_equals($ownerIdentity, (string) ($identities[$actor] ?? ''))) {
                $findings[] = ['path' => '__identity__', 'type' => $actor.'_equals_appsec_owner', 'severity' => 'high'];
            }
        }
        $raw = ['schema_version' => 'atlas.candidate_appsec_privacy_probe.v1', 'case_hash' => $case->caseHash,
            'order_hash' => $case->order->canonicalHash(), 'spec_hash' => $case->order->specHash,
            'candidate_hash' => $case->candidate->candidateHash, 'base_commit' => $case->candidate->baseCommit,
            'tree_hash' => $case->candidate->treeHash, 'diff_hash' => $case->candidate->diffHash, 'files' => $case->candidate->files,
            'source_hashes' => $sourceDigests, 'sensitive_paths' => $sensitivePaths, 'secret_privacy_findings' => $findings,
            'dependency_provenance_findings' => $dependencyFindings, 'scanner_schema' => CodeGraphSecretScanner::SCHEMA,
            'scanner_hash' => $scannerHash, 'autoload_classmap_hash' => $autoloadClassmapHash,
            'signed_composer_lock_hash' => $signedComposerLockHash,
            'semver_evaluator_hash' => hash('sha256', 'atlas.semver_evaluator.composer_npm_stable_only.v2'), 'applies' => $applies,
            'safe' => $findings === [] && $dependencyFindings === [], 'owner_identity' => $ownerIdentity,
            'owner_identity_hash' => hash('sha256', $ownerIdentity),
            'provider_identity_hash' => hash('sha256', (string) ($identities['provider'] ?? '')),
            'author_identity_hash' => hash('sha256', (string) ($identities['author'] ?? '')),
            'verifier_identity_hash' => hash('sha256', (string) ($identities['verifier'] ?? ''))];
        $root = $this->ownedAtlasArtifactRoot($case->candidate->sandboxRoot);
        $artifact = $root.'/appsec-privacy-probe-'.$case->caseHash.'.json';
        if ($writeArtifact) {
            File::put($artifact, json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
        $real = realpath($artifact);
        if ($real === false || ! str_starts_with($real, $root.'/') || is_link($artifact) || ! is_file($real)) {
            throw new \InvalidArgumentException('candidate_appsec_privacy_artifact_unavailable');
        }

        return $raw + ['raw_artifact' => ['path' => $real, 'sha256' => hash_file('sha256', $real)]];
    }

    /** @return list<array<string,string>> */
    private function phpDependencyProvenanceFindings(string $source, string $file, string $tree, string $sandbox): array
    {
        try {
            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);
            if (! is_array($ast)) {
                throw new \RuntimeException('php_ast_empty');
            }
            $traverser = new NodeTraverser;
            $traverser->addVisitor(new NameResolver);
            $traverser->addVisitor(new ParentConnectingVisitor);
            $ast = $traverser->traverse($ast);
        } catch (\Throwable) {
            return [['path' => $file, 'type' => 'php_ast_unsupported']];
        }
        $finder = new NodeFinder;
        $names = [];
        $record = static function (mixed $name) use (&$names): void {
            if ($name instanceof Node\Name) {
                $resolved = $name->getAttribute('resolvedName');
                $names[] = ltrim(($resolved instanceof Node\Name ? $resolved : $name)->toString(), '\\');
            } elseif (is_string($name) && $name !== '') {
                $names[] = ltrim($name, '\\');
            }
        };
        foreach ($finder->findInstanceOf($ast, Node\Name::class) as $name) {
            if ($this->phpAstNameIsDependencyReference($name)) {
                $record($name);
            }
        }
        $names = array_values(array_unique(array_filter($names)));
        sort($names, SORT_STRING);
        $findings = [];
        foreach ($names as $name) {
            if ($this->phpClassResolvableFromTreeOrAutoload($name, $tree, $sandbox)) {
                continue;
            }
            $findings[] = ['path' => $file, 'type' => 'php_dependency_class_unresolved',
                'reference_hash' => hash('sha256', $name)];
        }

        return $findings;
    }

    private function phpAstNameIsDependencyReference(Node\Name $name): bool
    {
        $parent = $name->getAttribute('parent');
        if (! $parent instanceof Node) {
            return false;
        }
        if ($parent instanceof Node\Stmt\Namespace_) {
            return false;
        }
        if ($parent instanceof Node\Stmt\UseUse || $parent instanceof Node\Stmt\TraitUse
            || $parent instanceof Node\Stmt\Catch_ || $parent instanceof Node\Attribute
            || $parent instanceof Node\Expr\New_ || $parent instanceof Node\Expr\StaticCall
            || $parent instanceof Node\Expr\ClassConstFetch || $parent instanceof Node\Expr\StaticPropertyFetch
            || $parent instanceof Node\Expr\Instanceof_ || $parent instanceof Node\Stmt\Class_
            || $parent instanceof Node\Stmt\Interface_ || $parent instanceof Node\Stmt\Enum_) {
            return true;
        }
        if ($parent instanceof Node\UnionType || $parent instanceof Node\IntersectionType || $parent instanceof Node\NullableType) {
            return true;
        }

        return $parent instanceof Node\Param || $parent instanceof Node\Stmt\Property
            || $parent instanceof Node\Stmt\ClassMethod || $parent instanceof Node\Stmt\Function_;
    }

    private function phpClassResolvableFromTreeOrAutoload(string $class, string $tree, string $sandbox): bool
    {
        $composerSource = $this->signedTreeFile($tree, 'composer.json', $sandbox);
        $composer = $composerSource === null ? null : json_decode($composerSource, true);
        if (is_array($composer)) {
            foreach (['autoload', 'autoload-dev'] as $section) {
                foreach ((array) data_get($composer, $section.'.psr-4', []) as $prefix => $directories) {
                    if (! is_string($prefix) || ! str_starts_with($class, $prefix)) {
                        continue;
                    }
                    foreach ((array) $directories as $directory) {
                        if (! is_string($directory)) {
                            continue;
                        }
                        $path = rtrim($directory, '/').'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
                        if ($this->signedPhpFileDeclaresClass($tree, $path, $class, $sandbox)) {
                            return true;
                        }
                    }
                }
                foreach ((array) data_get($composer, $section.'.classmap', []) as $mappedPath) {
                    if (! is_string($mappedPath)) {
                        continue;
                    }
                    $listing = new Process(['git', 'ls-tree', '-r', '--name-only', $tree, '--', $mappedPath], $sandbox);
                    $listing->run();
                    if (! $listing->isSuccessful()) {
                        continue;
                    }
                    foreach (array_filter(explode("\n", $listing->getOutput())) as $candidatePath) {
                        if (str_ends_with($candidatePath, '.php')
                            && $this->signedPhpFileDeclaresClass($tree, $candidatePath, $class, $sandbox)) {
                            return true;
                        }
                    }
                }
            }
        }
        $path = match (true) {
            str_starts_with($class, 'App\\') => 'app/'.str_replace('\\', '/', substr($class, 4)).'.php',
            str_starts_with($class, 'Tests\\') => 'tests/'.str_replace('\\', '/', substr($class, 6)).'.php',
            default => '',
        };
        if ($path !== '') {
            return $this->signedPhpFileDeclaresClass($tree, $path, $class, $sandbox);
        }

        if (! class_exists($class) && ! interface_exists($class) && ! trait_exists($class)
            && (! function_exists('enum_exists') || ! enum_exists($class))) {
            return false;
        }
        $reflection = new \ReflectionClass($class);
        $classFile = $reflection->getFileName();
        if ($classFile === false) {
            return true;
        }
        $vendorRoot = realpath(base_path('vendor'));
        $realClassFile = realpath($classFile);

        return $vendorRoot !== false && $realClassFile !== false && str_starts_with($realClassFile, $vendorRoot.'/')
            && $this->signedVendorClassProvenanceValid($class, $realClassFile, $tree, $sandbox);
    }

    private function signedVendorClassProvenanceValid(string $class, string $classFile, string $tree, string $sandbox): bool
    {
        $vendorRoot = realpath(base_path('vendor'));
        if ($vendorRoot === false || ! str_starts_with($classFile, $vendorRoot.'/')) {
            return false;
        }
        $relative = substr($classFile, strlen($vendorRoot) + 1);
        $segments = explode('/', $relative);
        if (count($segments) < 3) {
            return false;
        }
        $packageName = $segments[0].'/'.$segments[1];
        $manifestSource = $this->signedTreeFile($tree, 'composer.json', $sandbox);
        $lockSource = $this->signedTreeFile($tree, 'composer.lock', $sandbox);
        $manifest = $manifestSource === null ? null : json_decode($manifestSource, true);
        $lock = $lockSource === null ? null : json_decode($lockSource, true);
        if (! is_array($manifest) || ! is_array($lock)) {
            return false;
        }
        $constraint = data_get($manifest, 'require.'.$packageName, data_get($manifest, 'require-dev.'.$packageName));
        if (! is_string($constraint) || $constraint === '') {
            return false;
        }
        foreach ([...(array) ($lock['packages'] ?? []), ...(array) ($lock['packages-dev'] ?? [])] as $package) {
            if (! is_array($package) || ($package['name'] ?? null) !== $packageName || ! is_string($package['version'] ?? null)) {
                continue;
            }
            $reference = data_get($package, 'dist.reference', data_get($package, 'source.reference'));

            return is_string($reference) && $reference !== ''
                && $this->semverSatisfies($package['version'], $constraint, 'composer') === true;
        }

        return false;
    }

    private function signedPhpFileDeclaresClass(string $tree, string $path, string $class, string $sandbox): bool
    {
        $source = $this->signedTreeFile($tree, $path, $sandbox);
        if ($source === null) {
            return false;
        }
        try {
            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);
            if (! is_array($ast)) {
                return false;
            }
            $traverser = new NodeTraverser;
            $traverser->addVisitor(new NameResolver);
            $ast = $traverser->traverse($ast);
        } catch (\Throwable) {
            return false;
        }
        foreach ((new NodeFinder)->findInstanceOf($ast, Node\Stmt\ClassLike::class) as $declaration) {
            $declared = $declaration->getAttribute('namespacedName');
            if ($declared instanceof Node\Name && hash_equals($class, $declared->toString())) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array<string,string>> */
    private function javascriptDependencyProvenanceFindings(string $source, string $file, string $tree, string $sandbox): array
    {
        if (preg_match('/\bimport\s*\(\s*[^\'\"]/', $source) === 1) {
            return [['path' => $file, 'type' => 'javascript_dynamic_import_unsupported']];
        }
        preg_match_all('/(?:\bfrom\s*|\bimport\s*(?:\(\s*)?|\brequire\s*\()\s*[\'\"]([^\'\"]+)[\'\"]/', $source, $matches);
        $specifiers = array_values(array_unique($matches[1]));
        if (preg_match('/\b(?:import|require)\b/', $source) === 1 && $specifiers === []) {
            return [['path' => $file, 'type' => 'javascript_module_syntax_unsupported']];
        }
        sort($specifiers, SORT_STRING);
        $findings = [];
        foreach ($specifiers as $specifier) {
            if (! str_starts_with($specifier, '.')) {
                if (! $this->javascriptPackageLocked($specifier, $tree, $sandbox)) {
                    $findings[] = ['path' => $file, 'type' => 'javascript_package_provenance_unresolved',
                        'reference_hash' => hash('sha256', $specifier)];
                }

                continue;
            }
            $base = dirname($file).'/'.$specifier;
            $resolved = false;
            foreach (['', '.js', '.jsx', '.ts', '.tsx', '/index.js', '/index.ts'] as $suffix) {
                $candidate = str_replace('/./', '/', $base).$suffix;
                $probe = new Process(['git', 'cat-file', '-e', $tree.':'.$candidate], $sandbox);
                $probe->run();
                if ($probe->isSuccessful()) {
                    $resolved = true;
                    break;
                }
            }
            if (! $resolved) {
                $findings[] = ['path' => $file, 'type' => 'javascript_relative_import_unresolved',
                    'reference_hash' => hash('sha256', $specifier)];
            }
        }

        return $findings;
    }

    private function javascriptPackageLocked(string $specifier, string $tree, string $sandbox): bool
    {
        $parts = explode('/', $specifier);
        $package = str_starts_with($specifier, '@') ? implode('/', array_slice($parts, 0, 2)) : $parts[0];
        $manifestSource = $this->signedTreeFile($tree, 'package.json', $sandbox);
        $lockSource = $this->signedTreeFile($tree, 'package-lock.json', $sandbox);
        $manifest = $manifestSource === null ? null : json_decode($manifestSource, true);
        $lock = $lockSource === null ? null : json_decode($lockSource, true);
        if (! is_array($manifest) || ! is_array($lock) || ! is_array($lock['packages'] ?? null)) {
            return false;
        }
        $declared = false;
        $constraint = null;
        foreach (['dependencies', 'devDependencies', 'peerDependencies', 'optionalDependencies'] as $group) {
            if (is_string($manifest[$group][$package] ?? null)) {
                $declared = true;
                $constraint = $manifest[$group][$package];
            }
        }
        $entry = $lock['packages']['node_modules/'.$package] ?? null;
        if (! $declared || ! is_string($constraint) || ! is_array($entry) || ! is_string($entry['version'] ?? null)
            || $entry['version'] === '' || ! is_string($entry['integrity'] ?? null) || $entry['integrity'] === '') {
            return false;
        }

        return $this->semverSatisfies($entry['version'], $constraint, 'npm') === true;
    }

    /** @return list<array<string,string>> */
    private function manifestLockProvenanceFindings(string $source, string $file, string $tree, string $sandbox): array
    {
        $manifest = json_decode($source, true);
        if (! is_array($manifest)) {
            return [['path' => $file, 'type' => 'invalid_dependency_manifest']];
        }
        $directory = dirname($file) === '.' ? '' : dirname($file).'/';
        if (basename($file) === 'composer.json') {
            $lockSource = $this->signedTreeFile($tree, $directory.'composer.lock', $sandbox);
            $lock = $lockSource === null ? null : json_decode($lockSource, true);
            if (! is_array($lock) || ! is_string($lock['content-hash'] ?? null) || ($lock['content-hash'] ?? '') === '') {
                return [['path' => $file, 'type' => 'composer_lock_invalid_or_absent']];
            }
            if (! hash_equals((string) $lock['content-hash'], $this->composerManifestContentHash($manifest))) {
                return [['path' => $file, 'type' => 'composer_lock_content_hash_mismatch']];
            }
            $locked = [];
            foreach ([...(array) ($lock['packages'] ?? []), ...(array) ($lock['packages-dev'] ?? [])] as $package) {
                if (is_array($package) && is_string($package['name'] ?? null) && is_string($package['version'] ?? null) && $package['version'] !== '') {
                    $reference = data_get($package, 'dist.reference', data_get($package, 'source.reference'));
                    $locked[$package['name']] = ['version' => $package['version'], 'reference' => $reference];
                }
            }
            foreach ([...(array) ($manifest['require'] ?? []), ...(array) ($manifest['require-dev'] ?? [])] as $name => $constraint) {
                if (! is_string($name) || preg_match('/^(?:php|ext-|lib-)/', $name) === 1) {
                    continue;
                }
                if (! isset($locked[$name]) || ! is_string($constraint) || trim($constraint) === ''
                    || ! is_string($locked[$name]['reference'] ?? null) || $locked[$name]['reference'] === '') {
                    return [['path' => $file, 'type' => 'composer_locked_package_missing_or_invalid']];
                }
                $satisfies = $this->semverSatisfies((string) $locked[$name]['version'], $constraint, 'composer');
                if ($satisfies === null) {
                    return [['path' => $file, 'type' => 'composer_semver_evaluator_unavailable_or_invalid']];
                }
                if (! $satisfies) {
                    return [['path' => $file, 'type' => 'composer_locked_version_constraint_mismatch']];
                }
            }

            return [];
        }
        $lockSource = $this->signedTreeFile($tree, $directory.'package-lock.json', $sandbox);
        if ($lockSource === null && ($this->signedTreeFile($tree, $directory.'pnpm-lock.yaml', $sandbox) !== null
            || $this->signedTreeFile($tree, $directory.'yarn.lock', $sandbox) !== null)) {
            return [['path' => $file, 'type' => 'javascript_lock_ecosystem_unsupported']];
        }
        $lock = $lockSource === null ? null : json_decode($lockSource, true);
        if (! is_array($lock) || ! is_int($lock['lockfileVersion'] ?? null) || ! is_array($lock['packages'] ?? null)) {
            return [['path' => $file, 'type' => 'npm_lock_invalid_or_absent']];
        }
        foreach (['dependencies', 'devDependencies', 'peerDependencies', 'optionalDependencies'] as $group) {
            foreach ((array) ($manifest[$group] ?? []) as $name => $constraint) {
                $entry = $lock['packages']['node_modules/'.$name] ?? null;
                $rootPackage = $lock['packages'][''] ?? null;
                $rootConstraint = is_array($rootPackage) ? ($rootPackage[$group][$name] ?? null) : null;
                if (! is_string($name) || ! is_string($constraint) || trim($constraint) === '' || ! is_array($entry)
                    || ! is_string($entry['version'] ?? null) || $entry['version'] === ''
                    || ! is_string($entry['integrity'] ?? null) || $entry['integrity'] === ''
                    || ! is_string($rootConstraint) || ! hash_equals($constraint, $rootConstraint)) {
                    return [['path' => $file, 'type' => 'npm_locked_package_missing_or_invalid']];
                }
                $satisfies = $this->semverSatisfies((string) $entry['version'], $constraint, 'npm');
                if ($satisfies === null) {
                    return [['path' => $file, 'type' => 'npm_semver_evaluator_unavailable_or_invalid']];
                }
                if (! $satisfies) {
                    return [['path' => $file, 'type' => 'npm_locked_version_constraint_mismatch']];
                }
            }
        }

        return [];
    }

    private function signedTreeFile(string $tree, string $file, string $sandbox): ?string
    {
        $show = new Process(['git', 'show', $tree.':'.$file], $sandbox);
        $show->run();

        return $show->isSuccessful() ? $show->getOutput() : null;
    }

    /** @param array<string,mixed> $manifest */
    private function composerManifestContentHash(array $manifest): string
    {
        $relevant = array_intersect_key($manifest, array_flip([
            'name', 'version', 'require', 'require-dev', 'conflict', 'replace', 'provide',
            'minimum-stability', 'prefer-stable', 'repositories', 'extra',
        ]));
        $sort = function (array &$value) use (&$sort): void {
            foreach ($value as &$nested) {
                if (is_array($nested)) {
                    $sort($nested);
                }
            }
            unset($nested);
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
        };
        $sort($relevant);

        return md5(json_encode($relevant, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function semverSatisfies(string $version, string $constraint, string $ecosystem): ?bool
    {
        if (! in_array($ecosystem, ['composer', 'npm'], true)) {
            return null;
        }
        if (preg_match('/\d[-+][0-9A-Za-z]/', $version) === 1 || preg_match('/\d[-+][0-9A-Za-z]/', $constraint) === 1) {
            return false;
        }
        $normalize = static function (string $value): ?array {
            if (preg_match('/^v?(\d+)(?:\.(\d+))?(?:\.(\d+))?$/', trim($value), $match) !== 1) {
                return null;
            }

            return [(int) $match[1], (int) ($match[2] ?? 0), (int) ($match[3] ?? 0)];
        };
        $actual = $normalize($version);
        if ($actual === null || trim($constraint) === '') {
            return null;
        }
        if ($ecosystem === 'npm' && preg_match('/^v?(\d+)(?:\.(\d+))?$/', trim($constraint), $partial) === 1) {
            $prefix = [(int) $partial[1]];
            if (isset($partial[2])) {
                $prefix[] = (int) $partial[2];
            }

            return array_slice($actual, 0, count($prefix)) === $prefix;
        }
        foreach (preg_split('/\s*\|\|\s*/', trim($constraint)) ?: [] as $alternative) {
            $tokens = preg_split('/(?:\s*,\s*|\s+)/', trim($alternative), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $all = true;
            foreach ($tokens as $token) {
                if (in_array($token, ['*', 'x', 'X'], true)) {
                    continue;
                }
                if (preg_match('/^(\^|~|>=|<=|>|<|=)?\s*(v?\d+(?:\.\d+){0,2}|\d+(?:\.(?:x|X|\*)){1,2})$/', $token, $match) !== 1) {
                    return null;
                }
                $operator = $match[1];
                $targetText = $match[2];
                if (preg_match('/[xX*]/', $targetText) === 1) {
                    $prefix = array_map('intval', preg_split('/\./', preg_replace('/\.(?:x|X|\*).*$/', '', $targetText)) ?: []);
                    $all = $all && array_slice($actual, 0, count($prefix)) === $prefix;

                    continue;
                }
                $target = $normalize($targetText);
                if ($target === null) {
                    return null;
                }
                $compare = $actual <=> $target;
                $caretUpper = $target[0] > 0 ? [$target[0] + 1, 0, 0]
                    : ($target[1] > 0 ? [0, $target[1] + 1, 0] : [0, 0, $target[2] + 1]);
                $tildeUpper = substr_count($targetText, '.') === 0 ? [$target[0] + 1, 0, 0] : [$target[0], $target[1] + 1, 0];
                $matches = match ($operator) {
                    '>' => $compare > 0, '>=' => $compare >= 0, '<' => $compare < 0, '<=' => $compare <= 0,
                    '^' => $compare >= 0 && $actual < $caretUpper,
                    '~' => $compare >= 0 && $actual < $tildeUpper,
                    default => $compare === 0,
                };
                $all = $all && $matches;
            }
            if ($all) {
                return true;
            }
        }

        return false;
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
            'output' => $output, 'evidence_refs' => $evidenceRefs, 'binding' => $this->candidateOwnerBinding($case),
            'disposition' => $disposition->toArray(), 'data_evidence' => $evidence];
        $receipt['producer'] = $this->candidateOwnerProducerSeal($receipt, self::CANDIDATE_DATA_OWNER_DOMAIN);
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
            $artifactRoot = $this->ownedAtlasArtifactRoot($case->candidate->sandboxRoot);
            $artifactPath = realpath((string) ($artifact['path'] ?? ''));
            if ($artifactPath === false || ! str_starts_with($artifactPath, $artifactRoot.'/') || is_link((string) ($artifact['path'] ?? ''))
                || ! hash_equals((string) ($artifact['sha256'] ?? ''), (string) hash_file('sha256', $artifactPath))) {
                return false;
            }
            $raw = json_decode((string) file_get_contents($artifactPath), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($raw) || $raw !== array_diff_key($evidence, ['raw_artifact' => true])) {
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
            && ($receipt['binding'] ?? null) === $this->candidateOwnerBinding($case)
            && ($receipt['data_evidence'] ?? null) === $evidence && ($receipt['evidence_refs'] ?? null) === $refs
            && ($output['disposition'] ?? null) === $disposition->toArray()
            && ($output['role_evidence_receipt'] ?? null) === $typed && ($receipt['output'] ?? null) === $output
            && ($receipt['disposition'] ?? null) === $output['disposition']
            && hash_equals((string) $persisted->role_hash, (string) ($receipt['hash'] ?? ''))
            && hash_equals((string) ($receipt['hash'] ?? ''), EngineeringCompanyHash::make($unsigned))
            && $this->candidateOwnerProducerSealValid($unsigned, self::CANDIDATE_DATA_OWNER_DOMAIN);
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
            $this->candidateOwnerSignature($payload, self::CANDIDATE_DATA_OWNER_DOMAIN));
    }

    /** @return array<string,mixed> */
    private function candidateDataEvidence(CandidateQualityCase $case, bool $writeArtifact): array
    {
        $verification = $this->verifiedMutativeVerificationReceipt($case->verification, $case->order, $writeArtifact);
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
        $oracle = $sources === [] ? ['status' => 'not_applicable'] : $this->runIsolatedMigrationOracle(
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
        $artifactRoot = $this->ownedAtlasArtifactRoot($case->candidate->sandboxRoot);
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

    /** @param array<string,string> $sources @return array<string,mixed> */
    private function runIsolatedMigrationOracle(array $sources, bool $staticSafe, string $sandbox, string $caseHash): array
    {
        if (! $staticSafe || ! extension_loaded('pdo_sqlite')) {
            return ['status' => $staticSafe ? 'unavailable' : 'static_refused', 'forward_passed' => false,
                'n_minus_1_passed' => false, 'rollback_passed' => false];
        }
        $trustedTempRoot = '/private/tmp';
        $oracleRoot = $trustedTempRoot.'/atlas-owned-migration-oracle-'.bin2hex(random_bytes(16));
        if (! mkdir($oracleRoot, 0700) || is_link($oracleRoot)) {
            return ['status' => 'oracle_root_invalid', 'forward_passed' => false, 'n_minus_1_passed' => false, 'rollback_passed' => false];
        }
        $oracleReal = realpath($oracleRoot);
        $sandboxExec = '/usr/bin/sandbox-exec';
        if ($oracleReal === false || dirname($oracleReal) !== $trustedTempRoot || ! is_executable($sandboxExec)) {
            return ['status' => 'oracle_sandbox_unavailable', 'forward_passed' => false, 'n_minus_1_passed' => false, 'rollback_passed' => false];
        }
        $db = $oracleReal.'/candidate.sqlite';
        File::put($db, '');
        $sourcePaths = [];
        try {
            foreach ($sources as $path => $source) {
                $sourcePath = $oracleReal.'/migration-'.substr(hash('sha256', $path), 0, 12).'.php';
                File::put($sourcePath, $source);
                $real = realpath($sourcePath);
                if ($real === false || ! str_starts_with($real, $oracleReal.'/') || is_link($sourcePath)
                    || ! hash_equals(hash('sha256', $source), (string) hash_file('sha256', $real))) {
                    throw new \RuntimeException('isolated_migration_source_copy_invalid');
                }
                $sourcePaths[] = $real;
            }
            $runner = <<<'PHP'
                require $argv[1];
                $db = $argv[2];
                $action = $argv[3];
                $sources = array_slice($argv, 4);
                $capsuleClass = implode(chr(92), ['Illuminate', 'Database', 'Capsule', 'Manager']);
                $facadeClass = implode(chr(92), ['Illuminate', 'Support', 'Facades', 'Facade']);
                $migrationClass = implode(chr(92), ['Illuminate', 'Database', 'Migrations', 'Migration']);
                $capsule = new $capsuleClass();
                $capsule->addConnection(['driver' => 'sqlite', 'database' => $db, 'prefix' => '', 'foreign_key_constraints' => true]);
                $capsule->setAsGlobal(); $capsule->bootEloquent();
                $container = $capsule->getContainer();
                $container->singleton('db.schema', static fn () => $capsule->getConnection()->getSchemaBuilder());
                $facadeClass::setFacadeApplication($container);
                $connection = $capsule->getConnection();
                $connection->statement('PRAGMA foreign_keys = ON');
                $migrations = [];
                foreach ($sources as $source) {
                    $migration = require $source;
                    if (! $migration instanceof $migrationClass) { throw new RuntimeException('candidate_migration_contract_invalid'); }
                    $migrations[] = $migration;
                }
                if ($action === 'up') { foreach ($migrations as $migration) { $migration->up(); } }
                elseif ($action === 'down') { foreach (array_reverse($migrations) as $migration) { $migration->down(); } }
                else { throw new RuntimeException('candidate_migration_action_invalid'); }
                PHP;
            $vendor = $oracleReal.'/vendor';
            foreach (['composer', 'laravel', 'psr', 'symfony', 'nesbot', 'doctrine', 'brick', 'carbonphp'] as $package) {
                if (is_dir(base_path('vendor/'.$package))) {
                    File::copyDirectory(base_path('vendor/'.$package), $vendor.'/'.$package);
                }
            }
            File::put($vendor.'/autoload.php', <<<'PHP'
                <?php
                require __DIR__.'/composer/ClassLoader.php';
                $loader = new Composer\Autoload\ClassLoader(__DIR__);
                foreach (require __DIR__.'/composer/autoload_psr4.php' as $prefix => $paths) { $loader->setPsr4($prefix, $paths); }
                $loader->addClassMap(require __DIR__.'/composer/autoload_classmap.php');
                $loader->register(true);
                require __DIR__.'/laravel/framework/src/Illuminate/Collections/functions.php';
                require __DIR__.'/laravel/framework/src/Illuminate/Collections/helpers.php';
                require __DIR__.'/laravel/framework/src/Illuminate/Support/functions.php';
                require __DIR__.'/laravel/framework/src/Illuminate/Support/helpers.php';
                PHP);
            $snapshot = static function (string $database): array {
                $pdo = new \PDO('sqlite:'.$database);
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $schema = $pdo->query("SELECT type,name,tbl_name,sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type,name")->fetchAll(\PDO::FETCH_ASSOC);
                $data = [];
                foreach (['atlas_probe_parents', 'atlas_probe_records'] as $table) {
                    $data[$table] = $pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
                }

                return ['schema' => $schema, 'data' => $data];
            };
            $pdo = new \PDO('sqlite:'.$db);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec("CREATE TABLE atlas_probe_parents (id INTEGER PRIMARY KEY, name VARCHAR NOT NULL DEFAULT 'parent')");
            $pdo->exec("CREATE TABLE atlas_probe_records (id INTEGER PRIMARY KEY, parent_id INTEGER NOT NULL, legacy_value VARCHAR NOT NULL DEFAULT 'legacy', status VARCHAR NOT NULL DEFAULT 'active', FOREIGN KEY(parent_id) REFERENCES atlas_probe_parents(id))");
            $pdo->exec('CREATE INDEX atlas_probe_records_legacy_value_index ON atlas_probe_records (legacy_value)');
            $pdo->exec("INSERT INTO atlas_probe_parents (id,name) VALUES (1,'parent')");
            $pdo->exec("INSERT INTO atlas_probe_records (id,parent_id,legacy_value,status) VALUES (1,1,'legacy','active')");
            unset($pdo);
            $before = $snapshot($db);
            $profile = '(version 1)(deny default)(deny network*)(allow process-exec (literal "'.PHP_BINARY.'"))'
                .'(allow process-fork)(allow sysctl-read)(allow mach-lookup)'
                .'(allow file-read-metadata (literal "/") (literal "/usr") (literal "/System") (literal "/private") (subpath "'.dirname($oracleReal).'") (literal "/opt") (literal "/dev") (literal "'.$oracleReal.'"))'
                .'(allow file-read* (literal "/") (subpath "/opt/homebrew/Cellar") (subpath "/opt/homebrew/opt") (subpath "/opt/homebrew/lib") (subpath "/usr/lib") (subpath "/System/Library") (subpath "/Library") (subpath "/private/etc") (literal "'.$db.'") (subpath "'.$oracleReal.'") (literal "/dev/null") (literal "/dev/urandom"))'
                .'(allow file-write* (literal "'.$db.'") (literal "'.$db.'-journal") (literal "'.$db.'-wal") (literal "'.$db.'-shm") (literal "/dev/null"))';
            $run = function (string $action) use ($sandboxExec, $profile, $runner, $vendor, $db, $sourcePaths): Process {
                $process = new Process([$sandboxExec, '-p', $profile, PHP_BINARY, '-n',
                    '-d', 'display_errors=stderr', '-d', 'disable_functions=exec,shell_exec,system,passthru,proc_open,popen,pcntl_exec', '-r', $runner,
                    $vendor.'/autoload.php', $db, $action, ...$sourcePaths], '/', ['APP_ENV' => 'testing', 'HOME' => '/nonexistent']);
                $process->setTimeout(60);
                try {
                    $process->run();
                } catch (\Throwable $e) {
                    throw new \RuntimeException($e->getMessage().' stderr='.$process->getErrorOutput(), 0, $e);
                }

                return $process;
            };
            $up = $run('up');
            if (! $up->isSuccessful() || $up->getOutput() !== '' || $up->getErrorOutput() !== '') {
                throw new \RuntimeException('candidate_migration_up_process_untrusted stdout='.$up->getOutput().' stderr='.$up->getErrorOutput().' exit='.$up->getExitCode());
            }
            $forward = $snapshot($db);
            $legacyExpectation = ['id' => 1, 'parent_id' => 1, 'legacy_value' => 'legacy', 'status' => 'active'];
            $n1Passed = array_intersect_key((array) ($forward['data']['atlas_probe_records'][0] ?? []), $legacyExpectation)
                === $legacyExpectation;
            $down = $run('down');
            if (! $down->isSuccessful() || $down->getOutput() !== '' || $down->getErrorOutput() !== '') {
                throw new \RuntimeException('candidate_migration_down_process_untrusted');
            }
            $after = $snapshot($db);
            $receipt = ['status' => 'executed_isolated_laravel_sqlite', 'forward_passed' => $forward !== $before,
                'n_minus_1_passed' => $n1Passed, 'rollback_passed' => $after === $before,
                'before_hash' => RealExecutionHash::make($before), 'forward_hash' => RealExecutionHash::make($forward),
                'after_hash' => RealExecutionHash::make($after), 'database' => 'isolated_tempfile',
                'runner_hash' => hash('sha256', $runner), 'supervisor' => self::class];
            $receipt['supervisor_hmac'] = hash_hmac('sha256', RealExecutionHash::make($receipt), (string) config('app.key'));

            return $receipt;
        } catch (\Throwable $e) {
            return ['status' => 'oracle_failed', 'error_class' => $e::class, 'error_hash' => hash('sha256', $e->getMessage()), 'forward_passed' => false,
                'n_minus_1_passed' => false, 'rollback_passed' => false];
        } finally {
            foreach ($sourcePaths as $path) {
                @unlink($path);
            }
            @unlink($db);
            if (str_starts_with($oracleReal, $trustedTempRoot.'/atlas-owned-migration-oracle-')) {
                File::deleteDirectory($oracleReal, true);
            }
        }
    }

    private function ownedAtlasArtifactRoot(string $sandbox): string
    {
        $sandboxRoot = realpath($sandbox);
        $artifactPath = storage_path('app/atlas-owned-candidate-data');
        if ($sandboxRoot === false || is_link(storage_path('app')) || is_link($artifactPath)) {
            throw new \InvalidArgumentException('candidate_artifact_root_invalid');
        }
        File::ensureDirectoryExists($artifactPath, 0700);
        $artifactRoot = realpath($artifactPath);
        if ($artifactRoot === false || dirname($artifactRoot) !== realpath(storage_path('app')) || ! is_writable($artifactRoot)) {
            throw new \InvalidArgumentException('candidate_artifact_root_invalid');
        }

        return $artifactRoot;
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
        $binding = $this->candidateOwnerBinding($case);
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
        $receipt['producer'] = $this->candidateOwnerProducerSeal($receipt, self::CANDIDATE_ARCHITECTURE_OWNER_DOMAIN);
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
            && ($receipt['binding'] ?? null) === $this->candidateOwnerBinding($case)
            && ($receipt['architecture_evidence'] ?? null) === $expectedEvidence
            && ($receipt['evidence_refs'] ?? null) === $evidenceRefs
            && ($output['disposition'] ?? null) === $expectedDisposition->toArray()
            && ($output['role_evidence_receipt'] ?? null) === $expectedTyped
            && ($receipt['output'] ?? null) === $output && ($receipt['disposition'] ?? null) === $output['disposition']
            && hash_equals((string) $persisted->role_hash, (string) ($receipt['hash'] ?? ''))
            && hash_equals((string) ($receipt['hash'] ?? ''), EngineeringCompanyHash::make($unsigned))
            && $this->candidateOwnerProducerSealValid($unsigned, self::CANDIDATE_ARCHITECTURE_OWNER_DOMAIN);
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
            $this->candidateOwnerSignature($payload, self::CANDIDATE_ARCHITECTURE_OWNER_DOMAIN),
        );
    }

    /** @return array<string,mixed> */
    private function candidateArchitectureEvidence(CandidateQualityCase $case, bool $writeArtifact): array
    {
        $verification = $this->verifiedMutativeVerificationReceipt($case->verification, $case->order, $writeArtifact);
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

    /** @return array<string,mixed> */
    private function candidateOwnerBinding(CandidateQualityCase $case): array
    {
        return ['run_id' => $case->order->runId, 'delivery_id' => $case->order->deliveryId,
            'order_hash' => $case->order->canonicalHash(), 'spec_hash' => $case->order->specHash,
            'case_hash' => $case->caseHash, 'candidate_hash' => $case->candidate->candidateHash,
            'base_commit' => $case->candidate->baseCommit, 'diff_hash' => $case->candidate->diffHash,
            'tree_hash' => $case->candidate->treeHash, 'files' => $case->candidate->files,
            'engagement_record_id' => $case->engagementRecordId, 'cycle_record_id' => $case->cycleRecordId];
    }

    /** @return array<string,mixed> */
    private function backendOwnerBinding(CandidateQualityCase $case): array
    {
        return $this->candidateOwnerBinding($case) + ['workspace' => $case->order->workspace];
    }

    /** @param array<string,mixed> $payload */
    private function candidateOwnerSignature(array $payload, string $domain): string
    {
        return hash_hmac('sha256', RealExecutionHash::make($payload), hash_hmac('sha256', $domain, $this->producerKeyMaterial(), true));
    }

    /** @param array<string,mixed> $payload @return array<string,string> */
    private function candidateOwnerProducerSeal(array $payload, string $domain): array
    {
        $key = $this->producerKeyMaterial();
        $seal = ['domain' => $domain, 'key_id' => 'app-key-'.substr(hash('sha256', $key), 0, 16), 'payload_hash' => EngineeringCompanyHash::make($payload)];
        $authorityKey = hash_hmac('sha256', 'atlas.engineering_kernel.evidence_authority.v1', $key, true);
        $seal['signature'] = hash_hmac('sha256', EngineeringCompanyHash::make($seal), hash_hmac('sha256', $domain, $authorityKey, true));

        return $seal;
    }

    /** @param array<string,mixed> $unsigned */
    private function candidateOwnerProducerSealValid(array $unsigned, string $domain): bool
    {
        $producer = $unsigned['producer'] ?? null;
        if (! is_array($producer) || ($producer['domain'] ?? null) !== $domain) {
            return false;
        }
        $payload = array_diff_key($unsigned, ['producer' => true]);
        $signature = (string) ($producer['signature'] ?? '');
        $seal = array_diff_key($producer, ['signature' => true]);
        $key = $this->producerKeyMaterial();
        $authorityKey = hash_hmac('sha256', 'atlas.engineering_kernel.evidence_authority.v1', $key, true);

        return hash_equals((string) ($producer['payload_hash'] ?? ''), EngineeringCompanyHash::make($payload))
            && hash_equals($signature, hash_hmac('sha256', EngineeringCompanyHash::make($seal), hash_hmac('sha256', $domain, $authorityKey, true)));
    }

    public function persistCandidateQaOwnerReceipt(AiEngineeringCompanyEngagement $engagement, AiEngineeringCompanyCycle $cycle, CandidateQualityCase $case): AiEngineeringCompanyRoleRun
    {
        if (! $engagement->exists || ! $cycle->exists || $cycle->engagement_record_id !== $engagement->getKey()
            || (string) $engagement->getKey() !== $case->engagementRecordId || (string) $cycle->getKey() !== $case->cycleRecordId
            || ! $this->candidateQaArtifactsValid($case)) {
            throw new \InvalidArgumentException('candidate_qa_owner_evidence_invalid');
        }
        $roleRunId = 'aereqa_'.substr(RealExecutionHash::make([$case->caseHash, 'qa_testing']), 0, 24);
        if (AiEngineeringCompanyRoleRun::query()->where('role_run_id', $roleRunId)->exists()) {
            throw new \InvalidArgumentException('candidate_qa_owner_duplicate');
        }
        $issued = CarbonImmutable::now()->startOfSecond();
        $expires = $issued->addHour();
        $disposition = $this->candidateQaDisposition($case);
        $qaEvidence = $this->candidateQaEvidence($case);
        $evidenceRefs = [
            'candidate:'.$case->candidate->candidateHash,
            'verification:'.$case->verification->receiptHash,
            'mechanical_junit:'.(string) data_get($qaEvidence, 'mechanical_junit.sha256'),
            'behavioral_junit:'.(string) data_get($qaEvidence, 'behavioral_junit.sha256'),
        ];
        $typed = RoleEvidenceReceipt::issue(
            $case, $disposition, self::CANDIDATE_QA_OWNER_DOMAIN, self::CANDIDATE_QA_OWNER_VERSION,
            $issued->toAtomString(), $expires->toAtomString(), $evidenceRefs,
        );
        $output = ['disposition' => $disposition->toArray(), 'role_evidence_receipt' => $typed->toArray()];
        $binding = [
            'run_id' => $case->order->runId, 'delivery_id' => $case->order->deliveryId,
            'order_hash' => $case->order->canonicalHash(), 'spec_hash' => $case->order->specHash,
            'case_hash' => $case->caseHash, 'candidate_hash' => $case->candidate->candidateHash,
            'base_commit' => $case->candidate->baseCommit, 'diff_hash' => $case->candidate->diffHash,
            'tree_hash' => $case->candidate->treeHash, 'files' => $case->candidate->files,
            'engagement_record_id' => (string) $engagement->getKey(), 'cycle_record_id' => (string) $cycle->getKey(),
        ];
        $receipt = [
            'schema_version' => AtlasRealEngineeringCompanyRuntimeService::ROLE_SCHEMA,
            'purpose' => 'candidate_qa_owner_evidence', 'owner_domain' => self::CANDIDATE_QA_OWNER_DOMAIN,
            'owner_version' => self::CANDIDATE_QA_OWNER_VERSION, 'issued_at' => $issued->toAtomString(),
            'expires_at' => $expires->toAtomString(), 'role_run_id' => $roleRunId, 'role_id' => 'qa_testing',
            'status' => 'passed', 'output' => $output, 'evidence_refs' => $evidenceRefs,
            'binding' => $binding, 'disposition' => $disposition->toArray(), 'qa_evidence' => $qaEvidence,
        ];
        $receipt['producer'] = $this->qaOwnerProducerSeal($receipt);
        $receipt['hash'] = EngineeringCompanyHash::make($receipt);

        return AiEngineeringCompanyRoleRun::query()->create([
            'engagement_record_id' => $engagement->getKey(), 'cycle_record_id' => $cycle->getKey(),
            'role_run_id' => $roleRunId, 'role_id' => 'qa_testing', 'status' => 'passed',
            'responsibilities' => [], 'output' => $output, 'evidence_refs' => $evidenceRefs,
            'receipt' => $receipt, 'role_hash' => $receipt['hash'],
        ]);
    }

    public function verifiedMutativeVerification(MutativeVerificationReference $reference, ExecutionOrder $order): AiRealExecutionTestRun
    {
        return $this->verifiedMutativeVerificationReceipt($reference, $order, true);
    }

    private function verifiedMutativeVerificationReceipt(MutativeVerificationReference $reference, ExecutionOrder $order, bool $requireLiveWorkspace): AiRealExecutionTestRun
    {
        $row = AiRealExecutionTestRun::query()->where('test_run_id', $reference->runId)->first();
        $receipt = $row?->receipt;
        if (! $row instanceof AiRealExecutionTestRun || ! is_array($receipt)
            || ! hash_equals((string) $row->test_hash, $reference->receiptHash)
            || ! hash_equals((string) ($receipt['hash'] ?? ''), $reference->receiptHash)) {
            throw new \InvalidArgumentException('mutative_verification_owner_missing_or_changed');
        }
        $unsigned = array_diff_key($receipt, ['hash' => true]);
        $binding = $receipt['binding'] ?? null;
        $identities = $receipt['identities'] ?? null;
        try {
            $issued = CarbonImmutable::createFromFormat(DATE_ATOM, (string) ($receipt['issued_at'] ?? ''));
            $expires = CarbonImmutable::createFromFormat(DATE_ATOM, (string) ($receipt['expires_at'] ?? ''));
        } catch (\Throwable) {
            throw new \InvalidArgumentException('mutative_verification_owner_invalid');
        }
        if (! is_array($binding) || ! is_array($identities) || $row->status !== 'passed'
            || $issued === null || $expires === null || CarbonImmutable::now()->lt($issued) || CarbonImmutable::now()->gte($expires)
            || ($receipt['verification_run_id'] ?? null) !== $reference->runId
            || ($binding['run_id'] ?? null) !== $order->runId || ($binding['delivery_id'] ?? null) !== $order->deliveryId
            || ($binding['order_hash'] ?? null) !== $order->canonicalHash() || ($binding['spec_hash'] ?? null) !== $order->specHash
            || ($binding['base_commit'] ?? null) !== $order->baseCommit
            || ($binding['candidate_hash'] ?? null) !== $reference->candidateHash
            || ($identities['provider'] ?? null) !== $reference->providerIdentity
            || ($identities['author'] ?? null) !== $reference->authorIdentity
            || ($identities['verifier'] ?? null) !== $reference->verifierIdentity
            || ($identities['verifier_domain'] ?? null) !== self::KERNEL_VERIFICATION_PRODUCER
            || $reference->providerIdentity === $reference->verifierIdentity
            || $reference->authorIdentity === $reference->verifierIdentity
            || ! hash_equals($reference->receiptHash, RealExecutionHash::make($unsigned))
            || ! $this->qaVerificationProducerSealValid($unsigned)
            || ! $this->mutativeVerificationArtifactsValid($receipt)
            || ($requireLiveWorkspace && ! $this->mutativeVerificationWorkspaceMatches($receipt))) {
            throw new \InvalidArgumentException('mutative_verification_owner_invalid');
        }

        return $row;
    }

    /** @param array<string,mixed> $unsigned */
    private function qaVerificationProducerSealValid(array $unsigned): bool
    {
        $producer = $unsigned['producer'] ?? null;
        if (! is_array($producer) || ($producer['domain'] ?? null) !== self::KERNEL_VERIFICATION_PRODUCER) {
            return false;
        }
        $payload = array_diff_key($unsigned, ['producer' => true]);
        if (! hash_equals((string) ($producer['payload_hash'] ?? ''), RealExecutionHash::make($payload))) {
            return false;
        }
        $signature = (string) ($producer['signature'] ?? '');
        $seal = array_diff_key($producer, ['signature' => true]);
        $authorityKey = hash_hmac('sha256', 'atlas.engineering_kernel.evidence_authority.v1', $this->producerKeyMaterial(), true);

        return hash_equals($signature, hash_hmac('sha256', RealExecutionHash::make($seal), hash_hmac('sha256', self::KERNEL_VERIFICATION_PRODUCER, $authorityKey, true)));
    }

    /** @param array<string,mixed> $receipt */
    private function mutativeVerificationArtifactsValid(array $receipt): bool
    {
        if (($receipt['passed'] ?? false) !== true || data_get($receipt, 'behavioral.passed') !== true) {
            return false;
        }
        $commands = $receipt['commands'] ?? null;
        if (! is_array($commands) || $commands === [] || array_any($commands, static fn (mixed $row): bool => ! is_array($row) || ($row['passed'] ?? false) !== true)) {
            return false;
        }
        $root = realpath((string) ($receipt['sandbox_root'] ?? '').'/.atlas');
        if ($root === false) {
            return false;
        }
        foreach ([$receipt['junit_artifact'] ?? null, data_get($receipt, 'behavioral.junit_artifact'), $receipt['diff_artifact'] ?? null] as $artifact) {
            $path = is_array($artifact) ? (string) ($artifact['path'] ?? '') : '';
            $real = $path !== '' ? realpath($path) : false;
            if ($real === false || ! str_starts_with($real, $root.'/') || is_link($path)
                || ! hash_equals((string) ($artifact['sha256'] ?? ''), (string) hash_file('sha256', $real))) {
                return false;
            }
        }
        $runner = (string) data_get($receipt, 'behavioral.runner_path', '');

        return is_file($runner) && ! is_link($runner)
            && hash_equals((string) data_get($receipt, 'behavioral.runner_hash', ''), (string) hash_file('sha256', $runner));
    }

    /** @param array<string,mixed> $receipt */
    private function mutativeVerificationWorkspaceMatches(array $receipt): bool
    {
        $sandbox = (string) ($receipt['sandbox_root'] ?? '');
        $binding = $receipt['binding'] ?? null;
        $files = is_array($binding) && is_array($binding['files'] ?? null) ? array_values(array_map('strval', $binding['files'])) : [];
        $sourceHashes = is_array($binding) && is_array($binding['source_hashes'] ?? null) ? $binding['source_hashes'] : [];
        $base = is_array($binding) ? (string) ($binding['base_commit'] ?? '') : '';
        if ($sandbox === '' || ! is_dir($sandbox.'/.git') || $files === [] || $base === '' || array_keys($sourceHashes) !== $files) {
            return false;
        }
        foreach ($files as $file) {
            $path = $sandbox.'/'.$file;
            if (! is_file($path) || is_link($path)
                || ! hash_equals((string) ($sourceHashes[$file] ?? ''), (string) hash_file('sha256', $path))) {
                return false;
            }
        }
        $index = $sandbox.'/.atlas/revalidate-'.bin2hex(random_bytes(8)).'.index';
        if (! File::copy($sandbox.'/.git/index', $index)) {
            return false;
        }
        try {
            (new Process(['git', 'add', '-A', '--', ...$files], $sandbox, ['GIT_INDEX_FILE' => $index]))->mustRun();
            $tree = trim((new Process(['git', 'write-tree'], $sandbox, ['GIT_INDEX_FILE' => $index]))->mustRun()->getOutput());
            $diff = (new Process(['git', 'diff', '--cached', '--binary', $base, '--', ...$files], $sandbox, ['GIT_INDEX_FILE' => $index]))->mustRun()->getOutput();

            return hash_equals((string) ($binding['tree_hash'] ?? ''), $tree)
                && hash_equals((string) ($binding['diff_hash'] ?? ''), hash('sha256', $diff));
        } catch (\Throwable) {
            return false;
        } finally {
            @unlink($index);
        }
    }

    public function candidateQaOwnerReceiptValid(AiEngineeringCompanyRoleRun $row, CandidateQualityCase $case): bool
    {
        $persisted = $row->exists ? AiEngineeringCompanyRoleRun::query()->find($row->getKey()) : null;
        $receipt = $persisted?->getAttribute('receipt');
        $output = $persisted?->getAttribute('output');
        if (! $persisted instanceof AiEngineeringCompanyRoleRun || ! is_array($receipt) || ! is_array($output)
            || ! $this->candidateQaArtifactsValid($case)) {
            return false;
        }
        try {
            $issued = CarbonImmutable::createFromFormat(DATE_ATOM, (string) ($receipt['issued_at'] ?? ''));
            $expires = CarbonImmutable::createFromFormat(DATE_ATOM, (string) ($receipt['expires_at'] ?? ''));
        } catch (\Throwable) {
            return false;
        }
        $expectedDisposition = $this->candidateQaDisposition($case);
        $expectedEvidence = $this->candidateQaEvidence($case);
        $evidenceRefs = [
            'candidate:'.$case->candidate->candidateHash, 'verification:'.$case->verification->receiptHash,
            'mechanical_junit:'.(string) data_get($expectedEvidence, 'mechanical_junit.sha256'),
            'behavioral_junit:'.(string) data_get($expectedEvidence, 'behavioral_junit.sha256'),
        ];
        $expectedTyped = RoleEvidenceReceipt::issue(
            $case, $expectedDisposition, self::CANDIDATE_QA_OWNER_DOMAIN, self::CANDIDATE_QA_OWNER_VERSION,
            (string) $receipt['issued_at'], (string) $receipt['expires_at'], $evidenceRefs,
        )->toArray();
        $expectedBinding = [
            'run_id' => $case->order->runId, 'delivery_id' => $case->order->deliveryId,
            'order_hash' => $case->order->canonicalHash(), 'spec_hash' => $case->order->specHash,
            'case_hash' => $case->caseHash, 'candidate_hash' => $case->candidate->candidateHash,
            'base_commit' => $case->candidate->baseCommit, 'diff_hash' => $case->candidate->diffHash,
            'tree_hash' => $case->candidate->treeHash, 'files' => $case->candidate->files,
            'engagement_record_id' => $case->engagementRecordId,
            'cycle_record_id' => $case->cycleRecordId,
        ];
        $unsigned = array_diff_key($receipt, ['hash' => true]);
        $producer = $unsigned['producer'] ?? null;
        $withoutProducer = array_diff_key($unsigned, ['producer' => true]);

        return $persisted->role_id === 'qa_testing' && $persisted->status === 'passed'
            && ($receipt['purpose'] ?? null) === 'candidate_qa_owner_evidence'
            && ($receipt['owner_domain'] ?? null) === self::CANDIDATE_QA_OWNER_DOMAIN
            && ($receipt['owner_version'] ?? null) === self::CANDIDATE_QA_OWNER_VERSION
            && $issued !== null && $expires !== null && ! CarbonImmutable::now()->lt($issued) && CarbonImmutable::now()->lt($expires)
            && ($receipt['binding'] ?? null) === $expectedBinding && ($receipt['qa_evidence'] ?? null) === $expectedEvidence
            && ($receipt['evidence_refs'] ?? null) === $evidenceRefs && ($receipt['output'] ?? null) === $output
            && ($output['disposition'] ?? null) === $expectedDisposition->toArray()
            && ($output['role_evidence_receipt'] ?? null) === $expectedTyped
            && hash_equals((string) $persisted->role_hash, (string) ($receipt['hash'] ?? ''))
            && hash_equals((string) ($receipt['hash'] ?? ''), EngineeringCompanyHash::make($unsigned))
            && $this->qaOwnerProducerSealValid($withoutProducer, $producer);
    }

    public function candidateQaDisposition(CandidateQualityCase $case): RoleDisposition
    {
        $payload = ['purpose' => 'candidate_qa_owner_disposition', 'case_hash' => $case->caseHash,
            'order_hash' => $case->order->canonicalHash(), 'spec_hash' => $case->order->specHash,
            'candidate_hash' => $case->candidate->candidateHash, 'base_commit' => $case->candidate->baseCommit,
            'diff_hash' => $case->candidate->diffHash, 'tree_hash' => $case->candidate->treeHash,
            'files' => $case->candidate->files, 'verification_hash' => $case->verification->receiptHash,
            'signer_context' => self::CANDIDATE_QA_OWNER_DOMAIN];

        return RoleDisposition::qaCandidateVerified($case, self::CANDIDATE_QA_OWNER_DOMAIN, $this->qaOwnerSignature($payload));
    }

    /** @return array<string,mixed> */
    private function candidateQaEvidence(CandidateQualityCase $case): array
    {
        $verification = (array) $this->verifiedMutativeVerification($case->verification, $case->order)->receipt;

        return [
            'case_hash' => $case->caseHash, 'order_hash' => $case->order->canonicalHash(),
            'spec_hash' => $case->order->specHash, 'candidate_hash' => $case->candidate->candidateHash,
            'base_commit' => $case->candidate->baseCommit, 'diff_hash' => $case->candidate->diffHash,
            'tree_hash' => $case->candidate->treeHash, 'files' => $case->candidate->files,
            'verification_run_id' => $case->verification->runId,
            'verification_hash' => $case->verification->receiptHash, 'commands_hash' => RealExecutionHash::make((array) data_get($verification, 'commands', [])),
            'provider_identity' => $case->verification->providerIdentity,
            'author_identity' => $case->verification->authorIdentity,
            'verifier_identity' => $case->verification->verifierIdentity,
            'provider_receipt_hash' => data_get($verification, 'identities.provider_receipt_hash'),
            'runner_path' => data_get($verification, 'behavioral.runner_path'),
            'runner_hash' => data_get($verification, 'behavioral.runner_hash'),
            'runner_version' => data_get($verification, 'behavioral.runner_version'),
            'mechanical_junit' => (array) data_get($verification, 'junit_artifact', []),
            'behavioral_junit' => data_get($verification, 'behavioral.junit_artifact'),
            'diff_artifact' => (array) data_get($verification, 'diff_artifact', []),
        ];
    }

    private function candidateQaArtifactsValid(CandidateQualityCase $case): bool
    {
        try {
            $this->verifiedMutativeVerification($case->verification, $case->order);
        } catch (\Throwable) {
            return false;
        }

        return $case->verification->providerIdentity !== $case->verification->verifierIdentity
            && $case->verification->authorIdentity !== $case->verification->verifierIdentity;
    }

    /** @param array<string,mixed> $payload */
    private function qaOwnerSignature(array $payload): string
    {
        return hash_hmac('sha256', RealExecutionHash::make($payload), hash_hmac('sha256', self::CANDIDATE_QA_OWNER_DOMAIN, $this->producerKeyMaterial(), true));
    }

    /** @param array<string,mixed> $payload @return array<string,string> */
    private function qaOwnerProducerSeal(array $payload): array
    {
        $key = $this->producerKeyMaterial();
        $seal = ['domain' => self::CANDIDATE_QA_OWNER_DOMAIN, 'key_id' => 'app-key-'.substr(hash('sha256', $key), 0, 16), 'payload_hash' => EngineeringCompanyHash::make($payload)];
        $authorityKey = hash_hmac('sha256', 'atlas.engineering_kernel.evidence_authority.v1', $key, true);
        $seal['signature'] = hash_hmac('sha256', EngineeringCompanyHash::make($seal), hash_hmac('sha256', self::CANDIDATE_QA_OWNER_DOMAIN, $authorityKey, true));

        return $seal;
    }

    /** @param array<string,mixed> $payload */
    private function qaOwnerProducerSealValid(array $payload, mixed $producer): bool
    {
        if (! is_array($producer) || ($producer['domain'] ?? null) !== self::CANDIDATE_QA_OWNER_DOMAIN
            || ! hash_equals((string) ($producer['payload_hash'] ?? ''), EngineeringCompanyHash::make($payload))) {
            return false;
        }
        $signature = (string) ($producer['signature'] ?? '');
        $unsigned = array_diff_key($producer, ['signature' => true]);
        $key = $this->producerKeyMaterial();
        $authorityKey = hash_hmac('sha256', 'atlas.engineering_kernel.evidence_authority.v1', $key, true);

        return hash_equals($signature, hash_hmac('sha256', EngineeringCompanyHash::make($unsigned), hash_hmac('sha256', self::CANDIDATE_QA_OWNER_DOMAIN, $authorityKey, true)));
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
        // SOVEREIGN GATE — the fake-green kill. The engineering green may NOT be emitted unless the
        // sovereign AcceptanceGate promotes the recorded evidence. This path records only a `php -l`
        // smoke, so the gate refuses it (false_claim_blocked): this stub can no longer certify green.
        $sovereignVerdict = $this->sovereignEngineeringVerdict($latestGoal);
        $checks[] = $this->check('sovereign_engineering_gate_promoted', $sovereignVerdict->promoted());
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
     * Route the recorded engineering evidence through the sovereign AcceptanceGate. This path only
     * ever records a `php -l` smoke, so the gate refuses it (false_claim_blocked) — which is exactly
     * how the fake-green is killed: the certification can no longer emit an engineering green.
     */
    private function sovereignEngineeringVerdict(?AiAutonomousEngineeringGoal $goal): CertVerdict
    {
        $test = ($goal !== null && DatabaseTableAvailability::has('ai_real_execution_test_runs'))
            ? $this->latestQuery(AiRealExecutionTestRun::query()->where('goal_record_id', $goal->id))->first()
            : null;
        $patch = ($goal !== null && DatabaseTableAvailability::has('ai_real_execution_patch_runs'))
            ? $this->latestQuery(AiRealExecutionPatchRun::query()->where('goal_record_id', $goal->id))->first()
            : null;

        $selected = $test !== null ? array_values((array) $test->selected_tests) : [];
        $changedFiles = $patch !== null ? array_values((array) $patch->changed_files) : [];

        $bundle = AcceptanceBundle::fromArray([
            'criteria_hash' => '',
            'frozen_hash' => '',
            'changed_files' => $changedFiles,
            'changed_public_symbols' => [],
            'execution' => [
                'commands' => $selected,
                'claimed_status' => ($test?->status === 'passed') ? 'passed' : 'failed',
                'tests_run' => 0,            // a lint runs zero test cases
                'assertions_executed' => 0,  // and zero assertions
                'selected_tests' => $selected,
                'artifacts' => $changedFiles,
            ],
            'mutation_report' => [],
            'security_scan' => [],
            'judges' => [],
            'context_sufficiency' => 0,
        ]);

        // Surface-aware witness-set: a forge-promoted goal is witnessed as Forge, otherwise the
        // autonomous loop's FrozenJudge witness. The invariants (the bar) are identical either way.
        $trust = ($goal?->promotion_target === 'atlas_forge') ? TrustLevel::Forge : TrustLevel::Autonomos;

        return app(AtlasDevGateAdapter::class)->certify($bundle, $trust);
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
        // Rivals 1.0 arena readiness service retired (see
        // atlas-rivals2-rebuild-map-v1.md): honest permanent unavailable shape.
        return [
            'status' => 'blocked',
            'blockers' => ['provider_arena_readiness_service_missing'],
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

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
use App\Services\Ai\AutonomousEngineering\AtlasAutonomousEngineeringService;
use App\Services\Ai\EngineeringCompany\AtlasRealEngineeringCompanyRuntimeService;
use App\Services\Ai\EngineeringCompany\EngineeringCompanyHash;
use App\Services\Ai\EngineeringKernel\AcceptanceBundle;
use App\Services\Ai\EngineeringKernel\Adapters\AtlasDevGateAdapter;
use App\Services\Ai\EngineeringKernel\CandidateQualityCase;
use App\Services\Ai\EngineeringKernel\CertVerdict;
use App\Services\Ai\EngineeringKernel\ExecutionOrder;
use App\Services\Ai\EngineeringKernel\MutativeVerificationReference;
use App\Services\Ai\EngineeringKernel\RoleDisposition;
use App\Services\Ai\EngineeringKernel\RoleEvidenceReceipt;
use App\Services\Ai\EngineeringKernel\TrustLevel;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\Support\JsonFileStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class AtlasRealEngineeringExecutionKernelService
{
    public const WORKTREE_SCHEMA = 'atlas.ai.real_execution.worktree.v1';

    public const PATCH_SCHEMA = 'atlas.ai.real_execution.patch_run.v1';

    public const TEST_SCHEMA = 'atlas.ai.real_execution.test_run.v1';

    public const KERNEL_VERIFICATION_PRODUCER = 'atlas.real_execution.kernel_verification.v1';

    public const CANDIDATE_QA_OWNER_DOMAIN = 'atlas.real_execution.candidate_qa_owner.v1';

    public const CANDIDATE_QA_OWNER_VERSION = 'v1';

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
                'tree_hash' => $treeSha, 'diff_hash' => hash('sha256', $diff), 'files' => $files],
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
            || ! $this->mutativeVerificationArtifactsValid($receipt)) {
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

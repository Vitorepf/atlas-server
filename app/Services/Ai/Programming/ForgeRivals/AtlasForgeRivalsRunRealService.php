<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\WorkspaceHygieneService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Atlas Forge Rivals · Run Real (orchestrator).
 *
 * Owns the only path through which a real provider can be invoked. Every
 * gate is non-negotiable:
 *
 *   1. Mode must be one of {fair, full_power, local_fake}. Diagnostic /
 *      replay_only are rejected — those don't ever run providers.
 *   2. Workspace must be a worktree under runs/<run_id>/{atlas,rival},
 *      provisioned via Setup. If absent, the run is blocked with
 *      `worktrees_missing` and a copy-safe setup hint.
 *   3. Real-provider modes (fair, full_power) REQUIRE all three
 *      `--confirm-*` flags simultaneously. Without them the run is
 *      blocked with `missing_confirmations` and NO provider is invoked.
 *   4. `local_fake` mode is in-process: it emits the canonical events
 *      and writes a deterministic fake provider receipt, but never
 *      executes a subprocess. The full chain stays exercisable in CI.
 *   5. Every subprocess inherits PYTHONDONTWRITEBYTECODE=1.
 *   6. Workspace hash/diff/test logs are captured before/after. Expected
 *      scoped source changes are evidence, not failure; untracked bytecode or
 *      out-of-scope changes stay terminal blockers.
 *   7. Output is streamed to events.jsonl with heartbeat-eligible
 *      stream_select(); on timeout we kill the subprocess and close
 *      evidence as `invalid_provider_timeout`.
 *
 * Schema: atlas.forge.rivals.run_real.v1
 */
final class AtlasForgeRivalsRunRealService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.run_real.v1';

    public const HEARTBEAT_INTERVAL_SECONDS = 5;

    public const DEFAULT_PROVIDER_TIMEOUT_SECONDS = 900;

    public const DEFAULT_HARD_KILL_SECONDS = 1200;

    /** @var list<string> Modes admissible by run-real. */
    public const ALLOWED_MODES = [
        AtlasForgeRivalsModeRegistry::MODE_FAIR,
        AtlasForgeRivalsModeRegistry::MODE_FULL_POWER,
        AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE,
    ];

    public function __construct(
        private readonly AtlasForgeRivalsModeRegistry $modes,
        private readonly AtlasForgeRivalsModelMatrix $matrix,
        private readonly AtlasForgeRivalsCasesRegistry $cases,
        private readonly AtlasForgeRivalsRunPathResolver $paths,
        private readonly AtlasForgeRivalsEventStream $events,
        private readonly WorkspaceHygieneService $hygiene,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(array $input): array
    {
        $mode = trim((string) ($input['mode'] ?? ''));
        $atlasModel = trim((string) ($input['atlas_model'] ?? 'claude_sonnet'));
        $rivalModel = trim((string) ($input['rival'] ?? $atlasModel));
        $preset = trim((string) ($input['preset'] ?? 'smoke'));
        $confirms = (array) ($input['confirmations'] ?? []);

        $blockers = [];

        if (! in_array($mode, self::ALLOWED_MODES, true)) {
            $blockers[] = 'mode_not_admissible_for_run_real:'.$mode;

            return $this->blocked($blockers, 'pick --mode=fair|full_power|local_fake');
        }
        $modeDef = $this->modes->mode($mode);
        $matrixResult = $this->matrix->validate($mode, $atlasModel, $rivalModel);
        foreach ($matrixResult['blockers'] as $b) {
            $blockers[] = $b;
        }

        $cases = [];
        try {
            $cases = $this->cases->casesForPreset($preset);
        } catch (EmptyPresetIsFatalHarnessBug $e) {
            $blockers[] = 'zero_case_preset_fatal_harness_bug:'.$preset;
        } catch (\Throwable $e) {
            $blockers[] = 'preset_unknown:'.$preset;
        }
        if ($cases === []) {
            return $this->blocked($blockers, 'fix preset and re-run');
        }
        $case = $cases[0];

        $requiresProvider = $modeDef['requires_provider'];
        $confirmsPresent = [
            'runbook_reviewed' => (bool) ($confirms['runbook_reviewed'] ?? false),
            'provider_cost' => (bool) ($confirms['provider_cost'] ?? false),
            'real_provider_call' => (bool) ($confirms['real_provider_call'] ?? false),
        ];
        if ($requiresProvider) {
            foreach ($confirmsPresent as $k => $v) {
                if (! $v) {
                    $blockers[] = 'missing_confirmation:'.$k;
                }
            }
        }
        if ($blockers !== []) {
            return $this->blocked($blockers, 'pass all three --confirm-* flags');
        }

        if ($requiresProvider) {
            $driverBlockers = $this->driverAvailabilityBlockers($atlasModel, $rivalModel);
            if ($driverBlockers !== []) {
                return $this->blocked(
                    $driverBlockers,
                    'install missing provider CLI (claude/codex) before running real-provider battery',
                );
            }
        }

        // Resolve run_id (existing setup or fresh)
        $runId = trim((string) ($input['run_id'] ?? ''));
        if ($runId === '') {
            $runId = 'fr2-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6));
        }
        $paths = $this->paths->paths($runId);

        // Worktrees must exist
        $worktreesPresent = (is_dir($paths['atlas'].'/.git') || is_file($paths['atlas'].'/.git'))
            && (is_dir($paths['rival'].'/.git') || is_file($paths['rival'].'/.git'));
        if (! $worktreesPresent) {
            return $this->blocked(
                ['worktrees_missing'],
                "php artisan atlas:forge:rivals setup --source-ref=HEAD --run-id={$paths['run_id']} --json"
            );
        }

        $this->events->start($runId, [
            'mode' => $mode,
            'atlas_model' => $atlasModel,
            'rival_model' => $rivalModel,
            'preset' => $preset,
            'case_id' => $case['id'],
            'requires_provider' => $requiresProvider,
            'started_at' => $this->nowIso(),
        ]);

        $beforeAtlas = $this->workspaceHash($paths['atlas']);
        $beforeRival = $this->workspaceHash($paths['rival']);
        $this->events->event($runId, 'step_started', ['step' => 'before_workspace_hash']);

        // Run atlas arm + rival arm sequentially (orchestrator-simple in slice 3)
        $atlasReceipt = $this->runArm($runId, 'atlas', $paths['atlas'], $mode, $atlasModel, $case);
        $rivalReceipt = $this->runArm($runId, 'rival', $paths['rival'], $mode, $rivalModel, $case);

        $afterAtlas = $this->workspaceHash($paths['atlas']);
        $afterRival = $this->workspaceHash($paths['rival']);

        $dirtyAtlas = $this->workspaceDirty($paths['atlas']);
        $dirtyRival = $this->workspaceDirty($paths['rival']);
        $workspaceBlockers = array_values(array_merge(
            $this->stringList($atlasReceipt['workspace_blockers'] ?? []),
            $this->stringList($rivalReceipt['workspace_blockers'] ?? []),
        ));
        $dirtyAfterRun = $workspaceBlockers !== [];
        $this->events->event($runId, 'after_clean_check', [
            'atlas_clean' => ! (bool) ($atlasReceipt['workspace_has_blocking_changes'] ?? false),
            'rival_clean' => ! (bool) ($rivalReceipt['workspace_has_blocking_changes'] ?? false),
            'atlas_dirty_count' => $dirtyAtlas['count'],
            'rival_dirty_count' => $dirtyRival['count'],
            'atlas_changed_files' => $atlasReceipt['changed_files'] ?? [],
            'rival_changed_files' => $rivalReceipt['changed_files'] ?? [],
            'workspace_blockers' => $workspaceBlockers,
        ]);

        // Verdict
        $verdict = 'comparable';
        $score = null;
        $claimReady = false;
        if ($dirtyAfterRun) {
            $verdict = 'invalid_workspace_after_run';
        } elseif (($atlasReceipt['exit_code'] ?? -1) !== 0 || ($rivalReceipt['exit_code'] ?? -1) !== 0) {
            $verdict = $atlasReceipt['killed'] || $rivalReceipt['killed'] ? 'invalid_provider_timeout' : 'inconclusive';
        } elseif (($atlasReceipt['test_exit_code'] ?? -1) !== 0 || ($rivalReceipt['test_exit_code'] ?? -1) !== 0) {
            $verdict = 'invalid_tests_failed';
        } elseif ((int) ($atlasReceipt['patch_diff_bytes'] ?? 0) <= 0 || (int) ($rivalReceipt['patch_diff_bytes'] ?? 0) <= 0) {
            $verdict = 'invalid_no_patch_diff';
        }
        if ($verdict === 'comparable') {
            $score = [
                'comparable_score' => null,
                'diagnostic_score' => [
                    'atlas' => $this->armGateScore($atlasReceipt),
                    'rival' => $this->armGateScore($rivalReceipt),
                    'winner' => 'automated_quality_tie_requires_human_diff_review',
                ],
                'quality_claim' => 'automated gates passed; human diff review still required for qualitative winner',
            ];
            $claimReady = $mode !== AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE
                && ! $atlasReceipt['killed'] && ! $rivalReceipt['killed'];
        }

        // Persist receipts
        @mkdir($paths['evidence'], 0o755, true);
        file_put_contents($paths['evidence'].'/atlas_receipt.json', $this->jsonEncode($atlasReceipt));
        file_put_contents($paths['evidence'].'/rival_receipt.json', $this->jsonEncode($rivalReceipt));
        file_put_contents($paths['evidence'].'/workspace_hashes.json', $this->jsonEncode([
            'before' => ['atlas' => $beforeAtlas, 'rival' => $beforeRival],
            'after' => ['atlas' => $afterAtlas, 'rival' => $afterRival],
            'dirty_after_run' => $dirtyAfterRun,
            'workspace_blockers' => $workspaceBlockers,
            'workspace_changes_after_run' => [
                'atlas' => $atlasReceipt['changed_files'] ?? [],
                'rival' => $rivalReceipt['changed_files'] ?? [],
            ],
        ]));

        $manifest = [
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $paths['run_id'],
            'mode' => $mode,
            'atlas_model' => $atlasModel,
            'rival_model' => $rivalModel,
            'preset' => $preset,
            'case_id' => $case['id'],
            'started_at' => $this->nowIso(),
            'finished_at' => $this->nowIso(),
            'verdict' => $verdict,
            'score' => $score,
            'claim_ready' => $claimReady,
            'atlas_receipt_hash' => hash('sha256', $this->jsonEncode($atlasReceipt)),
            'rival_receipt_hash' => hash('sha256', $this->jsonEncode($rivalReceipt)),
            'workspace_hash_before' => ['atlas' => $beforeAtlas, 'rival' => $beforeRival],
            'workspace_hash_after' => ['atlas' => $afterAtlas, 'rival' => $afterRival],
            'dirty_after_run' => $dirtyAfterRun,
            'workspace_blockers' => $workspaceBlockers,
            'workspace_changes_after_run' => [
                'atlas' => $atlasReceipt['changed_files'] ?? [],
                'rival' => $rivalReceipt['changed_files'] ?? [],
            ],
            'external_provider_call' => $requiresProvider && $mode !== AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE,
            'provider_tokens_spent' => $requiresProvider && $mode !== AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE,
            'separated_from_external_rivals_certification' => true,
        ];
        file_put_contents($paths['evidence'].'/manifest.json', $this->jsonEncode($manifest));

        $this->events->event($runId, 'evidence_pack', [
            'verdict' => $verdict,
            'claim_ready' => $claimReady,
            'manifest_path' => $paths['evidence'].'/manifest.json',
        ]);
        $this->events->event($runId, 'final_report', [
            'verdict' => $verdict,
            'score' => $score,
            'claim_ready' => $claimReady,
        ]);

        return [
            'status' => 'ok',
            'run_id' => $paths['run_id'],
            'mode' => $mode,
            'verdict' => $verdict,
            'score' => $score,
            'claim_ready' => $claimReady,
            'paths' => $paths,
            'manifest' => $manifest,
            'atlas_receipt' => $atlasReceipt,
            'rival_receipt' => $rivalReceipt,
            'evidence_paths' => [
                $paths['events_jsonl'],
                $paths['manifest_json'],
                $paths['evidence'].'/atlas_receipt.json',
                $paths['evidence'].'/rival_receipt.json',
                $paths['evidence'].'/workspace_hashes.json',
            ],
            'external_provider_call' => $manifest['external_provider_call'],
            'provider_tokens_spent' => $manifest['provider_tokens_spent'],
            'next_command' => 'php artisan atlas:forge:rivals collect-evidence --run-id='.$paths['run_id'].' --json',
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array<string,mixed>
     */
    private function runArm(string $runId, string $arm, string $worktree, string $mode, string $model, array $case): array
    {
        $startedAt = $this->nowIso();
        $this->events->event($runId, 'provider_started', [
            'arm' => $arm,
            'mode' => $mode,
            'model' => $model,
            'worktree' => $worktree,
        ]);

        if ($mode === AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE) {
            return $this->fakeArm($runId, $arm, $worktree, $model, $case, $startedAt);
        }

        // Real provider: build provider command per arm, spawn subprocess.
        $command = $this->resolveProviderCommand($arm, $model, $case, $worktree);
        $env = $this->subprocessEnv();
        $promptHash = hash('sha256', $this->jsonEncode([
            'command' => $command,
            'arm' => $arm,
            'model' => $model,
            'case_id' => $case['id'],
        ]));
        $commandHash = hash('sha256', implode(' ', $command));

        $providerTimeoutSeconds = $this->providerTimeoutSeconds();
        $hardKillSeconds = $this->hardKillSeconds();
        $proc = new Process($command, $worktree, $env, null, $hardKillSeconds);
        // We enforce idle and hard timeouts explicitly below. Symfony's idle
        // timeout can be bypassed when callers only poll isRunning(); the
        // explicit clock is the Rivals source of truth.
        $proc->setIdleTimeout(null);

        $stdoutBuf = '';
        $stderrBuf = '';
        $startedAtMonotonic = microtime(true);
        $lastProviderOutputAt = $startedAtMonotonic;
        $lastHeartbeatAt = $startedAtMonotonic;
        $killed = false;
        $timeoutReason = null;

        try {
            $proc->start();
            while ($proc->isRunning()) {
                $sawProviderOutput = false;
                $newStdout = (string) $proc->getIncrementalOutput();
                $newStderr = (string) $proc->getIncrementalErrorOutput();
                if ($newStdout !== '') {
                    $stdoutBuf .= $newStdout;
                    $sawProviderOutput = true;
                    $this->events->event($runId, 'provider_stdout_chunk', [
                        'arm' => $arm,
                        'bytes' => strlen($newStdout),
                        'tail' => substr($newStdout, -200),
                    ]);
                }
                if ($newStderr !== '') {
                    $stderrBuf .= $newStderr;
                    $sawProviderOutput = true;
                    $this->events->event($runId, 'provider_stderr_chunk', [
                        'arm' => $arm,
                        'bytes' => strlen($newStderr),
                        'tail' => substr($newStderr, -200),
                    ]);
                }
                $now = microtime(true);
                if ($sawProviderOutput) {
                    $lastProviderOutputAt = $now;
                }

                if (($now - $lastProviderOutputAt) >= $providerTimeoutSeconds) {
                    $killed = true;
                    $timeoutReason = 'idle_timeout';
                    $this->events->event($runId, 'provider_timeout_warning', [
                        'arm' => $arm,
                        'reason' => $timeoutReason,
                        'seconds_without_provider_output' => (int) floor($now - $lastProviderOutputAt),
                        'provider_timeout_seconds' => $providerTimeoutSeconds,
                    ]);
                    $proc->stop(10);
                    break;
                }

                if (($now - $startedAtMonotonic) >= $hardKillSeconds) {
                    $killed = true;
                    $timeoutReason = 'hard_timeout';
                    $this->events->event($runId, 'provider_timeout_warning', [
                        'arm' => $arm,
                        'reason' => $timeoutReason,
                        'elapsed_seconds' => (int) floor($now - $startedAtMonotonic),
                        'hard_kill_seconds' => $hardKillSeconds,
                    ]);
                    $proc->stop(10);
                    break;
                }

                if (($now - $lastHeartbeatAt) >= self::HEARTBEAT_INTERVAL_SECONDS) {
                    $this->events->event($runId, 'heartbeat', [
                        'arm' => $arm,
                        'elapsed_since_last_provider_output_seconds' => (int) floor($now - $lastProviderOutputAt),
                        'elapsed_total_seconds' => (int) floor($now - $startedAtMonotonic),
                    ]);
                    $lastHeartbeatAt = $now;
                }
                usleep(200_000); // 200ms poll
            }
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
            $killed = true;
            $timeoutReason = 'idle_timeout';
            $this->events->event($runId, 'provider_timeout_warning', [
                'arm' => $arm,
                'reason' => 'idle_timeout',
            ]);
            try {
                $proc->stop(10);
            } catch (\Throwable) {
            }
        } catch (\Throwable $e) {
            $killed = true;
            $timeoutReason = 'process_failed:'.$e->getMessage();
        }

        $exit = (int) ($proc->getExitCode() ?? -1);
        $stdoutBuf .= (string) $proc->getIncrementalOutput();
        $stderrBuf .= (string) $proc->getIncrementalErrorOutput();

        $this->events->event($runId, 'provider_finished', [
            'arm' => $arm,
            'exit_code' => $exit,
            'killed' => $killed,
            'timeout_reason' => $timeoutReason,
            'stdout_tail' => substr($stdoutBuf, -500),
            'stderr_tail' => substr($stderrBuf, -500),
        ]);

        $logPaths = $this->writeProviderLogs($runId, $arm, $stdoutBuf, $stderrBuf);
        $patch = $this->capturePatch($runId, $arm, $worktree);
        $scope = $this->scopeCheck($worktree, $case);
        $test = $killed
            ? $this->skippedValidationCommand($runId, $arm, $case, (string) $timeoutReason)
            : $this->runValidationCommand($runId, $arm, $worktree, $case);

        return [
            'arm' => $arm,
            'mode' => $mode,
            'model' => $model,
            'command' => $command,
            'command_hash' => $commandHash,
            'prompt_hash' => $promptHash,
            'started_at' => $startedAt,
            'finished_at' => $this->nowIso(),
            'exit_code' => $exit,
            'killed' => $killed,
            'timeout' => $killed,
            'timeout_reason' => $timeoutReason,
            'stdout_hash' => hash('sha256', $stdoutBuf),
            'stderr_hash' => hash('sha256', $stderrBuf),
            'stdout_bytes' => strlen($stdoutBuf),
            'stderr_bytes' => strlen($stderrBuf),
            'stdout_tail' => substr($stdoutBuf, -2000),
            'stderr_tail' => substr($stderrBuf, -2000),
            'stdout_path' => $logPaths['stdout_path'],
            'stderr_path' => $logPaths['stderr_path'],
            'token_cost' => null,
            'tokens_used' => null,
            'worktree' => $worktree,
            'case_id' => $case['id'],
            'changed_files' => $scope['changed_files'],
            'out_of_scope_files' => $scope['out_of_scope_files'],
            'bytecode_artifacts' => $scope['bytecode_artifacts'],
            'workspace_blockers' => $scope['blockers'],
            'workspace_has_blocking_changes' => $scope['blockers'] !== [],
            'patch_diff_path' => $patch['path'],
            'patch_diff_hash' => $patch['sha256'],
            'patch_diff_bytes' => $patch['bytes'],
            'test_command' => $test['command'],
            'test_exit_code' => $test['exit_code'],
            'test_log_path' => $test['log_path'],
            'test_log_hash' => $test['log_hash'],
            'test_log_tail' => $test['tail'],
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array<string,mixed>
     */
    private function fakeArm(string $runId, string $arm, string $worktree, string $model, array $case, string $startedAt): array
    {
        // In-process fake provider: deterministic, fast, zero side-effects on worktree.
        // It still writes real evidence artifacts so the local battery proves
        // the comparable/report path instead of succeeding with an empty diff.
        $paths = $this->paths->paths($runId);
        @mkdir($paths['evidence'], 0o755, true);

        $command = ['atlas:forge-rivals-fake-provider', '--arm='.$arm, '--model='.$model, '--case='.$case['id']];
        $fakeStdout = sprintf("[FAKE %s/%s] case=%s status=ok\n", $arm, $model, $case['id']);
        $fakeStderr = '';
        $fakeChangedFiles = $this->fakeChangedFiles($arm);
        $fakePatch = $this->fakePatch($arm, $model, $case, $fakeChangedFiles);
        $patchPath = $paths['evidence'].'/'.$arm.'_patch.diff';
        file_put_contents($patchPath, $fakePatch);

        $testLog = sprintf(
            "PASS  Tests\\Feature\\Ai\\Programming\\ForgeRivalsLocalFake%sTest\n".
            "Tests: 2 passed (18 assertions)\n".
            "Case: %s\n".
            "Model: %s\n",
            ucfirst($arm),
            (string) $case['id'],
            $model,
        );
        $testLogPath = $paths['evidence'].'/'.$arm.'_test.log';
        file_put_contents($testLogPath, $testLog);
        $logPaths = $this->writeProviderLogs($runId, $arm, $fakeStdout, $fakeStderr);

        $this->events->event($runId, 'provider_stdout_chunk', [
            'arm' => $arm,
            'bytes' => strlen($fakeStdout),
            'tail' => $fakeStdout,
            'fake' => true,
        ]);
        $this->events->event($runId, 'heartbeat', ['arm' => $arm, 'fake' => true]);
        $this->events->event($runId, 'provider_finished', [
            'arm' => $arm,
            'exit_code' => 0,
            'killed' => false,
            'fake' => true,
        ]);

        return [
            'arm' => $arm,
            'mode' => AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE,
            'model' => $model,
            'command' => $command,
            'command_hash' => hash('sha256', implode(' ', $command)),
            'prompt_hash' => hash('sha256', $case['id'].'|'.$model.'|'.$arm),
            'started_at' => $startedAt,
            'finished_at' => $this->nowIso(),
            'exit_code' => 0,
            'killed' => false,
            'timeout' => false,
            'timeout_reason' => null,
            'stdout_hash' => hash('sha256', $fakeStdout),
            'stderr_hash' => hash('sha256', $fakeStderr),
            'stdout_bytes' => strlen($fakeStdout),
            'stderr_bytes' => strlen($fakeStderr),
            'stdout_tail' => $fakeStdout,
            'stderr_tail' => $fakeStderr,
            'stdout_path' => $logPaths['stdout_path'],
            'stderr_path' => $logPaths['stderr_path'],
            'changed_files' => $fakeChangedFiles,
            'out_of_scope_files' => [],
            'bytecode_artifacts' => [],
            'workspace_blockers' => [],
            'workspace_has_blocking_changes' => false,
            'patch_diff_path' => $patchPath,
            'patch_diff_hash' => hash('sha256', $fakePatch),
            'patch_diff_bytes' => strlen($fakePatch),
            'test_command' => 'local_fake_fixture_validation',
            'test_exit_code' => 0,
            'test_log_path' => $testLogPath,
            'test_log_hash' => hash('sha256', $testLog),
            'test_log_tail' => $testLog,
            'token_cost' => 0.0,
            'tokens_used' => 0,
            'worktree' => $worktree,
            'case_id' => $case['id'],
            'fake' => true,
        ];
    }

    /**
     * @return list<string>
     */
    private function fakeChangedFiles(string $arm): array
    {
        $suffix = $arm === 'atlas' ? 'Atlas' : 'Rival';

        return [
            'app/Services/Ai/Programming/ForgeRivals/LocalFake'.$suffix.'Patch.php',
            'tests/Feature/Ai/Programming/ForgeRivalsLocalFake'.$suffix.'Test.php',
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     * @param  list<string>  $changedFiles
     */
    private function fakePatch(string $arm, string $model, array $case, array $changedFiles): string
    {
        $caseId = (string) $case['id'];
        $classSuffix = $arm === 'atlas' ? 'Atlas' : 'Rival';
        $serviceFile = $changedFiles[0] ?? 'app/Services/Ai/Programming/ForgeRivals/LocalFake'.$classSuffix.'Patch.php';
        $testFile = $changedFiles[1] ?? 'tests/Feature/Ai/Programming/ForgeRivalsLocalFake'.$classSuffix.'Test.php';

        return <<<DIFF
diff --git a/{$serviceFile} b/{$serviceFile}
new file mode 100644
--- /dev/null
+++ b/{$serviceFile}
@@
+<?php
+
+declare(strict_types=1);
+
+final class LocalFake{$classSuffix}Patch
+{
+    public const ARM = '{$arm}';
+    public const MODEL = '{$model}';
+    public const CASE_ID = '{$caseId}';
+}
diff --git a/{$testFile} b/{$testFile}
new file mode 100644
--- /dev/null
+++ b/{$testFile}
@@
+<?php
+
+declare(strict_types=1);
+
+test('local fake {$arm} evidence fixture is comparable', function (): void {
+    expect('{$caseId}')->not->toBe('');
+});
DIFF;
    }

    /**
     * @param  array<string,mixed>  $case
     * @return list<string>
     */
    private function resolveProviderCommand(string $arm, string $model, array $case, string $worktree): array
    {
        if ($arm === 'atlas') {
            // Atlas arm: execute the provider under the Forge Rivals harness
            // contract. The outer harness owns isolation, evidence, scope, tests,
            // replay and invalidation; keeping this command provider-direct avoids
            // coupling the benchmark to legacy engineering_harness database drift.
            return [
                'claude',
                '--model',
                $this->claudeModelAlias($model),
                '--permission-mode',
                'bypassPermissions',
                '--output-format',
                'stream-json',
                '--verbose',
                '-p',
                $this->atlasForgePrompt($case),
            ];
        }

        // Rival arm: raw provider baseline, same model, no Atlas Forge.
        if ($model === 'codex') {
            return ['codex', 'exec', '--json', $this->rivalPrompt($case)];
        }

        return [
            'claude',
            '--model',
            $this->claudeModelAlias($model),
            '--permission-mode',
            'bypassPermissions',
            '--output-format',
            'stream-json',
            '--verbose',
            '-p',
            $this->rivalPrompt($case),
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     */
    private function atlasForgePrompt(array $case): string
    {
        return $this->casePrompt($case, 'Você é o braço Atlas Forge. Use o fluxo Atlas Forge, mantenha evidência, respeite escopo e rode o comando de teste informado.');
    }

    /**
     * @param  array<string,mixed>  $case
     */
    private function rivalPrompt(array $case): string
    {
        return $this->casePrompt($case, 'Você é o braço baseline. Implemente diretamente no workspace atual, sem usar Atlas Forge, respeitando exatamente o mesmo escopo e teste.');
    }

    /**
     * @param  array<string,mixed>  $case
     */
    private function casePrompt(array $case, string $role): string
    {
        $allowed = implode("\n- ", $this->stringList($case['allowed_files'] ?? []));
        $acceptance = implode("\n- ", $this->stringList($case['acceptance_criteria'] ?? []));
        $testCommand = $this->testCommand($case);

        return <<<PROMPT
{$role}

Objetivo:
{$case['objective']}

Escopo permitido:
- {$allowed}

Critérios de aceitação:
- {$acceptance}

Comando obrigatório de validação:
{$testCommand}

Regras:
- Altere somente arquivos dentro do escopo permitido.
- Não crie bytecode, caches, arquivos temporários ou artefatos fora do escopo.
- Execute o comando obrigatório de validação antes de terminar.
- Deixe as alterações no workspace para o harness capturar diff e evidência.
- Responda com resumo curto, arquivos alterados e resultado do teste.
PROMPT;
    }

    private function claudeModelAlias(string $model): string
    {
        return match ($model) {
            AtlasForgeRivalsModelMatrix::MODEL_CLAUDE_OPUS => 'opus',
            AtlasForgeRivalsModelMatrix::MODEL_CLAUDE_SONNET, AtlasForgeRivalsModelMatrix::MODEL_AUTO => 'sonnet',
            default => $model,
        };
    }

    /**
     * @param  array<string,mixed>  $case
     */
    private function testCommand(array $case): string
    {
        $full = trim((string) ($case['full_test_command'] ?? ''));
        if ($full !== '') {
            return $full;
        }
        $quick = trim((string) ($case['quick_test_command'] ?? ''));

        return $quick !== '' ? $quick : 'php artisan test';
    }

    /**
     * @return array{stdout_path:string,stderr_path:string}
     */
    private function writeProviderLogs(string $runId, string $arm, string $stdout, string $stderr): array
    {
        $paths = $this->paths->paths($runId);
        @mkdir($paths['evidence'], 0o755, true);
        $stdoutPath = $paths['evidence'].'/'.$arm.'_provider_stdout.log';
        $stderrPath = $paths['evidence'].'/'.$arm.'_provider_stderr.log';
        file_put_contents($stdoutPath, $stdout);
        file_put_contents($stderrPath, $stderr);

        return ['stdout_path' => $stdoutPath, 'stderr_path' => $stderrPath];
    }

    /**
     * @return array{path:string,sha256:string,bytes:int}
     */
    private function capturePatch(string $runId, string $arm, string $worktree): array
    {
        $paths = $this->paths->paths($runId);
        @mkdir($paths['evidence'], 0o755, true);
        $patchPath = $paths['evidence'].'/'.$arm.'_patch.diff';

        $proc = new Process(['git', '-C', $worktree, 'diff', '--binary', '--']);
        $proc->setTimeout(60);
        $proc->run();
        $patch = (string) $proc->getOutput();

        $status = $this->workspaceStatusLines($worktree);
        $untracked = [];
        foreach ($status as $line) {
            if (str_starts_with($line, '?? ')) {
                $untracked[] = $this->statusPath($line);
            }
        }
        foreach ($untracked as $file) {
            $patch .= $this->untrackedFilePatch($worktree, $file);
        }

        file_put_contents($patchPath, $patch);

        return [
            'path' => $patchPath,
            'sha256' => hash('sha256', $patch),
            'bytes' => strlen($patch),
        ];
    }

    private function untrackedFilePatch(string $worktree, string $file): string
    {
        $path = $worktree.'/'.$file;
        if (! is_file($path)) {
            return '';
        }
        $blob = (string) @file_get_contents($path);
        $lines = explode("\n", $blob);
        $body = '';
        foreach ($lines as $line) {
            $body .= '+'.$line."\n";
        }

        return "\ndiff --git a/{$file} b/{$file}\nnew file mode 100644\n--- /dev/null\n+++ b/{$file}\n@@\n".$body;
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array{
     *   changed_files:list<string>,
     *   out_of_scope_files:list<string>,
     *   bytecode_artifacts:list<string>,
     *   blockers:list<string>
     * }
     */
    private function scopeCheck(string $worktree, array $case): array
    {
        $allowed = $this->stringList($case['allowed_files'] ?? []);
        $changed = [];
        $outOfScope = [];
        $bytecode = [];
        $blockers = [];

        foreach ($this->workspaceStatusLines($worktree) as $line) {
            $file = $this->statusPath($line);
            if ($file === '') {
                continue;
            }
            $changed[] = $file;
            if ($this->isPythonBytecode($file)) {
                $bytecode[] = $file;
                $blockers[] = 'bytecode_artifact_after_run:'.$file;

                continue;
            }
            if (! $this->matchesAnyAllowedScope($file, $allowed)) {
                $outOfScope[] = $file;
                $blockers[] = 'out_of_scope_change:'.$file;
            }
        }

        return [
            'changed_files' => array_values(array_unique($changed)),
            'out_of_scope_files' => array_values(array_unique($outOfScope)),
            'bytecode_artifacts' => array_values(array_unique($bytecode)),
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array{command:string,exit_code:int,log_path:string,log_hash:string,tail:string}
     */
    private function runValidationCommand(string $runId, string $arm, string $worktree, array $case): array
    {
        $command = $this->testCommand($case);
        $paths = $this->paths->paths($runId);
        @mkdir($paths['evidence'], 0o755, true);
        $logPath = $paths['evidence'].'/'.$arm.'_test.log';

        $proc = Process::fromShellCommandline($command, $worktree, $this->subprocessEnv(), null, 900);
        $proc->run();
        $log = (string) $proc->getOutput().(string) $proc->getErrorOutput();
        file_put_contents($logPath, $log);
        $this->events->event($runId, 'validation_finished', [
            'arm' => $arm,
            'command' => $command,
            'exit_code' => (int) $proc->getExitCode(),
            'log_tail' => substr($log, -500),
        ]);

        return [
            'command' => $command,
            'exit_code' => (int) ($proc->getExitCode() ?? -1),
            'log_path' => $logPath,
            'log_hash' => hash('sha256', $log),
            'tail' => substr($log, -2000),
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array{command:string,exit_code:int,log_path:string,log_hash:string,tail:string}
     */
    private function skippedValidationCommand(string $runId, string $arm, array $case, string $reason): array
    {
        $command = $this->testCommand($case);
        $paths = $this->paths->paths($runId);
        @mkdir($paths['evidence'], 0o755, true);
        $logPath = $paths['evidence'].'/'.$arm.'_test.log';
        $log = "SKIPPED: provider timed out before validation could run.\nReason: {$reason}\nCommand: {$command}\n";
        file_put_contents($logPath, $log);
        $this->events->event($runId, 'validation_skipped', [
            'arm' => $arm,
            'command' => $command,
            'reason' => $reason,
        ]);

        return [
            'command' => $command,
            'exit_code' => -1,
            'log_path' => $logPath,
            'log_hash' => hash('sha256', $log),
            'tail' => $log,
        ];
    }

    /**
     * @return list<string>
     */
    private function workspaceStatusLines(string $workspace): array
    {
        if (! is_dir($workspace)) {
            return [];
        }
        $proc = new Process(['git', '-C', $workspace, 'status', '--porcelain']);
        $proc->setTimeout(15);
        $proc->run();
        if (! $proc->isSuccessful()) {
            return [];
        }

        return array_values(array_filter(
            explode("\n", rtrim((string) $proc->getOutput(), "\n\r")),
            static fn (string $line): bool => trim($line) !== '',
        ));
    }

    private function statusPath(string $line): string
    {
        $path = trim(substr($line, 3));
        if (str_contains($path, ' -> ')) {
            $parts = explode(' -> ', $path);
            $path = trim((string) end($parts));
        }

        return trim($path, "\" \t\n\r\0\x0B");
    }

    /**
     * @param  list<string>  $allowed
     */
    private function matchesAnyAllowedScope(string $file, array $allowed): bool
    {
        foreach ($allowed as $pattern) {
            if ($this->globMatches($pattern, $file)) {
                return true;
            }
        }

        return false;
    }

    private function globMatches(string $pattern, string $file): bool
    {
        $quoted = preg_quote($pattern, '#');
        $quoted = str_replace('\*\*', '.*', $quoted);
        $quoted = str_replace('\*', '[^/]*', $quoted);

        return (bool) preg_match('#^'.$quoted.'$#', $file);
    }

    private function isPythonBytecode(string $file): bool
    {
        return str_ends_with($file, '.pyc')
            || str_ends_with($file, '.pyo')
            || str_contains($file, '__pycache__/');
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    private function armGateScore(array $receipt): array
    {
        return [
            'provider_exit_zero' => (int) ($receipt['exit_code'] ?? -1) === 0,
            'tests_passed' => (int) ($receipt['test_exit_code'] ?? -1) === 0,
            'patch_diff_present' => (int) ($receipt['patch_diff_bytes'] ?? 0) > 0,
            'out_of_scope_files' => $this->stringList($receipt['out_of_scope_files'] ?? []),
            'bytecode_artifacts' => $this->stringList($receipt['bytecode_artifacts'] ?? []),
        ];
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn ($item): string => (string) $item, $value));
    }

    /**
     * @return array<string,string>
     */
    private function subprocessEnv(): array
    {
        $env = $_SERVER ?: [];
        $env['PYTHONDONTWRITEBYTECODE'] = '1';
        $env['ATLAS_FORGE_RIVALS_V2'] = '1';

        // Filter to string-only values (Process expects array<string,string>)
        $out = [];
        foreach ($env as $k => $v) {
            if (is_string($k) && (is_string($v) || is_numeric($v) || is_bool($v))) {
                $out[$k] = (string) $v;
            }
        }

        return $out;
    }

    private function providerTimeoutSeconds(): int
    {
        $value = (int) (getenv('ATLAS_FORGE_RIVALS_PROVIDER_TIMEOUT_SECONDS') ?: 0);

        return $value > 0 ? max(5, $value) : self::DEFAULT_PROVIDER_TIMEOUT_SECONDS;
    }

    private function hardKillSeconds(): int
    {
        $value = (int) (getenv('ATLAS_FORGE_RIVALS_HARD_KILL_SECONDS') ?: 0);

        return $value > 0 ? max(10, $value) : self::DEFAULT_HARD_KILL_SECONDS;
    }

    private function workspaceHash(string $workspace): ?string
    {
        if (! is_dir($workspace)) {
            return null;
        }
        $proc = new Process(['git', '-C', $workspace, 'status', '--porcelain']);
        $proc->setTimeout(15);
        $proc->run();
        if (! $proc->isSuccessful()) {
            return null;
        }

        return hash('sha256', (string) $proc->getOutput());
    }

    /**
     * @return array{dirty:bool,count:int,sample:list<string>}
     */
    private function workspaceDirty(string $workspace): array
    {
        if (! is_dir($workspace)) {
            return ['dirty' => false, 'count' => 0, 'sample' => []];
        }
        $proc = new Process(['git', '-C', $workspace, 'status', '--porcelain']);
        $proc->setTimeout(15);
        $proc->run();
        if (! $proc->isSuccessful()) {
            return ['dirty' => false, 'count' => 0, 'sample' => []];
        }
        $lines = array_values(array_filter(explode("\n", trim((string) $proc->getOutput())), static fn (string $l) => trim($l) !== ''));

        return ['dirty' => $lines !== [], 'count' => count($lines), 'sample' => array_slice($lines, 0, 10)];
    }

    /**
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function blocked(array $blockers, string $hint): array
    {
        return [
            'status' => 'blocked',
            'blockers' => $blockers,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'next_command' => $hint,
        ];
    }

    /**
     * @return list<string>
     */
    private function driverAvailabilityBlockers(string $atlasModel, string $rivalModel): array
    {
        $blockers = [];
        $atlasUsesClaude = in_array($atlasModel, [
            AtlasForgeRivalsModelMatrix::MODEL_CLAUDE_SONNET,
            AtlasForgeRivalsModelMatrix::MODEL_CLAUDE_OPUS,
            AtlasForgeRivalsModelMatrix::MODEL_AUTO,
        ], true);
        $rivalUsesClaude = in_array($rivalModel, [
            AtlasForgeRivalsModelMatrix::MODEL_CLAUDE_SONNET,
            AtlasForgeRivalsModelMatrix::MODEL_CLAUDE_OPUS,
            AtlasForgeRivalsModelMatrix::MODEL_AUTO,
        ], true);

        if (($atlasUsesClaude || $rivalUsesClaude) && ! $this->binaryAvailable('claude')) {
            $blockers[] = 'rival_driver_not_configured:claude';
        }
        if (($atlasModel === AtlasForgeRivalsModelMatrix::MODEL_CODEX
            || $rivalModel === AtlasForgeRivalsModelMatrix::MODEL_CODEX)
            && ! $this->binaryAvailable('codex')
        ) {
            $blockers[] = 'rival_driver_not_configured:codex';
        }

        return $blockers;
    }

    private function binaryAvailable(string $binary): bool
    {
        try {
            $proc = new Process(['which', $binary]);
            $proc->setTimeout(5);
            $proc->run();

            return $proc->isSuccessful() && trim((string) $proc->getOutput()) !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    private function jsonEncode(mixed $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function nowIso(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}

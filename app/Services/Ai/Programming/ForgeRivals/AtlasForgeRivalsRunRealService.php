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
 *   6. Workspace hash is captured before/after; `dirty_after_run` ⇒
 *      `verdict=invalid_dirty_after_run`, `score=null`, `claim=false`.
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
        $dirtyAfterRun = $dirtyAtlas['dirty'] || $dirtyRival['dirty'];
        $this->events->event($runId, 'after_clean_check', [
            'atlas_clean' => ! $dirtyAtlas['dirty'],
            'rival_clean' => ! $dirtyRival['dirty'],
            'atlas_dirty_count' => $dirtyAtlas['count'],
            'rival_dirty_count' => $dirtyRival['count'],
        ]);

        // Verdict
        $verdict = 'comparable';
        $score = null;
        $claimReady = false;
        if ($dirtyAfterRun) {
            $verdict = 'invalid_dirty_after_run';
        } elseif (($atlasReceipt['exit_code'] ?? -1) !== 0 || ($rivalReceipt['exit_code'] ?? -1) !== 0) {
            $verdict = $atlasReceipt['killed'] || $rivalReceipt['killed'] ? 'invalid_provider_timeout' : 'inconclusive';
        }
        if ($verdict === 'comparable') {
            // Slice 4 will compute the diagnostic + comparable scores from real artifacts.
            $score = ['comparable_score' => null, 'diagnostic_score' => null];
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
        $promptHash = hash('sha256', json_encode($command + ['arm' => $arm, 'model' => $model, 'case_id' => $case['id']], JSON_UNESCAPED_SLASHES) ?: '');
        $commandHash = hash('sha256', implode(' ', $command));

        $proc = new Process($command, $worktree, $env, null, self::DEFAULT_HARD_KILL_SECONDS);
        $proc->setIdleTimeout(self::DEFAULT_PROVIDER_TIMEOUT_SECONDS);

        $stdoutBuf = '';
        $stderrBuf = '';
        $lastChunkAt = time();
        $killed = false;
        $timeoutReason = null;

        try {
            $proc->start();
            while ($proc->isRunning()) {
                $newStdout = (string) $proc->getIncrementalOutput();
                $newStderr = (string) $proc->getIncrementalErrorOutput();
                if ($newStdout !== '') {
                    $stdoutBuf .= $newStdout;
                    $lastChunkAt = time();
                    $this->events->event($runId, 'provider_stdout_chunk', [
                        'arm' => $arm,
                        'bytes' => strlen($newStdout),
                        'tail' => substr($newStdout, -200),
                    ]);
                }
                if ($newStderr !== '') {
                    $stderrBuf .= $newStderr;
                    $lastChunkAt = time();
                    $this->events->event($runId, 'provider_stderr_chunk', [
                        'arm' => $arm,
                        'bytes' => strlen($newStderr),
                        'tail' => substr($newStderr, -200),
                    ]);
                }
                if ((time() - $lastChunkAt) >= self::HEARTBEAT_INTERVAL_SECONDS) {
                    $this->events->event($runId, 'heartbeat', [
                        'arm' => $arm,
                        'elapsed_since_last_chunk_seconds' => time() - $lastChunkAt,
                    ]);
                    $lastChunkAt = time();
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
        ]);

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
            'token_cost' => null,
            'tokens_used' => null,
            'worktree' => $worktree,
            'case_id' => $case['id'],
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array<string,mixed>
     */
    private function fakeArm(string $runId, string $arm, string $worktree, string $model, array $case, string $startedAt): array
    {
        // In-process fake provider: deterministic, fast, zero side-effects on worktree.
        $command = ['atlas:forge-rivals-fake-provider', '--arm='.$arm, '--model='.$model, '--case='.$case['id']];
        $fakeStdout = sprintf("[FAKE %s/%s] case=%s status=ok\n", $arm, $model, $case['id']);
        $fakeStderr = '';
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
            'token_cost' => 0.0,
            'tokens_used' => 0,
            'worktree' => $worktree,
            'case_id' => $case['id'],
            'fake' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     * @return list<string>
     */
    private function resolveProviderCommand(string $arm, string $model, array $case, string $worktree): array
    {
        if ($arm === 'atlas') {
            // Forge-side dispatch. Atlas arm always uses Forge (atlas_not_forge is impossible
            // here because the case manifest enforces forge runtime upstream).
            return [
                'php', 'artisan',
                'atlas:code:forge-fast-path',
                '--case='.$case['id'],
                '--model='.$model,
                '--workspace='.$worktree,
                '--json',
                '--strict',
            ];
        }
        // Rival arm: claude-code or codex CLI on the rival worktree
        if ($model === 'codex') {
            return ['codex', 'apply', '--case='.$case['id'], '--workspace='.$worktree, '--json'];
        }

        return ['claude', 'code', 'apply', '--case='.$case['id'], '--workspace='.$worktree, '--json'];
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

    private function jsonEncode(mixed $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function nowIso(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}

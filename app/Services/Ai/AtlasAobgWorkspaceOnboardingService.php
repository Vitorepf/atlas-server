<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Support\AppendOnlyJsonlStore;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * AOBG N1.F3 — multi-project: the gateway works in ANY project, AUTO-SCOPED.
 *
 * F1 (push) and F2 (write-back) already resolve a workspace id from an explicit
 * `workspace` (path or id) or the caller's `cwd` via {@see CodeGraphWorkspaceIdentity}
 * and scope to it (no cross-workspace leak — the W-3 scoping fix). F3 closes the last
 * gap for a BRAND-NEW project the external AI opens: "does the brain even know THIS
 * repo yet?" — and, if not, a GATED onboarding path so the gateway is useful from the
 * very first prompt instead of silently returning an empty pack.
 *
 * This builds NO new index engine — it READS the existing code-intelligence read-model
 * ({@see atlas_engineering_code_symbols}, W-1 workspace_id keyed) for the resolved
 * workspace, and OFFERS the existing index command. The single honest status answer:
 *
 *   { workspace_id, indexed:bool, symbols:int, last_index:?string, needs_onboarding:bool }
 *
 * AUTO-ONBOARDING is GATED (config `atlas.aobg.auto_onboard`, default FALSE): a heavy
 * index of an arbitrary repo is NEVER run implicitly. The DEFAULT is report + OFFER
 * (the command an operator/agent can run); ONLY with the opt-in flag ON does
 * {@see onboard()} actually invoke the index. Either way the status is honest — an
 * un-indexed repo reports `needs_onboarding:true`, never a fabricated "indexed".
 *
 * MULTI-PROJECT: the workspace is resolved ONCE per call from `workspace`/`cwd`; the
 * status counts ONLY that workspace's symbols (the resolved id is the read filter), so
 * project B's status never reflects project A's index.
 *
 * COST: the status read is local DB only — zero provider spend, zero network. The
 * onboarding index (when the flag is ON) is a local index command, still no provider
 * spend. NEVER throws: a missing table / transient fault degrades to an honest
 * "not indexed" status, never an exception that breaks the external session.
 */
class AtlasAobgWorkspaceOnboardingService
{
    public const SCHEMA = 'atlas.aobg.workspace_onboarding.v1';

    /** The W-1 code-intelligence read-model the gateway scopes its status to. */
    private const SYMBOLS_TABLE = 'atlas_engineering_code_symbols';

    /**
     * The default index command offered for onboarding a workspace. It is the AWIS-gated
     * code-intelligence indexer; it requires a resolvable `--workspace`. We surface the
     * exact invocation so an operator/agent can run it even when auto-onboard is OFF.
     */
    public const ONBOARD_COMMAND = 'atlas:engineering:knowledge';

    /**
     * Optional injected index runner — `fn(string $workspacePath, string $workspaceId): array`.
     * Defaults to the real Artisan index call. Injected in tests so the gate can be
     * proven (ON triggers / OFF does not) WITHOUT running a heavy real index.
     *
     * @var (callable(string,string):array<string,mixed>)|null
     */
    private $indexRunner;

    public function __construct(
        private readonly CodeGraphWorkspaceIdentity $workspaceIdentity,
    ) {}

    /**
     * Inject a custom index runner (test seam). Returns $this for fluent setup.
     *
     * @param  callable(string,string):array<string,mixed>  $runner
     */
    public function setIndexRunner(callable $runner): self
    {
        $this->indexRunner = $runner;

        return $this;
    }

    /**
     * Honest status for the resolved workspace: does the brain know THIS project?
     *
     * @param  array<string,mixed>  $opts
     *   - workspace: explicit workspace path OR id (wins over cwd).
     *   - cwd: caller's working directory (the external tool's project dir).
     * @return array{
     *   schema:string, workspace_id:string, workspace_path:?string, indexed:bool,
     *   symbols:int, last_index:?string, needs_onboarding:bool, auto_onboard:bool,
     *   onboard_command:string, generated_at:string
     * }
     */
    public function status(array $opts = []): array
    {
        $workspacePath = $this->resolvePath($opts);
        $workspaceId = $this->resolveWorkspaceId($opts);
        $counts = $this->symbolCounts($workspaceId);

        $indexed = $counts['symbols'] > 0;

        return [
            'schema' => self::SCHEMA,
            'workspace_id' => $workspaceId,
            'workspace_path' => $workspacePath,
            'indexed' => $indexed,
            'symbols' => $counts['symbols'],
            'last_index' => $counts['last_index'],
            'needs_onboarding' => ! $indexed,
            'auto_onboard' => $this->autoOnboardEnabled(),
            'onboard_command' => $this->offeredCommand($workspacePath),
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * GATED onboarding: when the workspace is not indexed AND auto-onboard is ON, run the
     * existing index command for it; otherwise REPORT + OFFER (never run a heavy index of
     * an arbitrary repo implicitly). Returns the post-action status + what was done.
     *
     * @param  array<string,mixed>  $opts  same shape as {@see status()}; plus:
     *   - force: re-run the index even when already indexed (still gated by auto_onboard).
     * @return array<string,mixed>
     */
    public function onboard(array $opts = []): array
    {
        $status = $this->status($opts);
        $autoOnboard = $this->autoOnboardEnabled();
        $force = (bool) ($opts['force'] ?? false);
        $needsRun = $status['needs_onboarding'] || $force;

        // Default contract: report + offer. The index is NEVER run unless the opt-in flag
        // is ON — a heavy index of an arbitrary repo is an operator decision, not implicit.
        if (! $needsRun) {
            return $this->envelope('already_indexed', false, $status, $opts);
        }
        if (! $autoOnboard) {
            return $this->envelope('offer_only', false, $status, $opts);
        }

        // Auto-onboard ON: run the existing index command for THIS workspace. Fail-open —
        // an index fault degrades to a reported failure, never an exception to the caller.
        $run = ['ok' => false, 'reason' => 'no_workspace_path'];
        $workspacePath = $status['workspace_path'];
        if (is_string($workspacePath) && $workspacePath !== '') {
            try {
                $run = $this->runIndex($workspacePath, $status['workspace_id']);
            } catch (Throwable $e) {
                $run = ['ok' => false, 'reason' => 'index_failed', 'exception' => class_basename($e)];
            }
        }

        // Re-read the status AFTER the index attempt so the answer reflects reality.
        $after = $this->status($opts);

        return $this->envelope(
            ($run['ok'] ?? false) === true ? 'onboarded' : 'onboard_attempt_failed',
            true,
            $after,
            $opts,
            $run,
        );
    }

    // ------------------------------------------------------------------
    // internals
    // ------------------------------------------------------------------

    /**
     * Symbol count + last index timestamp scoped to the resolved workspace_id ONLY.
     * Fail-safe: a missing table / missing workspace_id column / transient fault → an
     * honest zero (never a fabricated count, never a throw).
     *
     * @return array{symbols:int, last_index:?string}
     */
    private function symbolCounts(string $workspaceId): array
    {
        $empty = ['symbols' => 0, 'last_index' => null];

        try {
            if (! Schema::hasTable(self::SYMBOLS_TABLE)) {
                return $empty;
            }

            $query = DB::table(self::SYMBOLS_TABLE)->where('status', 'active');

            // Scope to THIS workspace when the read-model is W-1-keyed (post-migration).
            // Without the column the read-model predates multi-workspace — degrade to an
            // honest unscoped count rather than leaking or fabricating.
            if (Schema::hasColumn(self::SYMBOLS_TABLE, 'workspace_id')) {
                $query->where('workspace_id', $workspaceId);
            }

            $symbols = (int) (clone $query)->count();
            if ($symbols === 0) {
                return $empty;
            }

            $lastIndex = $this->lastIndexTimestamp(clone $query);

            return ['symbols' => $symbols, 'last_index' => $lastIndex];
        } catch (Throwable) {
            return $empty;
        }
    }

    /**
     * The most recent index time for the scoped symbols — prefers `indexed_at` (the real
     * index event) and falls back to `updated_at`. Returns null when neither is present.
     *
     * @param  \Illuminate\Database\Query\Builder  $scoped
     */
    private function lastIndexTimestamp($scoped): ?string
    {
        foreach (['indexed_at', 'updated_at'] as $column) {
            if (! Schema::hasColumn(self::SYMBOLS_TABLE, $column)) {
                continue;
            }
            $value = (clone $scoped)->max($column);
            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * Run the existing index command for a workspace. Uses the injected runner when set
     * (test seam); otherwise calls the real AWIS-gated index command with --workspace.
     *
     * @return array<string,mixed>
     */
    private function runIndex(string $workspacePath, string $workspaceId): array
    {
        if ($this->indexRunner !== null) {
            return ($this->indexRunner)($workspacePath, $workspaceId);
        }

        $exit = \Illuminate\Support\Facades\Artisan::call(self::ONBOARD_COMMAND, [
            'action' => 'index-code',
            '--workspace' => $workspacePath,
        ]);

        return [
            'ok' => $exit === 0,
            'reason' => $exit === 0 ? 'indexed' : 'index_command_nonzero',
            'exit_code' => $exit,
            'command' => self::ONBOARD_COMMAND.' index-code --workspace='.$workspacePath,
        ];
    }

    /**
     * @param  array<string,mixed>  $status
     * @param  array<string,mixed>  $opts
     * @param  array<string,mixed>|null  $run
     * @return array<string,mixed>
     */
    private function envelope(string $action, bool $triggered, array $status, array $opts, ?array $run = null): array
    {
        $envelope = [
            'ok' => $status['indexed'] || $action === 'already_indexed' || ($run['ok'] ?? false) === true,
            'schema' => self::SCHEMA,
            'action' => $action,
            'auto_onboard' => $this->autoOnboardEnabled(),
            'triggered_index' => $triggered,
            'status' => $status,
        ];
        if ($run !== null) {
            $envelope['index'] = $run;
        }

        $this->writeReceipt($action, [
            'workspace_id' => $status['workspace_id'],
            'indexed' => $status['indexed'],
            'symbols' => $status['symbols'],
            'auto_onboard' => $envelope['auto_onboard'],
            'triggered_index' => $triggered,
        ]);

        return $envelope;
    }

    private function autoOnboardEnabled(): bool
    {
        return (bool) config('atlas.aobg.auto_onboard', false);
    }

    /**
     * The exact index invocation offered to the caller. When we know the path, name it so
     * an operator/agent can copy-paste it; otherwise the bare command.
     */
    private function offeredCommand(?string $workspacePath): string
    {
        if (is_string($workspacePath) && $workspacePath !== '') {
            return self::ONBOARD_COMMAND.' index-code --workspace='.$workspacePath;
        }

        return self::ONBOARD_COMMAND.' index-code --workspace=<path>';
    }

    /**
     * @param  array<string,mixed>  $opts
     */
    private function resolveWorkspaceId(array $opts): string
    {
        try {
            $explicit = $this->stringOpt($opts, 'workspace');
            if ($explicit !== null) {
                // "path OR id": an existing path → its id; a stable id → verbatim, so a
                // status query by id reflects the SAME indexed workspace (not an empty
                // derived one). `cwd` is always a path.
                return $this->workspaceIdentity->resolveWorkspaceOrId($explicit);
            }
            $cwd = $this->stringOpt($opts, 'cwd');
            if ($cwd !== null) {
                return $this->workspaceIdentity->resolve($cwd);
            }

            return $this->workspaceIdentity->default();
        } catch (Throwable) {
            try {
                return $this->workspaceIdentity->default();
            } catch (Throwable) {
                return 'atlas-server';
            }
        }
    }

    /**
     * The concrete filesystem path of the workspace (for the offered index command + the
     * onboarding run). Prefers an explicit path-like `workspace`, then `cwd`. A bare id
     * (no path) yields null — the command is still offered with a <path> placeholder.
     *
     * @param  array<string,mixed>  $opts
     */
    private function resolvePath(array $opts): ?string
    {
        foreach (['workspace', 'cwd'] as $key) {
            $value = $this->stringOpt($opts, $key);
            if ($value !== null && (str_contains($value, '/') || is_dir($value))) {
                $real = realpath($value);

                return $real !== false ? $real : $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $opts
     */
    private function stringOpt(array $opts, string $key): ?string
    {
        $raw = $opts[$key] ?? null;
        if (! is_scalar($raw)) {
            return null;
        }
        $raw = trim((string) $raw);

        return $raw !== '' ? $raw : null;
    }

    /**
     * Append-only audit receipt for every status/onboard call (best-effort, never gates).
     *
     * @param  array<string,mixed>  $entry
     */
    private function writeReceipt(string $action, array $entry): void
    {
        try {
            $base = function_exists('storage_path')
                ? storage_path('atlas/governance')
                : sys_get_temp_dir().'/atlas/governance';
            AppendOnlyJsonlStore::appendUsingFilePutContents(
                $base.DIRECTORY_SEPARATOR.'aobg_workspace_onboarding.jsonl',
                array_merge(['schema' => self::SCHEMA, 'action' => $action, 'recorded_at' => now()->toJSON()], $entry),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                FILE_APPEND,
            );
        } catch (Throwable) {
            // audit logging is best-effort; the decision never depends on it.
        }
    }
}

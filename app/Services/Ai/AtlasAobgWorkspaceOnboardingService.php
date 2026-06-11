<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\AtlasCode\AtlasCodeWorkspaceProfileService;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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

    private const MANAGED_BLOCK_START = '<!-- atlas:aobg:auto-bootstrap:start -->';

    private const MANAGED_BLOCK_END = '<!-- atlas:aobg:auto-bootstrap:end -->';

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
        private readonly AtlasCodeWorkspaceProfileService $workspaceProfiles,
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
     *   onboard_command:string, activation_command:string, generated_at:string
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
            'activation_command' => $this->activationCommand($workspacePath),
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

    /**
     * Explicit workspace activation. This is the "open a folder and make it usable"
     * path: bind the folder into AWIS, write provider bootstrap files, then run the
     * existing AWIS-gated code index when the workspace has no symbols yet (or force).
     *
     * Unlike {@see onboard()}, this is NOT controlled by the global auto_onboard flag:
     * the caller/hook is explicitly asking to activate this workspace. Still local-only:
     * DB + filesystem bootstrap + local index, zero provider spend.
     *
     * @param  array<string,mixed>  $opts
     * @return array<string,mixed>
     */
    public function activate(array $opts = []): array
    {
        $workspacePath = $this->resolvePath($opts);
        if (! is_string($workspacePath) || trim($workspacePath) === '') {
            $status = $this->status($opts);

            return $this->activationEnvelope(
                'activation_blocked',
                false,
                $status,
                $status,
                $opts,
                ['ok' => false, 'reason' => 'workspace_path_required'],
            );
        }

        $workspacePath = rtrim($workspacePath, DIRECTORY_SEPARATOR);
        if (! is_dir($workspacePath)) {
            $status = $this->status($opts);

            return $this->activationEnvelope(
                'activation_blocked',
                false,
                $status,
                $status,
                $opts,
                ['ok' => false, 'reason' => 'workspace_path_not_directory', 'path' => $workspacePath],
            );
        }

        $workspaceId = $this->resolveWorkspaceId(['workspace' => $workspacePath]);
        $profile = $this->registerWorkspaceProfile($workspacePath, $workspaceId);
        $registeredSlug = $this->stringFromArray($profile, ['workspace', 'slug'])
            ?? $this->stringFromArray($profile, ['profile', 'slug']);
        if ($registeredSlug !== null) {
            $workspaceId = $registeredSlug;
        }

        $scopedOpts = array_merge($opts, ['workspace' => $workspacePath]);
        $before = $this->status($scopedOpts);
        $bootstrap = $this->writeProviderBootstrap(
            $workspacePath,
            (string) $before['workspace_id'],
            (bool) ($opts['force'] ?? false),
        );

        $force = (bool) ($opts['force'] ?? false);
        $shouldIndex = (bool) ($before['needs_onboarding'] ?? false) || $force;
        $run = ['ok' => true, 'reason' => 'index_not_needed'];
        if ($shouldIndex) {
            try {
                $run = $this->runIndex($workspacePath, (string) $before['workspace_id']);
            } catch (Throwable $e) {
                $run = ['ok' => false, 'reason' => 'index_failed', 'exception' => class_basename($e)];
            }
        }

        $after = $this->status($scopedOpts);
        $action = match (true) {
            ($profile['ok'] ?? false) !== true => 'activation_profile_blocked',
            ($bootstrap['ok'] ?? false) !== true => 'activation_bootstrap_incomplete',
            ($run['ok'] ?? false) !== true => 'activation_index_failed',
            $shouldIndex && (bool) ($after['indexed'] ?? false) => 'activated',
            $shouldIndex => 'activated_no_symbols',
            default => 'activated_already_indexed',
        };

        return $this->activationEnvelope($action, $shouldIndex, $after, $before, $opts, $profile, $bootstrap, $run);
    }

    /**
     * Explicitly activate every registered local workspace that exists on disk.
     *
     * This is the operator/UI "make the workspace registry real" sweep: every project
     * listed in Atlas Code gets the same activation contract as opening that folder
     * directly: AWIS profile binding, provider bootstrap files and CodeGraph indexing
     * when needed. Missing paths are reported, never invented.
     *
     * @param  array<string,mixed>  $opts
     * @return array<string,mixed>
     */
    public function activateAll(array $opts = []): array
    {
        $force = (bool) ($opts['force'] ?? false);
        $results = [];
        $seenPaths = [];
        $summary = [
            'total_profiles' => 0,
            'activated' => 0,
            'already_indexed' => 0,
            'indexed' => 0,
            'missing_path' => 0,
            'failed' => 0,
        ];

        foreach ($this->workspaceProfiles->listProfiles() as $profile) {
            if (($profile['status'] ?? 'active') !== 'active') {
                continue;
            }

            $summary['total_profiles']++;
            $workspacePath = $this->profilePath($profile);
            $slug = trim((string) ($profile['slug'] ?? ''));
            if ($workspacePath === null || ! is_dir($workspacePath)) {
                $summary['missing_path']++;
                $results[] = [
                    'ok' => false,
                    'action' => 'skipped_missing_workspace_path',
                    'workspace_id' => $slug !== '' ? $slug : null,
                    'workspace_path' => $workspacePath,
                ];

                continue;
            }

            $canonical = rtrim(realpath($workspacePath) ?: $workspacePath, DIRECTORY_SEPARATOR);
            if (isset($seenPaths[$canonical])) {
                continue;
            }
            $seenPaths[$canonical] = true;

            try {
                $result = $this->activate(['workspace' => $canonical, 'force' => $force]);
            } catch (Throwable $e) {
                $result = [
                    'ok' => false,
                    'action' => 'activation_exception',
                    'workspace_id' => $slug !== '' ? $slug : null,
                    'workspace_path' => $canonical,
                    'exception' => class_basename($e),
                ];
            }

            if (($result['ok'] ?? false) === true) {
                $summary['activated']++;
            } else {
                $summary['failed']++;
            }
            if (($result['triggered_index'] ?? false) === true) {
                $summary['indexed']++;
            }
            if (($result['action'] ?? null) === 'activated_already_indexed') {
                $summary['already_indexed']++;
            }

            $results[] = $result;
        }

        $envelope = [
            'ok' => $summary['failed'] === 0 && $summary['missing_path'] === 0,
            'schema' => self::SCHEMA,
            'action' => 'activate_all',
            'activation_explicit' => true,
            'auto_onboard' => $this->autoOnboardEnabled(),
            'summary' => $summary,
            'results' => $results,
            'generated_at' => now()->toJSON(),
        ];

        $this->writeReceipt('activate_all', [
            'workspace_id' => 'registry',
            'indexed' => $summary['indexed'] > 0,
            'symbols' => 0,
            'auto_onboard' => $envelope['auto_onboard'],
            'triggered_index' => $summary['indexed'] > 0,
            'activated' => $summary['activated'],
            'failed' => $summary['failed'],
        ]);

        return $envelope;
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

    /**
     * @param  array<string,mixed>  $status
     * @param  array<string,mixed>  $before
     * @param  array<string,mixed>  $opts
     * @param  array<string,mixed>  $profile
     * @param  array<string,mixed>|null  $bootstrap
     * @param  array<string,mixed>|null  $run
     * @return array<string,mixed>
     */
    private function activationEnvelope(
        string $action,
        bool $triggeredIndex,
        array $status,
        array $before,
        array $opts,
        array $profile,
        ?array $bootstrap = null,
        ?array $run = null,
    ): array {
        $ok = ($profile['ok'] ?? false) === true
            && ($bootstrap === null || ($bootstrap['ok'] ?? false) === true)
            && ($run === null || ($run['ok'] ?? false) === true);

        $envelope = [
            'ok' => $ok,
            'schema' => self::SCHEMA,
            'action' => $action,
            'auto_onboard' => $this->autoOnboardEnabled(),
            'activation_explicit' => true,
            'triggered_index' => $triggeredIndex,
            'status_before' => $before,
            'status' => $status,
            'workspace_id' => $status['workspace_id'] ?? $before['workspace_id'] ?? null,
            'workspace_path' => $status['workspace_path'] ?? $before['workspace_path'] ?? null,
            'profile' => $profile,
        ];

        if ($bootstrap !== null) {
            $envelope['provider_bootstrap'] = $bootstrap;
        }
        if ($run !== null) {
            $envelope['index'] = $run;
        }

        $this->writeReceipt($action, [
            'workspace_id' => (string) ($envelope['workspace_id'] ?? 'unknown'),
            'indexed' => (bool) ($status['indexed'] ?? false),
            'symbols' => (int) ($status['symbols'] ?? 0),
            'auto_onboard' => $envelope['auto_onboard'],
            'triggered_index' => $triggeredIndex,
        ]);

        return $envelope;
    }

    /**
     * @return array<string,mixed>
     */
    private function registerWorkspaceProfile(string $workspacePath, string $workspaceId): array
    {
        try {
            $existing = $this->exactProfileForPathOrSlug($workspacePath, $workspaceId);
            $slug = is_array($existing) && trim((string) ($existing['slug'] ?? '')) !== ''
                ? trim((string) $existing['slug'])
                : $this->profileSlugForNewWorkspace($workspaceId, $workspacePath);
            $configured = $this->configuredProfileBySlug($slug);
            $parent = $this->parentProfileForPath($workspacePath, $slug);
            $inferred = $this->inferWorkspaceProfile($workspacePath);
            $existingIsDiscovery = $this->isDiscoveryProfile($existing);
            $policy = is_array($configured)
                ? $configured
                : ($existingIsDiscovery && is_array($parent) ? $parent : null);

            $profile = $this->workspaceProfiles->upsertPersistedProfile([
                'slug' => $slug,
                'name' => $this->profileString($configured, 'name', $this->profileString($existing, 'name', $inferred['name'])),
                'kind' => $this->profileString($configured, 'kind', $this->profileString($existing, 'kind', 'product')),
                'workspace_path' => $workspacePath,
                'repo_root' => $workspacePath,
                'production_status' => $this->profileString($policy, 'production_status', $this->profileString($existing, 'production_status', 'development')),
                'stack_summary' => $this->profileString($configured, 'stack_summary', $existingIsDiscovery ? $inferred['stack_summary'] : $this->profileString($existing, 'stack_summary', $inferred['stack_summary'])),
                'commands' => $this->profileMap($configured, 'commands', $this->profileMap($existing, 'commands', [])),
                'test_commands' => $this->profileList($existing, 'test_commands', $this->profileList($configured, 'test_commands', $inferred['test_commands'])),
                'build_commands' => $this->profileList($existing, 'build_commands', $this->profileList($configured, 'build_commands', $inferred['build_commands'])),
                'dev_server_command' => $this->profileString($existing, 'dev_server_command', null),
                'critical_areas' => $this->profileList($existing, 'critical_areas', $inferred['critical_areas']),
                'docs_status' => $this->profileString($policy, 'docs_status', $this->profileString($existing, 'docs_status', 'unknown')),
                'default_risk' => $this->profileString($policy, 'default_risk', $this->profileString($existing, 'default_risk', 'medium')),
                'deployment_notes' => $this->profileString($policy, 'deployment_notes', $this->profileString($existing, 'deployment_notes', '')),
                'surfaces_enabled' => $this->profileList($policy, 'surfaces_enabled', $this->profileList($existing, 'surfaces_enabled', ['atlas_ai', 'cartografia', 'code', 'atencao'])),
                'source' => 'aobg_workspace_activation',
                'status' => 'active',
            ]);

            return [
                'ok' => true,
                'reason' => 'workspace_profile_registered',
                'workspace' => $profile,
                'preserved_existing_profile' => is_array($existing),
                'preserved_config_profile' => is_array($configured),
                'inherited_parent_policy' => ! is_array($configured) && $existingIsDiscovery && is_array($parent),
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'reason' => 'workspace_profile_registration_failed',
                'exception' => class_basename($e),
            ];
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function exactProfileForPathOrSlug(string $workspacePath, string $workspaceId): ?array
    {
        $byPath = $this->workspaceProfiles->findByPath($workspacePath);
        if (is_array($byPath)) {
            return $byPath;
        }

        $bySlug = $this->workspaceProfiles->findBySlug($workspaceId);
        if (! is_array($bySlug)) {
            return null;
        }

        $path = $this->profilePath($bySlug);
        if ($path !== null && $this->samePath($path, $workspacePath)) {
            return $bySlug;
        }

        return null;
    }

    private function profileSlugForNewWorkspace(string $workspaceId, string $workspacePath): string
    {
        $parent = $this->parentProfileForPath($workspacePath, null);
        $parentSlug = trim((string) ($parent['slug'] ?? ''));
        $candidate = $parentSlug !== '' && $this->sameWorkspaceId($workspaceId, $parentSlug)
            ? $this->slugFromPath($workspacePath)
            : $this->profileSlug($workspaceId, $workspacePath);

        $existing = $this->workspaceProfiles->findBySlug($candidate);
        $existingPath = is_array($existing) ? $this->profilePath($existing) : null;
        if ($existingPath !== null && ! $this->samePath($existingPath, $workspacePath)) {
            $candidate = rtrim(substr($candidate, 0, 111), '-').'-'.substr(hash('sha256', $workspacePath), 0, 8);
        }

        return $candidate;
    }

    private function slugFromPath(string $workspacePath): string
    {
        $base = strtolower(trim(basename($workspacePath)));
        $base = preg_replace('/[^a-z0-9-]+/', '-', $base) ?? '';
        $base = trim($base, '-');
        if (strlen($base) < 3) {
            return 'workspace-'.substr(hash('sha256', $workspacePath), 0, 8);
        }

        return substr($base, 0, 120);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function parentProfileForPath(string $workspacePath, ?string $childSlug): ?array
    {
        $workspacePath = rtrim(realpath($workspacePath) ?: $workspacePath, DIRECTORY_SEPARATOR);
        $childSlug = $childSlug !== null ? trim(strtolower($childSlug)) : null;
        $best = null;
        $bestLength = -1;

        foreach ($this->workspaceProfiles->listProfiles() as $profile) {
            $slug = trim(strtolower((string) ($profile['slug'] ?? '')));
            if ($childSlug !== null && $slug === $childSlug) {
                continue;
            }
            $root = $this->profilePath($profile);
            if ($root === null) {
                continue;
            }
            $root = rtrim(realpath($root) ?: $root, DIRECTORY_SEPARATOR);
            if ($workspacePath === $root || ! str_starts_with($workspacePath, $root.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $length = strlen($root);
            if ($length > $bestLength) {
                $best = $profile;
                $bestLength = $length;
            }
        }

        return $best;
    }

    /**
     * @param  array<string,mixed>|null  $profile
     */
    private function isDiscoveryProfile(?array $profile): bool
    {
        return is_array($profile)
            && trim((string) ($profile['source'] ?? '')) === 'atlas-code-graph-index-all';
    }

    /**
     * @return array{name:string,stack_summary:string,test_commands:array<int,string>,build_commands:array<int,string>,critical_areas:array<int,string>}
     */
    private function inferWorkspaceProfile(string $workspacePath): array
    {
        $roots = $this->candidateProjectRoots($workspacePath);
        $testCommands = [];
        $buildCommands = [];
        $criticalAreas = [];
        $stack = [];

        foreach ($roots as $root) {
            $relative = $this->relativePath($workspacePath, $root);
            $prefix = $relative === '.' ? '' : 'cd '.$relative.' && ';
            if ($relative !== '.') {
                $criticalAreas[] = $relative;
            }

            $package = $this->packageScripts($root);
            if ($package !== []) {
                $runner = $this->nodeRunner($root);
                $stack[] = 'node';
                if (isset($package['test']) && ! str_contains(strtolower((string) $package['test']), 'no test specified')) {
                    $testCommands[] = $prefix.$runner.' run test';
                }
                if (isset($package['build'])) {
                    $buildCommands[] = $prefix.$runner.' run build';
                }
                if (is_file($root.DIRECTORY_SEPARATOR.'app.json') || is_file($root.DIRECTORY_SEPARATOR.'app.config.ts')) {
                    $stack[] = 'expo';
                }
            }

            if (is_file($root.DIRECTORY_SEPARATOR.'composer.json') || is_file($root.DIRECTORY_SEPARATOR.'artisan')) {
                $stack[] = is_file($root.DIRECTORY_SEPARATOR.'artisan') ? 'laravel' : 'php';
                if (is_file($root.DIRECTORY_SEPARATOR.'artisan')) {
                    $testCommands[] = $prefix.'php artisan test';
                }
            }

            if ($this->looksLikeStaticWebRoot($root)) {
                $stack[] = 'static-web';
                if ($this->hasFileMatching($root, ['*.js', 'tests/*.test.js', 'tests/*.test.mjs', 'tests/*.spec.js', 'tests/*.spec.mjs'])) {
                    $stack[] = 'javascript';
                }
                foreach ($this->staticWebTestCommands($root, $prefix) as $command) {
                    $testCommands[] = $command;
                }
            }
        }

        $stack = array_values(array_unique($stack));

        return [
            'name' => $this->humanName(basename($workspacePath) ?: 'workspace'),
            'stack_summary' => $stack === [] ? '' : implode(', ', $stack),
            'test_commands' => array_values(array_unique(array_slice($testCommands, 0, 12))),
            'build_commands' => array_values(array_unique(array_slice($buildCommands, 0, 12))),
            'critical_areas' => array_values(array_unique(array_slice($criticalAreas, 0, 24))),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function candidateProjectRoots(string $workspacePath): array
    {
        $roots = [$workspacePath];
        try {
            foreach (File::directories($workspacePath) as $directory) {
                $base = basename($directory);
                if ($base === '' || str_starts_with($base, '.') || in_array($base, ['node_modules', 'vendor', 'storage'], true)) {
                    continue;
                }
                if ($this->looksLikeProjectRoot($directory)) {
                    $roots[] = $directory;
                }
            }
        } catch (Throwable) {
            return $roots;
        }

        return array_values(array_unique(array_slice($roots, 0, 18)));
    }

    private function looksLikeProjectRoot(string $directory): bool
    {
        foreach (['package.json', 'composer.json', 'artisan', 'vite.config.ts', 'vite.config.js', 'pyproject.toml', 'Cargo.toml'] as $marker) {
            if (is_file($directory.DIRECTORY_SEPARATOR.$marker)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeStaticWebRoot(string $root): bool
    {
        if (is_file($root.DIRECTORY_SEPARATOR.'index.html')) {
            return true;
        }

        return $this->hasFileMatching($root, ['*.html', 'css/*.css', 'tests/*.test.mjs', 'tests/*.test.js']);
    }

    /**
     * @param  array<int,string>  $patterns
     */
    private function hasFileMatching(string $root, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($this->globFiles($root, $pattern) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int,string>
     */
    private function staticWebTestCommands(string $root, string $prefix): array
    {
        $tests = [];
        foreach (['tests/*.test.mjs', 'tests/*.test.js', 'tests/*.spec.mjs', 'tests/*.spec.js'] as $pattern) {
            foreach ($this->globFiles($root, $pattern) as $file) {
                $relative = $this->relativePath($root, $file);
                if ($relative !== '.') {
                    $tests[] = $relative;
                }
            }
        }
        $tests = array_values(array_unique(array_slice($tests, 0, 8)));
        if ($tests === []) {
            return [];
        }

        return [$prefix.'node --test '.implode(' ', $tests)];
    }

    /**
     * @return array<int,string>
     */
    private function globFiles(string $root, string $pattern): array
    {
        $matches = glob(rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$pattern);
        if (! is_array($matches)) {
            return [];
        }

        return array_values(array_filter($matches, static fn (string $path): bool => is_file($path)));
    }

    /**
     * @return array<string,mixed>
     */
    private function packageScripts(string $root): array
    {
        $path = $root.DIRECTORY_SEPARATOR.'package.json';
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        try {
            $json = json_decode((string) file_get_contents($path), true);
            $scripts = is_array($json) && is_array($json['scripts'] ?? null) ? $json['scripts'] : [];

            return $scripts;
        } catch (Throwable) {
            return [];
        }
    }

    private function nodeRunner(string $root): string
    {
        if (is_file($root.DIRECTORY_SEPARATOR.'pnpm-lock.yaml')) {
            return 'pnpm';
        }
        if (is_file($root.DIRECTORY_SEPARATOR.'yarn.lock')) {
            return 'yarn';
        }
        if (is_file($root.DIRECTORY_SEPARATOR.'bun.lockb')) {
            return 'bun';
        }

        return 'npm';
    }

    private function relativePath(string $base, string $path): string
    {
        $base = rtrim(realpath($base) ?: $base, DIRECTORY_SEPARATOR);
        $path = rtrim(realpath($path) ?: $path, DIRECTORY_SEPARATOR);
        if ($path === $base) {
            return '.';
        }
        if (str_starts_with($path, $base.DIRECTORY_SEPARATOR)) {
            return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($base) + 1));
        }

        return $path;
    }

    /**
     * @return array<string,mixed>
     */
    private function writeProviderBootstrap(string $workspacePath, string $workspaceId, bool $force = false): array
    {
        $files = [
            'mcp' => $this->upsertMcpJson($workspacePath, $force),
            'claude_settings' => $this->upsertClaudeSettings($workspacePath),
            'agents' => $this->upsertProviderDoc($workspacePath, 'AGENTS.md', $workspaceId),
            'claude' => $this->upsertProviderDoc($workspacePath, 'CLAUDE.md', $workspaceId),
        ];

        $ok = collect($files)->every(fn (array $result): bool => ($result['ok'] ?? false) === true);

        return [
            'ok' => $ok,
            'reason' => $ok ? 'provider_bootstrap_ready' : 'provider_bootstrap_incomplete',
            'atlas_server_dir' => base_path(),
            'files' => $files,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function upsertMcpJson(string $workspacePath, bool $force): array
    {
        $path = $workspacePath.DIRECTORY_SEPARATOR.'.mcp.json';
        $json = $this->readJsonObject($path);
        if ($json === null) {
            return ['ok' => false, 'path' => $path, 'action' => 'skipped_invalid_json'];
        }

        $before = $json;
        if (! is_array($json['mcpServers'] ?? null)) {
            $json['mcpServers'] = [];
        }
        $server = [
            'command' => base_path('bin/atlas'),
            'args' => ['open-brain', 'mcp'],
        ];
        if ($force || (($json['mcpServers']['atlas-open-brain'] ?? null) !== $server)) {
            $json['mcpServers']['atlas-open-brain'] = $server;
        }

        return $this->writeJsonIfChanged($path, $before, $json);
    }

    /**
     * @return array<string,mixed>
     */
    private function upsertClaudeSettings(string $workspacePath): array
    {
        $path = $workspacePath.DIRECTORY_SEPARATOR.'.claude'.DIRECTORY_SEPARATOR.'settings.json';
        $json = $this->readJsonObject($path);
        if ($json === null) {
            return ['ok' => false, 'path' => $path, 'action' => 'skipped_invalid_json'];
        }

        $before = $json;
        $json['$comment'] = is_string($json['$comment'] ?? null)
            ? $json['$comment']
            : 'Atlas Open Brain Gateway hooks installed by workspace activation. Hooks live in atlas-server and scope calls to the opened workspace.';
        if (! is_array($json['hooks'] ?? null)) {
            $json['hooks'] = [];
        }

        $hookBase = base_path('.claude/hooks');
        $json = $this->ensureClaudeHook($json, 'UserPromptSubmit', $hookBase.'/atlas-ctx.sh');
        $json = $this->ensureClaudeHook($json, 'PreToolUse', $hookBase.'/atlas-pretooluse-guard.sh', 'Edit|Write|MultiEdit|NotebookEdit');
        $json = $this->ensureClaudeHook($json, 'PostToolUse', $hookBase.'/atlas-postedit-context.sh', 'Read|Edit|Write|MultiEdit|NotebookEdit');
        $json = $this->ensureClaudeHook($json, 'Stop', $hookBase.'/atlas-session-capture.sh');

        return $this->writeJsonIfChanged($path, $before, $json);
    }

    /**
     * @param  array<string,mixed>  $settings
     * @return array<string,mixed>
     */
    private function ensureClaudeHook(array $settings, string $event, string $command, ?string $matcher = null): array
    {
        $groups = is_array($settings['hooks'][$event] ?? null) ? $settings['hooks'][$event] : [];
        foreach ($groups as $group) {
            if (! is_array($group) || ! is_array($group['hooks'] ?? null)) {
                continue;
            }
            foreach ($group['hooks'] as $hook) {
                if (is_array($hook) && ($hook['command'] ?? null) === $command) {
                    $settings['hooks'][$event] = $groups;

                    return $settings;
                }
            }
        }

        $group = ['hooks' => [['type' => 'command', 'command' => $command]]];
        if ($matcher !== null) {
            $group = ['matcher' => $matcher] + $group;
        }
        $groups[] = $group;
        $settings['hooks'][$event] = $groups;

        return $settings;
    }

    /**
     * @return array<string,mixed>|null null means invalid JSON; [] means missing file.
     */
    private function readJsonObject(string $path): ?array
    {
        if (! is_file($path)) {
            return [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $before
     * @param  array<string,mixed>  $after
     * @return array<string,mixed>
     */
    private function writeJsonIfChanged(string $path, array $before, array $after): array
    {
        $action = is_file($path) ? 'unchanged' : 'created';
        if ($before !== $after || ! is_file($path)) {
            $this->writeTextFile($path, (string) json_encode($after, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
            $action = is_file($path) && $before !== [] ? 'updated' : 'created';
        }

        return ['ok' => true, 'path' => $path, 'action' => $action];
    }

    /**
     * @return array<string,mixed>
     */
    private function upsertProviderDoc(string $workspacePath, string $filename, string $workspaceId): array
    {
        $path = $workspacePath.DIRECTORY_SEPARATOR.$filename;
        $block = $this->providerBootstrapBlock($workspaceId, $workspacePath, $filename);
        $contents = is_file($path) ? (string) file_get_contents($path) : '';
        $action = 'unchanged';

        if ($contents === '') {
            $contents = '# '.$filename.' generated by Atlas AOBG activation'.PHP_EOL.PHP_EOL.$block.PHP_EOL;
            $action = 'created';
        } elseif (str_contains($contents, self::MANAGED_BLOCK_START) && str_contains($contents, self::MANAGED_BLOCK_END)) {
            $updated = preg_replace(
                '/'.preg_quote(self::MANAGED_BLOCK_START, '/').'.*?'.preg_quote(self::MANAGED_BLOCK_END, '/').'/s',
                $block,
                $contents,
            ) ?? $contents;
            if ($updated !== $contents) {
                $contents = $updated;
                $action = 'updated';
            }
        } else {
            $contents = rtrim($contents).PHP_EOL.PHP_EOL.$block.PHP_EOL;
            $action = 'appended';
        }

        if ($action !== 'unchanged') {
            $this->writeTextFile($path, $contents);
        }

        return ['ok' => true, 'path' => $path, 'action' => $action];
    }

    private function providerBootstrapBlock(string $workspaceId, string $workspacePath, string $filename): string
    {
        $atlas = base_path('bin/atlas');

        return self::MANAGED_BLOCK_START.PHP_EOL
            .'## Atlas Open Brain Gateway'.PHP_EOL
            .'- This workspace is activated as `'.$workspaceId.'` at `'.$workspacePath.'`.'.PHP_EOL
            .'- Atlas memory/context is canonical; this provider file is only a compact bootstrap.'.PHP_EOL
            .'- At session start or before context-sensitive implementation, run `'.$atlas.' aobg workspace activate --json` from this workspace.'.PHP_EOL
            .'- Before architecture or implementation work, request context with `'.$atlas.' open-brain context "<task>" --json` or MCP `atlas_context_pack`.'.PHP_EOL
            .'- If MCP native transport fails, use the CLI fallback above; it scopes to the current directory automatically.'.PHP_EOL
            .'- Treat AOBG output as provider-safe curated top-K context, then verify with direct file reads, `rg`, tests, and Atlas gates.'.PHP_EOL
            .'- Do not expose Atlas internal ids, traces, prompts, or provider details unless the operator asks for audit.'.PHP_EOL
            .PHP_EOL
            .'Provider target: `'.$filename.'`.'.PHP_EOL
            .self::MANAGED_BLOCK_END;
    }

    private function writeTextFile(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (! is_dir($directory)) {
            File::ensureDirectoryExists($directory);
        }
        File::put($path, $contents);
    }

    /**
     * @param  array<string,mixed>  $profile
     */
    private function profilePath(array $profile): ?string
    {
        foreach (['workspace_path', 'repo_root'] as $key) {
            $path = trim((string) ($profile[$key] ?? ''));
            if ($path !== '') {
                return rtrim(realpath($path) ?: $path, DIRECTORY_SEPARATOR);
            }
        }

        return null;
    }

    private function samePath(string $left, string $right): bool
    {
        return rtrim(realpath($left) ?: $left, DIRECTORY_SEPARATOR)
            === rtrim(realpath($right) ?: $right, DIRECTORY_SEPARATOR);
    }

    private function sameWorkspaceId(string $left, string $right): bool
    {
        return $this->profileSlug($left, $left) === $this->profileSlug($right, $right);
    }

    private function profileSlug(string $workspaceId, string $workspacePath): string
    {
        $slug = strtolower(trim($workspaceId));
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if (strlen($slug) < 3) {
            $slug = 'workspace-'.substr(hash('sha256', $workspacePath), 0, 8);
        }
        $slug = substr($slug, 0, 120);
        $slug = trim($slug, '-');

        return strlen($slug) >= 3 ? $slug : 'workspace-'.substr(hash('sha256', $workspacePath), 0, 8);
    }

    private function humanName(string $value): string
    {
        $value = trim(preg_replace('/[^A-Za-z0-9]+/', ' ', $value) ?? $value);

        return $value === '' ? 'Workspace' : ucwords(strtolower($value));
    }

    /**
     * @param  array<string,mixed>|null  $profile
     */
    private function profileString(?array $profile, string $key, ?string $fallback): ?string
    {
        $value = is_array($profile) ? ($profile[$key] ?? null) : null;
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return $fallback;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function configuredProfileBySlug(string $slug): ?array
    {
        try {
            $profiles = (array) config('atlas_projects.profiles', []);
            foreach ($profiles as $profile) {
                if (! is_array($profile)) {
                    continue;
                }
                if (trim(strtolower((string) ($profile['slug'] ?? $profile['id'] ?? ''))) === trim(strtolower($slug))) {
                    return $profile;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @param  array<string,mixed>|null  $profile
     * @param  array<int,string>  $fallback
     * @return array<int,string>
     */
    private function profileList(?array $profile, string $key, array $fallback): array
    {
        $value = is_array($profile) ? ($profile[$key] ?? null) : null;
        if (is_array($value)) {
            $list = [];
            foreach ($value as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $list[] = trim($item);
                }
            }
            if ($list !== []) {
                return array_values(array_unique($list));
            }
        }

        return $fallback;
    }

    /**
     * @param  array<string,mixed>|null  $profile
     * @param  array<string,string>  $fallback
     * @return array<string,string>
     */
    private function profileMap(?array $profile, string $key, array $fallback): array
    {
        $value = is_array($profile) ? ($profile[$key] ?? null) : null;
        if (! is_array($value)) {
            return $fallback;
        }

        $out = [];
        foreach ($value as $mapKey => $mapValue) {
            if (! is_string($mapKey) || ! is_string($mapValue)) {
                continue;
            }
            if (trim($mapKey) === '' || trim($mapValue) === '') {
                continue;
            }
            $out[trim($mapKey)] = trim($mapValue);
        }

        return $out !== [] ? $out : $fallback;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<int,string>  $path
     */
    private function stringFromArray(array $payload, array $path): ?string
    {
        $value = $payload;
        foreach ($path as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
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

    private function activationCommand(?string $workspacePath): string
    {
        $command = base_path('bin/atlas').' aobg workspace activate --json';
        if (is_string($workspacePath) && $workspacePath !== '') {
            return $command.' --workspace='.$workspacePath;
        }

        return $command.' --workspace=<path>';
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
        $workspace = $this->stringOpt($opts, 'workspace');
        if ($workspace !== null) {
            $path = $this->pathLikeValue($workspace) ?? $this->pathFromWorkspaceProfile($workspace);
            if ($path !== null) {
                return $path;
            }
        }

        $cwd = $this->stringOpt($opts, 'cwd');

        return $cwd !== null ? $this->pathLikeValue($cwd) : null;
    }

    private function pathLikeValue(string $value): ?string
    {
        if (! str_contains($value, '/') && ! is_dir($value)) {
            return null;
        }

        $real = realpath($value);

        return $real !== false ? $real : $value;
    }

    private function pathFromWorkspaceProfile(string $workspace): ?string
    {
        try {
            if (! function_exists('app')) {
                return null;
            }

            $profile = app(AtlasCodeWorkspaceProfileService::class)->findByReference($workspace);
            foreach (['workspace_path', 'repo_root'] as $key) {
                $path = is_array($profile) ? trim((string) ($profile[$key] ?? '')) : '';
                if ($path === '') {
                    continue;
                }

                return $this->pathLikeValue($path) ?? $path;
            }
        } catch (Throwable) {
            return null;
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

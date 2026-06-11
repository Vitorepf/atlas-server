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

    private const MODULES_TABLE = 'atlas_engineering_code_modules';

    private const DOC_LINKS_TABLE = 'atlas_engineering_doc_links';

    private const FILE_SNAPSHOTS_TABLE = 'atlas_engineering_code_file_snapshots';

    private const MANAGED_BLOCK_START = '<!-- atlas:aobg:auto-bootstrap:start -->';

    private const MANAGED_BLOCK_END = '<!-- atlas:aobg:auto-bootstrap:end -->';

    private const MAP_DETAIL_SUMMARY = 'summary';

    private const MAP_DETAIL_SAMPLES = 'samples';

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
        private readonly AtlasProviderProjectionService $providerProjection,
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
     *   symbols:int, last_index:?string, needs_onboarding:bool, needs_reindex:bool, auto_onboard:bool,
     *   onboard_command:string, activation_command:string, generated_at:string
     * }
     */
    public function status(array $opts = []): array
    {
        $workspacePath = $this->resolvePath($opts);
        $workspaceId = $this->resolveWorkspaceId($opts);
        $counts = $this->symbolCounts($workspaceId);

        $indexed = $counts['symbols'] > 0;
        $freshness = $this->freshnessStatus($workspacePath, $workspaceId, $counts['last_index']);
        $needsReindex = $indexed && ($freshness['status'] ?? null) === 'stale';

        return [
            'schema' => self::SCHEMA,
            'workspace_id' => $workspaceId,
            'workspace_path' => $workspacePath,
            'indexed' => $indexed,
            'symbols' => $counts['symbols'],
            'last_index' => $counts['last_index'],
            'needs_onboarding' => ! $indexed,
            'needs_reindex' => $needsReindex,
            'freshness_status' => $freshness['status'],
            'freshness' => $freshness,
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
        $needsRun = $status['needs_onboarding'] || $status['needs_reindex'] || $force;

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
        $shouldIndex = (bool) ($before['needs_onboarding'] ?? false)
            || (bool) ($before['needs_reindex'] ?? false)
            || $force;
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
            $shouldIndex && (bool) ($after['needs_reindex'] ?? false) => 'activation_index_still_stale',
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

    /**
     * Read-only fleet map for every configured local workspace.
     *
     * This is the compact "are the project folders actually brain-ready?" audit that
     * Atlas, Codex and Claude Code can call before choosing a specific workspace. It
     * never indexes and never returns raw file content; it aggregates the same per-
     * workspace readiness used by {@see map()} into a bounded provider-safe table.
     *
     * @param  array<string,mixed>  $opts
     * @return array<string,mixed>
     */
    public function mapAll(array $opts = []): array
    {
        $limit = $this->boundedLimit($opts['limit'] ?? null, 12, 50);
        $detail = $this->mapDetail($opts['detail'] ?? null);
        $results = [];
        $blockers = [];
        $warnings = [];
        $summary = [
            'total_profiles' => 0,
            'existing_path' => 0,
            'missing_path' => 0,
            'ready' => 0,
            'limited' => 0,
            'blocked' => 0,
            'needs_onboarding' => 0,
            'needs_reindex' => 0,
            'safe_for_initial_context' => 0,
            'safe_for_implementation' => 0,
            'symbol_count' => 0,
            'module_count' => 0,
            'file_count' => 0,
            'doc_link_count' => 0,
            'route_count' => 0,
            'command_count' => 0,
            'migration_count' => 0,
            'test_count' => 0,
        ];

        foreach ($this->workspaceProfiles->listProfiles() as $profile) {
            if (($profile['status'] ?? 'active') !== 'active') {
                continue;
            }

            $summary['total_profiles']++;
            $workspacePath = $this->profilePath($profile);
            if ($workspacePath === null || ! is_dir($workspacePath)) {
                $summary['missing_path']++;
                $row = $this->missingWorkspaceFleetRow($profile, $workspacePath);
            } else {
                $summary['existing_path']++;
                try {
                    $row = $this->workspaceFleetRow(
                        $this->map([
                            'workspace' => rtrim(realpath($workspacePath) ?: $workspacePath, DIRECTORY_SEPARATOR),
                            'limit' => $limit,
                            'detail' => self::MAP_DETAIL_SUMMARY,
                        ]),
                        $profile,
                    );
                } catch (Throwable $e) {
                    $row = [
                        'workspace_id' => $this->stringFromArray($profile, ['slug']),
                        'profile_slug' => $this->stringFromArray($profile, ['slug']),
                        'name' => $this->stringFromArray($profile, ['name']),
                        'kind' => $this->stringFromArray($profile, ['kind']),
                        'workspace_path' => $workspacePath,
                        'path_exists' => true,
                        'readiness_status' => 'blocked',
                        'safe_for_initial_context' => false,
                        'safe_for_implementation' => false,
                        'readiness_blockers' => ['workspace_map_failed'],
                        'readiness_warnings' => [],
                        'exception' => class_basename($e),
                        'next_actions' => [$this->workspaceMapCommand($workspacePath, $limit, self::MAP_DETAIL_SUMMARY)],
                    ];
                }
            }

            $readiness = (string) ($row['readiness_status'] ?? 'blocked');
            if (! in_array($readiness, ['ready', 'limited', 'blocked'], true)) {
                $readiness = 'blocked';
                $row['readiness_status'] = $readiness;
            }
            $summary[$readiness]++;
            $summary['needs_onboarding'] += (bool) ($row['needs_onboarding'] ?? false) ? 1 : 0;
            $summary['needs_reindex'] += (bool) ($row['needs_reindex'] ?? false) ? 1 : 0;
            $summary['safe_for_initial_context'] += (bool) ($row['safe_for_initial_context'] ?? false) ? 1 : 0;
            $summary['safe_for_implementation'] += (bool) ($row['safe_for_implementation'] ?? false) ? 1 : 0;

            foreach (['symbol_count', 'module_count', 'file_count', 'doc_link_count', 'route_count', 'command_count', 'migration_count', 'test_count'] as $metric) {
                $summary[$metric] += (int) ($row[$metric] ?? 0);
            }
            foreach ((array) ($row['readiness_blockers'] ?? []) as $blocker) {
                if (is_string($blocker) && $blocker !== '') {
                    $blockers[] = $blocker;
                }
            }
            foreach ((array) ($row['readiness_warnings'] ?? []) as $warning) {
                if (is_string($warning) && $warning !== '') {
                    $warnings[] = $warning;
                }
            }

            $results[] = $row;
        }

        if ($summary['total_profiles'] === 0) {
            $blockers[] = 'workspace_registry_empty';
        }

        $blockers = array_values(array_unique($blockers));
        $warnings = array_values(array_unique($warnings));
        $readiness = $summary['total_profiles'] === 0 || $summary['blocked'] > 0
            ? 'blocked'
            : ($summary['limited'] > 0 ? 'limited' : 'ready');

        return [
            'ok' => true,
            'schema' => self::SCHEMA,
            'fleet_schema' => 'atlas.aobg.workspace_fleet_map.v1',
            'action' => 'map_all',
            'read_only' => true,
            'provider_safe' => true,
            'detail' => $detail,
            'summary' => $summary,
            'readiness_status' => $readiness,
            'safe_for_initial_context' => $readiness !== 'blocked',
            'safe_for_implementation' => $readiness === 'ready',
            'readiness_blockers' => $blockers,
            'readiness_warnings' => $warnings,
            'workspaces' => $results,
            'sample_policy' => [
                'included' => false,
                'reason' => 'fleet_map_defers_workspace_samples',
                'deferred_sections' => ['modules', 'path_regions', 'examples'],
                'request_samples' => 'atlas aobg workspace map --workspace=<workspace> --detail=samples --limit='.$limit.' --json',
                'requested_detail' => $detail,
            ],
            'next_actions' => $this->fleetNextActions($summary, $blockers, $warnings, $limit),
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * Compact, provider-safe map of what the Atlas brain knows about one workspace.
     *
     * This is intentionally read-only and bounded: it uses the already-built Code
     * Intelligence read-model instead of triggering an index, and returns counts +
     * representative samples so external providers can orient before asking for a
     * focused context pack.
     *
     * @param  array<string,mixed>  $opts
     * @return array<string,mixed>
     */
    public function map(array $opts = []): array
    {
        $status = $this->status($opts);
        $workspaceId = (string) ($status['workspace_id'] ?? $this->resolveWorkspaceId($opts));
        $workspacePath = is_string($status['workspace_path'] ?? null) ? (string) $status['workspace_path'] : null;
        $limit = $this->boundedLimit($opts['limit'] ?? null, 12, 50);
        $detail = $this->mapDetail($opts['detail'] ?? null);
        $includeSamples = $detail === self::MAP_DETAIL_SAMPLES;
        $profile = $this->workspaceProfiles->findByReference($workspacePath ?? $workspaceId);
        $inventory = $this->workspaceInventory($workspaceId);
        $providerProjection = $this->providerProjectionStatus($workspacePath);
        $readiness = $this->workspaceReadiness($status, $inventory, $providerProjection);

        return [
            'ok' => true,
            'schema' => self::SCHEMA,
            'map_schema' => 'atlas.aobg.workspace_map.v1',
            'action' => 'map',
            'read_only' => true,
            'provider_safe' => true,
            'detail' => $detail,
            'workspace_id' => $workspaceId,
            'workspace_path' => $workspacePath,
            'indexed' => (bool) ($status['indexed'] ?? false),
            'needs_onboarding' => (bool) ($status['needs_onboarding'] ?? true),
            'last_index' => $status['last_index'] ?? null,
            'freshness_status' => $status['freshness_status'] ?? 'unknown',
            'freshness' => $status['freshness'] ?? null,
            'needs_reindex' => (bool) ($status['needs_reindex'] ?? false),
            'workspace_readiness' => $readiness,
            'readiness_status' => (string) ($readiness['status'] ?? 'unknown'),
            'safe_for_initial_context' => (bool) ($readiness['safe_for_initial_context'] ?? false),
            'safe_for_implementation' => (bool) ($readiness['safe_for_implementation'] ?? false),
            'readiness_blockers' => array_values((array) ($readiness['blockers'] ?? [])),
            'readiness_warnings' => array_values((array) ($readiness['warnings'] ?? [])),
            'profile' => $profile === null ? null : [
                'slug' => $profile['slug'] ?? null,
                'name' => $profile['name'] ?? null,
                'kind' => $profile['kind'] ?? null,
                'production_status' => $profile['production_status'] ?? null,
                'stack_summary' => $profile['stack_summary'] ?? null,
                'docs_status' => $profile['docs_status'] ?? null,
                'default_risk' => $profile['default_risk'] ?? null,
                'safety' => $profile['safety'] ?? null,
                'commands' => $profile['commands'] ?? [],
                'test_commands' => $profile['test_commands'] ?? [],
                'build_commands' => $profile['build_commands'] ?? [],
                'critical_areas' => $profile['critical_areas'] ?? [],
                'surfaces_enabled' => $profile['surfaces_enabled'] ?? [],
            ],
            'inventory' => $inventory,
            'modules' => $includeSamples ? $this->topModules($workspaceId, $limit) : [],
            'path_regions' => $includeSamples ? $this->pathRegions($workspaceId, $limit) : [],
            'examples' => $includeSamples ? [
                'routes' => $this->sampleSymbols($workspaceId, ['route', 'api_resource'], $limit),
                'commands' => $this->sampleSymbols($workspaceId, ['cli_command'], $limit),
                'migrations' => $this->sampleSymbols($workspaceId, ['migration_table'], $limit),
                'tests' => $this->sampleSymbols($workspaceId, ['test_method'], $limit),
                'entrypoints' => $this->sampleSymbols($workspaceId, ['class', 'function'], min($limit, 10)),
            ] : [
                'routes' => [],
                'commands' => [],
                'migrations' => [],
                'tests' => [],
                'entrypoints' => [],
            ],
            'sample_policy' => [
                'included' => $includeSamples,
                'reason' => $includeSamples ? 'requested_detail_samples' : 'summary_default_defers_samples',
                'deferred_sections' => $includeSamples ? [] : ['modules', 'path_regions', 'examples'],
                'request_samples' => $this->workspaceMapCommand($workspacePath ?? $workspaceId, $limit, self::MAP_DETAIL_SAMPLES),
            ],
            'provider_projection' => $providerProjection,
            'next_actions' => $this->workspaceNextActions($status, $readiness, $workspacePath ?? $workspaceId, $limit),
            'quality' => $this->mapQuality($status, $inventory, $workspacePath),
            'generated_at' => now()->toJSON(),
        ];
    }

    // ------------------------------------------------------------------
    // internals
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $profile
     * @param  string|null  $workspacePath
     * @return array<string,mixed>
     */
    private function missingWorkspaceFleetRow(array $profile, ?string $workspacePath): array
    {
        $slug = $this->stringFromArray($profile, ['slug']);

        return [
            'workspace_id' => $slug,
            'profile_slug' => $slug,
            'name' => $this->stringFromArray($profile, ['name']),
            'kind' => $this->stringFromArray($profile, ['kind']),
            'workspace_path' => $workspacePath,
            'path_exists' => false,
            'readiness_status' => 'blocked',
            'safe_for_initial_context' => false,
            'safe_for_implementation' => false,
            'readiness_blockers' => ['workspace_path_missing'],
            'readiness_warnings' => [],
            'indexed' => false,
            'needs_onboarding' => true,
            'needs_reindex' => false,
            'freshness_status' => 'unknown',
            'symbol_count' => 0,
            'module_count' => 0,
            'file_count' => 0,
            'doc_link_count' => 0,
            'route_count' => 0,
            'command_count' => 0,
            'migration_count' => 0,
            'test_count' => 0,
            'provider_projection_status' => 'unknown',
            'quality_label' => 'blocked',
            'next_actions' => [
                'Fix the workspace profile path, then run '.base_path('bin/atlas').' aobg workspace activate-all --json',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $map
     * @param  array<string,mixed>  $profile
     * @return array<string,mixed>
     */
    private function workspaceFleetRow(array $map, array $profile): array
    {
        $inventory = (array) ($map['inventory'] ?? []);

        return [
            'workspace_id' => $map['workspace_id'] ?? $this->stringFromArray($profile, ['slug']),
            'profile_slug' => $this->stringFromArray($profile, ['slug']),
            'name' => $this->stringFromArray($profile, ['name']),
            'kind' => $this->stringFromArray($profile, ['kind']),
            'workspace_path' => $map['workspace_path'] ?? $this->profilePath($profile),
            'path_exists' => true,
            'readiness_status' => (string) ($map['readiness_status'] ?? 'blocked'),
            'safe_for_initial_context' => (bool) ($map['safe_for_initial_context'] ?? false),
            'safe_for_implementation' => (bool) ($map['safe_for_implementation'] ?? false),
            'readiness_blockers' => array_values((array) ($map['readiness_blockers'] ?? [])),
            'readiness_warnings' => array_values((array) ($map['readiness_warnings'] ?? [])),
            'indexed' => (bool) ($map['indexed'] ?? false),
            'needs_onboarding' => (bool) ($map['needs_onboarding'] ?? true),
            'needs_reindex' => (bool) ($map['needs_reindex'] ?? false),
            'freshness_status' => (string) ($map['freshness_status'] ?? 'unknown'),
            'freshness_reason' => (string) data_get($map, 'freshness.reason', ''),
            'freshness_checked_files' => (int) data_get($map, 'freshness.checked_files', 0),
            'freshness_changed_files' => array_values((array) data_get($map, 'freshness.changed_files', [])),
            'freshness_missing_files' => array_values((array) data_get($map, 'freshness.missing_files', [])),
            'last_index' => $map['last_index'] ?? null,
            'symbol_count' => (int) ($inventory['symbol_count'] ?? 0),
            'module_count' => (int) ($inventory['module_count'] ?? 0),
            'file_count' => (int) ($inventory['file_count'] ?? 0),
            'doc_link_count' => (int) ($inventory['doc_link_count'] ?? 0),
            'route_count' => (int) ($inventory['route_count'] ?? 0),
            'command_count' => (int) ($inventory['command_count'] ?? 0),
            'migration_count' => (int) ($inventory['migration_count'] ?? 0),
            'test_count' => (int) ($inventory['test_count'] ?? 0),
            'provider_projection_status' => (string) data_get($map, 'provider_projection.status', 'unknown'),
            'quality_label' => (string) data_get($map, 'quality.label', 'unknown'),
            'next_actions' => array_slice(array_values((array) ($map['next_actions'] ?? [])), 0, 4),
        ];
    }

    /**
     * @param  array<string,int>  $summary
     * @param  array<int,string>  $blockers
     * @param  array<int,string>  $warnings
     * @return array<int,string>
     */
    private function fleetNextActions(array $summary, array $blockers, array $warnings, int $limit): array
    {
        $actions = [];
        if (($summary['total_profiles'] ?? 0) === 0) {
            $actions[] = 'atlas workspace-intelligence register --workspace=<slug> --path=<path> --json';
        }
        if (in_array('workspace_path_missing', $blockers, true)) {
            $actions[] = 'atlas workspace-intelligence list --json';
        }
        if (($summary['needs_onboarding'] ?? 0) > 0 || ($summary['needs_reindex'] ?? 0) > 0 || $warnings !== []) {
            $actions[] = base_path('bin/atlas').' aobg workspace activate-all --json';
        }

        $actions[] = 'atlas aobg workspace map-all --detail=summary --limit='.$limit.' --json';
        $actions[] = 'atlas aobg workspace map --workspace=<workspace> --detail=samples --limit='.$limit.' --json';

        return array_values(array_unique($actions));
    }

    /**
     * @return array<string,mixed>
     */
    private function workspaceInventory(string $workspaceId): array
    {
        $symbolTypes = $this->groupedCount(self::SYMBOLS_TABLE, 'symbol_type', $workspaceId);
        $modules = $this->countRows(self::MODULES_TABLE, $workspaceId);
        $symbols = array_sum($symbolTypes);
        $docLinks = $this->countRows(self::DOC_LINKS_TABLE, $workspaceId, ['status' => 'current']);
        $files = $this->countRows(self::FILE_SNAPSHOTS_TABLE, $workspaceId);

        return [
            'status' => $symbols > 0 ? 'ready' : 'empty',
            'tables' => [
                'modules' => Schema::hasTable(self::MODULES_TABLE),
                'symbols' => Schema::hasTable(self::SYMBOLS_TABLE),
                'doc_links' => Schema::hasTable(self::DOC_LINKS_TABLE),
                'file_snapshots' => Schema::hasTable(self::FILE_SNAPSHOTS_TABLE),
            ],
            'module_count' => $modules,
            'symbol_count' => $symbols,
            'file_count' => $files,
            'doc_link_count' => $docLinks,
            'route_count' => (int) (($symbolTypes['route'] ?? 0) + ($symbolTypes['api_resource'] ?? 0)),
            'command_count' => (int) ($symbolTypes['cli_command'] ?? 0),
            'migration_count' => (int) ($symbolTypes['migration_table'] ?? 0),
            'test_count' => (int) ($symbolTypes['test_method'] ?? 0),
            'symbol_types' => $symbolTypes,
            'languages' => $this->groupedCount(self::SYMBOLS_TABLE, 'language', $workspaceId),
            'layers' => $this->groupedCount(self::MODULES_TABLE, 'layer', $workspaceId),
            'docs_status' => $this->groupedCount(self::MODULES_TABLE, 'docs_status', $workspaceId),
            'doc_link_types' => $this->groupedCount(self::DOC_LINKS_TABLE, 'link_type', $workspaceId),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function topModules(string $workspaceId, int $limit): array
    {
        try {
            if (! Schema::hasTable(self::MODULES_TABLE)) {
                return [];
            }

            $query = $this->activeQuery(self::MODULES_TABLE, $workspaceId);
            $select = [];
            foreach (['slug', 'name', 'layer', 'root_path', 'primary_language', 'docs_status', 'file_count', 'symbol_count', 'route_count', 'command_count', 'migration_count', 'test_count', 'related_docs_json', 'related_tests_json', 'indexed_at'] as $column) {
                if (Schema::hasColumn(self::MODULES_TABLE, $column)) {
                    $select[] = $column;
                }
            }
            if ($select === []) {
                return [];
            }

            if (Schema::hasColumn(self::MODULES_TABLE, 'symbol_count')) {
                $query->orderByDesc('symbol_count');
            }
            if (Schema::hasColumn(self::MODULES_TABLE, 'slug')) {
                $query->orderBy('slug');
            }

            return $query
                ->select($select)
                ->limit($limit)
                ->get()
                ->map(fn (object $row): array => [
                    'slug' => $row->slug ?? null,
                    'name' => $row->name ?? null,
                    'layer' => $row->layer ?? null,
                    'root_path' => $row->root_path ?? null,
                    'primary_language' => $row->primary_language ?? null,
                    'docs_status' => $row->docs_status ?? null,
                    'file_count' => (int) ($row->file_count ?? 0),
                    'symbol_count' => (int) ($row->symbol_count ?? 0),
                    'route_count' => (int) ($row->route_count ?? 0),
                    'command_count' => (int) ($row->command_count ?? 0),
                    'migration_count' => (int) ($row->migration_count ?? 0),
                    'test_count' => (int) ($row->test_count ?? 0),
                    'related_docs' => array_slice($this->jsonList($row->related_docs_json ?? []), 0, 12),
                    'related_tests' => array_slice($this->jsonList($row->related_tests_json ?? []), 0, 12),
                    'indexed_at' => $row->indexed_at ?? null,
                ])
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<int,string>  $types
     * @return array<int,array<string,mixed>>
     */
    private function sampleSymbols(string $workspaceId, array $types, int $limit): array
    {
        try {
            if (! Schema::hasTable(self::SYMBOLS_TABLE)) {
                return [];
            }

            $query = $this->activeQuery(self::SYMBOLS_TABLE, $workspaceId, 'symbols')
                ->whereIn('symbols.symbol_type', $types);
            $select = [];
            foreach (['symbol_type', 'symbol_name', 'file_path', 'line_start', 'language', 'signature', 'metadata'] as $column) {
                if (Schema::hasColumn(self::SYMBOLS_TABLE, $column)) {
                    $select[] = 'symbols.'.$column;
                }
            }
            if ($select === []) {
                return [];
            }

            if (Schema::hasTable(self::MODULES_TABLE) && Schema::hasColumn(self::SYMBOLS_TABLE, 'module_id') && Schema::hasColumn(self::MODULES_TABLE, 'id')) {
                $query->leftJoin(self::MODULES_TABLE.' as modules', 'modules.id', '=', 'symbols.module_id');
                if (Schema::hasColumn(self::MODULES_TABLE, 'slug')) {
                    $select[] = 'modules.slug as module_slug';
                }
            }

            $query->orderBy('symbols.file_path');
            if (Schema::hasColumn(self::SYMBOLS_TABLE, 'line_start')) {
                $query->orderBy('symbols.line_start');
            }

            return $query
                ->select($select)
                ->limit($limit)
                ->get()
                ->map(fn (object $row): array => $this->symbolSamplePayload($row))
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function pathRegions(string $workspaceId, int $limit): array
    {
        try {
            if (! Schema::hasTable(self::SYMBOLS_TABLE) || ! Schema::hasColumn(self::SYMBOLS_TABLE, 'file_path')) {
                return [];
            }

            $rows = $this->activeQuery(self::SYMBOLS_TABLE, $workspaceId)
                ->select('file_path')
                ->selectRaw('count(*) as aggregate')
                ->groupBy('file_path')
                ->orderByDesc('aggregate')
                ->limit(1000)
                ->get();

            $regions = [];
            foreach ($rows as $row) {
                $region = $this->pathRegion((string) ($row->file_path ?? ''));
                if ($region === '') {
                    continue;
                }
                $regions[$region] ??= ['region' => $region, 'file_count' => 0, 'symbol_count' => 0, 'sample_files' => []];
                $regions[$region]['file_count']++;
                $regions[$region]['symbol_count'] += (int) ($row->aggregate ?? 0);
                if (count($regions[$region]['sample_files']) < 4) {
                    $regions[$region]['sample_files'][] = (string) $row->file_path;
                }
            }

            usort($regions, static fn (array $a, array $b): int => ($b['symbol_count'] <=> $a['symbol_count']) ?: strcmp($a['region'], $b['region']));

            return array_slice(array_values($regions), 0, $limit);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string,string>  $equals
     */
    private function countRows(string $table, string $workspaceId, array $equals = []): int
    {
        try {
            if (! Schema::hasTable($table)) {
                return 0;
            }

            $query = $this->activeQuery($table, $workspaceId);
            foreach ($equals as $column => $value) {
                if (Schema::hasColumn($table, $column)) {
                    $query->where($column, $value);
                }
            }

            return (int) $query->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @return array<string,int>
     */
    private function groupedCount(string $table, string $column, string $workspaceId): array
    {
        try {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                return [];
            }

            return $this->activeQuery($table, $workspaceId)
                ->select($column)
                ->selectRaw('count(*) as aggregate')
                ->groupBy($column)
                ->orderBy($column)
                ->get()
                ->mapWithKeys(fn (object $row): array => [
                    ((string) ($row->{$column} ?? 'unknown')) ?: 'unknown' => (int) ($row->aggregate ?? 0),
                ])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function activeQuery(string $table, string $workspaceId, ?string $alias = null)
    {
        $query = DB::table($alias === null ? $table : $table.' as '.$alias);
        $prefix = $alias === null ? '' : $alias.'.';

        if (Schema::hasColumn($table, 'status')) {
            $query->where($prefix.'status', $table === self::DOC_LINKS_TABLE ? 'current' : 'active');
        }
        if (Schema::hasColumn($table, 'archived_at')) {
            $query->whereNull($prefix.'archived_at');
        }
        if (Schema::hasColumn($table, 'workspace_id')) {
            $query->where($prefix.'workspace_id', $workspaceId);
        }

        return $query;
    }

    /**
     * @return array<string,mixed>
     */
    private function symbolSamplePayload(object $row): array
    {
        $metadata = $this->jsonMap($row->metadata ?? []);

        return [
            'type' => $row->symbol_type ?? null,
            'name' => $row->symbol_name ?? null,
            'module' => $row->module_slug ?? null,
            'path' => $row->file_path ?? null,
            'line' => isset($row->line_start) ? (int) $row->line_start : null,
            'language' => $row->language ?? null,
            'signature' => $this->shortText($row->signature ?? null, 180),
            'metadata' => $this->compactMetadata(array_intersect_key($metadata, array_flip([
                'http_method',
                'uri',
                'target',
                'controller',
                'method',
                'command_signature',
                'operation',
                'table',
                'classification',
            ]))),
        ];
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    private function compactMetadata(array $metadata): array
    {
        $compact = [];
        foreach ($metadata as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $compact[$key] = $this->shortText($value, 180);
            } elseif (is_array($value)) {
                $compact[$key] = $this->shortText(json_encode(array_slice($value, 0, 6), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 180);
            }
        }

        return $compact;
    }

    /**
     * @return array<string,mixed>
     */
    private function providerProjectionStatus(?string $workspacePath): array
    {
        if (! is_string($workspacePath) || $workspacePath === '') {
            return ['status' => 'unknown', 'reason' => 'workspace_path_missing'];
        }

        try {
            $status = $this->providerProjection->status('all', ['workspace' => $workspacePath], ['workspace' => $workspacePath]);

            return [
                'status' => $status['status'] ?? 'unknown',
                'ready' => (int) data_get($status, 'summary.ready', 0),
                'total' => (int) data_get($status, 'summary.total', 0),
                'manual_drift' => (int) data_get($status, 'summary.manual_drift', 0),
                'stale' => (int) data_get($status, 'summary.stale', 0),
                'unmanaged' => (int) data_get($status, 'summary.unmanaged', 0),
            ];
        } catch (Throwable $e) {
            return ['status' => 'unknown', 'exception' => class_basename($e)];
        }
    }

    /**
     * @param  array<string,mixed>  $status
     * @param  array<string,mixed>  $inventory
     * @return array<string,mixed>
     */
    private function mapQuality(array $status, array $inventory, ?string $workspacePath): array
    {
        $score = 0.0;
        $score += (bool) ($status['indexed'] ?? false) ? 0.35 : 0.0;
        $score += (int) ($inventory['symbol_count'] ?? 0) > 0 ? 0.2 : 0.0;
        $score += (int) ($inventory['module_count'] ?? 0) > 0 ? 0.15 : 0.0;
        $score += (int) ($inventory['file_count'] ?? 0) > 0 || (int) ($inventory['symbol_count'] ?? 0) > 0 ? 0.1 : 0.0;
        $score += (int) ($inventory['doc_link_count'] ?? 0) > 0 ? 0.1 : 0.0;
        $score += is_string($workspacePath) && is_dir($workspacePath) ? 0.1 : 0.0;
        if (($status['needs_reindex'] ?? false) === true) {
            $score = min($score, 0.59);
        }

        return [
            'score' => round(min(1.0, $score), 2),
            'label' => $score >= 0.85 ? 'strong' : ($score >= 0.6 ? 'usable' : 'thin'),
            'freshness_status' => $status['freshness_status'] ?? 'unknown',
            'needs_reindex' => (bool) ($status['needs_reindex'] ?? false),
            'note' => 'Score mede prontidao do mapa local; zero routes/migrations pode ser normal para apps sem backend Laravel.',
        ];
    }

    private function mapDetail(mixed $value): string
    {
        $detail = is_string($value) ? strtolower(trim($value)) : '';

        return $detail === self::MAP_DETAIL_SAMPLES ? self::MAP_DETAIL_SAMPLES : self::MAP_DETAIL_SUMMARY;
    }

    /**
     * @param  array<string,mixed>  $status
     * @param  array<string,mixed>  $inventory
     * @param  array<string,mixed>  $providerProjection
     * @return array<string,mixed>
     */
    private function workspaceReadiness(array $status, array $inventory, array $providerProjection): array
    {
        $blockers = [];
        $warnings = [];

        if ((bool) ($status['needs_onboarding'] ?? true)) {
            $blockers[] = 'workspace_not_indexed';
        }
        if ((bool) ($status['needs_reindex'] ?? false)) {
            $warnings[] = 'workspace_index_stale';
        }
        if ((int) ($inventory['symbol_count'] ?? 0) <= 0) {
            $blockers[] = 'code_symbols_empty';
        }
        if ((int) ($providerProjection['manual_drift'] ?? 0) > 0) {
            $warnings[] = 'provider_projection_manual_drift';
        }
        if ((int) ($providerProjection['stale'] ?? 0) > 0) {
            $warnings[] = 'provider_projection_stale';
        }
        if ((int) ($providerProjection['unmanaged'] ?? 0) > 0) {
            $warnings[] = 'provider_projection_unmanaged';
        }

        $readiness = $blockers !== []
            ? 'blocked'
            : ($warnings !== [] ? 'limited' : 'ready');

        return [
            'schema' => 'atlas.aobg.workspace_readiness.v1',
            'status' => $readiness,
            'safe_for_initial_context' => $blockers === [],
            'safe_for_implementation' => $readiness === 'ready',
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'required_before_implementation' => $readiness === 'ready' ? [] : array_values(array_filter([
                in_array('workspace_not_indexed', $blockers, true) || in_array('code_symbols_empty', $blockers, true)
                    ? (string) ($status['activation_command'] ?? '')
                    : null,
                in_array('workspace_index_stale', $warnings, true)
                    ? (string) ($status['onboard_command'] ?? '')
                    : null,
                ((int) ($providerProjection['stale'] ?? 0) > 0 || (int) ($providerProjection['manual_drift'] ?? 0) > 0)
                    ? 'php artisan atlas:memory:projection write --target=all --workspace='.(string) ($status['workspace_path'] ?? $status['workspace_id'] ?? '<workspace>').' --yes --json'
                    : null,
            ])),
            'policy' => [
                'read_only' => true,
                'provider_safe' => true,
                'raw_file_content_read' => false,
                'raw_diff_returned' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $status
     * @param  array<string,mixed>  $readiness
     * @return array<int,string>
     */
    private function workspaceNextActions(array $status, array $readiness, string $workspaceRef, int $limit): array
    {
        $actions = [];
        if ((bool) ($status['needs_onboarding'] ?? true)) {
            $actions[] = (string) ($status['activation_command'] ?? '');
        } elseif ((bool) ($status['needs_reindex'] ?? false)) {
            $actions[] = (string) ($status['onboard_command'] ?? '');
        }

        foreach ((array) ($readiness['required_before_implementation'] ?? []) as $required) {
            if (is_string($required) && $required !== '') {
                $actions[] = $required;
            }
        }

        $actions[] = 'atlas open-brain context "<task>" --workspace='.$this->commandWorkspaceArg($workspaceRef).' --json';
        $actions[] = $this->workspaceMapCommand($workspaceRef, $limit, self::MAP_DETAIL_SAMPLES);

        return array_values(array_unique(array_filter($actions, static fn (string $action): bool => trim($action) !== '')));
    }

    private function workspaceMapCommand(string $workspaceRef, int $limit, string $detail): string
    {
        return 'atlas aobg workspace map --workspace='.$this->commandWorkspaceArg($workspaceRef)
            .' --detail='.$detail
            .' --limit='.$limit
            .' --json';
    }

    private function commandWorkspaceArg(string $workspaceRef): string
    {
        return preg_match('/\s/', $workspaceRef) === 1
            ? '"'.str_replace('"', '\\"', $workspaceRef).'"'
            : $workspaceRef;
    }

    private function boundedLimit(mixed $value, int $default, int $max): int
    {
        if (! is_numeric($value)) {
            return $default;
        }

        return max(1, min($max, (int) $value));
    }

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
     * Provider-safe, bounded freshness check for the already-known file snapshot set.
     *
     * It does not crawl the whole repo. It samples the scoped snapshot read-model and
     * compares current file mtimes with the mtime captured at index time.
     *
     * @return array<string,mixed>
     */
    private function freshnessStatus(?string $workspacePath, string $workspaceId, ?string $lastIndex): array
    {
        $base = [
            'schema' => 'atlas.aobg.workspace_freshness.v1',
            'status' => 'unknown',
            'reason' => null,
            'last_index' => $lastIndex,
            'checked_files' => 0,
            'changed_files' => [],
            'missing_files' => [],
            'sample_limit' => 300,
            'source_policy' => [
                'file_content_read' => false,
                'raw_diff_returned' => false,
                'bounded_snapshot_sample' => true,
            ],
        ];

        if (! is_string($workspacePath) || $workspacePath === '' || ! is_dir($workspacePath)) {
            $base['reason'] = 'workspace_path_unavailable';

            return $base;
        }
        if ($lastIndex === null) {
            $base['reason'] = 'last_index_missing';

            return $base;
        }
        if (! Schema::hasTable(self::FILE_SNAPSHOTS_TABLE) || ! Schema::hasColumn(self::FILE_SNAPSHOTS_TABLE, 'file_path')) {
            $base['reason'] = 'file_snapshot_table_unavailable';

            return $base;
        }

        try {
            $query = $this->activeQuery(self::FILE_SNAPSHOTS_TABLE, $workspaceId)
                ->select(array_values(array_filter([
                    'file_path',
                    Schema::hasColumn(self::FILE_SNAPSHOTS_TABLE, 'mtime') ? 'mtime' : null,
                    Schema::hasColumn(self::FILE_SNAPSHOTS_TABLE, 'indexed_at') ? 'indexed_at' : null,
                ])))
                ->limit((int) $base['sample_limit']);

            if (Schema::hasColumn(self::FILE_SNAPSHOTS_TABLE, 'indexed_at')) {
                $query->orderByDesc('indexed_at');
            }

            $rows = $query->get();
            if ($rows->isEmpty()) {
                $base['reason'] = 'file_snapshots_empty';

                return $base;
            }

            $lastIndexTs = strtotime($lastIndex) ?: null;
            foreach ($rows as $row) {
                $relative = trim((string) ($row->file_path ?? ''));
                if ($relative === '') {
                    continue;
                }

                $path = $this->snapshotAbsolutePath($workspacePath, $relative);
                $base['checked_files']++;
                if (! is_file($path)) {
                    if (count($base['missing_files']) < 8) {
                        $base['missing_files'][] = $relative;
                    }
                    continue;
                }

                clearstatcache(false, $path);
                $currentMtime = filemtime($path);
                if (! is_int($currentMtime)) {
                    continue;
                }

                $storedMtime = $this->intFromMixed($row->mtime ?? null);
                $indexedTs = isset($row->indexed_at) && $row->indexed_at !== null
                    ? (strtotime((string) $row->indexed_at) ?: $lastIndexTs)
                    : $lastIndexTs;
                $baseline = $storedMtime !== null && $storedMtime > 0 ? $storedMtime : $indexedTs;
                if ($baseline !== null && $currentMtime > $baseline) {
                    if (count($base['changed_files']) < 8) {
                        $base['changed_files'][] = [
                            'path' => $relative,
                            'mtime' => $currentMtime,
                            'indexed_mtime' => $baseline,
                        ];
                    }
                }
            }

            if ($base['changed_files'] !== [] || $base['missing_files'] !== []) {
                $base['status'] = 'stale';
                $base['reason'] = $base['changed_files'] !== [] ? 'known_snapshot_changed' : 'known_snapshot_missing';

                return $base;
            }

            $base['status'] = 'fresh';
            $base['reason'] = 'known_snapshots_match';

            return $base;
        } catch (Throwable $e) {
            $base['reason'] = 'freshness_check_failed';
            $base['exception'] = class_basename($e);

            return $base;
        }
    }

    private function snapshotAbsolutePath(string $workspacePath, string $filePath): string
    {
        if (str_starts_with($filePath, DIRECTORY_SEPARATOR)) {
            return $filePath;
        }

        return rtrim($workspacePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filePath;
    }

    private function intFromMixed(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
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
            && ($run === null || ($run['ok'] ?? false) === true)
            && in_array($action, ['activated', 'activated_already_indexed'], true)
            && (bool) ($status['indexed'] ?? false)
            && ! (bool) ($status['needs_reindex'] ?? false);

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

        $projection = $this->ensureProviderProjection($workspacePath, $filename);

        return [
            'ok' => ($projection['ok'] ?? false) === true,
            'path' => $path,
            'action' => $action,
            'projection' => $projection,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function ensureProviderProjection(string $workspacePath, string $filename): array
    {
        $target = $filename === 'AGENTS.md' ? 'agents' : 'claude';
        $context = ['workspace' => $workspacePath];
        $options = ['workspace' => $workspacePath];

        try {
            $inspection = $this->providerProjection->inspect($target, $context, $options);
            if (($inspection['managed'] ?? false) !== true) {
                $result = $this->providerProjection->adopt($target, $context, $options);

                return [
                    'ok' => ($result['written'] ?? false) === true,
                    'action' => 'adopted',
                    'target' => $target,
                    'path' => $result['path'] ?? $workspacePath.DIRECTORY_SEPARATOR.$filename,
                    'written' => (bool) ($result['written'] ?? false),
                    'reason' => $result['error'] ?? null,
                ];
            }

            if (($inspection['manual_drift'] ?? false) === true) {
                return [
                    'ok' => false,
                    'action' => 'blocked_manual_drift',
                    'target' => $target,
                    'path' => $inspection['path'] ?? $workspacePath.DIRECTORY_SEPARATOR.$filename,
                    'reason' => $inspection['reason'] ?? 'checksum_mismatch',
                ];
            }

            if (($inspection['stale'] ?? false) === true) {
                $result = $this->providerProjection->write($target, $context, $options);

                return [
                    'ok' => ($result['written'] ?? false) === true,
                    'action' => 'updated',
                    'target' => $target,
                    'path' => $result['path'] ?? $workspacePath.DIRECTORY_SEPARATOR.$filename,
                    'written' => (bool) ($result['written'] ?? false),
                    'reason' => $result['error'] ?? null,
                ];
            }

            return [
                'ok' => true,
                'action' => 'ready',
                'target' => $target,
                'path' => $inspection['path'] ?? $workspacePath.DIRECTORY_SEPARATOR.$filename,
                'written' => false,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'action' => 'projection_exception',
                'target' => $target,
                'path' => $workspacePath.DIRECTORY_SEPARATOR.$filename,
                'exception' => class_basename($e),
            ];
        }
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

    private function pathRegion(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '') {
            return '';
        }

        $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));
        if (count($segments) <= 1) {
            return $segments[0] ?? '';
        }

        if (in_array($segments[0], ['routes', 'config', 'tests'], true)) {
            return $segments[0];
        }
        if ($segments[0] === 'database' && ($segments[1] ?? '') === 'migrations') {
            return 'database/migrations';
        }

        return $segments[0].'/'.$segments[1];
    }

    /**
     * @return array<int,mixed>
     */
    private function jsonList(mixed $value): array
    {
        $decoded = $this->jsonValue($value);

        return array_is_list($decoded) ? $decoded : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonMap(mixed $value): array
    {
        $decoded = $this->jsonValue($value);

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : [];
    }

    /**
     * @return array<mixed>|mixed
     */
    private function jsonValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function shortText(mixed $value, int $limit): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim(preg_replace('/\s+/', ' ', (string) $value) ?? (string) $value);
        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > $limit ? mb_substr($text, 0, max(1, $limit - 3)).'...' : $text;
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

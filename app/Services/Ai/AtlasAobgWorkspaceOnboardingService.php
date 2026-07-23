<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\AobgWorkspaceOnboarding\AobgWorkspaceOnboardingSupport;
use App\Services\Ai\AobgWorkspaceOnboarding\ProviderBootstrapSection;
use App\Services\Ai\AobgWorkspaceOnboarding\WorkspaceMapSection;
use App\Services\Ai\AobgWorkspaceOnboarding\WorkspaceProfileSection;
use App\Services\Ai\AobgWorkspaceOnboarding\WorkspaceReadModelSection;
use App\Services\Ai\Obra\AtlasDeterministicBriefService;
use App\Services\Ai\Instrumentation\AtlasProviderProjectionService;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use App\Services\AtlasCode\AtlasCodeWorkspaceProfileService;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use Illuminate\Support\Facades\Artisan;
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
 *
 * STRUCTURE: this class is the public façade (GOD-DEBULK star topology). The private
 * helper families live in `AobgWorkspaceOnboarding/`: the read-model queries in
 * {@see WorkspaceReadModelSection}, the map/fleet presentation in {@see WorkspaceMapSection},
 * AWIS profile registration in {@see WorkspaceProfileSection}, provider-file writing in
 * {@see ProviderBootstrapSection}, and cross-cutting leaf helpers in
 * {@see AobgWorkspaceOnboardingSupport}. Sections depend only on the leaf support + these
 * façade constants — never on each other.
 */
class AtlasAobgWorkspaceOnboardingService
{
    public const SCHEMA = 'atlas.aobg.workspace_onboarding.v1';

    /** The W-1 code-intelligence read-model the gateway scopes its status to. */
    public const SYMBOLS_TABLE = 'atlas_engineering_code_symbols';

    public const MODULES_TABLE = 'atlas_engineering_code_modules';

    public const DOC_LINKS_TABLE = 'atlas_engineering_doc_links';

    public const FILE_SNAPSHOTS_TABLE = 'atlas_engineering_code_file_snapshots';

    public const MANAGED_BLOCK_START = '<!-- atlas:aobg:auto-bootstrap:start -->';

    public const MANAGED_BLOCK_END = '<!-- atlas:aobg:auto-bootstrap:end -->';

    public const MAP_DETAIL_SUMMARY = 'summary';

    public const MAP_DETAIL_SAMPLES = 'samples';

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

    private readonly AobgWorkspaceOnboardingSupport $support;

    private readonly WorkspaceReadModelSection $readModel;

    private readonly WorkspaceMapSection $mapSection;

    private readonly WorkspaceProfileSection $profileSection;

    private readonly ProviderBootstrapSection $bootstrapSection;

    public function __construct(
        private readonly CodeGraphWorkspaceIdentity $workspaceIdentity,
        private readonly AtlasCodeWorkspaceProfileService $workspaceProfiles,
        private readonly AtlasProviderProjectionService $providerProjection,
    ) {
        $this->support = new AobgWorkspaceOnboardingSupport();
        $this->readModel = new WorkspaceReadModelSection();
        $this->mapSection = new WorkspaceMapSection($providerProjection, $this->support);
        $this->profileSection = new WorkspaceProfileSection($workspaceProfiles, $this->support);
        $this->bootstrapSection = new ProviderBootstrapSection($providerProjection);
    }

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
     *                                     - workspace: explicit workspace path OR id (wins over cwd).
     *                                     - cwd: caller's working directory (the external tool's project dir).
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
        $counts = $this->readModel->symbolCounts($workspaceId);

        $indexed = $counts['symbols'] > 0;
        $freshness = $this->readModel->freshnessStatus($workspacePath, $workspaceId, $counts['last_index']);
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
     *                                     - force: re-run the index even when already indexed (still gated by auto_onboard).
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
        $profile = $this->profileSection->registerWorkspaceProfile($workspacePath, $workspaceId);
        $registeredSlug = $this->support->stringFromArray($profile, ['workspace', 'slug'])
            ?? $this->support->stringFromArray($profile, ['profile', 'slug']);
        if ($registeredSlug !== null) {
            $workspaceId = $registeredSlug;
        }

        $scopedOpts = array_merge($opts, ['workspace' => $workspacePath]);
        $before = $this->status($scopedOpts);
        $bootstrap = $this->bootstrapSection->writeProviderBootstrap(
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

        // WO-17-T3 — multi-project: auto-generate a minimal deterministic brief on
        // activation so a freshly-opened repo has "lembra por quê" from turn 1 (the
        // retriever is already workspace-scoped; this was the missing trigger).
        // Side-effect only, fail-open — never blocks or breaks activation.
        $this->generateBriefFor($workspacePath, (string) $after['workspace_id']);

        // ADN F5 — o canto de docs do repo ganha lineage no AKIF na ativação
        // (docs/engineering-knowledge-base/atlas-documentation-network.md).
        // Side-effect fail-open: lineage nunca bloqueia ativação.
        try {
            $this->registerDocsCornerInAkif($workspacePath, (string) $after['workspace_id']);
        } catch (Throwable) {
            // fail-open
        }

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
     * ADN F5 — registra o canto canônico de docs do workspace como source
     * packet AKIF (`source_type=repo`) com lineage: workspace_id, docs_root e
     * origin (git remote quando existir; senão file://). Idempotente por
     * source_hash determinístico. Ausência de canto é ausência — nada nasce.
     *
     * @return array{ok:bool, reason?:string, status?:string, packet_id?:?string}
     */
    public function registerDocsCornerInAkif(string $workspacePath, string $workspaceId): array
    {
        $workspacePath = rtrim($workspacePath, DIRECTORY_SEPARATOR);
        $docsRoot = 'docs/engineering-knowledge-base';
        $corner = $workspacePath.DIRECTORY_SEPARATOR.$docsRoot;
        if (! is_dir($corner)) {
            return ['ok' => false, 'reason' => 'no_docs_corner'];
        }

        $remote = trim((string) @shell_exec(
            'git -C '.escapeshellarg($workspacePath).' config --get remote.origin.url 2>/dev/null',
        ));
        $originUri = $remote !== '' ? $remote : 'file://'.$workspacePath;

        return app(\App\Services\Ai\Knowledge\AtlasKnowledgeSourcePacketRegistryService::class)->register([
            'source_type' => \App\Services\Ai\Knowledge\AtlasKnowledgeSourcePacketRegistryService::SOURCE_TYPE_REPO,
            'origin_uri' => $originUri,
            'source_hash' => hash('sha256', 'adn.docs_corner|'.$workspaceId.'|'.$corner),
            'ingester' => 'aobg.workspace_activate',
            'metadata' => [
                'adn' => 'docs_corner.v1',
                'workspace_id' => $workspaceId,
                'docs_root' => $docsRoot,
            ],
        ]);
    }

    /**
     * WO-17-T3 — auto-generate the minimal deterministic brief for a freshly activated
     * workspace (scope = repo dir name; churn/HEAD read from the workspace repo, not the
     * artisan host). Fail-open side effect — never part of the activation verdict.
     */
    private function generateBriefFor(string $workspacePath, string $workspaceId): void
    {
        try {
            $scope = basename(rtrim($workspacePath, DIRECTORY_SEPARATOR));
            if ($scope === '') {
                $scope = $workspaceId;
            }
            app(AtlasDeterministicBriefService::class)->generate($scope, $workspacePath);
        } catch (Throwable) {
            // fail-open — brief generation must never block or break activation.
        }
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
            $workspacePath = $this->support->profilePath($profile);
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
            $workspacePath = $this->support->profilePath($profile);
            if ($workspacePath === null || ! is_dir($workspacePath)) {
                $summary['missing_path']++;
                $row = $this->mapSection->missingWorkspaceFleetRow($profile, $workspacePath);
            } else {
                $summary['existing_path']++;
                try {
                    $row = $this->mapSection->workspaceFleetRow(
                        $this->map([
                            'workspace' => rtrim(realpath($workspacePath) ?: $workspacePath, DIRECTORY_SEPARATOR),
                            'limit' => $limit,
                            'detail' => self::MAP_DETAIL_SUMMARY,
                        ]),
                        $profile,
                    );
                } catch (Throwable $e) {
                    $row = [
                        'workspace_id' => $this->support->stringFromArray($profile, ['slug']),
                        'profile_slug' => $this->support->stringFromArray($profile, ['slug']),
                        'name' => $this->support->stringFromArray($profile, ['name']),
                        'kind' => $this->support->stringFromArray($profile, ['kind']),
                        'workspace_path' => $workspacePath,
                        'path_exists' => true,
                        'readiness_status' => 'blocked',
                        'safe_for_initial_context' => false,
                        'safe_for_implementation' => false,
                        'readiness_blockers' => ['workspace_map_failed'],
                        'readiness_warnings' => [],
                        'exception' => class_basename($e),
                        'next_actions' => [$this->mapSection->workspaceMapCommand($workspacePath, $limit, self::MAP_DETAIL_SUMMARY)],
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
            'next_actions' => $this->mapSection->fleetNextActions($summary, $blockers, $warnings, $limit),
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
        $inventory = $this->readModel->workspaceInventory($workspaceId);
        $providerProjection = $this->mapSection->providerProjectionStatus($workspacePath);
        $readiness = $this->mapSection->workspaceReadiness($status, $inventory, $providerProjection);

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
            'modules' => $includeSamples ? $this->readModel->topModules($workspaceId, $limit) : [],
            'path_regions' => $includeSamples ? $this->readModel->pathRegions($workspaceId, $limit) : [],
            'examples' => $includeSamples ? [
                'routes' => $this->readModel->sampleSymbols($workspaceId, ['route', 'api_resource'], $limit),
                'commands' => $this->readModel->sampleSymbols($workspaceId, ['cli_command'], $limit),
                'migrations' => $this->readModel->sampleSymbols($workspaceId, ['migration_table'], $limit),
                'tests' => $this->readModel->sampleSymbols($workspaceId, ['test_method'], $limit),
                'entrypoints' => $this->readModel->sampleSymbols($workspaceId, ['class', 'function'], min($limit, 10)),
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
                'request_samples' => $this->mapSection->workspaceMapCommand($workspacePath ?? $workspaceId, $limit, self::MAP_DETAIL_SAMPLES),
            ],
            'provider_projection' => $providerProjection,
            'next_actions' => $this->mapSection->workspaceNextActions($status, $readiness, $workspacePath ?? $workspaceId, $limit),
            'quality' => $this->mapSection->mapQuality($status, $inventory, $workspacePath),
            'generated_at' => now()->toJSON(),
        ];
    }

    // ------------------------------------------------------------------
    // internals — orchestration-local helpers (envelopes, index runner,
    // workspace resolution). The read-model / map / profile / bootstrap
    // families live in AobgWorkspaceOnboarding/.
    // ------------------------------------------------------------------

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

        $exit = Artisan::call(self::ONBOARD_COMMAND, [
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

    private function boundedLimit(mixed $value, int $default, int $max): int
    {
        if (! is_numeric($value)) {
            return $default;
        }

        return max(1, min($max, (int) $value));
    }

    private function mapDetail(mixed $value): string
    {
        $detail = is_string($value) ? strtolower(trim($value)) : '';

        return $detail === self::MAP_DETAIL_SAMPLES ? self::MAP_DETAIL_SAMPLES : self::MAP_DETAIL_SUMMARY;
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
            );
        } catch (Throwable) {
            // audit logging is best-effort; the decision never depends on it.
        }
    }
}

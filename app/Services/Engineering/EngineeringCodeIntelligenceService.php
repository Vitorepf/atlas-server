<?php

namespace App\Services\Engineering;

use App\Support\MemoryLimitBytes;
use App\Models\AtlasEngineeringCodeModule;
use App\Models\AtlasEngineeringCodeSymbol;
use App\Models\AtlasEngineeringDocLink;
use App\Models\AtlasEngineeringKnowledgeItem;
use App\Services\AtlasCode\AtlasCodeWorkspaceProfileService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use App\Services\Engineering\CodeIntelligence\ModuleExtractor;
use App\Services\Engineering\CodeIntelligence\ParseExtractor;
use App\Services\Engineering\CodeIntelligence\DiscoverySection;
use App\Services\Engineering\CodeIntelligence\DocumentationSection;
use App\Services\Engineering\CodeIntelligence\DriftSection;
use App\Services\Engineering\CodeIntelligence\PersistSection;
use App\Services\Engineering\CodeIntelligence\PersistenceSupport;
use App\Services\Engineering\CodeIntelligence\ScanSummarySection;
use App\Services\Engineering\CodeIntelligence\SnapshotSection;
use App\Services\Engineering\CodeIntelligence\TreeSitterSection;
use App\Services\Engineering\CodeIntelligence\SymbolExtractor;
use App\Services\Tools\AtlasToolEvidenceStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;
use RuntimeException;
use SplFileInfo;
use Throwable;

class EngineeringCodeIntelligenceService
{
    // AP-815 A1: PHP/JS/MD keep their native parsers; the rest are extracted by the
    // tree-sitter runtime (CodeGraphTreeSitterExtractor) when the flag is on. atlas-server's
    // own Laravel roots contain none of the new languages except one finance .py script,
    // so this widens discovery for EXTERNAL repos without churning the primary graph.
    public const EXTENSIONS = ['php', 'ts', 'tsx', 'js', 'jsx', 'mjs', 'cjs', 'md', 'py', 'go', 'rs', 'java', 'rb', 'kt', 'kts', 'scala', 'swift', 'cpp', 'hpp', 'lua'];

    /**
     * Symbol-extraction version. Bump whenever the language parsers
     * (parsePhpSymbols / parseJavascriptSymbols / parseMarkdownSymbols) change
     * HOW they emit symbols.
     *
     * The file-snapshot cache is keyed on file CONTENT, so a file whose content
     * never changes (e.g. an immutable migration, or a Generated service that is
     * regenerated only occasionally) would keep serving symbols produced by an
     * OLDER parser indefinitely — masking the fix and making "re-run index-code"
     * a silent no-op. Folding this version into the snapshot cache key forces one
     * clean re-parse of the whole workspace after a parser change.
     *
     * v2: anchor class/interface/trait/enum extraction to a real declaration at
     *     line start, so docblock prose ("each class carries...") and anonymous
     *     classes ("new class extends Migration") no longer mint phantom symbols.
     * v4: split umbrella-workspace stored paths from sub-repo analysis paths, so
     *     Atlas root indexes atlas-server/routes and atlas-server/tests as real
     *     Laravel routes/tests while preserving their umbrella file paths.
     */
    public const EXTRACTOR_VERSION = 5; // v5: multi-class por arquivo + método atribuído por span

    /**
     * Detailed symbol set diffs require a PHP hash index of scan keys. On the primary
     * Atlas repo the scan can exceed 100k symbols and may already sit near the CLI
     * memory ceiling; in that case module/source-hash drift is enough to block the gate
     * and let `code-gate --auto-refresh` repair the index without an OOM.
     */
    public const DETAILED_SYMBOL_DRIFT_SCAN_LIMIT = 25000;

    public const SYMBOL_UPSERT_MAX_ROWS = 200;

    public const SNAPSHOT_UPSERT_MAX_ROWS = 100;

    public const DB_UPSERT_MAX_BYTES = 4_000_000;

    /**
     * AP-815 W-1: resolved workspace_id for the current index() run (default = primary).
     * Authority copy kept on the facade so reflection-based tests can set it directly;
     * mirrored into PersistenceSupport (see syncWorkspaceState) so the extracted sections
     * observe the same live value.
     */
    private string $workspaceId = 'atlas-server';

    private readonly PersistenceSupport $support;

    private readonly DiscoverySection $discovery;

    private readonly ScanSummarySection $scanSummarySection;

    private readonly TreeSitterSection $treeSitter;

    private readonly SnapshotSection $snapshot;

    private readonly PersistSection $persist;

    private readonly DriftSection $drift;

    private readonly DocumentationSection $documentation;


    public function __construct(
        private readonly AtlasToolEvidenceStore $toolEvidence,
        private readonly ModuleExtractor $moduleExtractor,
        private readonly SymbolExtractor $symbolExtractor,
        private readonly ParseExtractor $parseExtractor,
        private readonly ?EngineeringContextIntelligenceInput $input = null,
    ) {
        $this->support = new PersistenceSupport();
        $this->discovery = new DiscoverySection();
        $this->scanSummarySection = new ScanSummarySection();
        $this->treeSitter = new TreeSitterSection($this->discovery, $this->scanSummarySection, $this->symbolExtractor);
        $this->snapshot = new SnapshotSection($this->support, $this->symbolExtractor);
        $this->persist = new PersistSection($this->support);
        $this->drift = new DriftSection($this->support, $this->moduleExtractor, $this->symbolExtractor);
        $this->documentation = new DocumentationSection($this->support, $this->symbolExtractor);
    }

    /** Mirror the facade's authoritative workspace id into the shared persistence support. */
    private function syncWorkspaceState(): void
    {
        $this->support->workspaceId = $this->workspaceId;
    }

    // GOD-DEBULK reflection-facing delegators: some tests set the private $workspaceId and
    // invoke these methods directly via reflection. They stay behaviour-identical after the
    // split by mirroring the shared workspace state, then forwarding to the extracted section.
    private function symbolDrift(array $symbolRows, int $limit): array
    {
        $this->syncWorkspaceState();

        return $this->drift->symbolDrift($symbolRows, $limit);
    }

    private function docLinkHealth(string $workspace, int $limit): array
    {
        $this->syncWorkspaceState();

        return $this->drift->docLinkHealth($workspace, $limit);
    }

    private function loadFileSnapshots(bool $withData = false): array
    {
        $this->syncWorkspaceState();

        return $this->snapshot->loadFileSnapshots($withData);
    }

    private function syncDocLinks(string $workspace, bool $prune): int
    {
        $this->syncWorkspaceState();

        return $this->documentation->syncDocLinks($workspace, $prune);
    }

    private function archiveStaleDocLinks(Carbon $pruneStartedAt): void
    {
        $this->syncWorkspaceState();

        $this->documentation->archiveStaleDocLinks($pruneStartedAt);
    }

    private function parsePhpRelations(string $relativePath, string $content, string $moduleSlug): array
    {
        return $this->parseExtractor->parsePhpRelations($relativePath, $content, $moduleSlug);
    }


    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function index(array $options = []): array
    {
        $startedAt = microtime(true);
        $phaseTimings = [];
        $this->ensureIndexMemoryBudget();
        $this->ensureTables();

        $workspace = $this->workspace($options['workspace'] ?? base_path());
        $this->workspaceId = app(CodeGraphWorkspaceIdentity::class)->resolve($workspace);
        $this->syncWorkspaceState();
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $prune = (bool) ($options['prune'] ?? false);
        $context = $this->toolRuntimeContext($options);
        $phaseStartedAt = microtime(true);
        $scan = $this->scanWorkspace($workspace, true);
        $this->recordPhase($phaseTimings, 'scan_workspace', $phaseStartedAt);
        $moduleRows = $scan['modules'];
        $symbolRows = $scan['symbols'];

        if ($dryRun) {
            $durationMs = $this->elapsedMs($startedAt);
            $payload = [
                'ok' => true,
                'dry_run' => true,
                'workspace' => $workspace,
                'summary' => $scan['summary'],
                'modules' => $moduleRows,
                'symbols_preview' => array_slice($symbolRows, 0, 80),
                'duration_ms' => $durationMs,
                'performance' => $this->performanceProfile($durationMs, $phaseTimings, $scan['summary'], $scan['cache'] ?? []),
                'generated_at' => now()->toJSON(),
            ];
            $this->recordToolRuntimeEvidence('index', $workspace, $payload, $context);

            return $payload;
        }

        $phaseStartedAt = microtime(true);
        $moduleIds = $this->persist->persistModules($moduleRows, $prune);
        $this->recordPhase($phaseTimings, 'persist_modules', $phaseStartedAt);
        $phaseStartedAt = microtime(true);
        $symbolCount = $this->persist->persistSymbols($symbolRows, $moduleIds, $prune);
        $this->recordPhase($phaseTimings, 'persist_symbols', $phaseStartedAt);
        $phaseStartedAt = microtime(true);
        $this->snapshot->persistFileSnapshots((array) ($scan['file_snapshots'] ?? []), $prune, (array) ($scan['file_paths'] ?? []));
        $this->recordPhase($phaseTimings, 'persist_file_snapshots', $phaseStartedAt);
        $phaseStartedAt = microtime(true);
        $docLinkCount = $this->documentation->syncDocLinks($workspace, $prune);
        $this->recordPhase($phaseTimings, 'sync_doc_links', $phaseStartedAt);
        $summary = $this->scanSummarySection->scanSummary($moduleRows, $symbolRows, $docLinkCount);
        $cache = (array) ($scan['cache'] ?? []);
        unset($scan, $moduleRows, $symbolRows, $moduleIds);
        $phaseStartedAt = microtime(true);
        $this->documentation->refreshDocumentationStatus();
        $this->recordPhase($phaseTimings, 'refresh_documentation_status', $phaseStartedAt);
        $durationMs = $this->elapsedMs($startedAt);

        $payload = [
            'ok' => true,
            'dry_run' => false,
            'workspace' => $workspace,
            'summary' => $summary,
            'modules' => $this->catalog([], 30)['modules'],
            'symbol_count' => $symbolCount,
            'duration_ms' => $durationMs,
            'performance' => $this->performanceProfile($durationMs, $phaseTimings, $summary, $cache),
            'generated_at' => now()->toJSON(),
        ];
        $this->recordToolRuntimeEvidence('index', $workspace, $payload, $context);

        return $payload;
    }


    private function ensureIndexMemoryBudget(): void
    {
        $current = ini_get('memory_limit');
        if ($current === false || $current === '-1') {
            return;
        }

        if ($this->memoryLimitToBytes($current) < 1024 * 1024 * 1024) {
            ini_set('memory_limit', '1024M');
        }
    }


    private function memoryLimitToBytes(string $value): int
    {
        return MemoryLimitBytes::parse($value);
    }


    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function audit(array $options = []): array
    {
        $startedAt = microtime(true);
        $phaseTimings = [];
        $this->ensureIndexMemoryBudget();
        if (! $this->tablesExist()) {
            return $this->tablesMissingPayload('atlas.code_intelligence.audit.v1');
        }

        $workspace = $this->workspace($options['workspace'] ?? base_path());
        $this->workspaceId = app(CodeGraphWorkspaceIdentity::class)->resolve($workspace);
        $this->syncWorkspaceState();
        $limit = $this->contextInput()->codeLimit($options['limit'] ?? null);
        $context = $this->toolRuntimeContext($options);
        $persisted = $this->summary(['workspace' => $workspace]);
        $collectSymbolRows = (int) ($persisted['symbol_count'] ?? 0) <= self::DETAILED_SYMBOL_DRIFT_SCAN_LIMIT;
        $phaseStartedAt = microtime(true);
        $scan = $this->scanWorkspace($workspace, true, $collectSymbolRows);
        $this->recordPhase($phaseTimings, 'scan_workspace', $phaseStartedAt);
        $phaseStartedAt = microtime(true);
        $modules = $this->drift->moduleDrift($scan['modules'], $limit);
        $this->recordPhase($phaseTimings, 'module_drift', $phaseStartedAt);
        $moduleDrift = array_sum($modules['counts']);
        $phaseStartedAt = microtime(true);
        $scannedSymbolCount = (int) data_get($scan, 'summary.symbol_count', count($scan['symbols']));
        $symbols = $this->drift->canTrustModuleHashesForSymbolFreshness($moduleDrift, $scannedSymbolCount)
            ? $this->drift->emptySymbolDrift()
            : ($this->drift->shouldUseBoundedSymbolDrift($scannedSymbolCount)
                ? $this->drift->boundedSymbolDrift($scannedSymbolCount)
                : $this->drift->symbolDrift($scan['symbols'], $limit));
        $this->recordPhase($phaseTimings, 'symbol_drift', $phaseStartedAt);
        $phaseStartedAt = microtime(true);
        $docLinks = $this->drift->docLinkHealth($workspace, $limit);
        $this->recordPhase($phaseTimings, 'doc_link_health', $phaseStartedAt);
        $symbolDrift = array_sum($symbols['counts']);
        $docLinkDrift = (int) ($docLinks['counts']['missing_targets'] ?? 0)
            + (int) ($docLinks['counts']['stale_target_hashes'] ?? 0);
        $totalDrift = $moduleDrift + $symbolDrift + $docLinkDrift;
        $phaseStartedAt = microtime(true);
        $this->recordPhase($phaseTimings, 'persisted_summary', $phaseStartedAt);
        $durationMs = $this->elapsedMs($startedAt);

        $payload = [
            'ok' => true,
            'dry_run' => true,
            'writes' => false,
            'status' => ((int) ($persisted['module_count'] ?? 0)) === 0
                ? 'empty_index'
                : ($totalDrift > 0 ? 'drift_detected' : 'fresh'),
            'workspace' => $workspace,
            'summary' => [
                'scanned' => $scan['summary'],
                'persisted' => $persisted,
                'drift' => [
                    'total' => $totalDrift,
                    'modules' => $modules['counts'],
                    'symbols' => $symbols['counts'],
                    'doc_links' => $docLinks['counts'],
                ],
            ],
            'drift' => [
                'modules' => $modules,
                'symbols' => $symbols,
                'doc_links' => $docLinks,
            ],
            'duration_ms' => $durationMs,
            'performance' => $this->performanceProfile($durationMs, $phaseTimings, $scan['summary'], $scan['cache'] ?? []),
            'generated_at' => now()->toJSON(),
        ];
        $this->recordToolRuntimeEvidence('audit', $workspace, $payload, $context);

        return $payload;
    }


    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function readiness(array $options = []): array
    {
        $startedAt = microtime(true);
        if (! $this->tablesExist()) {
            return $this->tablesMissingPayload('atlas.code_intelligence.readiness.v1');
        }
        $workspace = $this->workspace($options['workspace'] ?? base_path());
        $audit = $this->audit($options);
        $stabilityRechecked = false;
        if (($audit['status'] ?? null) !== 'fresh') {
            usleep(100000);
            $secondAudit = $this->audit($options);
            $stabilityRechecked = true;

            if ((int) data_get($secondAudit, 'summary.drift.total', PHP_INT_MAX) <= (int) data_get($audit, 'summary.drift.total', PHP_INT_MAX)) {
                $audit = $secondAudit;
            }
        }

        $summary = (array) ($audit['summary']['persisted'] ?? $this->summary());
        $criticalFailures = [];
        $warnings = [];

        if (! (bool) ($summary['table_exists'] ?? false)) {
            $criticalFailures[] = 'tables_missing';
        }

        if (($summary['status'] ?? null) !== 'ready') {
            $criticalFailures[] = 'index_not_ready';
        }

        if (($audit['status'] ?? null) !== 'fresh') {
            $criticalFailures[] = 'audit_not_fresh';
        }

        foreach (['module_count', 'symbol_count', 'route_count', 'command_count', 'test_count'] as $field) {
            if ((int) ($summary[$field] ?? 0) <= 0) {
                $criticalFailures[] = $field.'_empty';
            }
        }

        if ((int) data_get($audit, 'summary.drift.total', 0) !== 0) {
            $criticalFailures[] = 'drift_detected';
        }

        if (($audit['performance']['status'] ?? null) === 'slow') {
            $warnings[] = 'audit_performance_slow';
        }

        $warnings = array_merge($warnings, $this->documentationWarnings($summary));

        $status = $criticalFailures === [] ? 'ready' : 'blocked';

        return [
            'schema_version' => 'atlas.code_intelligence.readiness.v1',
            'status' => $status,
            'workspace' => $workspace,
            'summary' => [
                'critical_failures' => count($criticalFailures),
                'warnings' => count($warnings),
                'module_count' => (int) ($summary['module_count'] ?? 0),
                'symbol_count' => (int) ($summary['symbol_count'] ?? 0),
                'doc_link_count' => (int) ($summary['doc_link_count'] ?? 0),
                'drift_total' => (int) data_get($audit, 'summary.drift.total', 0),
                'audit_duration_ms' => (int) ($audit['duration_ms'] ?? 0),
            ],
            'critical_failures' => EngineeringStringListNormalizer::uniqueStringCasts($criticalFailures),
            'warnings' => EngineeringStringListNormalizer::uniqueStringCasts($warnings),
            'audit' => [
                'status' => $audit['status'] ?? null,
                'performance' => $audit['performance'] ?? null,
                'stability_rechecked' => $stabilityRechecked,
            ],
            'claim_policy' => [
                'writes' => false,
                'provider_calls_made' => false,
                'external_graph_used' => false,
            ],
            'duration_ms' => $this->elapsedMs($startedAt),
            'generated_at' => now()->toJSON(),
        ];
    }


    /**
     * @param  array<string,mixed>  $summary
     * @return list<string>
     */
    private function documentationWarnings(array $summary): array
    {
        if (! $this->docLinksRequiredForCurrentWorkspace()) {
            return [];
        }

        return (int) data_get($summary, 'docs_status.undocumented', 0) > 0
            ? ['undocumented_modules_present']
            : [];
    }


    private function docLinksRequiredForCurrentWorkspace(): bool
    {
        try {
            return $this->workspaceId === app(CodeGraphWorkspaceIdentity::class)->default();
        } catch (Throwable) {
            return true;
        }
    }


    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function catalog(array $filters = [], int $limit = 50): array
    {
        if (! $this->tablesExist()) {
            return [
                'summary' => $this->summary(),
                'modules' => [],
            ];
        }

        $query = AtlasEngineeringCodeModule::query()->withCount('symbols');
        if (! (bool) ($filters['include_archived'] ?? false)) {
            $query->active();
        }
        if (is_string($filters['layer'] ?? null) && trim((string) $filters['layer']) !== '') {
            $query->where('layer', Str::slug(trim((string) $filters['layer']), '_'));
        }
        if (is_string($filters['docs_status'] ?? null) && trim((string) $filters['docs_status']) !== '') {
            $query->where('docs_status', trim((string) $filters['docs_status']));
        }
        if (is_string($filters['q'] ?? null) && trim((string) $filters['q']) !== '') {
            $q = trim((string) $filters['q']);
            $query->where(function (Builder $query) use ($q): void {
                $query->where('slug', 'like', "%{$q}%")
                    ->orWhere('name', 'like', "%{$q}%")
                    ->orWhere('root_path', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%");
            });
        }

        $modules = $query
            ->orderByDesc('symbol_count')
            ->orderBy('slug')
            ->limit($this->contextInput()->codeLimit($limit))
            ->get()
            ->map(fn (AtlasEngineeringCodeModule $module): array => $this->modulePayload($module))
            ->values()
            ->all();

        return [
            'summary' => $this->summary(),
            'modules' => $modules,
        ];
    }


    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function symbols(array $filters = [], int $limit = 100): array
    {
        if (! $this->tablesExist()) {
            return [
                'summary' => $this->summary(),
                'symbols' => [],
            ];
        }

        $query = AtlasEngineeringCodeSymbol::query()->with('module');
        if (! (bool) ($filters['include_archived'] ?? false)) {
            $query->active();
        }
        // AP-815 W-3 — scope to a workspace when the caller passes a resolved workspace_id
        // AND the read-model is W-1-keyed. On a pre-W-1 table (no column) the filter is
        // skipped so every row is implicitly the primary workspace (current behaviour),
        // and callers that pass no workspace_id are unaffected (the filter is opt-in).
        if (is_string($filters['workspace_id'] ?? null)
            && trim((string) $filters['workspace_id']) !== ''
            && $this->support->workspaceKeyed('atlas_engineering_code_symbols')) {
            $query->where('workspace_id', trim((string) $filters['workspace_id']));
        }
        foreach (['symbol_type', 'language', 'docs_status'] as $field) {
            if (is_string($filters[$field] ?? null) && trim((string) $filters[$field]) !== '') {
                $query->where($field, trim((string) $filters[$field]));
            }
        }
        if (is_string($filters['module'] ?? null) && trim((string) $filters['module']) !== '') {
            $module = trim((string) $filters['module']);
            $query->whereHas('module', function (Builder $query) use ($module): void {
                $query->where('slug', $module);
                if (Str::isUuid($module)) {
                    $query->orWhere('id', $module);
                }
            });
        }
        if (is_string($filters['q'] ?? null) && trim((string) $filters['q']) !== '') {
            $q = trim((string) $filters['q']);
            // AP-815 K2: match per WORD (OR across terms) instead of the whole phrase, so a
            // natural multi-word query ("secret scanner") recalls symbols matching ANY term
            // — mirrors the atlas:ctx retriever. A single-word query is byte-identical.
            $terms = array_values(array_filter(
                preg_split('/\s+/', $q) ?: [],
                static fn (string $t): bool => trim($t) !== '',
            ));
            if ($terms === []) {
                $terms = [$q];
            }
            $query->where(function (Builder $query) use ($terms): void {
                foreach ($terms as $term) {
                    $term = trim($term);
                    $query->orWhere('symbol_name', 'like', "%{$term}%")
                        ->orWhere('file_path', 'like', "%{$term}%")
                        ->orWhere('signature', 'like', "%{$term}%");
                }
            });
        }

        return [
            'summary' => $this->summary(),
            'symbols' => $query
                ->orderBy('file_path')
                ->orderBy('line_start')
                ->limit($this->contextInput()->codeLimit($limit))
                ->get()
                ->map(fn (AtlasEngineeringCodeSymbol $symbol): array => $this->symbolPayload($symbol))
                ->values()
                ->all(),
        ];
    }


    /**
     * @return array<string,mixed>|null
     */
    public function module(string $idOrSlug): ?array
    {
        if (! $this->tablesExist()) {
            return null;
        }

        $module = AtlasEngineeringCodeModule::query()
            ->with(['symbols' => fn ($query) => $query->active()->orderBy('symbol_type')->orderBy('file_path')->limit(120), 'docLinks'])
            ->where('slug', $idOrSlug)
            ->when(Str::isUuid($idOrSlug), fn (Builder $query) => $query->orWhere('id', $idOrSlug))
            ->first();

        if (! $module) {
            return null;
        }

        return [
            'module' => $this->modulePayload($module),
            'symbols' => $module->symbols->map(fn (AtlasEngineeringCodeSymbol $symbol): array => $this->symbolPayload($symbol))->values()->all(),
            'doc_links' => $module->docLinks->map(fn (AtlasEngineeringDocLink $link): array => $this->docLinkPayload($link))->values()->all(),
        ];
    }


    /**
     * @param  array<string,mixed>  $context
     * @return array<int,array<string,mixed>>
     */
    public function contextRefs(array $context = [], int $limit = 12): array
    {
        if (! $this->tablesExist()) {
            return [];
        }

        $tags = collect((array) data_get($context, 'contract.tags', []))
            ->merge((array) ($context['tags'] ?? []))
            ->map(fn (mixed $tag): string => Str::slug((string) $tag, '_'))
            ->filter()
            ->values()
            ->all();

        $query = AtlasEngineeringCodeModule::query()->active();
        if ($tags !== []) {
            $query->orderByRaw(
                'case when slug like ? or layer like ? then 0 else 1 end',
                ['%'.$tags[0].'%', '%'.$tags[0].'%'],
            );
        }

        return $query
            ->orderByDesc('symbol_count')
            ->orderByRaw("case when docs_status = 'documented' then 0 when docs_status = 'inferred' then 1 else 2 end")
            ->limit($this->contextInput()->codeLimit($limit))
            ->get()
            ->map(fn (AtlasEngineeringCodeModule $module): array => [
                'type' => 'atlas_engineering_code_module',
                'id' => $module->id,
                'slug' => $module->slug,
                'name' => $module->name,
                'layer' => $module->layer,
                'root_path' => $module->root_path,
                'docs_status' => $module->docs_status,
                'file_count' => $module->file_count,
                'symbol_count' => $module->symbol_count,
                'route_count' => $module->route_count,
                'command_count' => $module->command_count,
                'migration_count' => $module->migration_count,
                'test_count' => $module->test_count,
                'related_docs' => $module->related_docs_json ?? [],
                'related_tests' => $module->related_tests_json ?? [],
                'reason' => $tags !== [] && str_contains($module->slug, $tags[0])
                    ? 'matched_code_context'
                    : 'important_code_module',
            ])
            ->values()
            ->all();
    }


    /**
     * @return array<string,mixed>
     */
    public function summary(array $options = []): array
    {
        if (! $this->tablesExist()) {
            return [
                'status' => 'not_migrated',
                'table_exists' => false,
                'module_count' => 0,
                'symbol_count' => 0,
                'doc_link_count' => 0,
            ];
        }

        if (is_string($options['workspace'] ?? null) && trim((string) $options['workspace']) !== '') {
            $this->workspaceId = app(CodeGraphWorkspaceIdentity::class)->resolve((string) $options['workspace']);
            $this->syncWorkspaceState();
        }

        $moduleQuery = AtlasEngineeringCodeModule::query()->active();
        if ($this->support->workspaceKeyed('atlas_engineering_code_modules')) {
            $moduleQuery->where('workspace_id', $this->workspaceId);
        }
        $moduleCount = $moduleQuery->count();

        // AP-815 C3: one GROUP BY for every symbol-type count, replacing 5 separate
        // aggregate scans (symbol_count + route/command/migration/test).
        $typeCountsQuery = AtlasEngineeringCodeSymbol::query()->active();
        if ($this->support->workspaceKeyed('atlas_engineering_code_symbols')) {
            $typeCountsQuery->where('workspace_id', $this->workspaceId);
        }
        $typeCounts = $typeCountsQuery
            ->selectRaw('symbol_type, count(*) as aggregate')
            ->groupBy('symbol_type')
            ->pluck('aggregate', 'symbol_type');
        $symbolCount = (int) $typeCounts->sum();

        $moduleIndexedAtQuery = AtlasEngineeringCodeModule::query()->active();
        if ($this->support->workspaceKeyed('atlas_engineering_code_modules')) {
            $moduleIndexedAtQuery->where('workspace_id', $this->workspaceId);
        }
        $symbolIndexedAtQuery = AtlasEngineeringCodeSymbol::query()->active();
        if ($this->support->workspaceKeyed('atlas_engineering_code_symbols')) {
            $symbolIndexedAtQuery->where('workspace_id', $this->workspaceId);
        }
        $lastIndexedAt = collect([
            $moduleIndexedAtQuery->max('indexed_at'),
            $symbolIndexedAtQuery->max('indexed_at'),
        ])->filter()->sort()->last();

        $docLinkQuery = AtlasEngineeringDocLink::query()->whereNull('archived_at');
        if ($this->support->workspaceKeyed('atlas_engineering_doc_links')) {
            $docLinkQuery->where('workspace_id', $this->workspaceId);
        }

        return [
            'status' => $moduleCount > 0 ? 'ready' : 'empty',
            'table_exists' => true,
            'module_count' => $moduleCount,
            'symbol_count' => $symbolCount,
            'doc_link_count' => $docLinkQuery->count(),
            'route_count' => (int) (($typeCounts['route'] ?? 0) + ($typeCounts['api_resource'] ?? 0)),
            'command_count' => (int) ($typeCounts['cli_command'] ?? 0),
            'migration_count' => (int) ($typeCounts['migration_table'] ?? 0),
            'test_count' => (int) ($typeCounts['test_method'] ?? 0),
            'docs_status' => $this->groupedModuleCounts('docs_status'),
            'layers' => $this->groupedModuleCounts('layer'),
            'last_indexed_at' => $lastIndexedAt ? Carbon::parse($lastIndexedAt)->toJSON() : null,
        ];
    }


    /**
     * @return array<string,int>
     */
    private function groupedModuleCounts(string $column): array
    {
        $query = AtlasEngineeringCodeModule::query()->active();
        if ($this->support->workspaceKeyed('atlas_engineering_code_modules')) {
            $query->where('workspace_id', $this->workspaceId);
        }

        return $query
            ->select($column)
            ->selectRaw('count(*) as aggregate')
            ->groupBy($column)
            ->orderBy($column)
            ->pluck('aggregate', $column)
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }


    /**
     * @param  array<string,mixed>  $payload
     */
    private function recordToolRuntimeEvidence(string $operation, string $workspace, array $payload, array $context = []): void
    {
        try {
            $summary = (array) ($payload['summary'] ?? []);
            $drift = (array) ($summary['drift'] ?? []);
            $status = match ($operation) {
                'audit' => (($payload['status'] ?? null) === 'fresh' ? 'passed' : 'failed'),
                default => ($payload['ok'] ?? false) ? ((bool) ($payload['dry_run'] ?? false) ? 'skipped' : 'passed') : 'failed',
            };

            $this->toolEvidence->recordExternalToolResult('atlas_code_intelligence', $workspace, [
                'status' => $status,
                'required' => false,
                'failure_policy' => 'advisory',
                'policy_decision' => 'allowed',
                'duration_ms' => (int) ($payload['duration_ms'] ?? 0),
                'exit_code' => ($payload['ok'] ?? false) ? 0 : 1,
                'category' => 'code_intelligence',
                'metrics' => [
                    'operation' => $operation,
                    'module_count' => data_get($summary, 'module_count', data_get($summary, 'scanned.module_count')),
                    'symbol_count' => data_get($summary, 'symbol_count', data_get($summary, 'scanned.symbol_count')),
                    'route_count' => data_get($summary, 'route_count', data_get($summary, 'scanned.route_count')),
                    'command_count' => data_get($summary, 'command_count', data_get($summary, 'scanned.command_count')),
                    'migration_count' => data_get($summary, 'migration_count', data_get($summary, 'scanned.migration_count')),
                    'test_count' => data_get($summary, 'test_count', data_get($summary, 'scanned.test_count')),
                    'doc_link_count' => data_get($summary, 'doc_link_count', data_get($summary, 'scanned.doc_link_count')),
                    'drift_total' => data_get($drift, 'total'),
                    'performance_status' => data_get($payload, 'performance.status'),
                    'memory_peak_mb' => data_get($payload, 'performance.memory_peak_mb'),
                    'phase_timings_ms' => data_get($payload, 'performance.phase_timings_ms'),
                ],
                'findings' => $operation === 'audit' ? $this->toolRuntimeAuditFindings($payload) : [],
                'recommendations' => $operation === 'audit' && ($payload['status'] ?? null) !== 'fresh'
                    ? ['Run code intelligence indexing with --prune after reviewing drift, then update canonical docs if module ownership changed.']
                    : [],
            ], [
                'surface' => 'engineering_code_intelligence',
                'source' => 'engineering_code_intelligence_service',
                'run_context_type' => $context['run_context_type'] ?? null,
                'run_context_id' => $context['run_context_id'] ?? null,
                'metadata' => [
                    'operation' => $operation,
                    // AP-815 W-5: tag the code-graph outcome/evidence record with the resolved
                    // workspace_id so a second project's runs are distinguishable from the
                    // primary atlas-server graph. Additive — written into the metadata map the
                    // evidence store already spreads into metadata_json; no schema change.
                    'workspace_id' => $this->workspaceId,
                    'dry_run' => (bool) ($payload['dry_run'] ?? false),
                    'writes' => (bool) ($payload['writes'] ?? ! (bool) ($payload['dry_run'] ?? false)),
                    'status' => $payload['status'] ?? null,
                    'duration_ms' => (int) ($payload['duration_ms'] ?? 0),
                    'performance_status' => data_get($payload, 'performance.status'),
                    'memory_peak_mb' => data_get($payload, 'performance.memory_peak_mb'),
                    'phase_timings_ms' => data_get($payload, 'performance.phase_timings_ms'),
                    'generated_at' => $payload['generated_at'] ?? null,
                ],
            ]);
        } catch (Throwable) {
            // Code intelligence must remain usable even when the generic runtime tables are absent.
        }
    }


    /**
     * @param  array<string,mixed>  $options
     * @return array{run_context_type:?string,run_context_id:?string}
     */
    private function toolRuntimeContext(array $options): array
    {
        return [
            'run_context_type' => $this->nullableString($options['run_context_type'] ?? null),
            'run_context_id' => $this->nullableString($options['run_context_id'] ?? null),
        ];
    }


    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }


    /**
     * @param  array<string,mixed>  $payload
     * @return array<int,array<string,mixed>>
     */
    private function toolRuntimeAuditFindings(array $payload): array
    {
        $findings = [];

        foreach (['missing_in_index', 'removed_from_workspace', 'changed'] as $bucket) {
            foreach ((array) data_get($payload, 'drift.modules.'.$bucket, []) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $findings[] = [
                    'rule_id' => 'atlas_code_intelligence.module_'.$this->safeDriftType($item['drift_type'] ?? $bucket),
                    'title' => 'Code intelligence module drift: '.(string) ($item['slug'] ?? 'unknown'),
                    'message' => (string) ($item['drift_type'] ?? $bucket),
                    'severity' => 'medium',
                    'file_path' => $item['root_path'] ?? null,
                    'blocks_resolved' => false,
                    'metadata' => $item,
                ];
            }
        }

        foreach (['added', 'removed'] as $bucket) {
            foreach ((array) data_get($payload, 'drift.symbols.'.$bucket, []) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $findings[] = [
                    'rule_id' => 'atlas_code_intelligence.symbol_'.$this->safeDriftType($item['drift_type'] ?? $bucket),
                    'title' => 'Code intelligence symbol drift: '.(string) ($item['symbol_name'] ?? 'unknown'),
                    'message' => (string) ($item['drift_type'] ?? $bucket),
                    'severity' => 'low',
                    'file_path' => $item['file_path'] ?? null,
                    'line' => $item['line_start'] ?? null,
                    'blocks_resolved' => false,
                    'metadata' => $item,
                ];
            }
        }

        foreach (['missing_targets', 'stale_target_hashes'] as $bucket) {
            foreach ((array) data_get($payload, 'drift.doc_links.'.$bucket, []) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $findings[] = [
                    'rule_id' => 'atlas_code_intelligence.doc_link_'.$this->safeDriftType($item['status'] ?? $bucket),
                    'title' => 'Code intelligence doc link drift',
                    'message' => (string) ($item['status'] ?? $bucket),
                    'severity' => 'medium',
                    'file_path' => $item['canonical_path'] ?? null,
                    'blocks_resolved' => false,
                    'metadata' => $item,
                ];
            }
        }

        return array_slice($findings, 0, 100);
    }


    private function safeDriftType(mixed $value): string
    {
        return Str::slug((string) $value, '_') ?: 'drift';
    }


    private function elapsedMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }


    /**
     * @param  array<string,int>  $phaseTimings
     */
    private function recordPhase(array &$phaseTimings, string $phase, float $startedAt): void
    {
        $phaseTimings[$phase] = $this->elapsedMs($startedAt);
    }


    /**
     * @param  array<string,int>  $phaseTimings
     * @param  array<string,mixed>  $summary
     * @return array<string,mixed>
     */
    private function performanceProfile(int $durationMs, array $phaseTimings, array $summary, array $cache = []): array
    {
        $fileCount = max(1, (int) ($summary['file_count'] ?? 0));
        $symbolCount = max(0, (int) ($summary['symbol_count'] ?? 0));
        $docLinkCount = max(0, (int) ($summary['doc_link_count'] ?? 0));
        $seconds = max(0.001, $durationMs / 1000);

        return [
            'schema_version' => 'atlas.code_intelligence.performance.v1',
            'status' => $this->performanceStatus($durationMs, $summary),
            'phase_timings_ms' => $phaseTimings,
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'throughput' => [
                'files_per_second' => round($fileCount / $seconds, 2),
                'symbols_per_second' => round($symbolCount / $seconds, 2),
                'doc_links_per_second' => round($docLinkCount / $seconds, 2),
            ],
            'cache' => $cache,
            'budgets' => [
                'target_index_ms' => 120000,
                'target_audit_ms' => 30000,
                'memory_limit' => ini_get('memory_limit') ?: null,
            ],
        ];
    }


    /**
     * @param  array<string,mixed>  $summary
     */
    private function performanceStatus(int $durationMs, array $summary): string
    {
        $symbolCount = (int) ($summary['symbol_count'] ?? 0);
        $docLinkCount = (int) ($summary['doc_link_count'] ?? 0);
        $targetMs = $docLinkCount > 0 ? 120000 : 30000;

        if ($durationMs <= $targetMs) {
            return 'healthy';
        }

        if ($symbolCount > 50000 && $durationMs <= 180000) {
            return 'watch';
        }

        return 'slow';
    }


    /**
     * @return array{modules:array<int,array<string,mixed>>,symbols:array<int,array<string,mixed>>,summary:array<string,mixed>,file_snapshots:array<int,array<string,mixed>>,file_paths:array<int,string>,cache:array<string,mixed>}
     */
    private function scanWorkspace(string $workspace, bool $useFileSnapshots = false, bool $collectSymbolRows = true): array
    {
        $files = $this->discovery->discoverFiles($workspace);
        $snapshots = $useFileSnapshots ? $this->snapshot->loadFileSnapshots(true) : [];
        $modules = [];
        $symbols = [];
        $summaryCounts = $this->scanSummarySection->emptyScanSummaryCounts();
        $testPathSet = [];
        $fileHashes = [];
        $fileSnapshots = [];
        $cacheHits = 0;
        $cacheMisses = 0;
        $treeSitterEnabled = $this->treeSitter->treeSitterEnabled();
        $treeSitterDeferred = [];
        $analysisPrefixes = $this->discovery->workspaceAnalysisPrefixes($workspace);

        foreach ($files as $path) {
            $relativePath = $this->relativePath($path, $workspace);
            $analysisPath = $this->discovery->analysisPathForRelativePath($relativePath, $analysisPrefixes);
            $module = $this->discovery->qualifyModuleForAnalysisPath(
                $this->moduleExtractor->moduleForPath($analysisPath),
                $relativePath,
                $analysisPath,
            );
            $modules[$module['slug']] ??= $this->scanSummarySection->emptyModule($module);
            $language = $this->scanSummarySection->languageForPath($analysisPath);
            $snapshot = $snapshots[$relativePath] ?? null;
            unset($snapshots[$relativePath]);

            // AP-815 C2: mtime short-circuit — an unchanged file (matching mtime + the
            // current extractor version baked into source_hash) is trusted WITHOUT reading
            // or hashing it. Any mismatch falls through to a full read, so the content-hash
            // cache still catches touched-but-identical files.
            $mtimeRaw = @filemtime($path);
            $mtime = is_int($mtimeRaw) ? $mtimeRaw : 0;
            $mtimeHit = is_array($snapshot)
                && $mtime > 0
                && (int) ($snapshot['mtime'] ?? -1) === $mtime
                && is_string($snapshot['file_hash'] ?? null)
                && ($snapshot['file_hash'] ?? '') !== ''
                && ($snapshot['source_hash'] ?? '') === $this->snapshot->fileSnapshotCacheKey((string) $snapshot['file_hash']);

            if ($mtimeHit) {
                $content = null;
                $fileHash = (string) $snapshot['file_hash'];
                $snapshotKey = (string) $snapshot['source_hash'];
                $fileSize = (int) ($snapshot['file_size'] ?? 0);
            } else {
                $content = File::get($path);
                $fileHash = hash('sha256', $content);
                $snapshotKey = $this->snapshot->fileSnapshotCacheKey($fileHash);
                $fileSize = strlen($content);
            }
            $fileHashes[$relativePath] = $fileHash;

            $modules[$module['slug']]['files'][$relativePath] = [
                'path' => $relativePath,
                'hash' => $fileHash,
                'language' => $language,
            ];
            $modules[$module['slug']]['languages'][$language] = ($modules[$module['slug']]['languages'][$language] ?? 0) + 1;

            $fileMetadata = [
                'file_hash' => $fileHash,
                'extension' => pathinfo($relativePath, PATHINFO_EXTENSION),
            ];
            if ($analysisPath !== $relativePath) {
                $fileMetadata['analysis_path'] = $analysisPath;
                $fileMetadata['analysis_prefix'] = $this->discovery->analysisPrefixForRelativePath($relativePath, $analysisPath);
            }

            $fileSymbol = $this->symbolExtractor->symbol([
                'module_slug' => $module['slug'],
                'symbol_type' => 'file',
                'symbol_name' => $relativePath,
                'file_path' => $relativePath,
                'line_start' => 1,
                'language' => $language,
                'signature' => $relativePath,
                'metadata' => $fileMetadata,
            ]);
            if ($collectSymbolRows) {
                $symbols[] = $fileSymbol;
            } else {
                $this->scanSummarySection->countScannedSymbol($modules, $fileSymbol, $summaryCounts, $testPathSet);
            }

            // AP-815 C1: serve the cache hit from the bulk-loaded snapshot (no per-file query).
            $cached = (is_array($snapshot) && ($snapshot['source_hash'] ?? null) === $snapshotKey)
                ? $snapshot
                : null;

            // AP-815 C2 safety: a parse path needs the file content; if the mtime shortcut
            // skipped the read but this is NOT a cache hit, read + re-hash now so mtime can
            // never feed a stale/wrong parse.
            if ($cached === null && $content === null) {
                $content = File::get($path);
                $fileHash = hash('sha256', $content);
                $snapshotKey = $this->snapshot->fileSnapshotCacheKey($fileHash);
                $fileSize = strlen($content);
                $fileHashes[$relativePath] = $fileHash;
                $cached = (is_array($snapshot) && ($snapshot['source_hash'] ?? null) === $snapshotKey)
                    ? $snapshot
                    : null;
            }

            if (is_array($cached)) {
                $parsedSymbols = $this->snapshot->normalizeCachedSymbols($this->snapshot->snapshotSymbols($cached), $module['slug']);
                $relations = $this->snapshot->normalizeCachedRelations($this->snapshot->snapshotRelations($cached), $module['slug'], $relativePath);
                $cacheHits++;
            } elseif ($treeSitterEnabled && $this->treeSitter->treeSitterLanguage($analysisPath) !== null) {
                // AP-815 A1: defer non-PHP source files to one batched tree-sitter pass
                // below (real AST symbols replace the JS/TS regex + add other languages).
                $relations = $this->treeSitter->withIndexedRelationPaths(
                    $this->parseExtractor->parseFileRelations($analysisPath, (string) $content, $module['slug']),
                    $relativePath,
                    $analysisPath,
                );
                $treeSitterDeferred[$relativePath] = [
                    'content' => (string) $content,
                    'module_slug' => $module['slug'],
                    'language' => $language,
                    'analysis_path' => $analysisPath,
                    'snapshot_key' => $snapshotKey,
                    'length' => $fileSize,
                    'file_hash' => $fileHash,
                    'mtime' => $mtime,
                    'relations' => $relations,
                ];
                $parsedSymbols = [];
                $cacheMisses++;
            } else {
                $parsedSymbols = $this->treeSitter->withIndexedSymbolPaths(
                    $this->parseExtractor->parseFileSymbols($analysisPath, (string) $content, $module['slug']),
                    $relativePath,
                    $analysisPath,
                );
                $relations = $this->treeSitter->withIndexedRelationPaths(
                    $this->parseExtractor->parseFileRelations($analysisPath, (string) $content, $module['slug']),
                    $relativePath,
                    $analysisPath,
                );
                $fileSnapshots[] = $this->snapshot->fileSnapshotRow($relativePath, $module['slug'], $language, $snapshotKey, $fileSize, $fileHash, $mtime, $parsedSymbols, $relations);
                $cacheMisses++;
            }

            foreach ($parsedSymbols as $symbol) {
                if ($collectSymbolRows) {
                    $symbols[] = $symbol;
                } else {
                    $this->scanSummarySection->countScannedSymbol($modules, $symbol, $summaryCounts, $testPathSet);
                }
            }
            $modules[$module['slug']]['dependencies'] = array_merge($modules[$module['slug']]['dependencies'], $relations['dependencies']);
            $modules[$module['slug']]['symbol_references'] = array_merge($modules[$module['slug']]['symbol_references'], $relations['symbol_references']);
            $modules[$module['slug']]['test_targets'] = array_merge($modules[$module['slug']]['test_targets'], $relations['test_targets']);
            unset($content, $cached, $parsedSymbols, $relations);
        }
        unset($snapshots);

        // AP-815 A1: one batched tree-sitter pass for the deferred non-PHP files. If the
        // runtime is blocked/unavailable, extract() returns [] and each file falls back
        // to the existing regex/empty parser — so behaviour is identical with the flag off.
        if ($treeSitterDeferred !== []) {
            $batch = [];
            foreach ($treeSitterDeferred as $deferredPath => $deferred) {
                $batch[] = [
                    'path' => $deferredPath,
                    'language' => (string) $this->treeSitter->treeSitterLanguage((string) $deferred['analysis_path']),
                    'content' => $deferred['content'],
                ];
            }
            $extracted = app(\App\Services\Engineering\CodeGraph\CodeGraphTreeSitterExtractor::class)->extract($batch);
            foreach ($treeSitterDeferred as $deferredPath => $deferred) {
                $analysisPath = (string) $deferred['analysis_path'];
                $parsedSymbols = isset($extracted[$deferredPath])
                    ? $this->treeSitter->mapTreeSitterSymbols($extracted[$deferredPath], $deferredPath, $deferred['module_slug'])
                    : $this->parseExtractor->parseFileSymbols($analysisPath, $deferred['content'], $deferred['module_slug']);
                $parsedSymbols = $this->treeSitter->withIndexedSymbolPaths($parsedSymbols, $deferredPath, $analysisPath);
                foreach ($parsedSymbols as $symbol) {
                    if ($collectSymbolRows) {
                        $symbols[] = $symbol;
                    } else {
                        $this->scanSummarySection->countScannedSymbol($modules, $symbol, $summaryCounts, $testPathSet);
                    }
                }
                $fileSnapshots[] = $this->snapshot->fileSnapshotRow(
                    $deferredPath,
                    $deferred['module_slug'],
                    $deferred['language'],
                    $deferred['snapshot_key'],
                    $deferred['length'],
                    (string) ($deferred['file_hash'] ?? ''),
                    (int) ($deferred['mtime'] ?? 0),
                    $parsedSymbols,
                    $deferred['relations'],
                );
                unset($treeSitterDeferred[$deferredPath], $parsedSymbols);
            }
            unset($batch, $extracted, $treeSitterDeferred);
        }

        if ($collectSymbolRows) {
            foreach ($symbols as $symbol) {
                $moduleSlug = (string) ($symbol['module_slug'] ?? '');
                if (isset($modules[$moduleSlug]) && $symbol['symbol_type'] !== 'file') {
                    $modules[$moduleSlug]['symbol_count']++;
                    match ($symbol['symbol_type']) {
                        'route', 'api_resource' => $modules[$moduleSlug]['route_count']++,
                        'cli_command' => $modules[$moduleSlug]['command_count']++,
                        'migration_table' => $modules[$moduleSlug]['migration_count']++,
                        'test_method' => $modules[$moduleSlug]['test_count']++,
                        default => null,
                    };
                }
            }

            $testPaths = collect($symbols)
                ->where('symbol_type', 'test_method')
                ->pluck('file_path')
                ->unique()
                ->values()
                ->all();
        } else {
            $testPaths = array_keys($testPathSet);
        }

        $moduleRows = collect($modules)
            ->map(fn (array $module): array => $this->scanSummarySection->finalizeModule($module, $fileHashes, $testPaths))
            ->values()
            ->all();
        $symbolRows = $collectSymbolRows
            ? collect($symbols)
                ->map(fn (array $symbol): array => $this->scanSummarySection->finalizeSymbol($symbol))
                ->values()
                ->all()
            : [];

        return [
            'modules' => $moduleRows,
            'symbols' => $symbolRows,
            'summary' => $collectSymbolRows
                ? $this->scanSummarySection->scanSummary($moduleRows, $symbolRows, 0)
                : $this->scanSummarySection->scanSummaryFromCounts($moduleRows, $summaryCounts, 0),
            'file_snapshots' => $fileSnapshots,
            'file_paths' => array_keys($fileHashes),
            'cache' => [
                'schema_version' => 'atlas.code_intelligence.file_snapshot_cache.v1',
                'enabled' => $useFileSnapshots && $this->snapshot->fileSnapshotsTableExists(),
                'strategy' => 'content_hash_and_extractor_version_file_snapshot',
                'hits' => $cacheHits,
                'misses' => $cacheMisses,
                'writes_planned' => count($fileSnapshots),
                'hit_rate' => round($cacheHits / max(1, $cacheHits + $cacheMisses), 4),
                'quality_guard' => [
                    'key' => 'sha256_file_content_plus_extractor_version',
                    'extractor_version' => self::EXTRACTOR_VERSION,
                    'mtime_only' => false,
                    'stale_cache_allowed' => false,
                ],
            ],
        ];
    }


    private function modulePayload(AtlasEngineeringCodeModule $module): array
    {
        return $this->moduleExtractor->modulePayload($module);
    }


    private function symbolPayload(AtlasEngineeringCodeSymbol $symbol): array
    {
        return $this->symbolExtractor->symbolPayload($symbol);
    }


    private function docLinkPayload(AtlasEngineeringDocLink $link): array
    {
        return [
            'id' => $link->id,
            'knowledge_item_id' => $link->knowledge_item_id,
            'module_id' => $link->module_id,
            'symbol_id' => $link->symbol_id,
            'link_type' => $link->link_type,
            'status' => $link->status,
            'canonical_path' => $link->canonical_path,
            'target_path' => $link->target_path,
            'doc_hash' => $link->doc_hash,
            'target_hash' => $link->target_hash,
            'metadata' => $link->metadata ?? [],
            'indexed_at' => $link->indexed_at?->toJSON(),
        ];
    }


    private function ensureTables(): void
    {
        if (! $this->tablesExist()) {
            throw new RuntimeException('Tabelas de code intelligence ainda nao existem. Rode migrations.');
        }
    }


    private function tablesExist(): bool
    {
        return DatabaseTableAvailability::all([
            'atlas_engineering_code_modules',
            'atlas_engineering_code_symbols',
            'atlas_engineering_doc_links',
        ]);
    }


    /**
     * @return list<string>
     */
    private function missingTables(): array
    {
        $expected = [
            'atlas_engineering_code_modules',
            'atlas_engineering_code_symbols',
            'atlas_engineering_doc_links',
        ];
        $missing = [];
        foreach ($expected as $table) {
            if (! DatabaseTableAvailability::all([$table])) {
                $missing[] = $table;
            }
        }

        return $missing;
    }


    /**
     * @return array<string,mixed>
     */
    private function tablesMissingPayload(string $schemaVersion): array
    {
        return [
            'schema_version' => $schemaVersion,
            'status' => 'blocked',
            'blocker' => 'code_intelligence_tables_missing',
            'missing_tables' => $this->missingTables(),
            'repair_hint' => 'Run: php artisan migrate --path=database/migrations to create code intelligence tables.',
            'generated_at' => now()->toJSON(),
        ];
    }


    private function workspace(mixed $workspace): string
    {
        $workspace = is_scalar($workspace) && trim((string) $workspace) !== '' ? trim((string) $workspace) : base_path();
        try {
            if (function_exists('app')) {
                $profile = app(AtlasCodeWorkspaceProfileService::class)->findByReference($workspace);
                $profilePath = is_array($profile) ? trim((string) ($profile['workspace_path'] ?? '')) : '';
                if ($profilePath !== '' && File::isDirectory($profilePath)) {
                    $workspace = $profilePath;
                }
            }
        } catch (Throwable) {
            // Keep the original path/id fallback below.
        }

        $real = realpath($workspace);

        return $real !== false ? $real : $workspace;
    }


    private function relativePath(string $path, string $workspace): string
    {
        $base = rtrim($workspace, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $relative = str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;

        return str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }


    private function contextInput(): EngineeringContextIntelligenceInput
    {
        return $this->input ?? app(EngineeringContextIntelligenceInput::class);
    }
}

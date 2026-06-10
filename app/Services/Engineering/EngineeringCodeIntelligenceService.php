<?php

namespace App\Services\Engineering;

use App\Models\AtlasEngineeringCodeModule;
use App\Models\AtlasEngineeringCodeSymbol;
use App\Models\AtlasEngineeringDocLink;
use App\Models\AtlasEngineeringKnowledgeItem;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use App\Services\Tools\AtlasToolEvidenceStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\ParserFactory;
use RuntimeException;
use SplFileInfo;
use Throwable;

class EngineeringCodeIntelligenceService
{
    // AP-815 A1: PHP/JS/MD keep their native parsers; the rest are extracted by the
    // tree-sitter runtime (CodeGraphTreeSitterExtractor) when the flag is on. atlas-server's
    // own Laravel roots contain none of the new languages except one finance .py script,
    // so this widens discovery for EXTERNAL repos without churning the primary graph.
    private const EXTENSIONS = ['php', 'ts', 'tsx', 'js', 'jsx', 'mjs', 'cjs', 'md', 'py', 'go', 'rs', 'java', 'rb', 'kt', 'kts', 'scala', 'swift', 'cpp', 'hpp', 'lua'];

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
     */
    private const EXTRACTOR_VERSION = 3;

    /**
     * Detailed symbol set diffs require a PHP hash index of scan keys. On the primary
     * Atlas repo the scan can exceed 100k symbols and may already sit near the CLI
     * memory ceiling; in that case module/source-hash drift is enough to block the gate
     * and let `code-gate --auto-refresh` repair the index without an OOM.
     */
    private const DETAILED_SYMBOL_DRIFT_SCAN_LIMIT = 25000;

    /** @var array<string,string|null> */
    private array $docLinkTargetHashCache = [];

    /** AP-815 W-1: resolved workspace_id for the current index() run (default = primary). */
    private string $workspaceId = 'atlas-server';

    /** @var array<string,bool> AP-815 W-1: memoized "is this table workspace-keyed?" checks. */
    private array $workspaceColumnCache = [];

    public function __construct(
        private readonly AtlasToolEvidenceStore $toolEvidence,
        private readonly ?EngineeringContextIntelligenceInput $input = null,
    ) {}

    /**
     * AP-815 W-1 — whether the code-intel read-model is workspace-keyed (post-migration).
     * Memoized; when false the writers fall back to the pre-keying single-workspace path,
     * so legacy / manually-built schemas keep working byte-identically.
     */
    private function workspaceKeyed(string $table = 'atlas_engineering_code_symbols'): bool
    {
        return $this->workspaceColumnCache[$table] ??= DatabaseTableAvailability::hasColumn($table, 'workspace_id');
    }

    /**
     * AP-815 W-1 — prepend workspace_id to an upsert conflict key when the table is keyed.
     *
     * @param  array<int,string>  $key
     * @return array<int,string>
     */
    private function workspaceConflictKey(string $table, array $key): array
    {
        return $this->workspaceKeyed($table) ? array_merge(['workspace_id'], $key) : $key;
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
        $moduleIds = $this->persistModules($moduleRows, $prune);
        $this->recordPhase($phaseTimings, 'persist_modules', $phaseStartedAt);
        $phaseStartedAt = microtime(true);
        $symbolCount = $this->persistSymbols($symbolRows, $moduleIds, $prune);
        $this->recordPhase($phaseTimings, 'persist_symbols', $phaseStartedAt);
        $phaseStartedAt = microtime(true);
        $this->persistFileSnapshots((array) ($scan['file_snapshots'] ?? []), $prune, (array) ($scan['file_paths'] ?? []));
        $this->recordPhase($phaseTimings, 'persist_file_snapshots', $phaseStartedAt);
        $phaseStartedAt = microtime(true);
        $docLinkCount = $this->syncDocLinks($workspace, $prune);
        $this->recordPhase($phaseTimings, 'sync_doc_links', $phaseStartedAt);
        $summary = $this->scanSummary($moduleRows, $symbolRows, $docLinkCount);
        $cache = (array) ($scan['cache'] ?? []);
        unset($scan, $moduleRows, $symbolRows, $moduleIds);
        $phaseStartedAt = microtime(true);
        $this->refreshDocumentationStatus();
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
        $normalized = trim($value);
        if ($normalized === '') {
            return 0;
        }

        $unit = strtolower(substr($normalized, -1));
        $number = (int) $normalized;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => (int) $normalized,
        };
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
        $this->ensureTables();

        $workspace = $this->workspace($options['workspace'] ?? base_path());
        $this->workspaceId = app(CodeGraphWorkspaceIdentity::class)->resolve($workspace);
        $limit = $this->contextInput()->codeLimit($options['limit'] ?? null);
        $context = $this->toolRuntimeContext($options);
        $persisted = $this->summary(['workspace' => $workspace]);
        $collectSymbolRows = (int) ($persisted['symbol_count'] ?? 0) <= self::DETAILED_SYMBOL_DRIFT_SCAN_LIMIT;
        $phaseStartedAt = microtime(true);
        $scan = $this->scanWorkspace($workspace, true, $collectSymbolRows);
        $this->recordPhase($phaseTimings, 'scan_workspace', $phaseStartedAt);
        $phaseStartedAt = microtime(true);
        $modules = $this->moduleDrift($scan['modules'], $limit);
        $this->recordPhase($phaseTimings, 'module_drift', $phaseStartedAt);
        $moduleDrift = array_sum($modules['counts']);
        $phaseStartedAt = microtime(true);
        $scannedSymbolCount = (int) data_get($scan, 'summary.symbol_count', count($scan['symbols']));
        $symbols = $this->canTrustModuleHashesForSymbolFreshness($moduleDrift, $scannedSymbolCount)
            ? $this->emptySymbolDrift()
            : ($this->shouldUseBoundedSymbolDrift($scannedSymbolCount)
                ? $this->boundedSymbolDrift($scannedSymbolCount)
                : $this->symbolDrift($scan['symbols'], $limit));
        $this->recordPhase($phaseTimings, 'symbol_drift', $phaseStartedAt);
        $phaseStartedAt = microtime(true);
        $docLinks = $this->docLinkHealth($workspace, $limit);
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

        $undocumented = (int) data_get($summary, 'docs_status.undocumented', 0);
        if ($undocumented > 0) {
            $warnings[] = 'undocumented_modules_present';
        }

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
            'critical_failures' => array_values(array_unique($criticalFailures)),
            'warnings' => array_values(array_unique($warnings)),
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
            && $this->workspaceKeyed('atlas_engineering_code_symbols')) {
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
        }

        $moduleQuery = AtlasEngineeringCodeModule::query()->active();
        if ($this->workspaceKeyed('atlas_engineering_code_modules')) {
            $moduleQuery->where('workspace_id', $this->workspaceId);
        }
        $moduleCount = $moduleQuery->count();

        // AP-815 C3: one GROUP BY for every symbol-type count, replacing 5 separate
        // aggregate scans (symbol_count + route/command/migration/test).
        $typeCountsQuery = AtlasEngineeringCodeSymbol::query()->active();
        if ($this->workspaceKeyed('atlas_engineering_code_symbols')) {
            $typeCountsQuery->where('workspace_id', $this->workspaceId);
        }
        $typeCounts = $typeCountsQuery
            ->selectRaw('symbol_type, count(*) as aggregate')
            ->groupBy('symbol_type')
            ->pluck('aggregate', 'symbol_type');
        $symbolCount = (int) $typeCounts->sum();

        $moduleIndexedAtQuery = AtlasEngineeringCodeModule::query()->active();
        if ($this->workspaceKeyed('atlas_engineering_code_modules')) {
            $moduleIndexedAtQuery->where('workspace_id', $this->workspaceId);
        }
        $symbolIndexedAtQuery = AtlasEngineeringCodeSymbol::query()->active();
        if ($this->workspaceKeyed('atlas_engineering_code_symbols')) {
            $symbolIndexedAtQuery->where('workspace_id', $this->workspaceId);
        }
        $lastIndexedAt = collect([
            $moduleIndexedAtQuery->max('indexed_at'),
            $symbolIndexedAtQuery->max('indexed_at'),
        ])->filter()->sort()->last();

        $docLinkQuery = AtlasEngineeringDocLink::query()->whereNull('archived_at');
        if ($this->workspaceKeyed('atlas_engineering_doc_links')) {
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
        if ($this->workspaceKeyed('atlas_engineering_code_modules')) {
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
        $files = $this->discoverFiles($workspace);
        $snapshots = $useFileSnapshots ? $this->loadFileSnapshots(true) : [];
        $modules = [];
        $symbols = [];
        $summaryCounts = $this->emptyScanSummaryCounts();
        $testPathSet = [];
        $fileHashes = [];
        $fileSnapshots = [];
        $cacheHits = 0;
        $cacheMisses = 0;
        $treeSitterEnabled = $this->treeSitterEnabled();
        $treeSitterDeferred = [];

        foreach ($files as $path) {
            $relativePath = $this->relativePath($path, $workspace);
            $module = $this->moduleForPath($relativePath);
            $modules[$module['slug']] ??= $this->emptyModule($module);
            $language = $this->languageForPath($relativePath);
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
                && ($snapshot['source_hash'] ?? '') === $this->fileSnapshotCacheKey((string) $snapshot['file_hash']);

            if ($mtimeHit) {
                $content = null;
                $fileHash = (string) $snapshot['file_hash'];
                $snapshotKey = (string) $snapshot['source_hash'];
                $fileSize = (int) ($snapshot['file_size'] ?? 0);
            } else {
                $content = File::get($path);
                $fileHash = hash('sha256', $content);
                $snapshotKey = $this->fileSnapshotCacheKey($fileHash);
                $fileSize = strlen($content);
            }
            $fileHashes[$relativePath] = $fileHash;

            $modules[$module['slug']]['files'][$relativePath] = [
                'path' => $relativePath,
                'hash' => $fileHash,
                'language' => $language,
            ];
            $modules[$module['slug']]['languages'][$language] = ($modules[$module['slug']]['languages'][$language] ?? 0) + 1;

            $fileSymbol = $this->symbol([
                'module_slug' => $module['slug'],
                'symbol_type' => 'file',
                'symbol_name' => $relativePath,
                'file_path' => $relativePath,
                'line_start' => 1,
                'language' => $language,
                'signature' => $relativePath,
                'metadata' => [
                    'file_hash' => $fileHash,
                    'extension' => pathinfo($relativePath, PATHINFO_EXTENSION),
                ],
            ]);
            if ($collectSymbolRows) {
                $symbols[] = $fileSymbol;
            } else {
                $this->countScannedSymbol($modules, $fileSymbol, $summaryCounts, $testPathSet);
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
                $snapshotKey = $this->fileSnapshotCacheKey($fileHash);
                $fileSize = strlen($content);
                $fileHashes[$relativePath] = $fileHash;
                $cached = (is_array($snapshot) && ($snapshot['source_hash'] ?? null) === $snapshotKey)
                    ? $snapshot
                    : null;
            }

            if (is_array($cached)) {
                $parsedSymbols = $this->normalizeCachedSymbols($this->snapshotSymbols($cached), $module['slug']);
                $relations = $this->normalizeCachedRelations($this->snapshotRelations($cached), $module['slug'], $relativePath);
                $cacheHits++;
            } elseif ($treeSitterEnabled && $this->treeSitterLanguage($relativePath) !== null) {
                // AP-815 A1: defer non-PHP source files to one batched tree-sitter pass
                // below (real AST symbols replace the JS/TS regex + add other languages).
                $relations = $this->parseFileRelations($relativePath, (string) $content, $module['slug']);
                $treeSitterDeferred[$relativePath] = [
                    'content' => (string) $content,
                    'module_slug' => $module['slug'],
                    'language' => $language,
                    'snapshot_key' => $snapshotKey,
                    'length' => $fileSize,
                    'file_hash' => $fileHash,
                    'mtime' => $mtime,
                    'relations' => $relations,
                ];
                $parsedSymbols = [];
                $cacheMisses++;
            } else {
                $parsedSymbols = $this->parseFileSymbols($relativePath, (string) $content, $module['slug']);
                $relations = $this->parseFileRelations($relativePath, (string) $content, $module['slug']);
                $fileSnapshots[] = $this->fileSnapshotRow($relativePath, $module['slug'], $language, $snapshotKey, $fileSize, $fileHash, $mtime, $parsedSymbols, $relations);
                $cacheMisses++;
            }

            foreach ($parsedSymbols as $symbol) {
                if ($collectSymbolRows) {
                    $symbols[] = $symbol;
                } else {
                    $this->countScannedSymbol($modules, $symbol, $summaryCounts, $testPathSet);
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
                    'language' => (string) $this->treeSitterLanguage($deferredPath),
                    'content' => $deferred['content'],
                ];
            }
            $extracted = app(\App\Services\Engineering\CodeGraph\CodeGraphTreeSitterExtractor::class)->extract($batch);
            foreach ($treeSitterDeferred as $deferredPath => $deferred) {
                $parsedSymbols = isset($extracted[$deferredPath])
                    ? $this->mapTreeSitterSymbols($extracted[$deferredPath], $deferredPath, $deferred['module_slug'])
                    : $this->parseFileSymbols($deferredPath, $deferred['content'], $deferred['module_slug']);
                foreach ($parsedSymbols as $symbol) {
                    if ($collectSymbolRows) {
                        $symbols[] = $symbol;
                    } else {
                        $this->countScannedSymbol($modules, $symbol, $summaryCounts, $testPathSet);
                    }
                }
                $fileSnapshots[] = $this->fileSnapshotRow(
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
            ->map(fn (array $module): array => $this->finalizeModule($module, $fileHashes, $testPaths))
            ->values()
            ->all();
        $symbolRows = $collectSymbolRows
            ? collect($symbols)
                ->map(fn (array $symbol): array => $this->finalizeSymbol($symbol))
                ->values()
                ->all()
            : [];

        return [
            'modules' => $moduleRows,
            'symbols' => $symbolRows,
            'summary' => $collectSymbolRows
                ? $this->scanSummary($moduleRows, $symbolRows, 0)
                : $this->scanSummaryFromCounts($moduleRows, $summaryCounts, 0),
            'file_snapshots' => $fileSnapshots,
            'file_paths' => array_keys($fileHashes),
            'cache' => [
                'schema_version' => 'atlas.code_intelligence.file_snapshot_cache.v1',
                'enabled' => $useFileSnapshots && $this->fileSnapshotsTableExists(),
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

    /**
     * @return array<string,mixed>
     */
    private function loadFileSnapshots(bool $withData = false): array
    {
        if (! $this->fileSnapshotsTableExists()) {
            return [];
        }

        // Keep snapshot payloads raw in memory. The full Atlas graph can hold >100k
        // symbols; decoding every snapshot up front duplicates most of that graph before
        // the scan has a chance to stream file-by-file through the cache.
        $hasMtime = $this->snapshotSupportsMtime();
        $columns = ['file_path', 'source_hash'];
        if ($withData) {
            $columns = array_merge($columns, ['file_size', 'symbols_json', 'relations_json']);
            if ($hasMtime) {
                $columns[] = 'mtime';
                $columns[] = 'file_hash';
            }
        }

        $snapshotQuery = DB::table('atlas_engineering_code_file_snapshots')
            ->select($columns)
            ->where('status', 'active')
            ->whereNull('archived_at');
        if ($this->workspaceKeyed('atlas_engineering_code_file_snapshots')) {
            $snapshotQuery->where('workspace_id', $this->workspaceId);
        }

        $snapshots = [];
        foreach ($snapshotQuery->cursor() as $row) {
            if (! $withData) {
                $snapshots[(string) $row->file_path] = (string) $row->source_hash;

                continue;
            }

            $entry = [
                'source_hash' => (string) $row->source_hash,
                'file_size' => (int) ($row->file_size ?? 0),
                'symbols_json' => $row->symbols_json ?? '[]',
                'relations_json' => $row->relations_json ?? '{}',
            ];
            if ($hasMtime) {
                $entry['mtime'] = $row->mtime !== null ? (int) $row->mtime : null;
                $entry['file_hash'] = $row->file_hash !== null ? (string) $row->file_hash : null;
            }

            $snapshots[(string) $row->file_path] = $entry;
        }

        return $snapshots;
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<int,array<string,mixed>>
     */
    private function snapshotSymbols(array $snapshot): array
    {
        return $this->decodedJsonArray($snapshot['symbols_json'] ?? ($snapshot['symbols'] ?? []));
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    private function snapshotRelations(array $snapshot): array
    {
        return $this->decodedJsonArray($snapshot['relations_json'] ?? ($snapshot['relations'] ?? []));
    }

    /**
     * @param  array<int,array<string,mixed>>  $symbols
     * @return array<int,array<string,mixed>>
     */
    private function normalizeCachedSymbols(array $symbols, string $moduleSlug): array
    {
        return collect($symbols)
            ->filter(fn (mixed $symbol): bool => is_array($symbol))
            ->map(function (array $symbol) use ($moduleSlug): array {
                $symbol['module_slug'] = $moduleSlug;

                return $this->symbol($symbol);
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $relations
     * @return array{dependencies:array<int,array<string,mixed>>,symbol_references:array<int,array<string,mixed>>,test_targets:array<int,array<string,mixed>>}
     */
    private function normalizeCachedRelations(array $relations, string $moduleSlug, string $relativePath): array
    {
        $normalizeRows = function (mixed $rows) use ($moduleSlug, $relativePath): array {
            return collect(is_array($rows) ? $rows : [])
                ->filter(fn (mixed $row): bool => is_array($row))
                ->map(function (array $row) use ($moduleSlug, $relativePath): array {
                    if (array_key_exists('from_module', $row)) {
                        $row['from_module'] = $moduleSlug;
                    }

                    if (! array_key_exists('file_path', $row) || blank($row['file_path'])) {
                        $row['file_path'] = $relativePath;
                    }

                    return $row;
                })
                ->values()
                ->all();
        };

        return [
            'dependencies' => $normalizeRows($relations['dependencies'] ?? []),
            'symbol_references' => $normalizeRows($relations['symbol_references'] ?? []),
            'test_targets' => $normalizeRows($relations['test_targets'] ?? []),
        ];
    }

    /**
     * Cache key for a file snapshot: the file content hash salted with the
     * current EXTRACTOR_VERSION. Stored in the snapshot source_hash column, which
     * is used ONLY as the snapshot cache key (module/symbol drift use their own
     * content-derived hashes). Salting with the version means a parser change
     * invalidates every key, forcing a fresh parse instead of replaying symbols
     * minted by a superseded parser.
     */
    private function fileSnapshotCacheKey(string $fileHash): string
    {
        return hash('sha256', self::EXTRACTOR_VERSION.'|'.$fileHash);
    }

    /**
     * @param  array<int,array<string,mixed>>  $symbols
     * @param  array{dependencies:array<int,array<string,mixed>>,symbol_references:array<int,array<string,mixed>>,test_targets:array<int,array<string,mixed>>}  $relations
     * @return array<string,mixed>
     */
    private function fileSnapshotRow(string $relativePath, string $moduleSlug, string $language, string $cacheKey, int $fileSize, string $fileHash, int $mtime, array $symbols, array $relations): array
    {
        $row = [
            'file_path' => $relativePath,
            'module_slug' => $moduleSlug,
            'language' => $language,
            'source_hash' => $cacheKey,
            'file_size' => $fileSize,
            'symbols_json' => $symbols,
            'relations_json' => $relations,
        ];

        // AP-815 C2: persist mtime + raw content hash only when the columns exist, so a
        // test booting the pre-C2 snapshot schema still writes cleanly.
        if ($this->snapshotSupportsMtime()) {
            $row['mtime'] = $mtime > 0 ? $mtime : null;
            $row['file_hash'] = $fileHash !== '' ? $fileHash : null;
        }

        return $row;
    }

    /** @var bool|null AP-815 C2: memoized whether the snapshot table carries mtime/file_hash. */
    private ?bool $snapshotMtimeSupported = null;

    private function snapshotSupportsMtime(): bool
    {
        return $this->snapshotMtimeSupported ??= (
            $this->fileSnapshotsTableExists()
            && DatabaseTableAvailability::hasColumn('atlas_engineering_code_file_snapshots', 'mtime')
            && DatabaseTableAvailability::hasColumn('atlas_engineering_code_file_snapshots', 'file_hash')
        );
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @param  array<int,string>  $seenPaths
     */
    private function persistFileSnapshots(array $rows, bool $prune, array $seenPaths): void
    {
        if (! $this->fileSnapshotsTableExists()) {
            return;
        }

        $now = now();
        $indexedAt = now()->startOfSecond();
        $keyed = $this->workspaceKeyed('atlas_engineering_code_file_snapshots');
        $batch = [];
        foreach ($rows as $row) {
            $entry = [
                'id' => (string) Str::uuid(),
                'file_path' => (string) $row['file_path'],
                'module_slug' => (string) $row['module_slug'],
                'language' => $row['language'] ?? null,
                'source_hash' => (string) $row['source_hash'],
                'file_size' => (int) ($row['file_size'] ?? 0),
                'symbols_json' => $this->json((array) ($row['symbols_json'] ?? [])),
                'relations_json' => $this->json((array) ($row['relations_json'] ?? [])),
                'status' => 'active',
                'indexed_at' => $indexedAt,
                'archived_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if ($this->snapshotSupportsMtime()) {
                // AP-815 C2: persist mtime + raw content hash for the next index's short-circuit.
                $entry['mtime'] = isset($row['mtime']) && $row['mtime'] !== null ? (int) $row['mtime'] : null;
                $entry['file_hash'] = isset($row['file_hash']) && $row['file_hash'] !== null ? (string) $row['file_hash'] : null;
            }
            if ($keyed) {
                $entry['workspace_id'] = $this->workspaceId;
            }
            $batch[] = $entry;

            if (count($batch) >= 500) {
                $this->upsertFileSnapshotRows($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $this->upsertFileSnapshotRows($batch);
        }

        if ($prune && $seenPaths !== []) {
            $pruneQuery = DB::table('atlas_engineering_code_file_snapshots')
                ->where('status', '!=', 'archived')
                ->whereNotIn('file_path', $seenPaths);
            if ($keyed) {
                $pruneQuery->where('workspace_id', $this->workspaceId);
            }
            $pruneQuery->update([
                'status' => 'archived',
                'archived_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     */
    private function upsertFileSnapshotRows(array $rows): void
    {
        $update = [
            'module_slug',
            'language',
            'source_hash',
            'file_size',
            'symbols_json',
            'relations_json',
            'status',
            'indexed_at',
            'archived_at',
            'updated_at',
        ];

        // AP-815 C2: refresh mtime + file_hash on update too (when the columns exist).
        if ($this->snapshotSupportsMtime()) {
            $update[] = 'mtime';
            $update[] = 'file_hash';
        }

        DB::table('atlas_engineering_code_file_snapshots')->upsert(
            $rows,
            $this->workspaceConflictKey('atlas_engineering_code_file_snapshots', ['file_path']),
            $update,
        );
    }

    private function fileSnapshotsTableExists(): bool
    {
        return DatabaseTableAvailability::has('atlas_engineering_code_file_snapshots');
    }

    /**
     * @return array<string|int,mixed>
     */
    private function decodedJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * @param  array<int,array<string,mixed>>  $moduleRows
     * @return array<string,mixed>
     */
    private function moduleDrift(array $moduleRows, int $limit): array
    {
        $scanned = collect($moduleRows)->keyBy('slug');
        $persistedQuery = AtlasEngineeringCodeModule::query()->active();
        if ($this->workspaceKeyed('atlas_engineering_code_modules')) {
            $persistedQuery->where('workspace_id', $this->workspaceId);
        }

        $persisted = $persistedQuery
            ->get([
                'slug',
                'name',
                'layer',
                'root_path',
                'docs_status',
                'file_count',
                'symbol_count',
                'route_count',
                'command_count',
                'migration_count',
                'test_count',
                'source_hash',
                'indexed_at',
            ])
            ->keyBy('slug');
        $missingKeys = $scanned->keys()->diff($persisted->keys())->values();
        $removedKeys = $persisted->keys()->diff($scanned->keys())->values();
        $changed = $scanned
            ->keys()
            ->intersect($persisted->keys())
            ->map(fn (string $slug): ?array => $this->changedModulePayload($scanned->get($slug), $persisted->get($slug)))
            ->filter()
            ->values();

        return [
            'counts' => [
                'missing_in_index' => $missingKeys->count(),
                'removed_from_workspace' => $removedKeys->count(),
                'changed' => $changed->count(),
            ],
            'missing_in_index' => $missingKeys
                ->take($limit)
                ->map(fn (string $slug): array => $this->moduleAuditPayload($scanned->get($slug), 'missing_in_index'))
                ->values()
                ->all(),
            'removed_from_workspace' => $removedKeys
                ->take($limit)
                ->map(fn (string $slug): array => $this->persistedModuleAuditPayload($persisted->get($slug), 'removed_from_workspace'))
                ->values()
                ->all(),
            'changed' => $changed->take($limit)->values()->all(),
        ];
    }

    private function canTrustModuleHashesForSymbolFreshness(int $moduleDrift, int $scannedSymbolCount): bool
    {
        if ($moduleDrift !== 0) {
            return false;
        }

        return $this->persistedActiveSymbolCountForCurrentWorkspace() === $scannedSymbolCount;
    }

    private function shouldUseBoundedSymbolDrift(int $scannedSymbolCount): bool
    {
        return $scannedSymbolCount > self::DETAILED_SYMBOL_DRIFT_SCAN_LIMIT;
    }

    private function persistedActiveSymbolCountForCurrentWorkspace(): int
    {
        $query = DB::table('atlas_engineering_code_symbols')
            ->where('status', 'active')
            ->whereNull('archived_at');

        if ($this->workspaceKeyed('atlas_engineering_code_symbols')) {
            $query->where('workspace_id', $this->workspaceId);
        }

        return (int) $query->count();
    }

    /**
     * @return array<string,mixed>
     */
    private function boundedSymbolDrift(int $scannedSymbolCount): array
    {
        $persistedCount = $this->persistedActiveSymbolCountForCurrentWorkspace();

        return [
            'counts' => [
                'added' => max(0, $scannedSymbolCount - $persistedCount),
                'removed' => max(0, $persistedCount - $scannedSymbolCount),
            ],
            'by_type' => [
                'added' => [],
                'removed' => [],
            ],
            'added' => [],
            'removed' => [],
            'detail_limited' => true,
            'detail_limit_reason' => 'scan_symbol_count_exceeds_memory_safe_diff_limit',
            'scanned_symbol_count' => $scannedSymbolCount,
            'persisted_symbol_count' => $persistedCount,
            'detailed_scan_limit' => self::DETAILED_SYMBOL_DRIFT_SCAN_LIMIT,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function emptySymbolDrift(): array
    {
        return [
            'counts' => [
                'added' => 0,
                'removed' => 0,
            ],
            'by_type' => [
                'added' => [],
                'removed' => [],
            ],
            'added' => [],
            'removed' => [],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $symbolRows
     * @return array<string,mixed>
     */
    private function symbolDrift(array $symbolRows, int $limit): array
    {
        $scannedTypesByKey = [];
        foreach ($symbolRows as $symbol) {
            $scannedTypesByKey[$this->symbolSourceKey($symbol)] = (string) ($symbol['symbol_type'] ?? 'unknown');
        }

        $persistedQuery = DB::table('atlas_engineering_code_symbols as symbols')
            ->leftJoin('atlas_engineering_code_modules as modules', 'modules.id', '=', 'symbols.module_id')
            ->where('symbols.status', 'active')
            ->whereNull('symbols.archived_at');

        if ($this->workspaceKeyed('atlas_engineering_code_symbols')) {
            $persistedQuery->where('symbols.workspace_id', $this->workspaceId);
        }

        $removedCount = 0;
        $removedTypeCounts = [];
        $removedSamples = [];

        foreach ($persistedQuery
            ->select([
                'symbols.id',
                'symbols.module_id',
                'modules.slug as module_slug',
                'symbols.symbol_type',
                'symbols.symbol_name',
                'symbols.file_path',
                'symbols.line_start',
                'symbols.language',
                'symbols.source_hash',
                'symbols.indexed_at',
            ])
            ->orderBy('symbols.id')
            ->cursor() as $symbol) {
            $payload = [
                'id' => (string) $symbol->id,
                'module_id' => $symbol->module_id,
                'module_slug' => $symbol->module_slug,
                'symbol_type' => (string) $symbol->symbol_type,
                'symbol_name' => (string) $symbol->symbol_name,
                'file_path' => (string) $symbol->file_path,
                'line_start' => $symbol->line_start,
                'language' => $symbol->language,
                'source_hash' => (string) $symbol->source_hash,
                'indexed_at' => $symbol->indexed_at,
            ];
            $key = $this->symbolSourceKey($payload);

            if (array_key_exists($key, $scannedTypesByKey)) {
                unset($scannedTypesByKey[$key]);

                continue;
            }

            $removedCount++;
            $symbolType = (string) ($payload['symbol_type'] ?? 'unknown');
            $removedTypeCounts[$symbolType] = ($removedTypeCounts[$symbolType] ?? 0) + 1;
            if (count($removedSamples) < $limit) {
                $removedSamples[] = $this->persistedSymbolAuditPayload($payload, 'removed');
            }
        }

        ksort($removedTypeCounts);

        $addedCount = count($scannedTypesByKey);
        $addedTypeCounts = array_count_values($scannedTypesByKey);
        ksort($addedTypeCounts);

        $addedSamples = [];
        if ($addedCount > 0 && $limit > 0) {
            foreach ($symbolRows as $symbol) {
                if (! array_key_exists($this->symbolSourceKey($symbol), $scannedTypesByKey)) {
                    continue;
                }

                $addedSamples[] = $this->symbolAuditPayload($symbol, 'added');
                if (count($addedSamples) >= $limit) {
                    break;
                }
            }
        }

        return [
            'counts' => [
                'added' => $addedCount,
                'removed' => $removedCount,
            ],
            'by_type' => [
                'added' => $addedTypeCounts,
                'removed' => $removedTypeCounts,
            ],
            'added' => $addedSamples,
            'removed' => $removedSamples,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function docLinkHealth(string $workspace, int $limit): array
    {
        $links = DB::table('atlas_engineering_doc_links')
            ->whereNull('archived_at')
            ->select(['id', 'status', 'canonical_path', 'target_path', 'target_hash', 'link_type', 'indexed_at'])
            ->orderBy('id')
            ->cursor();
        $missingTargets = [];
        $staleHashes = [];
        $currentCount = 0;
        $persistedMissingTargetCount = 0;
        $targetHashCache = [];

        foreach ($links as $link) {
            if ($link->status === 'current') {
                $currentCount++;
            }

            if ($link->status === 'missing_target') {
                $persistedMissingTargetCount++;
            }

            $targetPath = is_string($link->target_path) && trim($link->target_path) !== ''
                ? trim($link->target_path)
                : null;
            if ($targetPath === null) {
                continue;
            }

            $fullPath = $workspace.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $targetPath);
            if (! File::exists($fullPath)) {
                if (in_array($link->link_type, ['module_path', 'module_capability'], true)) {
                    continue;
                }

                if ($link->status !== 'missing_target') {
                    $missingTargets[] = $this->docLinkAuditPayload($link, 'missing_target_detected');
                }

                continue;
            }

            if (File::isFile($fullPath) && $link->target_hash) {
                if (! array_key_exists($fullPath, $targetHashCache)) {
                    $targetHashCache[$fullPath] = hash_file('sha256', $fullPath) ?: null;
                }

                if ($targetHashCache[$fullPath] !== $link->target_hash) {
                    $staleHashes[] = $this->docLinkAuditPayload($link, 'stale_target_hash');
                }
            }
        }

        return [
            'counts' => [
                'current' => $currentCount,
                'persisted_missing_target_status' => $persistedMissingTargetCount,
                'missing_targets' => count($missingTargets),
                'stale_target_hashes' => count($staleHashes),
            ],
            'missing_targets' => array_slice($missingTargets, 0, $limit),
            'stale_target_hashes' => array_slice($staleHashes, 0, $limit),
        ];
    }

    /**
     * @param  array<string,mixed>|null  $symbol
     * @return array<string,mixed>
     */
    private function persistedSymbolAuditPayload(?array $symbol, string $reason): array
    {
        return [
            'reason' => $reason,
            'module_slug' => $symbol['module_slug'] ?? null,
            'symbol_type' => (string) ($symbol['symbol_type'] ?? ''),
            'symbol_name' => (string) ($symbol['symbol_name'] ?? ''),
            'file_path' => (string) ($symbol['file_path'] ?? ''),
            'line_start' => $symbol['line_start'] ?? null,
            'language' => $symbol['language'] ?? null,
            'source_hash' => (string) ($symbol['source_hash'] ?? ''),
            'indexed_at' => $symbol['indexed_at'] ?? null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function docLinkAuditPayload(object $link, string $reason): array
    {
        return [
            'reason' => $reason,
            'id' => $link->id,
            'status' => $link->status,
            'link_type' => $link->link_type,
            'canonical_path' => $link->canonical_path,
            'target_path' => $link->target_path,
            'target_hash' => $link->target_hash,
            'indexed_at' => is_object($link->indexed_at) && method_exists($link->indexed_at, 'toJSON')
                ? $link->indexed_at->toJSON()
                : $link->indexed_at,
        ];
    }

    /**
     * @param  array<string,mixed>|null  $scanned
     * @return array<string,mixed>|null
     */
    private function changedModulePayload(?array $scanned, ?AtlasEngineeringCodeModule $persisted): ?array
    {
        if ($scanned === null || $persisted === null) {
            return null;
        }

        $fields = ['source_hash', 'file_count', 'symbol_count', 'route_count', 'command_count', 'migration_count', 'test_count'];
        $differences = [];
        foreach ($fields as $field) {
            $scannedValue = $scanned[$field] ?? null;
            $persistedValue = $persisted->{$field};
            if ((string) $scannedValue !== (string) $persistedValue) {
                $differences[$field] = [
                    'scanned' => $scannedValue,
                    'persisted' => $persistedValue,
                ];
            }
        }

        if ($differences === []) {
            return null;
        }

        return array_merge($this->moduleAuditPayload($scanned, 'changed'), [
            'persisted' => [
                'source_hash' => $persisted->source_hash,
                'file_count' => $persisted->file_count,
                'symbol_count' => $persisted->symbol_count,
                'route_count' => $persisted->route_count,
                'command_count' => $persisted->command_count,
                'migration_count' => $persisted->migration_count,
                'test_count' => $persisted->test_count,
                'indexed_at' => $persisted->indexed_at?->toJSON(),
            ],
            'differences' => $differences,
        ]);
    }

    /**
     * @param  array<string,mixed>|null  $module
     * @return array<string,mixed>
     */
    private function moduleAuditPayload(?array $module, string $reason): array
    {
        return [
            'reason' => $reason,
            'slug' => (string) ($module['slug'] ?? ''),
            'name' => (string) ($module['name'] ?? ''),
            'layer' => (string) ($module['layer'] ?? ''),
            'root_path' => $module['root_path'] ?? null,
            'source_hash' => (string) ($module['source_hash'] ?? ''),
            'file_count' => (int) ($module['file_count'] ?? 0),
            'symbol_count' => (int) ($module['symbol_count'] ?? 0),
            'route_count' => (int) ($module['route_count'] ?? 0),
            'command_count' => (int) ($module['command_count'] ?? 0),
            'migration_count' => (int) ($module['migration_count'] ?? 0),
            'test_count' => (int) ($module['test_count'] ?? 0),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function persistedModuleAuditPayload(?AtlasEngineeringCodeModule $module, string $reason): array
    {
        return [
            'reason' => $reason,
            'slug' => (string) ($module?->slug ?? ''),
            'name' => (string) ($module?->name ?? ''),
            'layer' => (string) ($module?->layer ?? ''),
            'root_path' => $module?->root_path,
            'docs_status' => (string) ($module?->docs_status ?? ''),
            'source_hash' => (string) ($module?->source_hash ?? ''),
            'file_count' => (int) ($module?->file_count ?? 0),
            'symbol_count' => (int) ($module?->symbol_count ?? 0),
            'route_count' => (int) ($module?->route_count ?? 0),
            'command_count' => (int) ($module?->command_count ?? 0),
            'migration_count' => (int) ($module?->migration_count ?? 0),
            'test_count' => (int) ($module?->test_count ?? 0),
            'indexed_at' => $module?->indexed_at?->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $symbol
     */
    private function symbolSourceKey(array $symbol): string
    {
        return (string) ($symbol['symbol_type'] ?? '').'|'.(string) ($symbol['source_hash'] ?? '');
    }

    /**
     * @param  Collection<int,string>  $keys
     * @param  Collection<string,mixed>  $symbols
     * @return array<string,int>
     */
    private function symbolDriftTypeCounts(Collection $keys, Collection $symbols): array
    {
        return $keys
            ->map(fn (string $key): string => (string) data_get($symbols->get($key), 'symbol_type', 'unknown'))
            ->countBy()
            ->sortKeys()
            ->map(fn (int $count): int => $count)
            ->all();
    }

    /**
     * @param  array<string,mixed>|null  $symbol
     * @return array<string,mixed>
     */
    private function symbolAuditPayload(?array $symbol, string $reason): array
    {
        return [
            'reason' => $reason,
            'module_slug' => $symbol['module_slug'] ?? null,
            'symbol_type' => (string) ($symbol['symbol_type'] ?? ''),
            'symbol_name' => (string) ($symbol['symbol_name'] ?? ''),
            'file_path' => (string) ($symbol['file_path'] ?? ''),
            'line_start' => $symbol['line_start'] ?? null,
            'language' => $symbol['language'] ?? null,
            'source_hash' => (string) ($symbol['source_hash'] ?? ''),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function discoverFiles(string $workspace): array
    {
        $roots = [
            'app',
            'src',
            'routes',
            'database/migrations',
            'tests',
            'docs/engineering-knowledge-base',
            'config',
            'lib',
            'components',
            'packages',
            'source',
            'scripts',
        ];

        $reject = static fn (SplFileInfo $file): bool => str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)
            || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'node_modules'.DIRECTORY_SEPARATOR)
            || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'dist'.DIRECTORY_SEPARATOR)
            || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR);

        $collect = fn (array $dirs): array => collect($dirs)
            ->filter(fn (string $root): bool => File::isDirectory($root))
            ->flatMap(fn (string $root): array => File::allFiles($root))
            ->filter(fn (SplFileInfo $file): bool => in_array(strtolower($file->getExtension()), self::EXTENSIONS, true))
            ->reject($reject)
            ->map(fn (SplFileInfo $file): string => $file->getPathname())
            ->sort()
            ->values()
            ->all();

        $files = $collect(
            collect($roots)
                ->map(fn (string $root): string => $workspace.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $root))
                ->all()
        );

        // AP-815 W-2: cross-project fallback — a repo laid out differently from atlas-server
        // (no app/src/routes/... roots) still gets indexed by scanning the workspace root
        // directly (deps excluded). atlas-server always matches its roots, so $files is never
        // empty for it and this fallback never alters its behavior.
        if ($files === [] && File::isDirectory($workspace)) {
            $files = $collect([$workspace]);
        }

        return $files;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    /**
     * AP-815 A1: tree-sitter symbol extraction is gated behind the runtime flag (same
     * master flag as the python boundary) plus an opt-out sub-flag.
     */
    private function treeSitterEnabled(): bool
    {
        return (bool) config('atlas.code_graph.real_edges', false)
            && (bool) config('atlas.code_graph.treesitter_symbols', true);
    }

    /**
     * AP-815 A1: grammar id for a non-PHP source file tree-sitter should extract, or
     * null for PHP (kept on nikic/php-parser), markdown (kept on its parser), and
     * unknown extensions (which keep the existing empty/regex behaviour).
     */
    private function treeSitterLanguage(string $path): ?string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'ts', 'tsx' => 'typescript',
            'js', 'jsx', 'mjs', 'cjs' => 'javascript',
            'py' => 'python',
            'go' => 'go',
            'rs' => 'rust',
            'java' => 'java',
            'rb' => 'ruby',
            'kt', 'kts' => 'kotlin',
            'scala' => 'scala',
            'swift' => 'swift',
            'cpp', 'hpp' => 'cpp',
            'lua' => 'lua',
            default => null,
        };
    }

    /**
     * AP-815 A1: map tree-sitter nodes (from CodeGraphTreeSitterExtractor) into the
     * indexer's symbol-row shape.
     *
     * @param  array{symbols:array<int,array<string,mixed>>,imports:array<int,string>}  $data
     * @return array<int,array<string,mixed>>
     */
    private function mapTreeSitterSymbols(array $data, string $relativePath, string $moduleSlug): array
    {
        $language = $this->languageForPath($relativePath);
        $symbols = [];
        foreach (($data['symbols'] ?? []) as $node) {
            if (! is_array($node)) {
                continue;
            }
            $name = trim((string) ($node['label'] ?? ''));
            if ($name === '') {
                continue;
            }
            $symbols[] = $this->symbol([
                'module_slug' => $moduleSlug,
                'symbol_type' => (string) ($node['kind'] ?? 'symbol'),
                'symbol_name' => $name,
                'file_path' => $relativePath,
                'line_start' => (int) ($node['line_start'] ?? $node['line'] ?? 1),
                'language' => $language,
                'signature' => $name,
                'metadata' => [
                    'line_end' => $node['line_end'] ?? null,
                    'source' => 'treesitter',
                    'grammar' => $node['language'] ?? null,
                ],
            ]);
        }

        return $symbols;
    }

    private function parseFileSymbols(string $relativePath, string $content, string $moduleSlug): array
    {
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'php' => $this->parsePhpSymbols($relativePath, $content, $moduleSlug),
            'ts', 'tsx', 'js', 'jsx' => $this->parseJavascriptSymbols($relativePath, $content, $moduleSlug),
            'md' => $this->parseMarkdownSymbols($relativePath, $content, $moduleSlug),
            default => [],
        };
    }

    /**
     * @return array{dependencies:array<int,array<string,mixed>>,symbol_references:array<int,array<string,mixed>>,test_targets:array<int,array<string,mixed>>}
     */
    private function parseFileRelations(string $relativePath, string $content, string $moduleSlug): array
    {
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'php' => $this->parsePhpRelations($relativePath, $content, $moduleSlug),
            'ts', 'tsx', 'js', 'jsx' => $this->parseJavascriptRelations($relativePath, $content, $moduleSlug),
            default => ['dependencies' => [], 'symbol_references' => [], 'test_targets' => []],
        };
    }

    /**
     * @return array{dependencies:array<int,array<string,mixed>>,symbol_references:array<int,array<string,mixed>>,test_targets:array<int,array<string,mixed>>}
     */
    private function parsePhpRelations(string $relativePath, string $content, string $moduleSlug): array
    {
        $astRelations = $this->parsePhpAstRelations($relativePath, $content, $moduleSlug);
        if ($astRelations !== null) {
            return $astRelations;
        }

        $dependencies = [];
        $references = [];
        $testTargets = [];

        foreach ($this->lineMatches($content, '/^use\s+([^;]+);/') as $match) {
            $class = trim($match['matches'][1]);
            $targetModule = $this->moduleSlugForClass($class);
            $dependencies[] = [
                'kind' => 'php_use',
                'from_module' => $moduleSlug,
                'to_module' => $targetModule,
                'symbol' => $class,
                'file_path' => $relativePath,
                'line' => $match['line'],
            ];
            $references[] = [
                'kind' => 'php_use',
                'symbol' => $class,
                'target_module' => $targetModule,
                'file_path' => $relativePath,
                'line' => $match['line'],
            ];
        }

        foreach ($this->lineMatches($content, '/([A-Za-z_][A-Za-z0-9_\\\\]+)::class/') as $match) {
            $class = trim($match['matches'][1]);
            $targetModule = $this->moduleSlugForClass($class);
            $references[] = [
                'kind' => 'class_constant',
                'symbol' => $class,
                'target_module' => $targetModule,
                'file_path' => $relativePath,
                'line' => $match['line'],
            ];
        }

        if (str_starts_with($relativePath, 'tests/')) {
            foreach ($this->lineMatches($content, '/\b(App\\\\[A-Za-z0-9_\\\\]+|[A-Z][A-Za-z0-9_]+(?:Service|Controller|Command|Model))\b/') as $match) {
                $symbol = $match['matches'][1];
                $testTargets[] = [
                    'kind' => 'test_symbol_reference',
                    'symbol' => $symbol,
                    'target_module' => str_contains($symbol, '\\') ? $this->moduleSlugForClass($symbol) : $this->moduleSlugForShortName($symbol),
                    'test_path' => $relativePath,
                    'line' => $match['line'],
                ];
            }
        }

        return [
            'dependencies' => $dependencies,
            'symbol_references' => $references,
            'test_targets' => $testTargets,
        ];
    }

    /**
     * @return array{dependencies:array<int,array<string,mixed>>,symbol_references:array<int,array<string,mixed>>,test_targets:array<int,array<string,mixed>>}|null
     */
    private function parsePhpAstRelations(string $relativePath, string $content, string $moduleSlug): ?array
    {
        if (! class_exists(ParserFactory::class)) {
            return null;
        }

        try {
            $parser = (new ParserFactory)->createForNewestSupportedVersion();
            $statements = $parser->parse($content);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($statements)) {
            return null;
        }

        return $this->parsePhpAstStatementRelations($relativePath, $statements, $moduleSlug);
    }

    /**
     * @param  array<int,Node>  $statements
     * @return array{dependencies:array<int,array<string,mixed>>,symbol_references:array<int,array<string,mixed>>,test_targets:array<int,array<string,mixed>>}
     */
    private function parsePhpAstStatementRelations(string $relativePath, array $statements, string $moduleSlug): array
    {
        $dependencies = [];
        $references = [];
        $testTargets = [];

        foreach ($statements as $statement) {
            $namespace = '';
            $body = [$statement];
            if ($statement instanceof Namespace_) {
                $namespace = $statement->name instanceof Name ? $this->phpAstName($statement->name) : '';
                $body = $statement->stmts;
            }

            $imports = [];
            foreach ($body as $node) {
                if ($node instanceof Use_) {
                    foreach ($node->uses as $use) {
                        $class = ltrim($this->phpAstName($use->name), '\\');
                        if ($class === '') {
                            continue;
                        }

                        $shortName = $use->alias instanceof Node\Identifier
                            ? $use->alias->toString()
                            : Str::afterLast($class, '\\');
                        $imports[$shortName] = $class;
                        $targetModule = $this->moduleSlugForClass($class);
                        $dependencies[] = [
                            'kind' => 'php_use_ast',
                            'from_module' => $moduleSlug,
                            'to_module' => $targetModule,
                            'symbol' => $class,
                            'file_path' => $relativePath,
                            'line' => $use->getStartLine(),
                        ];
                        $references[] = [
                            'kind' => 'php_use_ast',
                            'symbol' => $class,
                            'target_module' => $targetModule,
                            'file_path' => $relativePath,
                            'line' => $use->getStartLine(),
                        ];
                    }

                    continue;
                }

                foreach ($this->phpAstClassConstFetches($node) as $fetch) {
                    if (! $fetch->class instanceof Name) {
                        continue;
                    }

                    $class = $this->resolvePhpAstClassName($this->phpAstName($fetch->class), $namespace, $imports);
                    if ($class === '') {
                        continue;
                    }

                    $targetModule = $this->moduleSlugForClass($class);
                    $references[] = [
                        'kind' => 'class_constant_ast',
                        'symbol' => $class,
                        'target_module' => $targetModule,
                        'file_path' => $relativePath,
                        'line' => $fetch->getStartLine(),
                    ];
                    if (str_starts_with($relativePath, 'tests/')) {
                        $testTargets[] = [
                            'kind' => 'test_symbol_reference_ast',
                            'symbol' => $class,
                            'target_module' => $targetModule,
                            'test_path' => $relativePath,
                            'line' => $fetch->getStartLine(),
                        ];
                    }
                }
            }
        }

        return [
            'dependencies' => $dependencies,
            'symbol_references' => $references,
            'test_targets' => $testTargets,
        ];
    }

    /**
     * @return array<int,ClassConstFetch>
     */
    private function phpAstClassConstFetches(Node $node): array
    {
        $matches = [];
        if ($node instanceof ClassConstFetch) {
            $matches[] = $node;
        }

        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->{$name};
            if ($value instanceof Node) {
                array_push($matches, ...$this->phpAstClassConstFetches($value));
            } elseif (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Node) {
                        array_push($matches, ...$this->phpAstClassConstFetches($child));
                    }
                }
            }
        }

        return $matches;
    }

    /**
     * @param  array<string,string>  $imports
     */
    private function resolvePhpAstClassName(string $class, string $namespace, array $imports): string
    {
        $class = ltrim($class, '\\');
        if ($class === '' || in_array(strtolower($class), ['self', 'static', 'parent'], true)) {
            return '';
        }

        $head = Str::before($class, '\\');
        if (isset($imports[$head])) {
            $tail = Str::after($class, $head);

            return $imports[$head].$tail;
        }

        if (str_contains($class, '\\')) {
            return $class;
        }

        return $namespace !== '' ? $namespace.'\\'.$class : $class;
    }

    private function phpAstName(Name $name): string
    {
        if (method_exists($name, 'toCodeString')) {
            return $name->toCodeString();
        }

        return $name->toString();
    }

    /**
     * @return array{dependencies:array<int,array<string,mixed>>,symbol_references:array<int,array<string,mixed>>,test_targets:array<int,array<string,mixed>>}
     */
    private function parseJavascriptRelations(string $relativePath, string $content, string $moduleSlug): array
    {
        $dependencies = [];
        foreach ($this->lineMatches($content, '/\bimport\s+(?:.+?\s+from\s+)?[\'"]([^\'"]+)[\'"]/') as $match) {
            $import = trim($match['matches'][1]);
            $dependencies[] = [
                'kind' => 'js_import',
                'from_module' => $moduleSlug,
                'to_module' => str_starts_with($import, '.') ? $this->moduleForPath($this->normalizeRelativeImport($relativePath, $import))['slug'] : 'external_package',
                'symbol' => $import,
                'file_path' => $relativePath,
                'line' => $match['line'],
            ];
        }

        return [
            'dependencies' => $dependencies,
            'symbol_references' => $dependencies,
            'test_targets' => [],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function parsePhpSymbols(string $relativePath, string $content, string $moduleSlug): array
    {
        $symbols = [];
        $namespace = preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatch)
            ? trim($namespaceMatch[1])
            : null;
        $className = null;
        // Anchor to a real declaration at line-start (optionally preceded by final/abstract/
        // readonly modifiers). A bare /\b(class|interface|trait|enum)\s+\w+/ also matched the
        // same keywords appearing as prose inside docblocks ("This class turns them...",
        // "mixing interface with..."), extracting a phantom symbol ("turns", "with") and
        // dropping the true class — which made evidence symbol refs silently unresolvable.
        if (preg_match('/^\s*(?:(?:final|abstract|readonly)\s+)*(class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/m', $content, $classMatch, PREG_OFFSET_CAPTURE)) {
            $className = $namespace ? $namespace.'\\'.$classMatch[2][0] : $classMatch[2][0];
            $symbols[] = $this->symbol([
                'module_slug' => $moduleSlug,
                'symbol_type' => $classMatch[1][0],
                'symbol_name' => $className,
                'file_path' => $relativePath,
                'line_start' => $this->lineForOffset($content, $classMatch[0][1]),
                'language' => 'php',
                'signature' => trim($classMatch[0][0]),
                'namespace' => $namespace,
                'metadata' => [
                    'short_name' => $classMatch[2][0],
                    'classification' => $this->classClassification($relativePath, $classMatch[2][0]),
                ],
            ]);
        }

        if (preg_match('/protected\s+\$signature\s*=\s*([\'"])(.*?)\1/s', $content, $signatureMatch, PREG_OFFSET_CAPTURE)) {
            $signature = trim(preg_replace('/\s+/', ' ', $signatureMatch[2][0]) ?? $signatureMatch[2][0]);
            $command = strtok($signature, ' ') ?: $signature;
            $symbols[] = $this->symbol([
                'module_slug' => $moduleSlug,
                'symbol_type' => 'cli_command',
                'symbol_name' => $command,
                'file_path' => $relativePath,
                'line_start' => $this->lineForOffset($content, $signatureMatch[0][1]),
                'language' => 'php',
                'signature' => $signature,
                'parent_symbol' => $className,
                'metadata' => [
                    'command_signature' => $signature,
                ],
            ]);
        }

        foreach ($this->lineMatches($content, '/\b(public|protected|private)\s+function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\((.*)$/') as $match) {
            $methodName = $className ? $className.'::'.$match['matches'][2] : $match['matches'][2];
            $symbols[] = $this->symbol([
                'module_slug' => $moduleSlug,
                'symbol_type' => str_starts_with($relativePath, 'tests/') && str_starts_with($match['matches'][2], 'test_') ? 'test_method' : 'method',
                'symbol_name' => $methodName,
                'file_path' => $relativePath,
                'line_start' => $match['line'],
                'language' => 'php',
                'signature' => trim($match['text']),
                'namespace' => $namespace,
                'parent_symbol' => $className,
                'visibility' => $match['matches'][1],
                'metadata' => [
                    'method' => $match['matches'][2],
                ],
            ]);
        }

        foreach ($this->parseRouteSymbols($relativePath, $content, $moduleSlug) as $routeSymbol) {
            $symbols[] = $routeSymbol;
        }

        foreach ($this->lineMatches($content, '/Schema::(create|table)\(\s*[\'"]([^\'"]+)[\'"]/') as $match) {
            $symbols[] = $this->symbol([
                'module_slug' => $moduleSlug,
                'symbol_type' => 'migration_table',
                'symbol_name' => $match['matches'][1].':'.$match['matches'][2],
                'file_path' => $relativePath,
                'line_start' => $match['line'],
                'language' => 'php',
                'signature' => trim($match['text']),
                'metadata' => [
                    'operation' => $match['matches'][1],
                    'table' => $match['matches'][2],
                ],
            ]);
        }

        return $symbols;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function parseRouteSymbols(string $relativePath, string $content, string $moduleSlug): array
    {
        if (! str_starts_with($relativePath, 'routes/')) {
            return [];
        }

        $symbols = [];
        $prefixStack = [];
        $depth = 0;
        $lines = preg_split('/\r?\n/', $content) ?: [];
        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;
            $prefixStack = array_values(array_filter(
                $prefixStack,
                fn (array $prefix): bool => $depth >= (int) $prefix['depth'],
            ));
            if (preg_match('/Route::prefix\(\s*[\'"]([^\'"]+)[\'"]\s*\)->group/', $line, $prefixMatch)) {
                $prefixStack[] = [
                    'prefix' => trim($prefixMatch[1], '/'),
                    'depth' => $depth + max(1, substr_count($line, '{')),
                ];
            }

            $prefix = trim(implode('/', array_column($prefixStack, 'prefix')), '/');
            if (preg_match('/Route::(get|post|put|patch|delete|options|any)\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*(.+)\);?/', $line, $routeMatch)) {
                $uri = $this->joinRoute($prefix, $routeMatch[2]);
                $verb = strtoupper($routeMatch[1]);
                $target = trim($routeMatch[3]);
                $symbols[] = $this->symbol([
                    'module_slug' => $moduleSlug,
                    'symbol_type' => 'route',
                    'symbol_name' => $verb.' '.$uri,
                    'file_path' => $relativePath,
                    'line_start' => $lineNumber,
                    'language' => 'php',
                    'signature' => trim($line),
                    'metadata' => array_merge([
                        'http_method' => $verb,
                        'uri' => $uri,
                        'target' => $target,
                    ], $this->routeTarget($target)),
                ]);
            }
            if (preg_match('/Route::apiResource\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*([^,\)]+)/', $line, $resourceMatch)) {
                $uri = $this->joinRoute($prefix, $resourceMatch[1]);
                $symbols[] = $this->symbol([
                    'module_slug' => $moduleSlug,
                    'symbol_type' => 'api_resource',
                    'symbol_name' => 'RESOURCE '.$uri,
                    'file_path' => $relativePath,
                    'line_start' => $lineNumber,
                    'language' => 'php',
                    'signature' => trim($line),
                    'metadata' => [
                        'uri' => $uri,
                        'controller' => trim($resourceMatch[2]),
                    ],
                ]);
            }

            $depth += substr_count($line, '{') - substr_count($line, '}');
        }

        return $symbols;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function parseJavascriptSymbols(string $relativePath, string $content, string $moduleSlug): array
    {
        $symbols = [];
        foreach ($this->lineMatches($content, '/\b(export\s+)?(default\s+)?(async\s+)?function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/') as $match) {
            $symbols[] = $this->symbol([
                'module_slug' => $moduleSlug,
                'symbol_type' => 'function',
                'symbol_name' => $match['matches'][4],
                'file_path' => $relativePath,
                'line_start' => $match['line'],
                'language' => $this->languageForPath($relativePath),
                'signature' => trim($match['text']),
                'metadata' => [
                    'exported' => trim((string) $match['matches'][1]) !== '',
                    'default' => trim((string) $match['matches'][2]) !== '',
                ],
            ]);
        }
        foreach ($this->lineMatches($content, '/\bexport\s+(interface|type|class|const)\s+([A-Za-z_][A-Za-z0-9_]*)/') as $match) {
            $symbols[] = $this->symbol([
                'module_slug' => $moduleSlug,
                'symbol_type' => $match['matches'][1],
                'symbol_name' => $match['matches'][2],
                'file_path' => $relativePath,
                'line_start' => $match['line'],
                'language' => $this->languageForPath($relativePath),
                'signature' => trim($match['text']),
                'metadata' => [
                    'exported' => true,
                ],
            ]);
        }

        return $symbols;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function parseMarkdownSymbols(string $relativePath, string $content, string $moduleSlug): array
    {
        $symbols = [];
        foreach ($this->lineMatches($content, '/^(#{1,3})\s+(.+)$/') as $match) {
            $symbols[] = $this->symbol([
                'module_slug' => $moduleSlug,
                'symbol_type' => 'doc_heading',
                'symbol_name' => $match['matches'][2],
                'file_path' => $relativePath,
                'line_start' => $match['line'],
                'language' => 'markdown',
                'signature' => trim($match['text']),
                'metadata' => [
                    'level' => strlen($match['matches'][1]),
                ],
            ]);
        }

        return $symbols;
    }

    /**
     * @param  array<string,mixed>  $module
     * @return array<string,mixed>
     */
    private function emptyModule(array $module): array
    {
        return array_merge($module, [
            'files' => [],
            'languages' => [],
            'symbol_count' => 0,
            'route_count' => 0,
            'command_count' => 0,
            'migration_count' => 0,
            'test_count' => 0,
            'dependencies' => [],
            'symbol_references' => [],
            'test_targets' => [],
        ]);
    }

    /**
     * @param  array<string,mixed>  $module
     * @param  array<string,string>  $fileHashes
     * @param  array<int,string>  $testPaths
     * @return array<string,mixed>
     */
    private function finalizeModule(array $module, array $fileHashes, array $testPaths): array
    {
        $files = array_values($module['files']);
        $hashParts = collect($files)
            ->map(fn (array $file): string => $file['path'].':'.($fileHashes[$file['path']] ?? $file['hash']))
            ->sort()
            ->values()
            ->all();
        $primaryLanguage = collect($module['languages'])->sortDesc()->keys()->first();
        $relatedTests = $this->relatedTestsForModule((string) $module['slug'], $testPaths);

        return [
            'slug' => $module['slug'],
            'name' => $module['name'],
            'layer' => $module['layer'],
            'root_path' => $module['root_path'],
            'primary_language' => $primaryLanguage,
            'status' => 'active',
            'owner' => $module['owner'] ?? 'atlas',
            'description' => $module['description'] ?? null,
            'docs_status' => 'undocumented',
            'file_count' => count($files),
            'symbol_count' => (int) $module['symbol_count'],
            'route_count' => (int) $module['route_count'],
            'command_count' => (int) $module['command_count'],
            'migration_count' => (int) $module['migration_count'],
            'test_count' => max((int) $module['test_count'], count($relatedTests)),
            'source_hash' => hash('sha256', implode('|', $hashParts)),
            'docs_hash' => null,
            'tags_json' => $module['tags'] ?? [],
            'related_docs_json' => [],
            'related_tests_json' => $relatedTests,
            'metadata' => [
                'files' => array_slice(array_column($files, 'path'), 0, 160),
                'language_counts' => $module['languages'],
                'dependency_edges' => $this->uniqueRelationRows((array) $module['dependencies'], 80),
                'symbol_references' => $this->uniqueRelationRows((array) $module['symbol_references'], 120),
                'test_targets' => $this->uniqueRelationRows((array) $module['test_targets'], 80),
                'code_intelligence_depth' => 'symbols_dependencies_tests_docs',
            ],
            'indexed_at' => now(),
            'archived_at' => null,
        ];
    }

    /**
     * @param  array<string,mixed>  $symbol
     * @return array<string,mixed>
     */
    private function finalizeSymbol(array $symbol): array
    {
        $source = [
            $symbol['symbol_type'] ?? '',
            $symbol['symbol_name'] ?? '',
            $symbol['file_path'] ?? '',
            $symbol['line_start'] ?? '',
            $symbol['signature'] ?? '',
        ];
        $metadata = is_array($symbol['metadata'] ?? null) ? $symbol['metadata'] : [];
        $symbol = $this->fitSymbolStorageLimits($symbol, $metadata);

        return array_merge($symbol, [
            'status' => 'active',
            'docs_status' => 'undocumented',
            'source_hash' => hash('sha256', implode('|', array_map('strval', $source))),
            'related_doc_ids_json' => [],
            'indexed_at' => now(),
            'archived_at' => null,
        ]);
    }

    /**
     * Keep source_hash based on the full extracted symbol, but persist only
     * values that fit the schema. Full values are retained in metadata so
     * generated long test names remain inspectable without breaking indexing.
     *
     * @param  array<string,mixed>  $symbol
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    private function fitSymbolStorageLimits(array $symbol, array $metadata): array
    {
        foreach ([
            'symbol_type' => 60,
            'symbol_name' => 300,
            'file_path' => 500,
            'language' => 40,
            'namespace' => 220,
            'parent_symbol' => 300,
            'visibility' => 40,
        ] as $field => $limit) {
            $value = $symbol[$field] ?? null;
            if (! is_string($value) || strlen($value) <= $limit) {
                continue;
            }

            $metadata['full_'.$field] = $value;
            $symbol[$field] = substr($value, 0, $limit);
        }

        $symbol['metadata'] = $metadata;

        return $symbol;
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    private function symbol(array $attributes): array
    {
        return array_merge([
            'module_slug' => null,
            'symbol_type' => 'symbol',
            'symbol_name' => null,
            'file_path' => null,
            'line_start' => null,
            'line_end' => null,
            'language' => null,
            'signature' => null,
            'namespace' => null,
            'parent_symbol' => null,
            'visibility' => null,
            'metadata' => [],
        ], $attributes);
    }

    /**
     * @param  array<int,array<string,mixed>>  $moduleRows
     * @return array<string,string>
     */
    private function persistModules(array $moduleRows, bool $prune): array
    {
        $ids = [];
        $seen = [];
        $keyed = $this->workspaceKeyed('atlas_engineering_code_modules');
        foreach ($moduleRows as $row) {
            $seen[] = $row['slug'];
            if ($keyed) {
                $row['workspace_id'] = $this->workspaceId;
            }
            $match = $keyed
                ? ['workspace_id' => $this->workspaceId, 'slug' => $row['slug']]
                : ['slug' => $row['slug']];
            $module = AtlasEngineeringCodeModule::query()->updateOrCreate($match, $row);
            $ids[$row['slug']] = $module->id;
        }

        if ($prune && $seen !== []) {
            $pruneQuery = AtlasEngineeringCodeModule::query()
                ->whereNotIn('slug', $seen)
                ->where('status', '!=', 'archived');
            if ($keyed) {
                $pruneQuery->where('workspace_id', $this->workspaceId);
            }
            $pruneQuery->update(['status' => 'archived', 'archived_at' => now()]);
        }

        return $ids;
    }

    /**
     * @param  array<int,array<string,mixed>>  $symbolRows
     * @param  array<string,string>  $moduleIds
     */
    private function persistSymbols(array $symbolRows, array $moduleIds, bool $prune): int
    {
        $count = 0;
        $indexedAt = now()->startOfSecond();
        $now = now();
        $keyedSymbols = $this->workspaceKeyed('atlas_engineering_code_symbols');
        $rows = [];
        foreach ($symbolRows as $row) {
            $moduleSlug = (string) ($row['module_slug'] ?? '');
            unset($row['module_slug']);
            $row['module_id'] = $moduleIds[$moduleSlug] ?? null;
            $row['status'] = 'active';
            $row['archived_at'] = null;
            $row['indexed_at'] = $indexedAt;
            $row['id'] = (string) Str::uuid();
            if ($keyedSymbols) {
                $row['workspace_id'] = $this->workspaceId;
            }
            $row['metadata'] = $this->json((array) ($row['metadata'] ?? []));
            $row['related_doc_ids_json'] = $this->json((array) ($row['related_doc_ids_json'] ?? []));
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $rows[] = $row;
            $count++;

            if (count($rows) >= 1000) {
                $this->upsertSymbolRows($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            $this->upsertSymbolRows($rows);
        }

        if ($prune && $symbolRows !== []) {
            $this->archiveStaleSymbols($indexedAt);
        }

        return $count;
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     */
    private function upsertSymbolRows(array $rows): void
    {
        DB::table('atlas_engineering_code_symbols')->upsert(
            $rows,
            $this->workspaceConflictKey('atlas_engineering_code_symbols', ['symbol_type', 'source_hash']),
            [
                'module_id',
                'symbol_name',
                'file_path',
                'line_start',
                'line_end',
                'language',
                'signature',
                'namespace',
                'parent_symbol',
                'visibility',
                'status',
                'metadata',
                'indexed_at',
                'archived_at',
                'updated_at',
            ],
        );
    }

    private function archiveStaleSymbols(Carbon $indexedAt): int
    {
        $archivedAt = now();
        $count = 0;

        $staleQuery = AtlasEngineeringCodeSymbol::query()
            ->where('status', '!=', 'archived');
        if ($this->workspaceKeyed('atlas_engineering_code_symbols')) {
            $staleQuery->where('workspace_id', $this->workspaceId);
        }
        $staleQuery
            ->where(function (Builder $query) use ($indexedAt): void {
                $query
                    ->whereNull('indexed_at')
                    ->orWhere('indexed_at', '<', $indexedAt);
            })
            ->select('id')
            ->chunkById(1000, function (Collection $symbols) use ($archivedAt, &$count): void {
                $ids = $symbols->pluck('id')->all();
                if ($ids === []) {
                    return;
                }

                AtlasEngineeringCodeSymbol::query()
                    ->whereIn('id', $ids)
                    ->update([
                        'status' => 'archived',
                        'archived_at' => $archivedAt,
                    ]);

                $count += count($ids);
            });

        return $count;
    }

    private function syncDocLinks(string $workspace, bool $prune): int
    {
        if (! DatabaseTableAvailability::has('atlas_engineering_knowledge_items')) {
            return 0;
        }

        // AP-815 W-5: the knowledge-item KB belongs to the PRIMARY workspace (atlas-server).
        // External workspaces have no entries in it, so syncing here would replicate
        // atlas-server's docs into EVERY workspace. Only the primary links docs from the KB.
        if ($this->workspaceKeyed('atlas_engineering_doc_links')
            && $this->workspaceId !== app(CodeGraphWorkspaceIdentity::class)->default()) {
            return 0;
        }

        $knowledgeItems = AtlasEngineeringKnowledgeItem::query()
            ->active()
            ->get([
                'id',
                'canonical_path',
                'content_hash',
                'related_paths_json',
                'capabilities_json',
                'tags_json',
            ]);
        $modules = AtlasEngineeringCodeModule::query()
            ->active()
            ->get(['id', 'slug', 'root_path']);
        $count = 0;
        $docLinkRows = [];
        $this->docLinkTargetHashCache = [];
        $pruneStartedAt = now();

        // AP-815 C6: build a path -> symbols index ONCE (chunked) instead of firing a
        // per-knowledge-item symbol query — the N-query cost the old loop paid per item.
        $allPaths = $knowledgeItems
            ->flatMap(fn ($item) => collect($item->related_paths_json ?? [])->push($item->canonical_path))
            ->filter()
            ->map(fn (mixed $path): string => trim((string) $path))
            ->filter(fn (string $path): bool => $path !== '')
            ->unique()
            ->values();
        $symbolsByPath = [];
        foreach ($allPaths->chunk(1000) as $pathChunk) {
            DB::table('atlas_engineering_code_symbols')
                ->where('status', 'active')
                ->whereNull('archived_at')
                ->whereIn('file_path', $pathChunk->all())
                ->select(['id', 'symbol_name', 'symbol_type', 'file_path'])
                ->orderBy('id')
                ->each(function (object $symbol) use (&$symbolsByPath): void {
                    $symbolsByPath[(string) $symbol->file_path][] = $symbol;
                });
        }

        foreach ($knowledgeItems as $item) {
            $paths = collect($item->related_paths_json ?? [])
                ->push($item->canonical_path)
                ->filter()
                ->map(fn (mixed $path): string => trim((string) $path))
                ->unique()
                ->values();
            $capabilities = collect($item->capabilities_json ?? [])
                ->merge($item->tags_json ?? [])
                ->map(fn (mixed $value): string => Str::slug((string) $value, '_'))
                ->filter()
                ->values()
                ->all();

            foreach ($modules as $module) {
                $matchedPath = $paths->first(fn (string $path): bool => $this->pathMatchesModule($path, $module));
                $matchedCapability = $matchedPath ? null : collect($capabilities)->first(
                    fn (string $capability): bool => $capability !== '' && str_contains($module->slug, $capability),
                );
                if (! $matchedPath && ! $matchedCapability) {
                    continue;
                }

                $targetPath = $matchedPath ?: $module->root_path;
                $link = $this->docLinkRow($workspace, $item, [
                    'module_id' => $module->id,
                    'target_path' => $targetPath,
                    'link_type' => $matchedPath ? 'module_path' : 'module_capability',
                    'metadata' => [
                        'module_slug' => $module->slug,
                        'module_root_path' => $module->root_path,
                        'capability' => $matchedCapability,
                    ],
                ]);
                $docLinkRows[] = $link;
                $count++;

                if (count($docLinkRows) >= 1000) {
                    $this->upsertDocLinkRows($docLinkRows);
                    $docLinkRows = [];
                }
            }

            // AP-815 C6: resolve symbols from the pre-built path index (no per-item query).
            foreach ($paths as $path) {
                foreach ($symbolsByPath[$path] ?? [] as $symbol) {
                    $link = $this->docLinkRow($workspace, $item, [
                        'symbol_id' => $symbol->id,
                        'target_path' => $symbol->file_path,
                        'link_type' => 'symbol_path',
                        'metadata' => ['symbol_name' => $symbol->symbol_name, 'symbol_type' => $symbol->symbol_type],
                    ]);
                    $docLinkRows[] = $link;
                    $count++;

                    if (count($docLinkRows) >= 1000) {
                        $this->upsertDocLinkRows($docLinkRows);
                        $docLinkRows = [];
                    }
                }
            }
        }

        if ($docLinkRows !== []) {
            $this->upsertDocLinkRows($docLinkRows);
        }

        if ($prune) {
            $this->archiveStaleDocLinks($pruneStartedAt);
        }

        return $count;
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     */
    private function upsertDocLinkRows(array $rows): void
    {
        $now = now();

        $keyed = $this->workspaceKeyed('atlas_engineering_doc_links');
        $workspaceId = $this->workspaceId;
        $prepared = array_map(function (array $row) use ($now, $keyed, $workspaceId): array {
            $row['id'] = (string) Str::uuid();
            $row['metadata'] = $this->json((array) ($row['metadata'] ?? []));
            if ($keyed) {
                $row['workspace_id'] = $workspaceId;
            }
            $row['created_at'] = $now;
            $row['updated_at'] = $now;

            return $row;
        }, $rows);

        DB::table('atlas_engineering_doc_links')->upsert(
            $prepared,
            $this->workspaceConflictKey('atlas_engineering_doc_links', ['link_hash']),
            [
                'knowledge_item_id',
                'module_id',
                'symbol_id',
                'link_type',
                'status',
                'canonical_path',
                'target_path',
                'doc_hash',
                'target_hash',
                'metadata',
                'indexed_at',
                'archived_at',
                'updated_at',
            ],
        );
    }

    private function archiveStaleDocLinks(Carbon $pruneStartedAt): void
    {
        $archivedAt = now();

        $query = DB::table('atlas_engineering_doc_links')
            ->whereNull('archived_at')
            ->where(function ($query) use ($pruneStartedAt): void {
                $query
                    ->whereNull('indexed_at')
                    ->orWhere('indexed_at', '<', $pruneStartedAt);
            });

        // AP-815 W-1: scope the prune to THIS workspace so a second project's index never
        // archives the primary workspace's doc-links (the leak that emptied atlas-server).
        if ($this->workspaceKeyed('atlas_engineering_doc_links')) {
            $query->where('workspace_id', $this->workspaceId);
        }

        $query->update(['status' => 'archived', 'archived_at' => $archivedAt]);
    }

    /**
     * @param  array<string,mixed>  $target
     * @return array<string,mixed>
     */
    private function docLinkRow(string $workspace, AtlasEngineeringKnowledgeItem $item, array $target): array
    {
        $targetPath = is_string($target['target_path'] ?? null) ? $target['target_path'] : null;
        $targetFullPath = $targetPath ? $workspace.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $targetPath) : null;
        $isModuleLevelLink = ($target['module_id'] ?? null) !== null
            && ($target['symbol_id'] ?? null) === null
            && in_array($target['link_type'] ?? null, ['module_path', 'module_capability'], true);
        $targetExists = $targetFullPath && File::exists($targetFullPath);
        $targetHash = null;
        if ($targetFullPath && File::isFile($targetFullPath)) {
            if (! array_key_exists($targetFullPath, $this->docLinkTargetHashCache)) {
                $this->docLinkTargetHashCache[$targetFullPath] = hash_file('sha256', $targetFullPath) ?: null;
            }

            $targetHash = $this->docLinkTargetHashCache[$targetFullPath];
        }
        $status = $targetPath === null || $targetExists || $isModuleLevelLink ? 'current' : 'missing_target';
        $linkHash = hash('sha256', implode('|', [
            $item->id,
            $target['module_id'] ?? '',
            $target['symbol_id'] ?? '',
            $target['link_type'] ?? '',
            $targetPath ?? '',
        ]));

        return [
            'knowledge_item_id' => $item->id,
            'module_id' => $target['module_id'] ?? null,
            'symbol_id' => $target['symbol_id'] ?? null,
            'link_type' => $target['link_type'],
            'status' => $status,
            'canonical_path' => $item->canonical_path,
            'target_path' => $targetPath,
            'doc_hash' => $item->content_hash,
            'target_hash' => $targetHash,
            'link_hash' => $linkHash,
            'metadata' => $target['metadata'] ?? [],
            'indexed_at' => now(),
            'archived_at' => null,
        ];
    }

    private function refreshDocumentationStatus(): void
    {
        $moduleDocs = [];

        foreach (DB::table('atlas_engineering_doc_links')
            ->select(['module_id', 'canonical_path', 'doc_hash'])
            ->whereNotNull('module_id')
            ->whereNull('archived_at')
            ->where('status', 'current')
            ->orderBy('module_id')
            ->cursor() as $link) {
            $moduleId = (string) $link->module_id;

            $moduleDocs[$moduleId]['paths'][] = (string) $link->canonical_path;

            if (is_string($link->doc_hash) && $link->doc_hash !== '') {
                $moduleDocs[$moduleId]['hashes'][] = $link->doc_hash;
            }
        }

        foreach ($moduleDocs as $moduleId => $docs) {
            $paths = array_values(array_unique($docs['paths'] ?? []));
            $hashes = array_values(array_filter($docs['hashes'] ?? [], fn (string $hash): bool => $hash !== ''));
            sort($hashes);

            $moduleDocs[$moduleId] = [
                'paths' => $paths,
                'docs_hash' => $hashes === [] ? null : hash('sha256', implode('|', $hashes)),
            ];
        }

        $documentedModuleIds = [];
        $now = now();

        DB::table('atlas_engineering_code_modules')
            ->select(['id', 'docs_status', 'docs_hash', 'related_docs_json'])
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->orderBy('id')
            ->chunkById(200, function ($modules) use ($moduleDocs, &$documentedModuleIds, $now): void {
                foreach ($modules as $module) {
                    $moduleId = (string) $module->id;
                    $docs = $moduleDocs[$moduleId] ?? ['paths' => [], 'docs_hash' => null];
                    $relatedDocs = $docs['paths'];

                    if ($relatedDocs !== []) {
                        $documentedModuleIds[$moduleId] = true;
                    }

                    $targetStatus = $relatedDocs === [] ? 'undocumented' : 'documented';
                    $targetRelated = $this->json($relatedDocs);
                    $targetHash = $docs['docs_hash'];

                    // AP-815 C4: only write when something actually changed — turns a
                    // per-module UPDATE-every-index into UPDATE-only-when-changed (most
                    // modules' docs are stable across re-indexes).
                    if ((string) ($module->docs_status ?? '') === $targetStatus
                        && (string) ($module->docs_hash ?? '') === (string) ($targetHash ?? '')
                        && (string) ($module->related_docs_json ?? '') === $targetRelated) {
                        continue;
                    }

                    DB::table('atlas_engineering_code_modules')->where('id', $moduleId)->update([
                        'docs_status' => $targetStatus,
                        'related_docs_json' => $targetRelated,
                        'docs_hash' => $targetHash,
                        'updated_at' => $now,
                    ]);
                }
            });

        $this->refreshSymbolDocumentationStatus(array_keys($documentedModuleIds), $now);
    }

    /**
     * @param  array<int,string>  $documentedModuleIds
     */
    private function refreshSymbolDocumentationStatus(array $documentedModuleIds, Carbon $now): void
    {
        $baseQuery = DB::table('atlas_engineering_code_symbols')
            ->where('status', 'active')
            ->whereNull('archived_at');
        $directDocSymbolIds = fn () => DB::table('atlas_engineering_doc_links')
            ->select('symbol_id')
            ->whereNotNull('symbol_id')
            ->whereNull('archived_at')
            ->where('status', 'current');

        if ($documentedModuleIds !== []) {
            (clone $baseQuery)
                ->whereIn('module_id', $documentedModuleIds)
                ->whereNotIn('id', $directDocSymbolIds())
                ->where('docs_status', '!=', 'module_documented')
                ->update([
                    'docs_status' => 'module_documented',
                    'related_doc_ids_json' => $this->json([]),
                    'updated_at' => $now,
                ]);

            (clone $baseQuery)
                ->where(function ($query) use ($documentedModuleIds): void {
                    $query
                        ->whereNull('module_id')
                        ->orWhereNotIn('module_id', $documentedModuleIds);
                })
                ->whereNotIn('id', $directDocSymbolIds())
                ->where('docs_status', '!=', 'undocumented')
                ->update([
                    'docs_status' => 'undocumented',
                    'related_doc_ids_json' => $this->json([]),
                    'updated_at' => $now,
                ]);
        } else {
            (clone $baseQuery)
                ->whereNotIn('id', $directDocSymbolIds())
                ->where('docs_status', '!=', 'undocumented')
                ->update([
                    'docs_status' => 'undocumented',
                    'related_doc_ids_json' => $this->json([]),
                    'updated_at' => $now,
                ]);
        }

        $rows = [];
        $currentSymbolId = null;
        $currentDocIds = [];

        foreach (DB::table('atlas_engineering_doc_links')
            ->select(['symbol_id', 'knowledge_item_id'])
            ->whereNotNull('symbol_id')
            ->whereNull('archived_at')
            ->where('status', 'current')
            ->orderBy('symbol_id')
            ->cursor() as $link) {
            $symbolId = (string) $link->symbol_id;
            $knowledgeItemId = (string) $link->knowledge_item_id;

            if ($currentSymbolId !== null && $symbolId !== $currentSymbolId) {
                $rows[] = $this->symbolDocumentationUpdateRow($currentSymbolId, $currentDocIds, $now);
                $currentDocIds = [];

                if (count($rows) >= 1000) {
                    $this->upsertSymbolDocumentationRows($rows);
                    $rows = [];
                }
            }

            $currentSymbolId = $symbolId;
            if ($knowledgeItemId !== '') {
                $currentDocIds[$knowledgeItemId] = true;
            }
        }

        if ($currentSymbolId !== null) {
            $rows[] = $this->symbolDocumentationUpdateRow($currentSymbolId, $currentDocIds, $now);
        }

        if ($rows !== []) {
            $this->upsertSymbolDocumentationRows($rows);
        }
    }

    /**
     * @param  array<string,bool>  $docIds
     * @return array<string,mixed>
     */
    private function symbolDocumentationUpdateRow(string $symbolId, array $docIds, Carbon $now): array
    {
        return [
            'id' => $symbolId,
            'docs_status' => 'documented',
            'related_doc_ids_json' => $this->json(array_keys($docIds)),
            'updated_at' => $now,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     */
    private function upsertSymbolDocumentationRows(array $rows): void
    {
        collect($rows)
            ->groupBy(fn (array $row): string => (string) $row['related_doc_ids_json'])
            ->each(function (Collection $group): void {
                DB::table('atlas_engineering_code_symbols')
                    ->whereIn('id', $group->pluck('id')->all())
                    ->update([
                        'docs_status' => 'documented',
                        'related_doc_ids_json' => (string) $group->first()['related_doc_ids_json'],
                        'updated_at' => $group->first()['updated_at'],
                    ]);
            });
    }

    /**
     * @param  array<mixed>  $value
     */
    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
    }

    /**
     * @param  array<int,array<string,mixed>>  $moduleRows
     * @param  array<int,array<string,mixed>>  $symbolRows
     * @return array<string,mixed>
     */
    private function scanSummary(array $moduleRows, array $symbolRows, int $docLinkCount): array
    {
        $routeCount = 0;
        $commandCount = 0;
        $migrationCount = 0;
        $testCount = 0;
        $fileCount = 0;

        foreach ($symbolRows as $symbol) {
            $type = (string) ($symbol['symbol_type'] ?? '');
            match ($type) {
                'route', 'api_resource' => $routeCount++,
                'cli_command' => $commandCount++,
                'migration_table' => $migrationCount++,
                'test_method' => $testCount++,
                'file' => $fileCount++,
                default => null,
            };
        }

        return [
            'module_count' => count($moduleRows),
            'symbol_count' => count($symbolRows),
            'doc_link_count' => $docLinkCount,
            'route_count' => $routeCount,
            'command_count' => $commandCount,
            'migration_count' => $migrationCount,
            'test_count' => $testCount,
            'file_count' => $fileCount,
        ];
    }

    /**
     * @return array{symbol_count:int,route_count:int,command_count:int,migration_count:int,test_count:int,file_count:int}
     */
    private function emptyScanSummaryCounts(): array
    {
        return [
            'symbol_count' => 0,
            'route_count' => 0,
            'command_count' => 0,
            'migration_count' => 0,
            'test_count' => 0,
            'file_count' => 0,
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $modules
     * @param  array{symbol_count:int,route_count:int,command_count:int,migration_count:int,test_count:int,file_count:int}  $summaryCounts
     * @param  array<string,true>  $testPathSet
     */
    private function countScannedSymbol(array &$modules, array $symbol, array &$summaryCounts, array &$testPathSet): void
    {
        $type = (string) ($symbol['symbol_type'] ?? '');
        $summaryCounts['symbol_count']++;

        match ($type) {
            'route', 'api_resource' => $summaryCounts['route_count']++,
            'cli_command' => $summaryCounts['command_count']++,
            'migration_table' => $summaryCounts['migration_count']++,
            'test_method' => $summaryCounts['test_count']++,
            'file' => $summaryCounts['file_count']++,
            default => null,
        };

        $moduleSlug = (string) ($symbol['module_slug'] ?? '');
        if (! isset($modules[$moduleSlug]) || $type === 'file') {
            return;
        }

        $modules[$moduleSlug]['symbol_count']++;
        match ($type) {
            'route', 'api_resource' => $modules[$moduleSlug]['route_count']++,
            'cli_command' => $modules[$moduleSlug]['command_count']++,
            'migration_table' => $modules[$moduleSlug]['migration_count']++,
            'test_method' => $modules[$moduleSlug]['test_count']++,
            default => null,
        };

        if ($type === 'test_method') {
            $filePath = (string) ($symbol['file_path'] ?? '');
            if ($filePath !== '') {
                $testPathSet[$filePath] = true;
            }
        }
    }

    /**
     * @param  array<int,array<string,mixed>>  $moduleRows
     * @param  array{symbol_count:int,route_count:int,command_count:int,migration_count:int,test_count:int,file_count:int}  $counts
     * @return array<string,mixed>
     */
    private function scanSummaryFromCounts(array $moduleRows, array $counts, int $docLinkCount): array
    {
        return [
            'module_count' => count($moduleRows),
            'symbol_count' => $counts['symbol_count'],
            'doc_link_count' => $docLinkCount,
            'route_count' => $counts['route_count'],
            'command_count' => $counts['command_count'],
            'migration_count' => $counts['migration_count'],
            'test_count' => $counts['test_count'],
            'file_count' => $counts['file_count'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function moduleForPath(string $path): array
    {
        $rules = [
            ['prefix' => 'docs/engineering-knowledge-base', 'slug' => 'engineering_knowledge_docs', 'name' => 'Engineering Knowledge Docs', 'layer' => 'documentation', 'tags' => ['docs', 'knowledge']],
            ['prefix' => 'routes', 'slug' => 'api_routes', 'name' => 'API Routes', 'layer' => 'api', 'tags' => ['routes', 'api']],
            ['prefix' => 'app/Services/Engineering', 'slug' => 'engineering_harness_services', 'name' => 'Engineering Harness Services', 'layer' => 'service', 'tags' => ['engineering', 'harness']],
            ['prefix' => 'app/Console/Commands/AtlasEngineering', 'slug' => 'engineering_harness_cli', 'name' => 'Engineering Harness CLI', 'layer' => 'cli', 'tags' => ['engineering', 'cli']],
            ['prefix' => 'app/Http/Controllers/Engineering', 'slug' => 'engineering_harness_api', 'name' => 'Engineering Harness API', 'layer' => 'api', 'tags' => ['engineering', 'api']],
            ['prefix' => 'app/Models/AtlasEngineering', 'slug' => 'engineering_harness_models', 'name' => 'Engineering Harness Models', 'layer' => 'model', 'tags' => ['engineering', 'database']],
            ['contains' => 'atlas_engineering', 'prefix' => 'database/migrations', 'slug' => 'engineering_harness_schema', 'name' => 'Engineering Harness Schema', 'layer' => 'database', 'tags' => ['engineering', 'migrations']],
            ['prefix' => 'tests', 'contains' => 'Engineering', 'slug' => 'engineering_harness_tests', 'name' => 'Engineering Harness Tests', 'layer' => 'test', 'tags' => ['engineering', 'tests']],
            ['prefix' => 'app/Services/Ai', 'slug' => 'atlas_ai_services', 'name' => 'Atlas AI Services', 'layer' => 'service', 'tags' => ['ai']],
            ['prefix' => 'app/Console/Commands/AtlasCli', 'slug' => 'atlas_cli', 'name' => 'Atlas CLI', 'layer' => 'cli', 'tags' => ['cli']],
            ['prefix' => 'app/Console/Commands/AtlasMemory', 'slug' => 'atlas_memory_cli', 'name' => 'Atlas Memory CLI', 'layer' => 'cli', 'tags' => ['memory']],
            ['prefix' => 'app/Models/AtlasMemory', 'slug' => 'atlas_memory_models', 'name' => 'Atlas Memory Models', 'layer' => 'model', 'tags' => ['memory']],
            ['prefix' => 'app/Models/Ai', 'slug' => 'atlas_ai_models', 'name' => 'Atlas AI Models', 'layer' => 'model', 'tags' => ['ai']],
            ['prefix' => 'app/Http/Controllers/Mobile', 'slug' => 'mobile_gateway_api', 'name' => 'Mobile Gateway API', 'layer' => 'api', 'tags' => ['mobile']],
            ['prefix' => 'database/migrations', 'slug' => 'database_schema', 'name' => 'Database Schema', 'layer' => 'database', 'tags' => ['database']],
            ['prefix' => 'tests', 'slug' => 'test_suite', 'name' => 'Test Suite', 'layer' => 'test', 'tags' => ['tests']],
            ['prefix' => 'app/Console/Commands', 'slug' => 'console_commands', 'name' => 'Console Commands', 'layer' => 'cli', 'tags' => ['cli']],
            ['prefix' => 'app/Http/Controllers', 'slug' => 'http_controllers', 'name' => 'HTTP Controllers', 'layer' => 'api', 'tags' => ['api']],
            ['prefix' => 'app/Models', 'slug' => 'eloquent_models', 'name' => 'Eloquent Models', 'layer' => 'model', 'tags' => ['models']],
            ['prefix' => 'app/Services', 'slug' => 'application_services', 'name' => 'Application Services', 'layer' => 'service', 'tags' => ['services']],
        ];

        foreach ($rules as $rule) {
            if (isset($rule['prefix']) && ! str_starts_with($path, (string) $rule['prefix'])) {
                continue;
            }
            if (isset($rule['contains']) && ! str_contains($path, (string) $rule['contains'])) {
                continue;
            }

            return [
                'slug' => $rule['slug'],
                'name' => $rule['name'],
                'layer' => $rule['layer'],
                'root_path' => $rule['prefix'] ?? dirname($path),
                'description' => null,
                'tags' => $rule['tags'] ?? [],
            ];
        }

        $root = explode('/', $path)[0] ?? 'workspace';

        return [
            'slug' => Str::slug($root.'_misc', '_'),
            'name' => Str::of($root)->replace('_', ' ')->title().' Misc',
            'layer' => 'misc',
            'root_path' => $root,
            'description' => null,
            'tags' => [$root],
        ];
    }

    private function moduleSlugForClass(string $class): ?string
    {
        $class = ltrim($class, '\\');
        $path = str_replace('\\', '/', $class).'.php';
        $path = preg_replace('/^App\//', 'app/', $path) ?? $path;
        $module = $this->moduleForPath($path);

        return $module['slug'] ?? null;
    }

    private function moduleSlugForShortName(string $symbol): ?string
    {
        return match (true) {
            str_ends_with($symbol, 'Service') => 'application_services',
            str_ends_with($symbol, 'Controller') => 'http_controllers',
            str_ends_with($symbol, 'Command') => 'console_commands',
            str_ends_with($symbol, 'Model') => 'eloquent_models',
            default => null,
        };
    }

    private function normalizeRelativeImport(string $relativePath, string $import): string
    {
        $base = trim(dirname($relativePath), '.');
        $parts = explode('/', trim($base.'/'.$import, '/'));
        $normalized = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($normalized);

                continue;
            }
            $normalized[] = $part;
        }

        return implode('/', $normalized);
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<int,array<string,mixed>>
     */
    private function uniqueRelationRows(array $rows, int $limit): array
    {
        return collect($rows)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->unique(fn (array $row): string => hash('sha256', json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''))
            ->values()
            ->take($limit)
            ->all();
    }

    /**
     * @return array<int,array{line:int,text:string,matches:array<int,string>}>
     */
    private function lineMatches(string $content, string $pattern): array
    {
        $matches = [];
        foreach (preg_split('/\r?\n/', $content) ?: [] as $index => $line) {
            if (preg_match($pattern, $line, $match)) {
                $matches[] = [
                    'line' => $index + 1,
                    'text' => $line,
                    'matches' => $match,
                ];
            }
        }

        return $matches;
    }

    private function lineForOffset(string $content, int $offset): int
    {
        return substr_count(substr($content, 0, $offset), "\n") + 1;
    }

    private function routeTarget(string $target): array
    {
        if (preg_match('/\[([A-Za-z0-9_\\\\]+)::class,\s*[\'"]([^\'"]+)[\'"]\]/', $target, $match)) {
            return [
                'controller' => $match[1],
                'action' => $match[2],
            ];
        }

        return [];
    }

    private function joinRoute(string $prefix, string $uri): string
    {
        $uri = trim($uri, '/');
        $path = trim($prefix.'/'.$uri, '/');

        return '/'.$path;
    }

    /**
     * @return array<int,string>
     */
    private function relatedTestsForModule(string $moduleSlug, array $testPaths): array
    {
        $needles = match (true) {
            str_contains($moduleSlug, 'engineering') => ['Engineering', 'AtlasEngineering'],
            str_contains($moduleSlug, 'atlas_ai') => ['Ai'],
            str_contains($moduleSlug, 'memory') => ['Memory'],
            str_contains($moduleSlug, 'cli') => ['AtlasCli'],
            default => [Str::studly($moduleSlug)],
        };

        return collect($testPaths)
            ->filter(fn (string $path): bool => collect($needles)->contains(fn (string $needle): bool => str_contains($path, $needle)))
            ->values()
            ->all();
    }

    private function pathMatchesModule(string $path, AtlasEngineeringCodeModule $module): bool
    {
        $root = trim((string) $module->root_path, '/');
        $path = trim($path, '/');

        if ($root === '') {
            return false;
        }

        if ($path === $root || str_starts_with($path, $root.'/') || str_starts_with($root, $path.'/')) {
            return true;
        }

        if (! str_starts_with($path, $root)) {
            return false;
        }

        $nextCharacter = substr($path, strlen($root), 1);

        return $nextCharacter === '' || $nextCharacter === '.' || ctype_upper($nextCharacter);
    }

    private function languageForPath(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'php' => 'php',
            'ts' => 'typescript',
            'tsx' => 'typescript_react',
            'js' => 'javascript',
            'jsx' => 'javascript_react',
            'md' => 'markdown',
            default => 'text',
        };
    }

    private function classClassification(string $path, string $shortName): string
    {
        return match (true) {
            str_ends_with($shortName, 'Command') => 'command',
            str_ends_with($shortName, 'Controller') => 'controller',
            str_ends_with($shortName, 'Service') => 'service',
            str_starts_with($path, 'app/Models/') => 'model',
            str_starts_with($path, 'tests/') => 'test',
            default => 'class',
        };
    }

    private function modulePayload(AtlasEngineeringCodeModule $module): array
    {
        return [
            'id' => $module->id,
            'slug' => $module->slug,
            'name' => $module->name,
            'layer' => $module->layer,
            'root_path' => $module->root_path,
            'primary_language' => $module->primary_language,
            'status' => $module->status,
            'owner' => $module->owner,
            'description' => $module->description,
            'docs_status' => $module->docs_status,
            'file_count' => $module->file_count,
            'symbol_count' => $module->symbol_count,
            'route_count' => $module->route_count,
            'command_count' => $module->command_count,
            'migration_count' => $module->migration_count,
            'test_count' => $module->test_count,
            'source_hash' => $module->source_hash,
            'docs_hash' => $module->docs_hash,
            'tags' => $module->tags_json ?? [],
            'related_docs' => $module->related_docs_json ?? [],
            'related_tests' => $module->related_tests_json ?? [],
            'metadata' => $module->metadata ?? [],
            'indexed_at' => $module->indexed_at?->toJSON(),
            'archived_at' => $module->archived_at?->toJSON(),
            'created_at' => $module->created_at?->toJSON(),
            'updated_at' => $module->updated_at?->toJSON(),
        ];
    }

    private function symbolPayload(AtlasEngineeringCodeSymbol $symbol): array
    {
        return [
            'id' => $symbol->id,
            'module_id' => $symbol->module_id,
            'module_slug' => $symbol->module?->slug,
            'symbol_type' => $symbol->symbol_type,
            'symbol_name' => $symbol->symbol_name,
            'file_path' => $symbol->file_path,
            'line_start' => $symbol->line_start,
            'line_end' => $symbol->line_end,
            'language' => $symbol->language,
            'signature' => $symbol->signature,
            'namespace' => $symbol->namespace,
            'parent_symbol' => $symbol->parent_symbol,
            'visibility' => $symbol->visibility,
            'status' => $symbol->status,
            'docs_status' => $symbol->docs_status,
            'source_hash' => $symbol->source_hash,
            'related_doc_ids' => $symbol->related_doc_ids_json ?? [],
            'metadata' => $symbol->metadata ?? [],
            'indexed_at' => $symbol->indexed_at?->toJSON(),
        ];
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

    private function workspace(mixed $workspace): string
    {
        $workspace = is_scalar($workspace) && trim((string) $workspace) !== '' ? trim((string) $workspace) : base_path();
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

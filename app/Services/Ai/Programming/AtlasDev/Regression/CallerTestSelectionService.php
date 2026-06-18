<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Regression;

use App\Services\Ai\Programming\ProgrammingTestImpactAnalyzer;
use App\Services\Ai\Support\AiStringListNormalizer;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * E5 -- Caller-test selection via the CodeGraph read-model.
 *
 * Expands `selected_existing_tests` by discovering tests of DIRECT CALLERS
 * of changed symbols. The proven path is the one
 * {@see \App\Services\Ai\AutonomousEvolution\AtlasLoopCrossFileConsumerGateService}
 * already walks: the Code Intelligence graph (symbols + file-snapshot
 * relations) records, per file, which symbols it references and which test
 * targets cover them. This service reads the SAME tables
 * (`atlas_engineering_code_symbols` + `atlas_engineering_code_file_snapshots`
 * `.relations_json`) to resolve, for a set of changed files:
 *
 *   1. the changed symbols (class / interface / trait / enum / method) that
 *      live in those files;
 *   2. the file snapshots whose `relations_json` references those symbols
 *      (i.e. direct callers / consumers);
 *   3. the test paths those snapshots declare as covering the symbol
 *      (`test_targets` / `symbol_references` buckets), filtered to `tests/`
 *      paths.
 *
 * The result is a `$codeGraph` array whose `related_tests` key carries the
 * caller tests. That payload is fed into
 * {@see ProgrammingTestImpactAnalyzer::analyze()}
 * which merges `related_tests` into `selected_existing_tests` -- widening the
 * verification floor so a patch that breaks a direct caller's test T_C runs
 * T_C and surfaces the failure (VAL-E5-006, VAL-E5-007, VAL-E5-008).
 *
 * Safe degradation (VAL-E5-009, VAL-E5-010): every DB read is guarded by
 * {@see DatabaseTableAvailability::has()} and wrapped in a try/catch. When
 * the CI tables are absent (a deployment without Code Intelligence indexing),
 * the service returns an EMPTY `related_tests` list and the analyzer falls
 * back to the conventional impacted-tests selection -- no crash, no false
 * hard-fail. The `$codeGraph['tables']` map reports which tables were
 * available so the caller can record the degradation reason for auditability.
 *
 * The service is a plain Laravel service (no constructor dependencies): the
 * executor resolves it from the container and passes it the changed files +
 * workspace. The {@see CodeGraphWorkspaceIdentity} is resolved via `app(...)`
 * so the workspace->id mapping is consistent with the rest of the code graph
 * read-model.
 *
 * Canonical: mission architecture.md (Atlas Dev Elevation v2, M4 / E5,
 * e5-caller-test-selection feature).
 */
final class CallerTestSelectionService
{
    public const SCHEMA = 'atlas.programming.caller_test_selection.code_graph.v1';

    /**
     * The Code Intelligence symbol types treated as "changed symbols" for
     * caller-test resolution. Mirrors the set
     * {@see AtlasLoopCrossFileConsumerGateService} queries (class / interface
     * / trait / enum / method): the unit of "a caller might break when this
     * changes" is a type or a method, not a property or a constant.
     */
    private const CHANGED_SYMBOL_TYPES = ['class', 'interface', 'trait', 'enum', 'method'];

    /**
     * Resolve the `$codeGraph` payload (with `related_tests`) for a set of
     * changed files.
     *
     * @param  list<string>  $changedFiles  the observed diff's changed file paths.
     * @param  string  $workspace  the workspace path (resolved to a workspace
     *                             id via {@see CodeGraphWorkspaceIdentity}).
     * @return array<string,mixed> the code graph payload:
     *                             - related_tests: list<string> -- tests of direct callers of changed symbols.
     *                             - changed_symbols: list<array<string,mixed>> -- the symbols discovered.
     *                             - tables: array<string,bool> -- CI table availability (auditability).
     *                             - source: string -- which read-model path produced the result.
     *                             - workspace_id: string -- the resolved workspace id.
     */
    public function resolveCodeGraph(array $changedFiles, string $workspace): array
    {
        $tables = $this->tableAvailability();

        // No changed files => nothing to resolve. Still return the payload
        // (with table availability) so the caller can record it.
        if ($changedFiles === []) {
            return $this->payload(
                relatedTests: [],
                changedSymbols: [],
                tables: $tables,
                source: 'no_changed_files',
                workspaceId: $this->resolveWorkspaceId($workspace),
            );
        }

        // VAL-E5-009: when the CI tables are absent, return empty related_tests
        // (no crash). The analyzer then falls back to the conventional
        // impacted-tests selection.
        if (! $tables['symbols'] || ! $tables['file_snapshots']) {
            return $this->payload(
                relatedTests: [],
                changedSymbols: [],
                tables: $tables,
                source: 'ci_tables_absent_conventional_fallback',
                workspaceId: $this->resolveWorkspaceId($workspace),
            );
        }

        $workspaceId = $this->resolveWorkspaceId($workspace);
        $changedSymbols = $this->changedSymbols($workspaceId, $changedFiles);

        if ($changedSymbols === []) {
            return $this->payload(
                relatedTests: [],
                changedSymbols: [],
                tables: $tables,
                source: 'no_changed_symbols_in_read_model',
                workspaceId: $workspaceId,
            );
        }

        $callerTests = $this->callerTests($workspaceId, $changedSymbols, $changedFiles);

        return $this->payload(
            relatedTests: $callerTests,
            changedSymbols: $changedSymbols,
            tables: $tables,
            source: 'atlas_engineering_code_file_snapshots',
            workspaceId: $workspaceId,
        );
    }

    /**
     * @param  list<string>  $relatedTests
     * @param  list<array<string,mixed>>  $changedSymbols
     * @param  array<string,bool>  $tables
     */
    private function payload(array $relatedTests, array $changedSymbols, array $tables, string $source, string $workspaceId): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'related_tests' => array_values($relatedTests),
            'changed_symbols' => $changedSymbols,
            'tables' => $tables,
            'source' => $source,
            'workspace_id' => $workspaceId,
        ];
    }

    /**
     * The CI table availability map (guarded so a missing table never crashes).
     *
     * @return array<string,bool>
     */
    private function tableAvailability(): array
    {
        return [
            'symbols' => DatabaseTableAvailability::has('atlas_engineering_code_symbols'),
            'file_snapshots' => DatabaseTableAvailability::has('atlas_engineering_code_file_snapshots'),
            'workspace_keyed_symbols' => DatabaseTableAvailability::hasColumn('atlas_engineering_code_symbols', 'workspace_id'),
            'workspace_keyed_file_snapshots' => DatabaseTableAvailability::hasColumn('atlas_engineering_code_file_snapshots', 'workspace_id'),
        ];
    }

    /**
     * Resolve the changed symbols (class/interface/trait/enum/method) that
     * live in the changed files. Mirrors the proven
     * {@see AtlasLoopCrossFileConsumerGateService::changedSymbolsFromReadModel()}
     * query shape, guarded by table availability and wrapped in try/catch.
     *
     * @param  list<string>  $changedFiles
     * @return list<array<string,mixed>>
     */
    private function changedSymbols(string $workspaceId, array $changedFiles): array
    {
        try {
            $query = DB::table('atlas_engineering_code_symbols')
                ->where('status', 'active')
                ->whereNull('archived_at')
                ->whereIn('file_path', $changedFiles)
                ->whereIn('symbol_type', self::CHANGED_SYMBOL_TYPES)
                ->select(['id', 'symbol_type', 'symbol_name', 'file_path', 'line_start', 'line_end']);

            if (DatabaseTableAvailability::hasColumn('atlas_engineering_code_symbols', 'workspace_id')) {
                $query->where('workspace_id', $workspaceId);
            }

            return $query
                ->orderBy('file_path')
                ->orderBy('line_start')
                ->limit(50)
                ->get()
                ->map(fn (object $row): array => [
                    'id' => (string) ($row->id ?? ''),
                    'symbol_type' => (string) ($row->symbol_type ?? ''),
                    'symbol_name' => (string) ($row->symbol_name ?? ''),
                    'short_name' => $this->shortName((string) ($row->symbol_name ?? '')),
                    'file_path' => (string) ($row->file_path ?? ''),
                    'line_start' => $row->line_start ?? null,
                    'line_end' => $row->line_end ?? null,
                ])
                ->filter(fn (array $row): bool => trim((string) $row['symbol_name']) !== '')
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Resolve the tests of DIRECT CALLERS of the changed symbols by scanning
     * the file snapshots' relations_json. Mirrors the proven
     * {@see AtlasLoopCrossFileConsumerGateService::consumerContractsFromSnapshots()}
     * relation-bucket scan: for each file snapshot, inspect the
     * `test_targets` and `symbol_references` buckets for relations whose
     * `symbol` matches a changed symbol, and collect the declared test paths.
     *
     * Only relations pointing at a DIFFERENT file (the caller, not the changed
     * file itself) whose test_path lives under `tests/` are admitted -- this
     * is the "caller test T_C" that the conservative floor would miss.
     *
     * @param  list<array<string,mixed>>  $changedSymbols
     * @param  list<string>  $changedFiles
     * @return list<string>
     */
    private function callerTests(string $workspaceId, array $changedSymbols, array $changedFiles): array
    {
        // Index changed symbols by both full name and short name so a
        // relation that records either form is matched.
        $names = [];
        foreach ($changedSymbols as $symbol) {
            foreach ([(string) ($symbol['symbol_name'] ?? ''), (string) ($symbol['short_name'] ?? '')] as $name) {
                $name = trim($name);
                if ($name !== '') {
                    $names[$name] = true;
                }
            }
        }

        if ($names === []) {
            return [];
        }

        $tests = [];
        try {
            $query = DB::table('atlas_engineering_code_file_snapshots')
                ->where('status', 'active')
                ->whereNull('archived_at')
                ->select(['file_path', 'relations_json']);

            if (DatabaseTableAvailability::hasColumn('atlas_engineering_code_file_snapshots', 'workspace_id')) {
                $query->where('workspace_id', $workspaceId);
            }

            $query->orderBy('file_path')->chunk(500, function ($rows) use (&$tests, $names, $changedFiles): void {
                foreach ($rows as $row) {
                    $filePath = trim((string) ($row->file_path ?? ''));
                    $relations = $this->decodeJson($row->relations_json ?? null);
                    foreach (['test_targets', 'symbol_references', 'dependencies'] as $bucket) {
                        foreach ((array) ($relations[$bucket] ?? []) as $relation) {
                            if (! is_array($relation)) {
                                continue;
                            }
                            $symbol = trim((string) ($relation['symbol'] ?? ''));
                            if ($symbol === '' || (! isset($names[$symbol]) && ! isset($names[$this->shortName($symbol)]))) {
                                continue;
                            }

                            // The caller test path: prefer test_path, then fall
                            // back to file_path (the snapshot's own file).
                            $testPath = trim((string) ($relation['test_path'] ?? ''));
                            if ($testPath === '') {
                                $candidateFile = trim((string) ($relation['file_path'] ?? $filePath));
                                // Only treat the relation's file_path as a test
                                // when it actually lives under tests/.
                                $testPath = str_starts_with($candidateFile, 'tests/') ? $candidateFile : '';
                            }

                            if ($testPath === '') {
                                continue;
                            }

                            // Exclude the changed files themselves: a test that
                            // lives IN a changed file is already in the
                            // conventional floor (the analyzer adds changed test
                            // files directly). We only want the CALLER tests
                            // (tests of other files that reference the symbol).
                            if (in_array($testPath, $changedFiles, true)) {
                                continue;
                            }

                            $tests[$testPath] = true;
                        }
                    }
                }
            });
        } catch (Throwable) {
            return [];
        }

        return AiStringListNormalizer::uniqueStrings(array_keys($tests));
    }

    /**
     * Resolve the workspace id from a path via {@see CodeGraphWorkspaceIdentity}.
     * Falls back to an empty string if the identity resolver is unavailable
     * (defensive: the service must never crash on resolution).
     */
    private function resolveWorkspaceId(string $workspace): string
    {
        try {
            return app(CodeGraphWorkspaceIdentity::class)->resolveWorkspaceOrId($workspace);
        } catch (Throwable) {
            return '';
        }
    }

    private function decodeJson(mixed $value): array
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

    private function shortName(string $symbol): string
    {
        $symbol = trim($symbol);
        if ($symbol === '') {
            return '';
        }

        return str_contains($symbol, '\\') ? substr($symbol, strrpos($symbol, '\\') + 1) : $symbol;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos;

use App\Models\AtlasEngineeringCodeSymbol;

/**
 * Resolves a single doc-declared evidence_ref against the real Code Intelligence
 * index (atlas_engineering_code_symbols). This is the resolution half of R4
 * (atlas:aaeos:maturity): a doc does not DECLARE that something is implemented;
 * it CLAIMS evidence_refs, and this resolver checks whether each ref actually
 * exists in indexed code. Never fabricates: a ref that does not resolve returns
 * resolved=false, never an assumed pass.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-documentation-as-law-proposal.md
 */
class AtlasAaeosImplementationEvidenceResolver
{
    /**
     * Code symbol types that count as "a symbol exists" for a {kind: symbol} ref.
     *
     * @var array<int,string>
     */
    private const SYMBOL_TYPES = ['class', 'method', 'trait', 'interface', 'enum'];

    /**
     * Container key under which the loaded index array is cached scoped-to-the-request, so
     * the many resolver instances one create builds share a single load. Resolver-private.
     */
    private const SHARED_INDEX_KEY = 'atlas.aaeos.evidence_resolver.symbol_index';

    /**
     * The symbol_type values for which matchTyped() consults the signature column (routes,
     * artisan commands and migration tables are identified by a substring of their indexed
     * signature). ONLY these rows carry a signature in the index, so the other ~105k rows
     * never pay for a signature string they are never matched against. matchTyped() is the
     * sole reader of the signature, and resolve() only ever calls it with these three types.
     *
     * @var array<int,string>
     */
    private const SIGNATURE_MATCH_TYPES = ['route', 'cli_command', 'migration_table'];

    /**
     * The active Code Intelligence index, loaded ONCE per request and matched in PHP. Stored
     * COLUMNAR — parallel arrays keyed by a single integer row offset — NOT as one small array
     * per row: at ~108k active rows a tuple-per-row layout costs ~64MB (mostly PHP per-array
     * zval overhead, not data), enough to push the create path past PHP's 128MB memory_limit.
     * The columnar layout holds the SAME strings for ~8x less, because the per-row array
     * overhead is gone and the heavily-repeated file_path/symbol_type columns are interned
     * (one shared zval per distinct value instead of one per row).
     *
     * Columns (each indexed by the same integer row offset):
     *   - 'names': symbol_name                                   (every row)
     *   - 'paths': file_path, trimmed + interned                 (every row; ~9k distinct/~108k)
     *   - 'types': symbol_type, interned                         (every row)
     *   - 'sig':   signature, ONLY for SIGNATURE_MATCH_TYPES rows (sparse — the only rows whose
     *              signature matchTyped ever reads; absent offsets are treated as '')
     * Partition views, each a list of row offsets in the DB's natural (heap) load order:
     *   - 'symbol': offsets whose type is in SYMBOL_TYPES (matchSymbol, resolveSymbolFilePaths)
     *   - 'test':   offsets of type test_method|class      (matchTest, resolveTestFilePath)
     *   - 'byType': [symbol_type => offsets]               (matchTyped, the FQN helpers)
     * EVERY view preserves heap load order, and the partition predicate is exactly the
     * symbol_type filter each matcher already applied (`whereIn('symbol_type', …)` /
     * `where('symbol_type', …)`). So iterating a view visits precisely the rows the old per-ref
     * query admitted, in the same order — identical results (incl. cross-type first-match
     * selection in matchTest), just without re-scanning the ~108k-row table or re-querying per
     * ref. The old per-ref `symbol_name LIKE '%ref'` queries were a leading-wildcard seq-scan;
     * one create resolves hundreds of refs, so that was O(refs) full scans (>180s). The SQL
     * LIKE was only a prefilter for a suffix/substring check the matchers already do in PHP, so
     * dropping it changes nothing; dropping the per-query `limit` can only AVOID truncating a
     * real match (strictly-better, never-worse).
     *
     * @var array{names:array<int,string>, paths:array<int,string>, types:array<int,string>, sig:array<int,string>, symbol:array<int,int>, test:array<int,int>, byType:array<string,array<int,int>>}|null
     */
    private ?array $symbolIndex = null;

    /**
     * Load (or reuse) the partitioned index. Cached BOTH per-instance and — keyed in the
     * container as a scoped binding — per-request: one create orchestration builds MANY
     * resolver instances through different consumers (the AAEOS truth service, the
     * documentation-reality service, the test-execution service, …); without the shared cache
     * each would re-run the full 100k-row load (~30 loads/request — seconds of waste plus
     * enough memory churn to blow the 128MB limit). The first resolver in a request loads it;
     * every later resolver — container-made or `new` — gets the SAME structure by reference
     * (PHP copy-on-write keeps that free since it is never mutated). `scoped` resets between
     * requests, so this is request-local reuse, NOT cross-request global state that could go
     * stale across a re-index or deploy. With no container (some unit tests `new` the resolver
     * standalone) it falls back to a per-instance load — still correct.
     *
     * @return array{names:array<int,string>, paths:array<int,string>, types:array<int,string>, sig:array<int,string>, symbol:array<int,int>, test:array<int,int>, byType:array<string,array<int,int>>}
     */
    private function index(): array
    {
        if ($this->symbolIndex !== null) {
            return $this->symbolIndex;
        }

        if (! function_exists('app') || ! app()->bound('app')) {
            return $this->symbolIndex = $this->buildIndex();
        }

        $container = app();
        if (! $container->bound(self::SHARED_INDEX_KEY)) {
            $container->scoped(self::SHARED_INDEX_KEY, fn (): array => $this->buildIndex());
        }

        return $this->symbolIndex = $container->make(self::SHARED_INDEX_KEY);
    }

    /**
     * The single DB read that materializes the index, partitioned in one pass into the COLUMNAR
     * views the matchers consume — all in the DB's natural load order (no order-by, exactly like
     * the prior per-ref queries).
     *
     * Read via the base query builder (->toBase()) streamed with ->cursor(), NOT Eloquent
     * ->get(): the index is large (100k+ active rows) and hydrating that many full models at
     * once exhausts PHP's memory_limit (a 128MB OOM fatal on the create path). cursor() runs
     * ONE lazy query and yields rows one at a time; each row's columns are appended to parallel
     * arrays (and only its offset — an int — to the partition views), so no per-row array is
     * ever retained. None of the four selected columns is cast, so the base-builder raw values
     * are byte-identical to the Eloquent attribute values. file_path and symbol_type are
     * interned via small pools so the ~108k rows share one zval per distinct value (most file
     * paths and all symbol types repeat heavily); the pools are released when this returns.
     *
     * @return array{names:array<int,string>, paths:array<int,string>, types:array<int,string>, sig:array<int,string>, symbol:array<int,int>, test:array<int,int>, byType:array<string,array<int,int>>}
     */
    private function buildIndex(): array
    {
        $names = [];
        $paths = [];
        $types = [];
        $sig = [];
        $symbol = [];
        $test = [];
        $byType = [];

        // Intern the heavily-repeated columns: ~108k rows resolve to ~9k distinct file paths
        // and ~12 distinct symbol types, so one shared zval per distinct value replaces one
        // string per row — the bulk of the memory saving over a tuple-per-row layout.
        $pathPool = [];
        $typePool = [];

        $rows = AtlasEngineeringCodeSymbol::query()
            ->toBase()
            ->where('status', 'active')
            ->select(['symbol_name', 'file_path', 'signature', 'symbol_type'])
            ->cursor();

        $offset = 0;
        foreach ($rows as $row) {
            $type = (string) $row->symbol_type;
            $type = $typePool[$type] ??= $type;
            $path = trim((string) ($row->file_path ?? ''));
            $path = $pathPool[$path] ??= $path;

            $names[$offset] = (string) $row->symbol_name;
            $paths[$offset] = $path;
            $types[$offset] = $type;

            $byType[$type][] = $offset;
            if (in_array($type, self::SYMBOL_TYPES, true)) {
                $symbol[] = $offset;
            }
            if ($type === 'test_method' || $type === 'class') {
                $test[] = $offset;
            }
            // Only SIGNATURE_MATCH_TYPES rows are ever matched on their signature, so only they
            // carry one — the other ~105k rows leave 'sig' unset (read back as '').
            if (in_array($type, self::SIGNATURE_MATCH_TYPES, true)) {
                $sig[$offset] = (string) ($row->signature ?? '');
            }

            $offset++;
        }

        return [
            'names' => $names,
            'paths' => $paths,
            'types' => $types,
            'sig' => $sig,
            'symbol' => $symbol,
            'test' => $test,
            'byType' => $byType,
        ];
    }

    /**
     * @return array{kind:string, ref:string, resolved:bool, matched:?string}
     */
    public function resolve(string $kind, string $ref): array
    {
        $kind = strtolower(trim($kind));
        $ref = trim($ref);

        $matched = $ref === '' ? null : match ($kind) {
            'symbol' => $this->matchSymbol($ref),
            'route' => $this->matchTyped('route', $ref),
            'command' => $this->matchTyped('cli_command', $ref),
            'test' => $this->matchTest($ref),
            'receipt' => $this->matchReceipt($ref),
            'migration' => $this->matchTyped('migration_table', $ref),
            default => null,
        };

        return [
            'kind' => $kind,
            'ref' => $ref,
            'resolved' => $matched !== null,
            'matched' => $matched,
        ];
    }

    /**
     * A symbol resolves when an active class/method/trait/interface/enum symbol
     * matches the ref exactly, or by FQN suffix (\Ref) or method suffix (::ref).
     * Iterates the load-once index; the boundary check (same as before) avoids loose
     * substring hits. Returns the first boundary match, preserving prior semantics.
     */
    private function matchSymbol(string $ref): ?string
    {
        $index = $this->index();
        $names = $index['names'];
        foreach ($index['symbol'] as $offset) {
            if ($this->symbolNameMatchesRef($names[$offset], $ref)) {
                return $names[$offset];
            }
        }

        return null;
    }

    /**
     * The symbol-boundary predicate shared by matchSymbol() and resolveSymbolFilePaths():
     * the indexed name equals the ref, or ends with the FQN suffix "\Ref" or the method
     * suffix "::ref". Centralized so both paths stay byte-identical.
     */
    private function symbolNameMatchesRef(string $name, string $ref): bool
    {
        return $name === $ref
            || str_ends_with($name, '\\'.$ref)
            || str_ends_with($name, '::'.$ref);
    }

    /**
     * B3 freshness — the implementation FILE(S) a {kind: symbol} ref resolves to in
     * the Code Intelligence index. Same boundary matching as matchSymbol(), but returns
     * the distinct, sorted file_path(s) so the truth service can hash their CONTENT and
     * decay `verified` when the implementation changes. Empty when nothing resolves.
     *
     * @return array<int,string> distinct relative file paths, sorted (deterministic)
     */
    public function resolveSymbolFilePaths(string $ref): array
    {
        $ref = trim($ref);
        if ($ref === '') {
            return [];
        }

        $index = $this->index();
        $names = $index['names'];
        $pathCol = $index['paths'];
        $paths = [];
        foreach ($index['symbol'] as $offset) {
            if ($pathCol[$offset] === '') {
                continue;
            }
            if ($this->symbolNameMatchesRef($names[$offset], $ref)) {
                $paths[$pathCol[$offset]] = true;
            }
        }

        $paths = array_keys($paths);
        sort($paths);

        return $paths;
    }

    /**
     * B3 freshness — the test class FILE a {kind: test} ref resolves to in the index,
     * so the truth service can hash its CONTENT and decay `verified` when the test
     * changes. Resolves the test symbol (existence match, same query as matchTest),
     * preferring the file_path of a row whose symbol_name carries the class part of a
     * Class::method ref. Null when no test symbol with a file_path resolves.
     */
    public function resolveTestFilePath(string $ref): ?string
    {
        $ref = trim($ref);
        if ($ref === '') {
            return null;
        }

        // For a Class::method ref, key the file lookup on the class so the file belongs
        // to the DECLARED class, not a same-named method elsewhere.
        $classPart = $this->testClassPart($ref);
        $lookup = $classPart ?? $ref;

        // Same predicate as the prior query (test_method|class, name carries $lookup and
        // "Test", a present file_path), with the SAME class-before-method preference the
        // orderByRaw + value() encoded: a matching `class` row wins; otherwise the first
        // matching `test_method` row. First match within a group preserves value()'s
        // take-the-first-row semantics over the load-once index.
        $index = $this->index();
        $names = $index['names'];
        $pathCol = $index['paths'];
        $typeCol = $index['types'];
        $classPath = null;
        $methodPath = null;
        foreach ($index['test'] as $offset) {
            if ($pathCol[$offset] === '') {
                continue; // mirrors whereNotNull('file_path')
            }
            if (! str_contains($names[$offset], $lookup) || ! str_contains($names[$offset], 'Test')) {
                continue;
            }
            if ($typeCol[$offset] === 'class') {
                $classPath = $pathCol[$offset];
                break; // a class match is top priority; nothing later can outrank it
            }
            $methodPath ??= $pathCol[$offset];
        }

        return $classPath ?? $methodPath;
    }

    /**
     * B3 FQN-bound filter — resolve a declared test ref to the canonical, indexed
     * fully-qualified name so the PHPUnit --filter can be anchored to the DECLARED
     * class and never match a same-named method in a different class.
     *
     * Returns the resolved ['class' => FQN, 'method' => ?string]:
     *   - 'Class::method' -> the indexed FQ class + that method (most specific).
     *   - 'Class'         -> the indexed FQ class, method null (run the class).
     * Returns null when the ref does NOT resolve to a real indexed Class/Class::method
     * (a bare fragment) — the caller must then refuse to record a broad green.
     *
     * @return array{class:string, method:?string}|null
     */
    public function resolveTestFqn(string $ref): ?array
    {
        $ref = trim($ref);
        if ($ref === '') {
            return null;
        }

        $method = null;
        $classRef = $ref;
        if (str_contains($ref, '::')) {
            $pos = (int) strrpos($ref, '::');
            $classRef = trim(substr($ref, 0, $pos));
            $method = trim(substr($ref, $pos + 2));
            if ($method === '' || $classRef === '') {
                return null;
            }
        }

        // The class must be an indexed Test CLASS symbol, matched on the class boundary
        // (exact, or FQN suffix) so "FooTest" cannot silently bind to "BarFooTest".
        $fqn = $this->matchTestClassFqn($classRef);
        if ($fqn === null) {
            return null;
        }

        // A Class::method ref additionally requires the method to exist on THAT class in
        // the index, so a real class + a method that only exists elsewhere is rejected.
        if ($method !== null && ! $this->testMethodExistsOnClass($fqn, $method)) {
            return null;
        }

        return ['class' => $fqn, 'method' => $method];
    }

    /**
     * The class portion of a Class::method ref (short or FQ), or null for a bare ref.
     */
    private function testClassPart(string $ref): ?string
    {
        if (! str_contains($ref, '::')) {
            return null;
        }
        $class = trim(substr($ref, 0, (int) strrpos($ref, '::')));

        return $class !== '' ? $class : null;
    }

    /**
     * Resolve a (short or FQ) test class ref to its indexed FQN, anchored on the class
     * boundary. Matches an active class symbol whose name equals the ref or ends with
     * "\Ref" (or the test_method's parent class). Null when no such class is indexed.
     */
    private function matchTestClassFqn(string $classRef): ?string
    {
        $classRef = trim($classRef);
        if ($classRef === '' || ! str_contains(strtolower($classRef), 'test')) {
            return null;
        }

        // Prefer an indexed class symbol for the test class. Same boundary predicate as
        // before over the class rows; the prior `LIKE '%classRef'` was a prefilter that the
        // boundary check already implies.
        $index = $this->index();
        $names = $index['names'];
        foreach ($index['byType']['class'] ?? [] as $offset) {
            $name = $names[$offset];
            if ($name === $classRef || str_ends_with($name, '\\'.$classRef)) {
                return $name;
            }
        }

        // Fall back to the parent class of a test_method symbol carrying this class.
        foreach ($index['byType']['test_method'] ?? [] as $offset) {
            $name = $names[$offset];
            $classOnly = str_contains($name, '::') ? substr($name, 0, (int) strrpos($name, '::')) : $name;
            if ($classOnly === $classRef || str_ends_with($classOnly, '\\'.$classRef)) {
                return $classOnly;
            }
        }

        return null;
    }

    /**
     * Does $method exist as an indexed test_method on the EXACT class FQN? Anchored so a
     * method that only exists on a different class never satisfies a Class::method ref.
     */
    private function testMethodExistsOnClass(string $classFqn, string $method): bool
    {
        // Same predicate as before over the load-once index; the prior `LIKE '%method'`
        // was a prefilter implied by every check below (all end with $method). A match in
        // EITHER bucket suffices (this returns a bool), so the two buckets are scanned in
        // sequence — order does not affect the answer.
        $shortClass = str_contains($classFqn, '\\') ? substr($classFqn, (int) strrpos($classFqn, '\\') + 1) : $classFqn;
        $index = $this->index();
        $names = $index['names'];
        foreach (['test_method', 'method'] as $type) {
            foreach ($index['byType'][$type] ?? [] as $offset) {
                $name = $names[$offset];
                if ($name === $classFqn.'::'.$method || str_ends_with($name, '\\'.$classFqn.'::'.$method)) {
                    return true;
                }
                // Tolerate index rows that store only the short class::method form.
                if ($name === $shortClass.'::'.$method) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Routes/commands/migrations are identified by a substring of their indexed
     * symbol_name or signature (e.g. a URI path or an artisan command signature).
     */
    private function matchTyped(string $symbolType, string $ref): ?string
    {
        $index = $this->index();
        $names = $index['names'];
        $sig = $index['sig'];
        foreach ($index['byType'][$symbolType] ?? [] as $offset) {
            if (str_contains($names[$offset], $ref) || str_contains($sig[$offset] ?? '', $ref)) {
                return $names[$offset];
            }
        }

        return null;
    }

    /**
     * A test resolves when a test_method symbol references the ref, or a Test
     * class symbol contains it. This checks EXISTENCE only (not green-ness): it
     * answers "does a test symbol for this ref exist in the index?", never "did it
     * pass?". B3 / criterion C2: existence alone no longer reaches the `verified`
     * tier — AtlasAaeosImplementationTruthService requires a GREEN-RUN RECEIPT
     * (AtlasAaeosTestExecutionService) on top of this match, and marks the per-row
     * test_resolution 'existence_only_unrun' until a real green run is recorded.
     */
    private function matchTest(string $ref): ?string
    {
        $index = $this->index();
        $names = $index['names'];
        foreach ($index['test'] as $offset) {
            if (str_contains($names[$offset], $ref) && str_contains($names[$offset], 'Test')) {
                return $names[$offset];
            }
        }

        return null;
    }

    /**
     * A receipt resolves when the referenced evidence artifact exists on disk.
     */
    private function matchReceipt(string $ref): ?string
    {
        return is_file(base_path($ref)) ? $ref : null;
    }
}

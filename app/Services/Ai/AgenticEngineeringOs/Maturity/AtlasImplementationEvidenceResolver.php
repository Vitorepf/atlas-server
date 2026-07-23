<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs\Maturity;

use App\Models\AtlasEngineeringCodeSymbol;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\AgenticEngineeringOs\Support\AtlasEvidenceRefNormalizer;

/**
 * Resolves a single doc-declared evidence_ref against the real Code Intelligence
 * index (atlas_engineering_code_symbols). This is the resolution half of R4
 * (atlas:aeos:maturity): a doc does not DECLARE that something is implemented;
 * it CLAIMS evidence_refs, and this resolver checks whether each ref actually
 * exists in indexed code. Never fabricates: a ref that does not resolve returns
 * resolved=false, never an assumed pass.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-documentation-as-law-proposal.md
 */
class AtlasImplementationEvidenceResolver
{
    public const FIELD_MATCHED = 'matched';
    public const FIELD_MIGRATION = 'migration';
    /**
     * Code symbol types that count as "a symbol exists" for a {kind: symbol} ref.
     *
     * @var array<int,string>
     */
    public const SYMBOL_TYPES = ['class', 'method', 'trait', 'interface', 'enum'];

    /**
     * Container key under which the loaded index array is cached scoped-to-the-request, so
     * the many resolver instances one create builds share a single load. Resolver-private.
     */
    public const SHARED_INDEX_KEY = 'atlas.aaeos.evidence_resolver.symbol_index';

    /**
     * The symbol_type values for which matchTyped() consults the signature column (routes,
     * artisan commands and migration tables are identified by a substring of their indexed
     * signature). ONLY these rows carry a signature in the index, so the other ~105k rows
     * never pay for a signature string they are never matched against. matchTyped() is the
     * sole reader of the signature, and resolve() only ever calls it with these three types.
     *
     * @var array<int,string>
     */
    public const SIGNATURE_MATCH_TYPES = ['route', 'cli_command', 'migration_table'];

    public const STATUS_ACTIVE = 'active';
    public const FIELD_SYMBOL = 'symbol';
    public const FIELD_TEST = 'test';
    public const FIELD_CLASS = 'class';
    public const FIELD_METHOD = 'method';
    public const FIELD_NAMES = 'names';
    public const FIELD_PATHS = 'paths';
    public const FIELD_TYPES = 'types';
    public const FIELD_SIG = 'sig';
    public const FIELD_COMMAND = 'command';
    public const FIELD_KIND = 'kind';
    public const FIELD_RECEIPT = 'receipt';
    public const FIELD_REF = 'ref';
    public const FIELD_RESOLVED = 'resolved';
    public const FIELD_ROUTE = 'route';
    public const FIELD_TEST_METHOD = 'test_method';
    public const FIELD_STATUS = 'status';
    public const FIELD_APP = 'app';
    public const FIELD_FILE_PATH = 'file_path';
    public const FIELD_MEMORY_LIMIT = 'memory_limit';
    public const FIELD_SYMBOL_TYPE = 'symbol_type';
    public const FIELD_CLI_COMMAND = 'cli_command';
    public const FIELD_MIGRATION_TABLE = 'migration_table';
    public const FIELD_SIGNATURE = 'signature';
    public const FIELD_SYMBOL_NAME = 'symbol_name';
    public const FIELD_BY_TYPE = 'byType';
    public const FIELD_TEST_2 = 'Test';

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
     *   - self::FIELD_TEST:   offsets of type test_method|class      (matchTest, resolveTestFilePath)
     *   - self::FIELD_BY_TYPE: [symbol_type => offsets]               (matchTyped, the FQN helpers)
     * EVERY view preserves heap load order, and the partition predicate is exactly the
     * symbol_type filter each matcher already applied (`whereIn(self::FIELD_SYMBOL_TYPE, …)` /
     * `where(self::FIELD_SYMBOL_TYPE, …)`). So iterating a view visits precisely the rows the old per-ref
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

    public function __construct(
        private readonly AtlasEvidenceRefNormalizer $evidenceRefNormalizer = new AtlasEvidenceRefNormalizer,
    ) {}

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

        // The columnar build over ~108k symbols peaks ~85-90MB; PHP's 128MB default
        // leaves a memory-loaded caller (notably atlas:ai:session-bootstrap, every
        // agent's mandatory first step) too little headroom and the fill OOMs at the
        // columnar append. The index is already memory-optimized — this RAISES-ONLY
        // to the 512MB in-process floor commands already use, before the build.
        $this->ensureIndexMemoryFloor();

        if (! function_exists(self::FIELD_APP) || ! app()->bound(self::FIELD_APP)) {
            return $this->symbolIndex = $this->buildIndex();
        }

        $container = app();
        if (! $container->bound(self::SHARED_INDEX_KEY)) {
            $container->scoped(self::SHARED_INDEX_KEY, fn (): array => $this->buildIndex());
        }

        return $this->symbolIndex = $container->make(self::SHARED_INDEX_KEY);
    }

    /**
     * RAISE-ONLY memory floor for the one-time columnar index build. The build over
     * ~108k active code symbols is already heavily optimized (cursor + interning +
     * columnar layout, ~85-90MB peak) — the residual is the irreducible cost of
     * indexing a 108k-symbol codebase, which legitimately needs more than PHP's
     * 128MB default once a caller has already consumed memory before reaching here.
     * Never lowers an already-higher CLI/test budget (`-d memory_limit=…` / paratest
     * stay intact) and never touches an unlimited (-1) limit. Idempotent / a no-op
     * once raised.
     */
    private function ensureIndexMemoryFloor(): void
    {
        $floorBytes = 512 * 1024 * 1024;
        $current = $this->memoryLimitBytes();
        if ($current !== -1 && $current < $floorBytes) {
            @ini_set(self::FIELD_MEMORY_LIMIT, '512M');
        }
    }

    /** Current `memory_limit` in bytes; -1 means unlimited. */
    private function memoryLimitBytes(): int
    {
        $raw = AiValueNormalizer::trimmedStringOrNull(ini_get(self::FIELD_MEMORY_LIMIT)) ?? '';
        if ($raw === '' || $raw === '-1') {
            return -1;
        }

        $value = (int) $raw;

        return match (AiValueNormalizer::lowerTrimmedString(substr($raw, -1))) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /**
     * The single DB read that materializes the index, partitioned in one pass into the COLUMNAR
     * views the matchers consume.
     *
     * The read is SELECT DISTINCT, NOT a raw scan of every active row. The Code Intelligence
     * table is re-indexed in place and accumulates exact-duplicate rows (the indexer appends
     * a fresh row set per run; without a prune it grows unbounded). It has been observed at
     * ~11.8M active rows that collapse to only ~280k DISTINCT (symbol_name, file_path,
     * signature, symbol_type) tuples — ~98% duplicates. Loading the raw 11.8M into the columnar
     * arrays OOMs even a 2GB limit (the array slots alone exceed it); the DISTINCT set is the
     * actual working set and fits the 512MB index floor. Pushing DISTINCT to Postgres (server-
     * side hash-aggregate) also slashes the rows transferred over the wire. Every matcher is a
     * suffix/substring existence check or a sorted-distinct path projection, so collapsing exact
     * duplicates cannot change any result: duplicate rows share the same name/path/type, and the
     * matchers were already order-insensitive (no ORDER BY — a leading-wildcard scan never had a
     * guaranteed order, so "first boundary match" was always "some boundary match").
     *
     * Read via the base query builder (->toBase()) streamed with ->cursor(), NOT Eloquent
     * ->get(): hydrating hundreds of thousands of full models at once exhausts PHP's
     * memory_limit. cursor() runs ONE lazy query and yields rows one at a time; each row's
     * columns are appended to parallel arrays (and only its offset — an int — to the partition
     * views), so no per-row array is ever retained. None of the four selected columns is cast,
     * so the base-builder raw values are byte-identical to the Eloquent attribute values.
     * file_path and symbol_type are interned via small pools so the rows share one zval per
     * distinct value (~26k distinct paths, ~18 distinct types — both repeat heavily across the
     * ~280k distinct tuples); the pools are released when this returns.
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

        // Intern the heavily-repeated columns: ~280k distinct tuples resolve to ~26k distinct
        // file paths and ~18 distinct symbol types, so one shared zval per distinct value
        // replaces one string per row — the bulk of the memory saving over a tuple-per-row
        // layout. symbol_name is also interned: the ~280k tuples carry only ~141k distinct
        // names (one symbol appears under several paths), so duplicates share one zval.
        $namePool = [];
        $pathPool = [];
        $typePool = [];

        $rows = AtlasEngineeringCodeSymbol::query()
            ->toBase()
            ->where(self::FIELD_STATUS, self::STATUS_ACTIVE)
            ->select([self::FIELD_SYMBOL_NAME, self::FIELD_FILE_PATH, self::FIELD_SIGNATURE, self::FIELD_SYMBOL_TYPE])
            ->distinct()
            ->cursor();

        $offset = 0;
        foreach ($rows as $row) {
            $type = AiValueNormalizer::trimmedScalarStringOrNull($row->symbol_type ?? null) ?? '';
            $type = $typePool[$type] ??= $type;
            $path = AiValueNormalizer::trimmedStringOrNull($row->file_path ?? null) ?? '';
            $path = $pathPool[$path] ??= $path;

            $name = AiValueNormalizer::trimmedScalarStringOrNull($row->symbol_name ?? null) ?? '';
            $names[$offset] = $namePool[$name] ??= $name;
            $paths[$offset] = $path;
            $types[$offset] = $type;

            $byType[$type][] = $offset;
            if (in_array($type, self::SYMBOL_TYPES, true)) {
                $symbol[] = $offset;
            }
            if ($type === self::FIELD_TEST_METHOD || $type === 'class') {
                $test[] = $offset;
            }
            // Only SIGNATURE_MATCH_TYPES rows are ever matched on their signature, so only they
            // carry one — the other ~105k rows leave 'sig' unset (read back as '').
            if (in_array($type, self::SIGNATURE_MATCH_TYPES, true)) {
                $sig[$offset] = AiValueNormalizer::trimmedScalarStringOrNull($row->signature ?? null) ?? '';
            }

            $offset++;
        }

        return [
            self::FIELD_NAMES => $names,
            self::FIELD_PATHS => $paths,
            self::FIELD_TYPES => $types,
            self::FIELD_SIG => $sig,
            self::FIELD_SYMBOL => $symbol,
            self::FIELD_TEST => $test,
            self::FIELD_BY_TYPE => $byType,
        ];
    }

    /**
     * @return array{kind:string, ref:string, resolved:bool, matched:?string}
     */
    public function resolve(string $kind, string $ref): array
    {
        $kind = $this->evidenceRefNormalizer->kind($kind);
        $ref = $this->evidenceRefNormalizer->ref($ref);

        $matched = $ref === '' ? null : match ($kind) {
            self::FIELD_SYMBOL => $this->matchSymbol($ref),
            self::FIELD_ROUTE => $this->matchTyped(self::FIELD_ROUTE, $ref),
            self::FIELD_COMMAND => $this->matchTyped(self::FIELD_CLI_COMMAND, $ref),
            self::FIELD_TEST => $this->matchTest($ref),
            self::FIELD_RECEIPT => $this->matchReceipt($ref),
            self::FIELD_MIGRATION => $this->matchTyped(self::FIELD_MIGRATION_TABLE, $ref),
            default => null,
        };

        return [
            self::FIELD_KIND => $kind,
            self::FIELD_REF => $ref,
            self::FIELD_RESOLVED => $matched !== null,
            self::FIELD_MATCHED => $matched,
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
        $names = $index[self::FIELD_NAMES];
        foreach ($index[self::FIELD_SYMBOL] as $offset) {
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
     * the Code Intelligence index. Anchored to the CANONICAL symbol that matchSymbol()
     * would return (exact indexed FQN / method name), NOT every suffix-boundary hit in
     * the index. That set-stability matters: a later re-index that adds another class
     * whose FQN merely ends with "\Ref" must not inflate the hashed path set and
     * falsely stale a green receipt whose implementation file never changed.
     *
     * @return array<int,string> distinct relative file paths, sorted (deterministic)
     */
    public function resolveSymbolFilePaths(string $ref): array
    {
        $ref = AiValueNormalizer::trimmedStringOrNull($ref) ?? '';
        if ($ref === '') {
            return [];
        }

        $matched = $this->matchSymbol($ref);
        if ($matched === null) {
            return [];
        }

        $index = $this->index();
        $names = $index[self::FIELD_NAMES];
        $pathCol = $index[self::FIELD_PATHS];
        $paths = [];
        foreach ($index[self::FIELD_SYMBOL] as $offset) {
            if ($names[$offset] !== $matched) {
                continue;
            }
            $path = $pathCol[$offset];
            if ($path !== '') {
                $paths[$path] = true;
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
        $ref = AiValueNormalizer::trimmedStringOrNull($ref) ?? '';
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
        $names = $index[self::FIELD_NAMES];
        $pathCol = $index[self::FIELD_PATHS];
        $typeCol = $index[self::FIELD_TYPES];
        $classPath = null;
        $methodPath = null;
        foreach ($index[self::FIELD_TEST] as $offset) {
            if ($pathCol[$offset] === '') {
                continue; // mirrors whereNotNull(self::FIELD_FILE_PATH)
            }
            if (! str_contains($names[$offset], $lookup) || ! str_contains($names[$offset], self::FIELD_TEST_2)) {
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
     * Returns the resolved [self::FIELD_CLASS => FQN, self::FIELD_METHOD => ?string]:
     *   - 'Class::method' -> the indexed FQ class + that method (most specific).
     *   - 'Class'         -> the indexed FQ class, method null (run the class).
     * Returns null when the ref does NOT resolve to a real indexed Class/Class::method
     * (a bare fragment) — the caller must then refuse to record a broad green.
     *
     * @return array{class:string, method:?string}|null
     */
    public function resolveTestFqn(string $ref): ?array
    {
        $ref = AiValueNormalizer::trimmedStringOrNull($ref) ?? '';
        if ($ref === '') {
            return null;
        }

        $method = null;
        $classRef = $ref;
        if (str_contains($ref, '::')) {
            $pos = (int) strrpos($ref, '::');
            $classRef = AiValueNormalizer::trimmedStringOrNull(substr($ref, 0, $pos)) ?? '';
            $method = AiValueNormalizer::trimmedStringOrNull(substr($ref, $pos + 2)) ?? '';
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

        return [self::FIELD_CLASS => $fqn, self::FIELD_METHOD => $method];
    }

    /**
     * The class portion of a Class::method ref (short or FQ), or null for a bare ref.
     */
    private function testClassPart(string $ref): ?string
    {
        if (! str_contains($ref, '::')) {
            return null;
        }
        $class = AiValueNormalizer::trimmedStringOrNull(substr($ref, 0, (int) strrpos($ref, '::'))) ?? '';

        return $class !== '' ? $class : null;
    }

    /**
     * Resolve a (short or FQ) test class ref to its indexed FQN, anchored on the class
     * boundary. Matches an active class symbol whose name equals the ref or ends with
     * "\Ref" (or the test_method's parent class). Null when no such class is indexed.
     */
    private function matchTestClassFqn(string $classRef): ?string
    {
        $classRef = AiValueNormalizer::trimmedStringOrNull($classRef) ?? '';
        if ($classRef === '' || ! str_contains(AiValueNormalizer::lowerTrimmedString($classRef), self::FIELD_TEST)) {
            return null;
        }

        // Prefer an indexed class symbol for the test class. Same boundary predicate as
        // before over the class rows; the prior `LIKE '%classRef'` was a prefilter that the
        // boundary check already implies.
        $index = $this->index();
        $names = $index[self::FIELD_NAMES];
        foreach ($index[self::FIELD_BY_TYPE][self::FIELD_CLASS] ?? [] as $offset) {
            $name = $names[$offset];
            if ($name === $classRef || str_ends_with($name, '\\'.$classRef)) {
                return $name;
            }
        }

        // Fall back to the parent class of a test_method symbol carrying this class.
        foreach ($index[self::FIELD_BY_TYPE][self::FIELD_TEST_METHOD] ?? [] as $offset) {
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
        $names = $index[self::FIELD_NAMES];
        foreach ([self::FIELD_TEST_METHOD, self::FIELD_METHOD] as $type) {
            foreach ($index[self::FIELD_BY_TYPE][$type] ?? [] as $offset) {
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
        $names = $index[self::FIELD_NAMES];
        $sig = $index[self::FIELD_SIG];
        foreach ($index[self::FIELD_BY_TYPE][$symbolType] ?? [] as $offset) {
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
     * tier — AtlasImplementationTruthService requires a GREEN-RUN RECEIPT
     * (AtlasCapabilityTestExecutionService) on top of this match, and marks the per-row
     * test_resolution 'existence_only_unrun' until a real green run is recorded.
     */
    private function matchTest(string $ref): ?string
    {
        $index = $this->index();
        $names = $index[self::FIELD_NAMES];
        foreach ($index[self::FIELD_TEST] as $offset) {
            if (str_contains($names[$offset], $ref) && str_contains($names[$offset], self::FIELD_TEST_2)) {
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

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
     * The LIKE narrows in SQL; the PHP boundary check avoids loose substring hits.
     */
    private function matchSymbol(string $ref): ?string
    {
        $candidates = AtlasEngineeringCodeSymbol::query()
            ->where('status', 'active')
            ->whereIn('symbol_type', self::SYMBOL_TYPES)
            ->where('symbol_name', 'like', '%'.$ref)
            ->limit(100)
            ->pluck('symbol_name');

        foreach ($candidates as $name) {
            $name = (string) $name;
            if ($name === $ref
                || str_ends_with($name, '\\'.$ref)
                || str_ends_with($name, '::'.$ref)) {
                return $name;
            }
        }

        return null;
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

        $rows = AtlasEngineeringCodeSymbol::query()
            ->where('status', 'active')
            ->whereIn('symbol_type', self::SYMBOL_TYPES)
            ->where('symbol_name', 'like', '%'.$ref)
            ->limit(100)
            ->get(['symbol_name', 'file_path']);

        $paths = [];
        foreach ($rows as $row) {
            $name = (string) $row->symbol_name;
            $path = trim((string) ($row->file_path ?? ''));
            if ($path === '') {
                continue;
            }
            if ($name === $ref
                || str_ends_with($name, '\\'.$ref)
                || str_ends_with($name, '::'.$ref)) {
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
        $ref = trim($ref);
        if ($ref === '') {
            return null;
        }

        // For a Class::method ref, key the file lookup on the class so the file belongs
        // to the DECLARED class, not a same-named method elsewhere.
        $classPart = $this->testClassPart($ref);
        $lookup = $classPart ?? $ref;

        $row = AtlasEngineeringCodeSymbol::query()
            ->where('status', 'active')
            ->whereIn('symbol_type', ['test_method', 'class'])
            ->where('symbol_name', 'like', '%'.$lookup.'%')
            ->where('symbol_name', 'like', '%Test%')
            ->whereNotNull('file_path')
            ->orderByRaw("CASE WHEN symbol_type = 'class' THEN 0 ELSE 1 END")
            ->value('file_path');

        $path = $row !== null ? trim((string) $row) : '';

        return $path !== '' ? $path : null;
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

        // Prefer an indexed class symbol for the test class.
        $candidates = AtlasEngineeringCodeSymbol::query()
            ->where('status', 'active')
            ->where('symbol_type', 'class')
            ->where('symbol_name', 'like', '%'.$classRef)
            ->limit(100)
            ->pluck('symbol_name');

        foreach ($candidates as $name) {
            $name = (string) $name;
            if ($name === $classRef || str_ends_with($name, '\\'.$classRef)) {
                return $name;
            }
        }

        // Fall back to the parent class of a test_method symbol carrying this class.
        $methodRows = AtlasEngineeringCodeSymbol::query()
            ->where('status', 'active')
            ->where('symbol_type', 'test_method')
            ->where('symbol_name', 'like', '%'.$classRef.'::%')
            ->limit(100)
            ->pluck('symbol_name');

        foreach ($methodRows as $name) {
            $name = (string) $name;
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
        $rows = AtlasEngineeringCodeSymbol::query()
            ->where('status', 'active')
            ->whereIn('symbol_type', ['test_method', 'method'])
            ->where('symbol_name', 'like', '%'.$method)
            ->limit(200)
            ->pluck('symbol_name');

        foreach ($rows as $name) {
            $name = (string) $name;
            if ($name === $classFqn.'::'.$method || str_ends_with($name, '\\'.$classFqn.'::'.$method)) {
                return true;
            }
            // Tolerate index rows that store only the short class::method form.
            $shortClass = str_contains($classFqn, '\\') ? substr($classFqn, (int) strrpos($classFqn, '\\') + 1) : $classFqn;
            if ($name === $shortClass.'::'.$method) {
                return true;
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
        $value = AtlasEngineeringCodeSymbol::query()
            ->where('status', 'active')
            ->where('symbol_type', $symbolType)
            ->where(function ($w) use ($ref): void {
                $w->where('symbol_name', 'like', '%'.$ref.'%')
                    ->orWhere('signature', 'like', '%'.$ref.'%');
            })
            ->value('symbol_name');

        return $value !== null ? (string) $value : null;
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
        $value = AtlasEngineeringCodeSymbol::query()
            ->where('status', 'active')
            ->whereIn('symbol_type', ['test_method', 'class'])
            ->where('symbol_name', 'like', '%'.$ref.'%')
            ->where('symbol_name', 'like', '%Test%')
            ->value('symbol_name');

        return $value !== null ? (string) $value : null;
    }

    /**
     * A receipt resolves when the referenced evidence artifact exists on disk.
     */
    private function matchReceipt(string $ref): ?string
    {
        return is_file(base_path($ref)) ? $ref : null;
    }
}

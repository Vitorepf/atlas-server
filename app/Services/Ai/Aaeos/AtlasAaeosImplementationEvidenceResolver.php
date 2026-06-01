<?php

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
     * class symbol contains it. v1 checks EXISTENCE only (not green-ness); the
     * truth service marks test_resolution=existence_only so callers do not read
     * this as a passing-test guarantee.
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

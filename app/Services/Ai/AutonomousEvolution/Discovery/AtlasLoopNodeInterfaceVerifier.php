<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * ACDE Leap 6 (design-judgement ceiling) — verify a workspace's REAL AST surface against the HUMAN-frozen
 * node-interface contract.
 *
 * For each file the contract names, read its actual source (from the replay workspace), extract its real
 * interface via {@see AtlasLoopNodeInterfaceExtractor} (decorrelated AST, not the provider LLM), and prove
 * the file HONORS the frozen requirements: the named type exists, exposes the required public methods,
 * implements/extends the required types, and carries NONE of the forbidden imports (dependency-direction).
 * Any miss is an 'interface_contract_violation:<file>:<detail>' reason fed to the REPLAN loop / cert refusal.
 *
 * Deterministic + ungameable: the bar is human-authored, the surface is an AST census. Pure (no provider,
 * no DB). Name resolution honours the file's namespace + use-imports so a short written name matches the
 * contract's FQN.
 */
final class AtlasLoopNodeInterfaceVerifier
{
    public function __construct(private readonly ?AtlasLoopNodeInterfaceExtractor $extractor = null) {}

    /**
     * @param  array<string, array{fqn:?string, required_public_methods:list<string>, implements:list<string>, extends:list<string>, forbidden_imports:list<string>}>  $contract
     * @param  callable(string): ?string  $readSource  (relativeFile) => source, or null/'' if absent
     * @return list<string> violation reasons (empty => the contract is honored)
     */
    public function verify(array $contract, callable $readSource): array
    {
        $extractor = $this->extractor ?? new AtlasLoopNodeInterfaceExtractor;
        $violations = [];

        foreach ($contract as $file => $req) {
            $src = $readSource($file);
            if (! is_string($src) || trim($src) === '') {
                $violations[] = $this->v($file, 'file_missing');

                continue;
            }
            $iface = $extractor->extract($src);
            if (($iface['parsed'] ?? false) !== true) {
                $violations[] = $this->v($file, 'unparseable');

                continue;
            }

            $type = $this->pickType($iface['types'], $req['fqn']);
            if ($type === null) {
                $violations[] = $this->v($file, $req['fqn'] !== null ? 'missing_type:'.$req['fqn'] : 'no_declared_type');

                continue;
            }

            foreach ($req['required_public_methods'] as $m) {
                if (! $this->hasMethodCi($type['public_methods'], $m)) {
                    $violations[] = $this->v($file, 'missing_public_method:'.$m);
                }
            }
            foreach ($req['implements'] as $needed) {
                if (! $this->refSatisfies($type['implements'], $needed, $iface)) {
                    $violations[] = $this->v($file, 'missing_implements:'.$needed);
                }
            }
            foreach ($req['extends'] as $needed) {
                if (! $this->refSatisfies($type['extends'], $needed, $iface)) {
                    $violations[] = $this->v($file, 'missing_extends:'.$needed);
                }
            }
            foreach ($req['forbidden_imports'] as $forbidden) {
                if ($this->importsContain($iface['imports'], $forbidden)) {
                    $violations[] = $this->v($file, 'forbidden_import:'.$forbidden);
                }
            }
        }

        return $violations;
    }

    private function v(string $file, string $detail): string
    {
        return 'interface_contract_violation:'.$file.':'.$detail;
    }

    /**
     * Pick the contract's target type: the one whose FQN matches (if specified), else the first declared.
     *
     * @param  list<array{fqn:string, name:string, kind:string, public_methods:list<string>, implements:list<string>, extends:list<string>}>  $types
     * @return array{fqn:string, name:string, kind:string, public_methods:list<string>, implements:list<string>, extends:list<string>}|null
     */
    private function pickType(array $types, ?string $fqn): ?array
    {
        if ($types === []) {
            return null;
        }
        if ($fqn === null) {
            return $types[0];
        }
        $want = ltrim($fqn, '\\');
        foreach ($types as $t) {
            if (ltrim((string) $t['fqn'], '\\') === $want || $this->shortName((string) $t['fqn']) === $this->shortName($want)) {
                return $t;
            }
        }

        return null;
    }

    /** @param  list<string>  $have */
    private function hasMethodCi(array $have, string $method): bool
    {
        $needle = mb_strtolower(trim($method));
        foreach ($have as $h) {
            if (mb_strtolower($h) === $needle) {
                return true; // PHP method names are case-insensitive
            }
        }

        return false;
    }

    /**
     * Does the actual implements/extends list satisfy a required FQN? Resolve each written ref against the
     * file's namespace + imports, then compare by full FQN OR (pragmatic, low-false-negative) short name.
     *
     * @param  list<string>  $written  the names as written in the source (short or qualified)
     * @param  array{namespace:?string, imports:list<string>}  $iface
     */
    private function refSatisfies(array $written, string $needed, array $iface): bool
    {
        $needFqn = ltrim(trim($needed), '\\');
        $needShort = $this->shortName($needFqn);
        foreach ($written as $w) {
            $resolved = $this->resolveFqn($w, (array) ($iface['imports'] ?? []), $iface['namespace'] ?? null);
            if ($resolved === $needFqn || $this->shortName($w) === $needShort) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<string>  $imports */
    private function importsContain(array $imports, string $forbidden): bool
    {
        $want = ltrim(trim($forbidden), '\\');
        $wantShort = $this->shortName($want);
        foreach ($imports as $imp) {
            $imp = ltrim(trim($imp), '\\');
            if ($imp === $want || $this->shortName($imp) === $wantShort) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve a written class reference to an FQN using the file's use-imports + namespace.
     *
     * @param  list<string>  $imports
     */
    private function resolveFqn(string $written, array $imports, ?string $namespace): string
    {
        $w = trim($written);
        if (str_starts_with($w, '\\')) {
            return ltrim($w, '\\'); // fully-qualified
        }
        $firstSeg = explode('\\', $w)[0];
        foreach ($imports as $imp) {
            $imp = ltrim(trim($imp), '\\');
            if ($this->shortName($imp) === $firstSeg) {
                $rest = mb_substr($w, mb_strlen($firstSeg));

                return $imp.$rest; // import-resolved
            }
        }

        return $namespace !== null && trim($namespace) !== '' ? trim($namespace).'\\'.$w : $w;
    }

    private function shortName(string $fqn): string
    {
        $parts = explode('\\', ltrim(trim($fqn), '\\'));

        return (string) end($parts);
    }
}

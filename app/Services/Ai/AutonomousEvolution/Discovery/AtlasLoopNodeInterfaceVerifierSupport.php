<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

final class AtlasLoopNodeInterfaceVerifierSupport
{
    /**
     * @param  array{fqn:?string, required_public_methods:list<string>, implements:list<string>, extends:list<string>, forbidden_imports:list<string>}  $req
     * @param  callable(string): ?string  $readSource
     * @return list<string>
     */
    public function verifyFile(string $file, array $req, callable $readSource, AtlasLoopNodeInterfaceExtractor $extractor): array
    {
        $src = $readSource($file);
        if (! is_string($src) || trim($src) === '') {
            return [$this->v($file, 'file_missing')];
        }

        $iface = $extractor->extract($src);
        if (($iface['parsed'] ?? false) !== true) {
            return [$this->v($file, 'unparseable')];
        }

        $type = $this->pickType($iface['types'], $req['fqn']);
        if ($type === null) {
            return [$this->v($file, $req['fqn'] !== null ? 'missing_type:'.$req['fqn'] : 'no_declared_type')];
        }

        $violations = [];
        $this->appendTypeContractViolations($violations, $file, $type, $req, $iface);

        return $violations;
    }

    /**
     * Pick the contract's target type: the one whose FQN matches (if specified), else the first declared.
     *
     * @param  list<array{fqn:string, name:string, kind:string, public_methods:list<string>, implements:list<string>, extends:list<string>}>  $types
     * @return array{fqn:string, name:string, kind:string, public_methods:list<string>, implements:list<string>, extends:list<string>}|null
     */
    public function pickType(array $types, ?string $fqn): ?array
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

    /**
     * @param  list<string>  $violations
     * @param  array{fqn:string, name:string, kind:string, public_methods:list<string>, implements:list<string>, extends:list<string>}  $type
     * @param  array{required_public_methods:list<string>, implements:list<string>, extends:list<string>, forbidden_imports:list<string>}  $req
     * @param  array{imports:list<string>, namespace:?string, service_refs?:list<string>}  $iface
     */
    public function appendTypeContractViolations(array &$violations, string $file, array $type, array $req, array $iface): void
    {
        foreach ($req['required_public_methods'] as $method) {
            if (! $this->hasMethodCi($type['public_methods'], $method)) {
                $violations[] = $this->v($file, 'missing_public_method:'.$method);
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

        $this->appendForbiddenImportViolations($violations, $file, $req['forbidden_imports'], $iface);
    }

    /** @param  list<string>  $have */
    public function hasMethodCi(array $have, string $method): bool
    {
        $needle = mb_strtolower(trim($method));
        foreach ($have as $item) {
            if (mb_strtolower($item) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $written
     * @param  array{namespace:?string, imports:list<string>}  $iface
     */
    public function refSatisfies(array $written, string $needed, array $iface): bool
    {
        $needFqn = ltrim(trim($needed), '\\');
        $needShort = $this->shortName($needFqn);
        foreach ($written as $item) {
            $resolved = $this->resolveFqn($item, (array) ($iface['imports'] ?? []), $iface['namespace'] ?? null);
            if ($resolved === $needFqn || $this->shortName($item) === $needShort) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $violations
     * @param  list<string>  $forbiddenImports
     * @param  array{imports:list<string>, namespace:?string, service_refs?:list<string>}  $iface
     */
    public function appendForbiddenImportViolations(array &$violations, string $file, array $forbiddenImports, array $iface): void
    {
        foreach ($forbiddenImports as $forbidden) {
            if ($this->importsContain($iface['imports'], $forbidden)) {
                $violations[] = $this->v($file, 'forbidden_import:'.$forbidden);
            } elseif ($this->serviceRefsContain((array) ($iface['service_refs'] ?? []), $forbidden, $iface)) {
                $violations[] = $this->v($file, 'forbidden_service_string:'.$forbidden);
            }
        }
    }

    /**
     * @param  list<string>  $imports
     */
    public function importsContain(array $imports, string $forbidden): bool
    {
        $want = ltrim(trim($forbidden), '\\');
        if (str_ends_with($want, '\\')) {
            foreach ($imports as $imp) {
                if (str_starts_with(ltrim(trim($imp), '\\').'\\', $want)) {
                    return true;
                }
            }

            return false;
        }
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
     * @param  list<string>  $serviceRefs
     * @param  array{namespace:?string, imports:list<string>}  $iface
     */
    public function serviceRefsContain(array $serviceRefs, string $forbidden, array $iface): bool
    {
        $want = ltrim(trim($forbidden), '\\');
        $isPrefix = str_ends_with($want, '\\');
        $wantShort = $isPrefix ? '' : $this->shortName($want);
        foreach ($serviceRefs as $ref) {
            $resolved = $this->resolveFqn($ref, (array) ($iface['imports'] ?? []), $iface['namespace'] ?? null);
            if ($isPrefix) {
                if (str_starts_with($resolved.'\\', $want)) {
                    return true;
                }
            } elseif ($resolved === $want || $this->shortName($ref) === $wantShort) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $imports
     */
    public function resolveFqn(string $written, array $imports, ?string $namespace): string
    {
        $w = trim($written);
        if (str_starts_with($w, '\\')) {
            return ltrim($w, '\\');
        }
        $firstSeg = explode('\\', $w)[0];
        foreach ($imports as $imp) {
            $imp = ltrim(trim($imp), '\\');
            if ($this->shortName($imp) === $firstSeg) {
                $rest = mb_substr($w, mb_strlen($firstSeg));

                return $imp.$rest;
            }
        }

        return $namespace !== null && trim($namespace) !== '' ? trim($namespace).'\\'.$w : $w;
    }

    public function shortName(string $fqn): string
    {
        $parts = explode('\\', ltrim(trim($fqn), '\\'));

        return (string) end($parts);
    }

    public function v(string $file, string $detail): string
    {
        return 'interface_contract_violation:'.$file.':'.$detail;
    }
}

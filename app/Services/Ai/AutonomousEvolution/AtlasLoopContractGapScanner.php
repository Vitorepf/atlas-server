<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Console\Commands\AtlasBrainContractGapsCommand;

/**
 * CONTRACT-GAP scanner (shared organ). Detects an INTERFACE declared in a set of files that has ZERO concrete
 * implementer anywhere in app/ — a contract the architecture DECLARED but never fulfilled (architectural
 * capability debt). Binary, UNAMBIGUOUS signal (a class either `implements X` or it does not — no name-variant
 * fuzziness), so it never fires a false gap; GROUNDED (carries the real interface FQCN + the method signatures
 * the implementation must satisfy). Membership SET, never a computed scalar (anti-Goodhart). Fail-OPEN.
 *
 * Two live consumers: {@see AtlasBrainContractGapsCommand} (Mode-B session surface) and
 * {@see AtlasLoopComprehensionOriginator} (the automated origination prompt, flag-gated). Single source of truth.
 */
final class AtlasLoopContractGapScanner
{
    /**
     * Interfaces among $phpFilePaths with zero app-wide implementer, each with its method obligations.
     *
     * @param  list<string>  $phpFilePaths  absolute paths to scan for interface declarations
     * @return list<array{fqcn:string, file:string, methods:list<string>, implementer_count:int}>
     */
    public function capabilityGaps(array $phpFilePaths, string $repoRoot): array
    {
        $implemented = $this->implementedInterfaceNames($repoRoot);
        $interfaces = [];
        foreach ($phpFilePaths as $path) {
            $src = (string) @file_get_contents($path);
            if (! preg_match('/^interface\s+(\w+)/m', $src, $im)) {
                continue;
            }
            $short = $im[1];
            $ns = preg_match('/^namespace\s+([^;]+);/m', $src, $nm) ? trim($nm[1]) : '';
            $interfaces[] = [
                'fqcn' => $ns !== '' ? $ns.'\\'.$short : $short,
                'file' => ltrim(str_replace($repoRoot, '', $path), '/'),
                'methods' => self::interfaceMethods($src),
                'implementer_count' => isset($implemented[$short]) ? 1 : 0,
            ];
        }

        return self::gapsFrom($interfaces);
    }

    /**
     * Enumerate every .php under the given scope roots (absolute paths). IO; fail-OPEN to [].
     *
     * @param  list<string>  $roots
     * @return list<string>
     */
    public function phpFilesUnder(array $roots, string $repoRoot): array
    {
        $paths = [];
        try {
            foreach ($roots as $root) {
                $base = $repoRoot.'/'.trim((string) $root, '/');
                if (! is_dir($base)) {
                    continue;
                }
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $p) {
                    $p = (string) $p;
                    if (str_ends_with($p, '.php')) {
                        $paths[] = $p;
                    }
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $paths;
    }

    /**
     * Pure: keep only interfaces with zero implementers, sorted by FQCN. The membership signal — never a score.
     *
     * @param  list<array{fqcn:string, file:string, methods:list<string>, implementer_count:int}>  $interfaces
     * @return list<array{fqcn:string, file:string, methods:list<string>, implementer_count:int}>
     */
    public static function gapsFrom(array $interfaces): array
    {
        $gaps = array_values(array_filter($interfaces, static fn (array $i): bool => (int) ($i['implementer_count'] ?? 0) === 0));
        usort($gaps, static fn (array $a, array $b): int => strcmp((string) ($a['fqcn'] ?? ''), (string) ($b['fqcn'] ?? '')));

        return $gaps;
    }

    /**
     * Pure: the public method signatures an implementation must satisfy — the grounded obligation.
     *
     * @return list<string>
     */
    public static function interfaceMethods(string $source): array
    {
        $methods = [];
        if (preg_match_all('/public\s+(?:static\s+)?function\s+(\w+\s*\([^;{]*\)(?:\s*:\s*[^;{\n]+)?)/m', $source, $m) === false) {
            return [];
        }
        foreach ($m[1] as $sig) {
            $methods[] = 'function '.trim((string) preg_replace('/\s+/', ' ', (string) $sig));
        }

        return $methods;
    }

    /**
     * One pass over app/: every interface short-name that appears in a concrete `implements` clause. Fail-OPEN.
     *
     * @return array<string,bool>
     */
    private function implementedInterfaceNames(string $repoRoot): array
    {
        $set = [];
        try {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($repoRoot.'/app', \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $path) {
                $path = (string) $path;
                if (! str_ends_with($path, '.php')) {
                    continue;
                }
                $src = (string) @file_get_contents($path);
                if (preg_match_all('/\bimplements\s+([^{]+?)[\n{]/m', $src, $m) === false) {
                    continue;
                }
                foreach ($m[1] as $clause) {
                    foreach (preg_split('/[\s,]+/', trim((string) $clause)) ?: [] as $name) {
                        $name = ltrim((string) $name, '\\');
                        if ($name === '') {
                            continue;
                        }
                        $parts = explode('\\', $name);
                        $set[(string) end($parts)] = true;
                    }
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $set;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Coherence;

/**
 * FACT-only orphaned-test detector. Scans test files affected by a recent edit set and emits the
 * subset whose target-under-test no longer exists (or whose covered method was removed).
 *
 * Detection rules (in order of priority):
 *   1. explicit @covers TargetFqcn::method — wins
 *   2. explicit @coversDefaultClass TargetFqcn — fallback
 *   3. class-name convention FooTest → Foo (in same namespace minus \Tests prefix)
 *   4. single `use TargetFqcn;` import — fallback when no explicit annotation
 *
 * NEVER deletes, modifies, or grades. Only enumerates {file, target_fqcn, missing_symbol,
 * detection_rule} tuples.
 */
final class AtlasLoopPostEditOrphanedTestDetector
{
    /**
     * @param  list<string>  $testFiles        absolute paths to test files
     * @param  list<string>  $removedSymbols   list of FQCN or "FQCN::method" removed by the edit
     * @return list<array<string,mixed>>
     */
    public function detect(array $testFiles, array $removedSymbols = []): array
    {
        $removedSet = array_flip($removedSymbols);
        $findings = [];

        foreach ($testFiles as $file) {
            if (! is_file($file)) {
                continue;
            }
            $contents = (string) file_get_contents($file);
            [$rule, $targetFqcn, $coveredMethod] = $this->resolveTarget($file, $contents);
            if ($targetFqcn === '') {
                continue;
            }

            $missing = '';
            if (isset($removedSet[$targetFqcn])) {
                $missing = $targetFqcn;
            } elseif ($coveredMethod !== '' && isset($removedSet[$targetFqcn.'::'.$coveredMethod])) {
                $missing = $targetFqcn.'::'.$coveredMethod;
            } elseif (! class_exists($targetFqcn) && ! interface_exists($targetFqcn) && ! trait_exists($targetFqcn) && ! enum_exists($targetFqcn)) {
                $missing = $targetFqcn;
            } elseif ($coveredMethod !== '' && ! method_exists($targetFqcn, $coveredMethod)) {
                $missing = $targetFqcn.'::'.$coveredMethod;
            }

            if ($missing !== '') {
                $findings[] = [
                    'file' => $this->relative($file),
                    'target_fqcn' => $targetFqcn,
                    'missing_symbol' => $missing,
                    'detection_rule' => $rule,
                ];
            }
        }

        usort($findings, static fn (array $a, array $b): int => strcmp(
            $a['file'].':'.$a['missing_symbol'],
            $b['file'].':'.$b['missing_symbol'],
        ));

        return $findings;
    }

    /**
     * @return array{0:string, 1:string, 2:string}  [rule, target_fqcn, covered_method]
     */
    private function resolveTarget(string $file, string $contents): array
    {
        // Rule 1: @covers
        if (preg_match('/@covers\s+\\\\?([A-Za-z0-9_\\\\]+)(?:::([A-Za-z_][A-Za-z0-9_]*))?/u', $contents, $m)) {
            return ['covers_annotation', (string) $m[1], (string) ($m[2] ?? '')];
        }
        // Rule 2: @coversDefaultClass
        if (preg_match('/@coversDefaultClass\s+\\\\?([A-Za-z0-9_\\\\]+)/u', $contents, $m)) {
            return ['covers_default_class', (string) $m[1], ''];
        }
        // Rule 3: class-name convention FooTest → Foo
        if (preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+([A-Za-z0-9_]+)Test\b/m', $contents, $cls)) {
            $sutShort = $cls[1];
            $sutNamespace = $this->namespaceOf($contents);
            if ($sutNamespace !== '') {
                $sutNamespace = preg_replace('/^Tests\\\\(Unit\\\\|Feature\\\\)?/', '', $sutNamespace) ?? $sutNamespace;
            }
            $candidate = ($sutNamespace !== '' ? 'App\\'.$sutNamespace.'\\' : 'App\\').$sutShort;

            return ['class_name_convention', $candidate, ''];
        }
        // Rule 4: single use of a SUT class
        if (preg_match_all('/^\s*use\s+([A-Za-z0-9_\\\\]+);/m', $contents, $uses)) {
            $candidates = array_values(array_filter(
                $uses[1],
                static fn (string $u): bool => ! str_starts_with($u, 'PHPUnit\\') && ! str_starts_with($u, 'Tests\\'),
            ));
            if (count($candidates) === 1) {
                return ['single_use_import', (string) $candidates[0], ''];
            }
        }

        return ['', '', ''];
    }

    private function namespaceOf(string $contents): string
    {
        if (preg_match('/^\s*namespace\s+([A-Za-z0-9_\\\\]+);/m', $contents, $m)) {
            return (string) $m[1];
        }

        return '';
    }

    private function relative(string $file): string
    {
        $base = function_exists('base_path') ? base_path().'/' : '';
        if ($base !== '' && str_starts_with($file, $base)) {
            return substr($file, strlen($base));
        }

        return $file;
    }
}

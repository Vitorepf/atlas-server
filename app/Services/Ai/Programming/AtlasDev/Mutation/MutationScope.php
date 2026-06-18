<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Mutation;

/**
 * The deduplicated scope for a scoped E3 infection run.
 *
 * The scope captures EXACTLY the patch's touched test files plus their
 * covered source files (VAL-E3-001), and NOTHING ELSE:
 *   - {@see $testFiles}:   touched files in the tests/ tree (PHPUnit will be
 *                           scoped to these via --test-framework-options).
 *   - {@see $sourceFiles}: the covered SOURCE files infection will MUTATE,
 *                           scoped via --filter. This is the deduped union of
 *                           touched source files plus the source files
 *                           convention-derived from touched test files
 *                           (e.g. tests/Unit/CalculatorTest.php covers
 *                           app/Calculator.php), so a test file touched
 *                           without its source in the patch still has the
 *                           unit-under-test in the mutation scope
 *                           (VAL-E3-012: multi-file union).
 *
 * Cardinality of (testFiles + sourceFiles) is FAR below the ~3592-file suite:
 * the adapter NEVER runs mutation/coverage across the full tree
 * (VAL-E3-001, mission boundary: infection must be scoped to touched files
 * only — never the full ~3592-file suite).
 *
 * {@see isEmpty()} is the canonical no-op guard (VAL-E3-008): when no test
 * files are touched, the scope is empty and the adapter is a no-op (skipped
 * with reason, never a false fail).
 */
final class MutationScope
{
    /**
     * @param  list<string>  $testFiles  touched PHPUnit test files (tests/...).
     * @param  list<string>  $sourceFiles  covered source files infection will mutate (app/...).
     */
    public function __construct(
        public readonly array $testFiles,
        public readonly array $sourceFiles,
    ) {}

    /**
     * A scope is empty iff it carries no test files. Without test files
     * there is nothing to mutation-test (the patch touched only non-test
     * source, docs, or nothing at all). VAL-E3-008: an empty scope => the
     * adapter is a no-op (skipped with reason, never a false fail).
     *
     * Note: source files alone do not make the scope non-empty. Even if the
     * patch touched source, without a touched test file there is no coverage
     * to mutation-test against (and we do not invent one out of thin air).
     */
    public function isEmpty(): bool
    {
        return $this->testFiles === [];
    }

    /**
     * The deduplicated UNION of pcov.directory candidates (directories that
     * contain the scoped source files). pcov instruments source for coverage
     * only inside these directories; everything outside is not instrumented.
     *
     * pcov.directory is a single-path ini directive (one directory per run),
     * so when source files span multiple directories we widen to the smallest
     * shared ancestor — by construction all app/ source lives under app/, so
     * the union for a typical AtlasDev patch collapses to one directory.
     * The adapter emits one `-d pcov.directory=<dir>` per directory so pcov
     * is scoped to the touched source tree ONLY, never the repo root
     * (VAL-E3-001: scope; VAL-E3-011: pcov driver-backed scoped coverage).
     *
     * @return list<string>
     */
    public function pcovDirectories(): array
    {
        $dirs = [];
        foreach ($this->sourceFiles as $source) {
            $dir = dirname($source);
            if ($dir === '' || $dir === '.') {
                continue;
            }
            $dirs[] = $dir;
        }
        $dirs = array_values(array_unique($dirs));

        // Collapse to the smallest set of directories that still cover every
        // source file: drop any directory that is a parent (prefix) of
        // another in the set, so e.g. app/Services/Foo and app/Services both
        // present collapse to the narrower app/Services/Foo when only that
        // subtree is touched.
        return self::collapseToNarrowest($dirs);
    }

    /**
     * @param  list<string>  $dirs
     * @return list<string>
     */
    private static function collapseToNarrowest(array $dirs): array
    {
        $result = [];
        foreach ($dirs as $candidate) {
            $covered = false;
            foreach ($dirs as $other) {
                if ($candidate === $other) {
                    continue;
                }
                // If $other is a narrower sub-path of $candidate, keep $other
                // and drop $candidate.
                if (str_starts_with($other.'/', $candidate.'/')) {
                    $covered = true;
                    break;
                }
            }
            if (! $covered) {
                $result[] = $candidate;
            }
        }

        return array_values(array_unique($result));
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * §5.6 · ORPHAN-WIRING · grind-time acceptance authoring (the keystone bridge).
 *
 * Orphan-wiring (and doc-gap, research-objectives) can't pre-bake the acceptance test — the test that proves
 * "this wiring is real" must be AUTHORED by the grind for the specific orphan. The danger is the writer grading
 * itself (an always-green or trivially-passing test). This producer is the deterministic guard around that:
 * given the grind-authored test, it VERIFIES the test is genuinely EARNED-RED (it actually FAILS on the
 * pre-wiring baseline — reusing the proven {@see AtlasLoopAcceptanceRedProducer::verifyRed}), then FREEZES it
 * into a wired_proof acceptance the frozen judge's Guard 4e certifies. A test that is already GREEN on the
 * baseline proves nothing (the wiring isn't needed) ⇒ null (fail-closed). The model authors the test CONTENT;
 * the earned-RED proof + the Guard-4e neutralization conjunct keep that authored test honest.
 */
final class AtlasLoopWiredAcceptanceProducer
{
    private const MAX_PRODUCTION_CALLER_FILES = 200;

    public function __construct(private readonly ?AtlasLoopAcceptanceRedProducer $redProducer = null) {}

    /**
     * Turn a grind-authored wiring test into a FROZEN, Guard-4e-certifiable acceptance — or null when the test
     * is not earned-RED on the baseline (fail-closed: the writer cannot grade itself with an always-green test).
     *
     * @param  string  $orphanRel  the orphan being wired (Guard 4e's wired_target)
     * @param  string  $testRel  the grind-authored test file (frozen so the grind can't weaken it)
     * @param  string  $testCommand  the runnable command for that test (e.g. `php tests/wiring_test.php`)
     * @param  list<string>  $allowedGlobs  the impl files the wiring may edit/create
     * @param  string  $baselineCwd  the PRE-wiring workspace the earned-RED check runs against
     * @return array{commands:list<string>, allowed_globs:list<string>, frozen_globs:list<string>,
     *               metric_kind:string, wired_proof:true, wired_target:array{orphan_path:string},
     *               earned_red:true}|null
     */
    public function produce(string $orphanRel, string $testRel, string $testCommand, array $allowedGlobs, string $baselineCwd): ?array
    {
        $orphanRel = ltrim($orphanRel, '/');
        $testRel = ltrim($testRel, '/');
        $testCommand = trim($testCommand);
        if ($orphanRel === '' || $testRel === '' || $testCommand === '') {
            return null;
        }

        // EARNED-RED: the authored test must GENUINELY fail on the pre-wiring tree. A test that passes already
        // (always-green / does not exercise the orphan) cannot prove the wiring is needed ⇒ reject.
        $verdict = ($this->redProducer ?? new AtlasLoopAcceptanceRedProducer)->verifyRed($testCommand, $baselineCwd);
        if (($verdict['ran'] ?? false) !== true || ($verdict['red'] ?? false) !== true) {
            return null;
        }

        $productionCaller = $this->productionCaller($orphanRel, $testRel, $baselineCwd);

        return [
            'commands' => [$testCommand],
            'allowed_globs' => array_values(array_filter($allowedGlobs, 'is_string')),
            // FREEZE the authored test (and tests/**) so the grind can never weaken it after the fact.
            'frozen_globs' => array_values(array_unique([$testRel, 'tests/**'])),
            'metric_kind' => 'gate',
            'wired_proof' => true,
            'production_caller' => $productionCaller,
            'wired_target' => ['orphan_path' => $orphanRel],
            'earned_red' => true,
        ];
    }

    public function productionCaller(string $orphanRel, string $testRel, string $repoRoot): bool
    {
        return self::detectProductionCaller($orphanRel, $testRel, $repoRoot);
    }

    private static function detectProductionCaller(string $orphanRel, string $testRel, string $repoRoot): bool
    {
        try {
            $orphanRel = ltrim(trim($orphanRel), '/');
            $testRel = ltrim(trim($testRel), '/');
            $repoRoot = rtrim(trim($repoRoot), '/');
            if ($orphanRel === '' || $testRel === '' || $repoRoot === '') {
                return false;
            }
            if (str_starts_with($testRel, 'tests/')) {
                $basename = pathinfo($orphanRel, PATHINFO_FILENAME);
                if ($basename === '') {
                    return false;
                }

                $scanned = 0;
                foreach (['app', 'src'] as $root) {
                    $dir = $repoRoot.'/'.$root;
                    if (! is_dir($dir)) {
                        continue;
                    }
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
                    );
                    foreach ($iterator as $file) {
                        if (! $file->isFile()) {
                            continue;
                        }
                        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($repoRoot) + 1));
                        if ($relative === $orphanRel) {
                            continue;
                        }
                        $scanned++;
                        if ($scanned > self::MAX_PRODUCTION_CALLER_FILES) {
                            return false;
                        }
                        $contents = @file_get_contents($file->getPathname());
                        if (! is_string($contents)) {
                            return false;
                        }
                        if (str_contains($contents, $basename)) {
                            return true;
                        }
                    }
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }
}

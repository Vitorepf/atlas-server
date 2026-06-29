<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopCoverageDeficitSource;
use FilesystemIterator;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Arms the dormant {@see AtlasLoopCoverageDeficitSource::score()} at the operator surface: scores a source
 * file's coverage deficit by mutation-survival density (how many radius-1 frozen mutants live in it) and
 * whether a sibling characterization test already refutes them.
 *
 * Read-only + deterministic: it reads the file + the frozen kill-vocabulary, resolves sibling-test presence by
 * scanning a tests root, and emits the deficit fact. It never proposes, ranks, writes, or mutates anything.
 */
final class AtlasLoopCoverageDeficitCommand extends Command
{
    protected $signature = 'atlas:loop:coverage-deficit {--file=} {--tests-root=} {--json}';

    protected $description = 'Read-only coverage-deficit score for a source file (mutation-survival density + sibling-test presence).';

    public function handle(): int
    {
        $file = trim((string) $this->option('file'));
        if ($file === '') {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'coverage-deficit requires --file=<path>',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $abs = str_starts_with($file, '/') ? $file : base_path($file);
        $testsRoot = trim((string) $this->option('tests-root'));
        if ($testsRoot === '') {
            $testsRoot = base_path('tests');
        }

        $hasSiblingTest = $this->hasSiblingTest($testsRoot, pathinfo($abs, PATHINFO_FILENAME));
        $score = app(AtlasLoopCoverageDeficitSource::class)->score($abs, $hasSiblingTest);

        $facts = [
            'schema' => 'atlas.loop.coverage_deficit.v1',
            'file' => $file,
            'mutants' => $score['mutants'],
            'has_sibling_test' => $score['has_sibling_test'],
            'deficit' => $score['deficit'],
            'is_high_deficit' => ! $score['has_sibling_test'] && $score['deficit'] >= AtlasLoopCoverageDeficitSource::HIGH_DEFICIT_THRESHOLD,
            'reason' => $score['reason'],
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('file: '.$file);
            $this->line('deficit: '.$facts['deficit'].($facts['is_high_deficit'] ? ' (HIGH)' : ''));
            $this->line($facts['reason']);
        }

        return self::SUCCESS;
    }

    /**
     * Sibling characterization test exists ⇔ a file named `{Basename}Test.php` lives anywhere under the tests
     * root (the same convention the loop's mirror uses). Fail-open false on an unreadable tree.
     */
    private function hasSiblingTest(string $testsRoot, string $base): bool
    {
        if ($base === '' || ! is_dir($testsRoot)) {
            return false;
        }
        $needle = $base.'Test.php';
        try {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testsRoot, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $info) {
                if ($info->isFile() && $info->getFilename() === $needle) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }
}

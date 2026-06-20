<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Verify;

use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopSignalAnalyzer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class AtlasLoopSignalAnalyzerTest extends TestCase
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir().'/atlas-loop-signals-'.bin2hex(random_bytes(4));
        mkdir($this->repo.'/app', 0o755, true);
        mkdir($this->repo.'/docs', 0o755, true);
        $this->writeFixture();
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->repo]))->run();
        parent::tearDown();
    }

    public function test_detects_clone_complexity_coverage_and_doc_signals_as_flags(): void
    {
        $scan = (new AtlasLoopSignalAnalyzer)->scan($this->repo, [
            'code_roots' => ['app'],
            'docs_roots' => ['docs'],
            'max_files' => 20,
            'complexity_threshold' => 4,
        ]);

        $modes = array_column($scan['flags'], 'mode');

        $this->assertContains('code_clone', $modes);
        $this->assertContains('complexity_hotspot', $modes);
        $this->assertContains('coverage_gap', $modes);
        $this->assertContains('doc_drift', $modes);
        $this->assertContains('doc_duplicate', $modes);
        $this->assertGreaterThanOrEqual(5, $scan['summary']['signal_flags']);
        $this->assertSame('flag', $scan['flags'][0]['disposition']);
    }

    public function test_file_complexity_counts_match_expressions_as_decision_points(): void
    {
        $src = <<<'PHP'
        <?php
        final class UsesMatch
        {
            public function classify(string $kind): string
            {
                return match ($kind) {
                    'bug' => 'fix',
                    'feature' => 'build',
                    default => 'review',
                };
            }
        }
        PHP;

        $complexity = (new AtlasLoopSignalAnalyzer)->fileComplexity($src);

        $this->assertSame(4, $complexity['max_per_method']);
        $this->assertSame(4, $complexity['total']);
    }

    private function writeFixture(): void
    {
        $clone = <<<'PHP'
        <?php
        namespace App;

        final class %s
        {
            public function duplicated(int $value): int
            {
                $total = 0;
                foreach ([1, 2, 3] as $item) {
                    $total += $item + $value;
                }
                if ($total > 10) {
                    return $total;
                }

                return $value;
            }
        }
        PHP;
        file_put_contents($this->repo.'/app/CloneA.php', sprintf($clone, 'CloneA'));
        file_put_contents($this->repo.'/app/CloneB.php', sprintf($clone, 'CloneB'));

        file_put_contents($this->repo.'/app/ComplexSubject.php', <<<'PHP'
        <?php
        namespace App;

        final class ComplexSubject
        {
            public function branchy(array $items, bool $strict): int
            {
                $score = 0;
                if ($strict && $items !== []) {
                    foreach ($items as $item) {
                        if ($item > 10) {
                            $score += $item;
                        } elseif ($item < 0) {
                            $score--;
                        }
                    }
                }
                while ($score > 100) {
                    $score--;
                }

                return $score;
            }
        }
        PHP);

        $doc = <<<'MD'
        # Duplicate Signal Fixture

        This document deliberately repeats a long operational paragraph so the analyzer can
        detect duplicated documentation bodies without relying on global repository state.
        The words describe ownership review, evidence review, dispatch review, routing review,
        implementation review, maintenance review, operator review, framework review, backlog
        review, testing review, documentation review, architecture review, context review,
        signal review, and final human review. It references `app/CloneA.php` so a stale doc
        can be compared against a newer code file by modification time.
        MD;
        file_put_contents($this->repo.'/docs/one.md', $doc);
        file_put_contents($this->repo.'/docs/two.md', $doc);

        $old = time() - 172800;
        touch($this->repo.'/docs/one.md', $old);
        touch($this->repo.'/docs/two.md', $old);
        touch($this->repo.'/app/CloneA.php', time());
        clearstatcache();
    }
}

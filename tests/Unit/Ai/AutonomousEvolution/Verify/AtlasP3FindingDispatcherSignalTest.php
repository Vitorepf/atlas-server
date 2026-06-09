<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Verify;

use App\Services\Ai\AutonomousEvolution\Verify\AtlasDeadCodeAnalyzer;
use App\Services\Ai\AutonomousEvolution\Verify\AtlasDocClaimAnalyzer;
use App\Services\Ai\AutonomousEvolution\Verify\AtlasDocStructureAnalyzer;
use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopSignalAnalyzer;
use App\Services\Ai\AutonomousEvolution\Verify\AtlasP3FindingDispatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class AtlasP3FindingDispatcherSignalTest extends TestCase
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir().'/atlas-p3-signals-'.bin2hex(random_bytes(4));
        mkdir($this->repo.'/app', 0o755, true);
        mkdir($this->repo.'/docs', 0o755, true);
        $this->writeFixture();
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->repo]))->run();
        parent::tearDown();
    }

    public function test_dispatcher_merges_signal_modes_into_flag_backlog_only(): void
    {
        $dispatcher = new AtlasP3FindingDispatcher(
            new AtlasDeadCodeAnalyzer,
            new AtlasDocClaimAnalyzer,
            new AtlasDocStructureAnalyzer,
            new AtlasLoopSignalAnalyzer,
        );

        $scan = $dispatcher->scan($this->repo, [
            'code_roots' => ['app'],
            'docs_roots' => ['docs'],
            'max_files' => 20,
            'signal_max_files' => 20,
            'complexity_threshold' => 4,
        ]);

        $flagModes = array_values(array_unique(array_column($scan['flags'], 'mode')));
        $autoModes = array_values(array_unique(array_column($scan['auto_loop'], 'mode')));

        $this->assertContains('code_clone', $flagModes);
        $this->assertContains('complexity_hotspot', $flagModes);
        $this->assertContains('coverage_gap', $flagModes);
        $this->assertContains('doc_drift', $flagModes);
        $this->assertContains('doc_duplicate', $flagModes);
        $this->assertNotContains('code_clone', $autoModes);
        $this->assertNotContains('complexity_hotspot', $autoModes);
        $this->assertNotContains('coverage_gap', $autoModes);
        $this->assertGreaterThanOrEqual(5, $scan['summary']['signal_flags']);
        $this->assertSame(0, $scan['summary']['flag_phantoms']);
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

        This document deliberately repeats a long operational paragraph so the dispatcher can
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

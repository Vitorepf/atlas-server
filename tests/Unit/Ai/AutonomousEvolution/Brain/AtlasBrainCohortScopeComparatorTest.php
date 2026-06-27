<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainCohortScopeComparator;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainDoneSetLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainReflectionStream;
use Tests\TestCase;

/**
 * FROZEN proof of the cohort scope comparator — multi-scope health ranking.
 */
final class AtlasBrainCohortScopeComparatorTest extends TestCase
{
    private string $doneRoot;

    private string $streamPath;

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir().'/atlas-brain-cohort-'.bin2hex(random_bytes(6));
        @mkdir($base, 0o775, true);
        $this->doneRoot = $base.'/done-set';
        @mkdir($this->doneRoot, 0o775, true);
        $this->streamPath = $base.'/reflection.ndjson';
        config()->set('atlas.brain.reflection_enabled', true);
    }

    public function test_top_scope_is_the_one_with_best_health_score(): void
    {
        // Scope A: 3 served + 1 refused ⇒ ratio 75%; no reflections ⇒ starvation 0% ⇒ health 75.
        $aLedger = new AtlasBrainDoneSetLedger('alpha', $this->doneRoot);
        $aLedger->record(['snapshot_id' => 'a1', 'status' => 'served']);
        $aLedger->record(['snapshot_id' => 'a2', 'status' => 'served']);
        $aLedger->record(['snapshot_id' => 'a3', 'status' => 'served']);
        $aLedger->record(['snapshot_id' => 'a4', 'status' => 'refused']);

        // Scope B: 1 served + 3 refused ⇒ ratio 25%; 4 blocked reflections ⇒ starvation 100% ⇒ health -75.
        $bLedger = new AtlasBrainDoneSetLedger('beta', $this->doneRoot);
        $bLedger->record(['snapshot_id' => 'b1', 'status' => 'served']);
        $bLedger->record(['snapshot_id' => 'b2', 'status' => 'refused']);
        $bLedger->record(['snapshot_id' => 'b3', 'status' => 'refused']);
        $bLedger->record(['snapshot_id' => 'b4', 'status' => 'refused']);
        for ($i = 0; $i < 4; $i++) {
            $row = ['schema' => 'x', 'scope' => 'beta', 'cycle_id' => "b{$i}", 'result_kind' => 'blocked', 'reflection' => 'blocked', 'signals' => [], 'recorded_at' => 0];
            file_put_contents($this->streamPath, json_encode($row).PHP_EOL, FILE_APPEND);
        }

        $stream = new AtlasBrainReflectionStream($this->streamPath);
        $report = (new AtlasBrainCohortScopeComparator)->compare(['alpha', 'beta'], $stream, $this->doneRoot);

        self::assertSame('alpha', $report['top']);
        self::assertSame(75, $report['rows'][0]['health']);
        self::assertSame('beta', $report['rows'][1]['scope']);
        self::assertSame(-75, $report['rows'][1]['health']);
    }

    public function test_comparator_organ_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainCohortScopeComparator.php',
            true
        );
        self::assertSame('forbidden', $verdict);
    }
}

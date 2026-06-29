<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the learning-transfer admission orchestrator is live at the operator surface and emits deterministic
 * facts (observe mode, no transfer executed): a thin give-back holds at the gate (below repetition
 * threshold); with thresholds relaxed the same lesson is admitted. A missing --give-back is a usage error.
 */
final class AtlasLoopLearningTransferCommandTest extends TestCase
{
    public function test_requires_give_back(): void
    {
        $exit = Artisan::call('atlas:loop:learning-transfer', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_thin_lesson_holds_at_gate(): void
    {
        $exit = Artisan::call('atlas:loop:learning-transfer', [
            '--give-back' => json_encode(['reason' => 'forbidden_target', 'packet_id' => 'p1']),
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.learning_transfer.admission_orchestrator.v1', $decoded['schema_version']);
        $this->assertSame('observe', $decoded['mode']);
        $this->assertSame('short_circuited_at_gate', $decoded['outcome']);
        $this->assertSame('hold', $decoded['gate_decision']['decision']);
    }

    public function test_relaxed_thresholds_admit_the_lesson(): void
    {
        $exit = Artisan::call('atlas:loop:learning-transfer', [
            '--give-back' => json_encode(['reason' => 'forbidden_target', 'packet_id' => 'p1']),
            '--thresholds' => json_encode(['min_repetitions' => 1, 'min_proven' => 0, 'conflict_tolerance' => 99]),
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('admitted_and_recorded', $decoded['outcome']);
        $this->assertSame('admit', $decoded['gate_decision']['decision']);
        $this->assertNotNull($decoded['plan']);
    }
}

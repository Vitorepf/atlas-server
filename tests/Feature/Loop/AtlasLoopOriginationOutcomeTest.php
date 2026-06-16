<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopOriginationOutcome;
use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\AtlasLoopOriginationOutcomeRecorder;
use App\Services\Ai\AutonomousEvolution\AtlasLoopOriginationProducer;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ACDE O2 — proves the operator accept/reject ledger sharpens the next origination: the back-off rule is
 * conservative (thin shapes never silence), the producer backs off a shape the operator keeps rejecting, and
 * OFF is byte-identical (no consult, no rows).
 */
final class AtlasLoopOriginationOutcomeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_origination_outcomes')) {
            (require base_path('database/migrations/2026_06_16_000300_create_atlas_loop_origination_outcomes_table.php'))->up();
        }
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
                '2026_06_11_000100_add_quality_columns_to_atlas_loop_tables.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
        AtlasLoopOriginationOutcome::query()->delete();
        AtlasLoopProposal::query()->delete();
    }

    public function test_backs_off_is_conservative_and_deterministic(): void
    {
        // thin corpus (under min_samples) NEVER backs off, regardless of rejects.
        $this->assertFalse(AtlasLoopOriginationOutcomeRecorder::backsOff(0, 2, 3, 0.5));
        // enough samples + accept-rate below target => back off.
        $this->assertTrue(AtlasLoopOriginationOutcomeRecorder::backsOff(1, 5, 3, 0.5));
        // enough samples + accept-rate at/above target => keep proposing.
        $this->assertFalse(AtlasLoopOriginationOutcomeRecorder::backsOff(4, 5, 3, 0.5));
    }

    public function test_shape_token_is_directory_and_criteria_sensitive(): void
    {
        $r = new AtlasLoopOriginationOutcomeRecorder;
        $this->assertSame($r->shapeToken('app/Foo/A.php', 2), $r->shapeToken('app/Foo/B.php', 3), 'same dir + same criteria bucket => same shape');
        $this->assertNotSame($r->shapeToken('app/Foo/A.php', 2), $r->shapeToken('app/Bar/A.php', 2), 'different dir => different shape');
        $this->assertNotSame($r->shapeToken('app/Foo/A.php', 2), $r->shapeToken('app/Foo/A.php', 9), 'different criteria bucket => different shape');
    }

    public function test_record_is_a_no_op_when_the_flag_is_off(): void
    {
        config(['atlas.loop.origination_outcome_enabled' => false]);
        (new AtlasLoopOriginationOutcomeRecorder)->record('tok', false, 'p1', 'app/Foo/A.php');
        $this->assertSame(0, AtlasLoopOriginationOutcome::query()->count(), 'OFF => no row => byte-identical');
    }

    public function test_producer_backs_off_a_shape_the_operator_keeps_rejecting(): void
    {
        config(['atlas.loop.origination_producer_enabled' => true]);
        config(['atlas.loop.origination_outcome_enabled' => true]);
        config(['atlas.loop.origination_backoff_min_samples' => 3]);
        config(['atlas.loop.origination_backoff_target_rate' => 0.5]);

        $recorder = new AtlasLoopOriginationOutcomeRecorder;
        $token = $recorder->shapeToken('app/Foo/Target.php', 3);
        // operator rejects this shape 4 times (0/4 accepted => below 0.5, >= 3 samples).
        for ($i = 0; $i < 4; $i++) {
            $recorder->record($token, false, 'p'.$i, 'app/Foo/Target.php');
        }

        // a NEW origination of the SAME shape (same dir + same criteria bucket) is now backed off.
        $id = (new AtlasLoopOriginationProducer)->produce('app/Foo/Another.php', 'add x', ['criteria_count' => 3]);
        $this->assertNull($id, 'the producer backs off a shape the operator keeps rejecting');
        $this->assertSame(0, AtlasLoopProposal::query()->count(), 'no origination row is authored for a rejected shape');

        // a DIFFERENT shape (different criteria bucket) is still authored.
        $other = (new AtlasLoopOriginationProducer)->produce('app/Foo/Another.php', 'add y', ['criteria_count' => 9]);
        $this->assertNotNull($other, 'an un-rejected shape is still proposed');
    }

    public function test_off_outcome_flag_leaves_the_producer_byte_identical(): void
    {
        config(['atlas.loop.origination_producer_enabled' => true]);
        config(['atlas.loop.origination_outcome_enabled' => false]);

        $recorder = new AtlasLoopOriginationOutcomeRecorder;
        $token = $recorder->shapeToken('app/Foo/Target.php', 3);
        // even with a (hypothetical) rejection history, OFF means NO consult — the producer still authors.
        config(['atlas.loop.origination_outcome_enabled' => true]);
        for ($i = 0; $i < 4; $i++) {
            $recorder->record($token, false, 'p'.$i, 'app/Foo/Target.php');
        }
        config(['atlas.loop.origination_outcome_enabled' => false]);

        $id = (new AtlasLoopOriginationProducer)->produce('app/Foo/Another.php', 'add x', ['criteria_count' => 3]);
        $this->assertNotNull($id, 'O2 OFF => the producer ignores the ledger => byte-identical to pre-O2 O1');
    }
}

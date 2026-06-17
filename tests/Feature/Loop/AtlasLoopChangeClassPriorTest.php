<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopClarificationRequest;
use App\Models\AtlasLoopDecompositionOutcome;
use App\Services\Ai\AutonomousEvolution\AtlasLoopObraExecutionAdapter;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopChangeClassPriorService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ACDE DC4 — the change-class (objective_kind family) landing prior. Reads the EXISTING decomposition-
 * outcomes ledger (no new table), buckets by the canonical normalized family (the class-string semantic
 * fix), and abstains-and-asks at planning time on a proven-hopeless class. Default OFF / thin corpus =>
 * byte-identical (the planner reaches its usual spec_not_ready fallback, never the DC4 abstention).
 */
final class AtlasLoopChangeClassPriorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_decomposition_outcomes')) {
            (require base_path('database/migrations/2026_06_16_000100_create_atlas_loop_decomposition_outcomes_table.php'))->up();
        }
        if (! Schema::hasTable('atlas_loop_clarification_requests')) {
            (require base_path('database/migrations/2026_06_17_000200_create_atlas_loop_clarification_requests_table.php'))->up();
        }
    }

    // --- the class-string semantic fix + pure aggregate ----------------------------------------

    public function test_change_class_normalizes_to_the_canonical_family_token(): void
    {
        $svc = new AtlasLoopChangeClassPriorService;

        $this->assertSame('refactor', $svc->changeClass('refactor_extract_method'));
        $this->assertSame('refactor', $svc->changeClass('Refactor_Extract_Class'), 'case/variant fold onto one family');
        $this->assertSame('feature', $svc->changeClass('feature_sequenced'));
        $this->assertSame('unknown', $svc->changeClass(''), 'empty => unknown bucket');
        $this->assertSame('unknown', $svc->changeClass('   '));
    }

    public function test_aggregate_buckets_by_family_and_flags_hopeless_only_with_enough_samples(): void
    {
        $svc = new AtlasLoopChangeClassPriorService;
        $rows = [];
        // 'refactor' family: 10 samples, ALL thrashed (certified=false) => hopeless.
        for ($i = 0; $i < 10; $i++) {
            $rows[] = ['objective_kind' => $i % 2 ? 'refactor_extract_method' : 'refactor_extract_class', 'certified' => false];
        }
        // 'feature' family: only 3 samples (thin) => never hopeless even though all failed.
        for ($i = 0; $i < 3; $i++) {
            $rows[] = ['objective_kind' => 'feature_sequenced', 'certified' => false];
        }
        // 'characterization' family: 10 samples all certified => healthy.
        for ($i = 0; $i < 10; $i++) {
            $rows[] = ['objective_kind' => 'characterization_test', 'certified' => true];
        }

        $out = $svc->aggregate($rows, 8, 0.15);

        $this->assertSame(10, $out['refactor']['samples'], 'both refactor variants accumulate into one family bucket');
        $this->assertTrue($out['refactor']['hopeless']);
        $this->assertFalse($out['feature']['hopeless'], 'a thin cell is never hopeless');
        $this->assertFalse($out['feature']['enough_samples']);
        $this->assertFalse($out['characterization']['hopeless'], 'a healthy class is not hopeless');
        $this->assertSame(1.0, $out['characterization']['landing_rate']);
    }

    public function test_prior_for_reads_the_decomposition_ledger(): void
    {
        $this->seedOutcomes('feature_sequenced', 10, certified: false);

        $prior = (new AtlasLoopChangeClassPriorService)->priorFor('feature_anything');
        $this->assertSame('feature', $prior['change_class']);
        $this->assertSame(10, $prior['samples']);
        $this->assertTrue($prior['hopeless']);

        // A class with no rows yields the empty (never-hopeless) prior — fail-open on no evidence.
        $empty = (new AtlasLoopChangeClassPriorService)->priorFor('migration_only');
        $this->assertFalse($empty['hopeless']);
        $this->assertSame(0, $empty['samples']);
    }

    // --- the adapter wiring (OFF byte-identical / ON abstains) ----------------------------------

    public function test_off_does_not_abstain_on_a_hopeless_class_byte_identical(): void
    {
        config([
            'atlas.loop.planning_enabled' => true,
            'atlas.loop.change_class_prior_enabled' => false, // DC4 OFF
            'atlas.loop.clarification_queue_enabled' => true, // observe the abstention reason via the queue
        ]);
        $this->seedOutcomes('feature_sequenced', 10, certified: false); // a hopeless 'feature' class

        $out = $this->adapter()->callMaybePlan($this->payload(), ['app/A.php', 'app/B.php']);

        $this->assertNull($out, 'a never-ready spec still falls back to buildPlan');
        $this->assertSame(0, $this->clarificationsWithReason('change_class_historically_thrashes'),
            'DC4 OFF => the hopeless class must NOT abstain (byte-identical)');
        $this->assertSame(1, $this->clarificationsWithReason('spec_not_ready'),
            'the path proceeded past DC4 to the usual spec_not_ready fallback');
    }

    public function test_armed_abstains_and_asks_on_a_hopeless_class(): void
    {
        config([
            'atlas.loop.planning_enabled' => true,
            'atlas.loop.change_class_prior_enabled' => true, // DC4 ON
            'atlas.loop.clarification_queue_enabled' => true,
        ]);
        $this->seedOutcomes('feature_sequenced', 10, certified: false);

        $out = $this->adapter()->callMaybePlan($this->payload(), ['app/A.php', 'app/B.php']);

        $this->assertNull($out, 'a hopeless change class abstains => buildPlan fallback');
        $this->assertSame(1, $this->clarificationsWithReason('change_class_historically_thrashes'),
            'DC4 abstains-and-asks (composes with the U5 queue)');
        $this->assertSame(0, $this->clarificationsWithReason('spec_not_ready'),
            'DC4 returns BEFORE the spec compiler, so spec_not_ready never fires');
    }

    // --- DC6: thin-prior max-uncertainty abstain-and-ask ---------------------------------------

    public function test_dc6_thin_prior_max_uncertainty_only_fires_in_the_danger_band(): void
    {
        $svc = new AtlasLoopChangeClassPriorService;

        // thin (3<8) AND below floor (0 < 0.15) => the DC6 danger band.
        $this->assertTrue($svc->thinPriorMaxUncertainty(
            ['samples' => 3, 'enough_samples' => false, 'hopeless' => false, 'landing_rate' => 0.0, 'floor_rate' => 0.15]
        ));
        // zero evidence => never fires (would stall every fresh class).
        $this->assertFalse($svc->thinPriorMaxUncertainty(
            ['samples' => 0, 'enough_samples' => false, 'hopeless' => false, 'landing_rate' => 0.0, 'floor_rate' => 0.15]
        ));
        // thin BUT above floor (early good lean) => not the danger band.
        $this->assertFalse($svc->thinPriorMaxUncertainty(
            ['samples' => 3, 'enough_samples' => false, 'hopeless' => false, 'landing_rate' => 0.66, 'floor_rate' => 0.15]
        ));
        // enough samples => DC4 owns it, not DC6.
        $this->assertFalse($svc->thinPriorMaxUncertainty(
            ['samples' => 10, 'enough_samples' => true, 'hopeless' => true, 'landing_rate' => 0.0, 'floor_rate' => 0.15]
        ));
    }

    public function test_dc6_armed_abstains_and_asks_on_a_thin_negative_class(): void
    {
        config([
            'atlas.loop.planning_enabled' => true,
            'atlas.loop.change_class_prior_enabled' => false,       // DC4 hopeless gate OFF
            'atlas.loop.change_class_thin_prior_ask_enabled' => true, // DC6 ON (independent)
            'atlas.loop.clarification_queue_enabled' => true,
        ]);
        // 3 failures => samples=3 (< min 8), landing_rate 0 < floor => DC6 danger band (NOT yet hopeless).
        $this->seedOutcomes('feature_sequenced', 3, certified: false);

        $out = $this->adapter()->callMaybePlan($this->payload(), ['app/A.php', 'app/B.php']);

        $this->assertNull($out, 'a thin-negative class asks before burning more budget');
        $this->assertSame(1, $this->clarificationsWithReason('change_class_thin_prior_ask'), 'DC6 abstains-and-asks');
        $this->assertSame(0, $this->clarificationsWithReason('change_class_historically_thrashes'), 'not yet hopeless => not the DC4 reason');
        $this->assertSame(0, $this->clarificationsWithReason('spec_not_ready'), 'DC6 returns before the spec compiler');
    }

    public function test_dc6_off_is_byte_identical_on_a_thin_negative_class(): void
    {
        config([
            'atlas.loop.planning_enabled' => true,
            'atlas.loop.change_class_prior_enabled' => false,
            'atlas.loop.change_class_thin_prior_ask_enabled' => false, // DC6 OFF
            'atlas.loop.clarification_queue_enabled' => true,
        ]);
        $this->seedOutcomes('feature_sequenced', 3, certified: false);

        $out = $this->adapter()->callMaybePlan($this->payload(), ['app/A.php', 'app/B.php']);

        $this->assertNull($out);
        $this->assertSame(0, $this->clarificationsWithReason('change_class_thin_prior_ask'), 'OFF => no DC6 abstention');
        $this->assertSame(1, $this->clarificationsWithReason('spec_not_ready'), 'OFF => the usual spec_not_ready fallback (byte-identical)');
    }

    // --- helpers --------------------------------------------------------------------------------

    private function payload(): array
    {
        return ['objective' => 'sequence the new export in app/A.php', 'objective_kind' => 'feature_sequenced'];
    }

    private function seedOutcomes(string $objectiveKind, int $n, bool $certified): void
    {
        for ($i = 0; $i < $n; $i++) {
            AtlasLoopDecompositionOutcome::create([
                'fingerprint_hash' => substr(hash('sha256', $objectiveKind.$i), 0, 32),
                'objective_kind' => $objectiveKind,
                'node_count' => 2,
                'certified' => $certified,
                'thrashed' => ! $certified,
                'terminal_reason' => $certified ? 'certified' : 'thrashed',
                'rounds' => 1,
            ]);
        }
    }

    private function clarificationsWithReason(string $reason): int
    {
        return AtlasLoopClarificationRequest::query()->where('reason', $reason)->count();
    }

    /** An adapter double exposing maybePlan with a never-ready spec seam (no provider spend). */
    private function adapter(): object
    {
        return new class extends AtlasLoopObraExecutionAdapter
        {
            public function callMaybePlan(array $payload, array $allowed): ?array
            {
                return $this->maybePlan($payload, $allowed);
            }

            protected function generateSpecViaProvider(string $goal, array $priorGaps, array $payload, array $allowed): array
            {
                return ['summary' => '', 'acceptance_criteria' => []]; // refuses-with-gaps => spec_not_ready
            }
        };
    }
}

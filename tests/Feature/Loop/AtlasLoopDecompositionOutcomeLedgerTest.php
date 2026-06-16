<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopDecompositionOutcome;
use App\Services\Ai\AutonomousEvolution\AtlasLoopDecompositionOutcomeRecorder;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopDecompositionShapeFingerprinter;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopPlanReadinessGate;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ACDE Leap 5 — the durable decomposition outcome corpus + the readiness gate's shape-prior advisory band.
 *
 *  - The recorder appends exactly one row per executed obra, mirroring the terminal envelope booleans.
 *  - The readiness gate appends 'shape_historically_thrashes' (=> REPLAN) when the corpus holds >= minSamples
 *    for the plan's structural shape with a below-target certified-rate; flag OFF / thin corpus => byte-identical.
 *  - Every signal is a machine-resolved terminal outcome — never model self-report.
 */
final class AtlasLoopDecompositionOutcomeLedgerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The full migration set is Postgres-only (raw CREATE EXTENSION …); each test boots a fresh empty
        // sqlite :memory: DB, so create ONLY this leap's table from its own migration (idempotent guard).
        if (! Schema::hasTable('atlas_loop_decomposition_outcomes')) {
            (require base_path('database/migrations/2026_06_16_000100_create_atlas_loop_decomposition_outcomes_table.php'))->up();
        }
    }

    /** A structurally-valid, fully-specified, pre-verified node (so the only possible gap is the shape-prior). */
    private function goodNode(string $id, string $file, array $deps = []): array
    {
        return [
            'id' => $id,
            'seq' => $deps === [] ? 0 : 1,
            'request' => 'Reduce the worst-method cyclomatic complexity of '.$file.' preserving behaviour.',
            'target_area' => $file,
            'depends_on' => $deps,
            'complexity_proof' => true,
        ];
    }

    private function readyPlan(): array
    {
        return ['plan_id' => 'obra', 'nodes' => [
            $this->goodNode('create', 'app/Support/Helper.php'),
            $this->goodNode('redirect', 'app/Services/Hub.php', ['create']),
        ]];
    }

    private function seedCorpus(string $hash, int $certified, int $total): void
    {
        for ($i = 0; $i < $total; $i++) {
            $isCert = $i < $certified;
            AtlasLoopDecompositionOutcome::create([
                'fingerprint_hash' => $hash,
                'objective_kind' => 'refactor_extract_class',
                'node_count' => 2,
                'certified' => $isCert,
                'thrashed' => ! $isCert,
                'terminal_reason' => $isCert ? 'certified' : 'aggregate_complexity_not_reduced:not_reduced',
                'rounds' => 2,
            ]);
        }
    }

    public function test_recorder_writes_one_row_per_terminal_mirroring_the_envelope_booleans(): void
    {
        config()->set('atlas.loop.decomposition_corpus_enabled', true);
        $recorder = new AtlasLoopDecompositionOutcomeRecorder;
        $plan = $this->readyPlan();

        $recorder->record($plan, 'refactor_extract_class', false, 'obra_not_certified:partial', 2);
        $recorder->record($plan, 'refactor_extract_class', true, 'certified', 1);

        $this->assertSame(2, AtlasLoopDecompositionOutcome::count(), 'exactly one row per record() call');
        $hash = (new AtlasLoopDecompositionShapeFingerprinter)->fingerprint($plan)['hash'];
        $this->assertSame(['certified' => 1, 'total' => 2], $recorder->history($hash));
        $this->assertSame(1, AtlasLoopDecompositionOutcome::where('thrashed', true)->where('certified', false)->count());
        $this->assertSame(1, AtlasLoopDecompositionOutcome::where('certified', true)->where('thrashed', false)->count());
    }

    public function test_recorder_is_a_no_op_when_the_corpus_flag_is_off(): void
    {
        config()->set('atlas.loop.decomposition_corpus_enabled', false);
        (new AtlasLoopDecompositionOutcomeRecorder)->record($this->readyPlan(), 'refactor_extract_class', false, 'x', 1);

        $this->assertSame(0, AtlasLoopDecompositionOutcome::count(), 'flag OFF => no rows written (byte-identical)');
    }

    public function test_gate_appends_shape_thrashes_when_the_corpus_is_below_target(): void
    {
        config()->set('atlas.loop.decomposition_corpus_enabled', true);
        config()->set('atlas.loop.shape_prior_gate_enabled', true);
        config()->set('atlas.loop.shape_prior_min_samples', 8);
        config()->set('atlas.loop.shape_prior_target_rate', 0.5);

        $plan = $this->readyPlan();
        $hash = (new AtlasLoopDecompositionShapeFingerprinter)->fingerprint($plan)['hash'];
        $this->seedCorpus($hash, certified: 1, total: 12); // 1/12 certified — empirically thrashes

        $r = (new AtlasLoopPlanReadinessGate)->assess($plan, ['app/Support/Helper.php', 'app/Services/Hub.php']);

        $this->assertFalse($r['ready'], 'a historically-thrashing shape must REPLAN');
        $this->assertTrue($r['structural_valid'], 'the plan is structurally sound — only the shape-prior refuses it');
        $this->assertSame(AtlasLoopPlanReadinessGate::REPLAN, $r['decision']);
        $this->assertNotEmpty(array_values(array_filter(
            $r['gaps'],
            static fn (string $g): bool => str_starts_with($g, 'shape_historically_thrashes'),
        )));
    }

    public function test_gate_is_byte_identical_when_the_flag_is_off(): void
    {
        config()->set('atlas.loop.decomposition_corpus_enabled', true);
        config()->set('atlas.loop.shape_prior_gate_enabled', false); // gate OFF — the corpus is never consulted

        $plan = $this->readyPlan();
        $hash = (new AtlasLoopDecompositionShapeFingerprinter)->fingerprint($plan)['hash'];
        $this->seedCorpus($hash, certified: 0, total: 20); // a corpus that WOULD refuse — but the flag is off

        $r = (new AtlasLoopPlanReadinessGate)->assess($plan, ['app/Support/Helper.php', 'app/Services/Hub.php']);

        $this->assertTrue($r['ready'], 'flag OFF => structural-only gate admits the plan exactly as today');
        $this->assertSame([], array_values(array_filter(
            $r['gaps'],
            static fn (string $g): bool => str_starts_with($g, 'shape_historically_thrashes'),
        )));
    }

    public function test_gate_degrades_to_byte_identical_when_the_corpus_is_thin(): void
    {
        config()->set('atlas.loop.decomposition_corpus_enabled', true);
        config()->set('atlas.loop.shape_prior_gate_enabled', true);
        config()->set('atlas.loop.shape_prior_min_samples', 8);

        $plan = $this->readyPlan();
        $hash = (new AtlasLoopDecompositionShapeFingerprinter)->fingerprint($plan)['hash'];
        $this->seedCorpus($hash, certified: 0, total: 3); // below minSamples => UNKNOWN => contributes nothing

        $r = (new AtlasLoopPlanReadinessGate)->assess($plan, ['app/Support/Helper.php', 'app/Services/Hub.php']);

        $this->assertTrue($r['ready'], 'a thin corpus (n<minSamples) never blocks a novel shape');
        $this->assertSame([], array_values(array_filter(
            $r['gaps'],
            static fn (string $g): bool => str_starts_with($g, 'shape_historically_thrashes'),
        )));
    }
}

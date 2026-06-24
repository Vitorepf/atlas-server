<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Metrics;

use App\Services\Ai\AutonomousEvolution\Metrics\AtlasLoopE2ECycleLiveness;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Proves the HONEST E2E cycle-liveness metric: an INTEGER count 0..5 of canonical phases that emitted REAL
 * evidence in a HARD 7-day window, with first_dead_phase walking F1..F5 in fixed order. Each test inserts /
 * omits evidence directly in the real tables (no class mocks) + a controlled projection-outcomes tmp dir.
 *
 * Uses the loop's focused-migration setUp (no RefreshDatabase: the full suite has a Postgres-only extension).
 * DB is sqlite :memory:, so the per-test row cleanup is on an ephemeral, isolated database.
 */
final class AtlasLoopE2ECycleLivenessTest extends TestCase
{
    private string $dir;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('atlas_loop_proposals')) {
            (require base_path('database/migrations/2026_06_02_000100_create_atlas_loop_runtime_tables.php'))->up();
        }
        foreach ([
            '2026_06_16_000100_create_atlas_loop_decomposition_outcomes_table.php',
            '2026_06_16_000200_create_atlas_loop_delivery_contracts_table.php',
            '2026_06_16_000300_create_atlas_loop_origination_outcomes_table.php',
        ] as $file) {
            (require base_path('database/migrations/'.$file))->up(); // each guards Schema::hasTable internally
        }

        // Deterministic: clear the global outcome ledgers so a prior test in the same process can't pollute.
        DB::table('atlas_loop_origination_outcomes')->delete();
        DB::table('atlas_loop_decomposition_outcomes')->delete();
        DB::table('atlas_loop_delivery_contracts')->delete();
        DB::table('atlas_loop_proposals')->delete();

        $this->dir = sys_get_temp_dir().'/atlas-e2e-liveness-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o755, true);
        $this->now = new DateTimeImmutable('2026-06-24 12:00:00');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function metric(): AtlasLoopE2ECycleLiveness
    {
        return new AtlasLoopE2ECycleLiveness($this->dir);
    }

    /** Make F1 alive: a projection-outcome file modified within the window. */
    private function comprehendFileInWindow(): void
    {
        $f = $this->dir.'/projection-'.bin2hex(random_bytes(3)).'.json';
        file_put_contents($f, '{"ok":true}');
        touch($f, $this->now->getTimestamp());
    }

    private function inWindow(): string
    {
        return $this->now->format('Y-m-d H:i:s');
    }

    private function insertOrigination(string $createdAt): void
    {
        DB::table('atlas_loop_origination_outcomes')->insert([
            'id' => Str::uuid()->toString(),
            'shape_token' => 'shape-'.bin2hex(random_bytes(3)),
            'accepted' => true,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function insertDecomposition(string $createdAt): void
    {
        DB::table('atlas_loop_decomposition_outcomes')->insert([
            'id' => Str::uuid()->toString(),
            'fingerprint_hash' => bin2hex(random_bytes(8)),
            'node_count' => 3,
            'certified' => true,
            'thrashed' => false,
            'rounds' => 1,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function insertDeliveryContract(string $createdAt): void
    {
        DB::table('atlas_loop_delivery_contracts')->insert([
            'id' => Str::uuid()->toString(),
            'candidate_hash' => 'cand-'.bin2hex(random_bytes(6)),
            'target_path' => 'app/Some/Target.php',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function insertCertifiedProposal(string $updatedAt): void
    {
        DB::table('atlas_loop_proposals')->insert([
            'id' => Str::uuid()->toString(),
            'campaign_id' => Str::uuid()->toString(),
            'status' => 'certified_for_review', // the real production-forced certified status
            'objective' => 'fix the thing',
            'diff_text' => "--- a\n+++ b\n",
            'proposal_hash' => bin2hex(random_bytes(8)),
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);
    }

    // (a) no evidence at all → score 0, first_dead='comprehend'
    public function test_no_evidence_scores_zero_and_first_dead_is_comprehend(): void
    {
        $out = $this->metric()->measure('camp-1', $this->now);

        $this->assertSame(0, $out['score']);
        $this->assertSame('comprehend', $out['first_dead_phase']);
        $this->assertSame(
            ['comprehend' => false, 'originate' => false, 'decompose' => false, 'delivery_contract' => false, 'proposal_certified' => false],
            $out['phases'],
        );
        $this->assertSame('atlas.loop.e2e_cycle_liveness.v1', $out['schema']);
        $this->assertSame(7, $out['window_days']);
    }

    // (b) only an origination_outcome → score 1, first_dead still 'comprehend' (order runs F1..F5)
    public function test_only_origination_scores_one_and_first_dead_is_comprehend_not_decompose(): void
    {
        $this->insertOrigination($this->inWindow());

        $out = $this->metric()->measure('camp-1', $this->now);

        $this->assertSame(1, $out['score']);
        $this->assertTrue($out['phases']['originate']);
        $this->assertSame('comprehend', $out['first_dead_phase'], 'first_dead is the FIRST false in F1..F5 order, not the next-after-the-live-one');
    }

    // (c) F1..F4 green but no certified proposal → score 4, first_dead='proposal_certified'
    public function test_f1_to_f4_green_but_no_certified_proposal_scores_four(): void
    {
        $this->comprehendFileInWindow();
        $this->insertOrigination($this->inWindow());
        $this->insertDecomposition($this->inWindow());
        $this->insertDeliveryContract($this->inWindow());
        // intentionally NO certified proposal

        $out = $this->metric()->measure('camp-1', $this->now);

        $this->assertSame(4, $out['score']);
        $this->assertFalse($out['phases']['proposal_certified']);
        $this->assertSame('proposal_certified', $out['first_dead_phase']);
    }

    // (d) all five phases green within the window → score 5, first_dead=null
    public function test_all_phases_green_scores_five_and_first_dead_is_null(): void
    {
        $this->comprehendFileInWindow();
        $this->insertOrigination($this->inWindow());
        $this->insertDecomposition($this->inWindow());
        $this->insertDeliveryContract($this->inWindow());
        $this->insertCertifiedProposal($this->inWindow());

        $out = $this->metric()->measure('camp-1', $this->now);

        $this->assertSame(5, $out['score']);
        $this->assertNull($out['first_dead_phase']);
        $this->assertSame(
            ['comprehend' => true, 'originate' => true, 'decompose' => true, 'delivery_contract' => true, 'proposal_certified' => true],
            $out['phases'],
        );
    }

    // (e) a delivery_contract 30 days old does NOT count — the 7d window is HARD
    public function test_out_of_window_delivery_contract_does_not_count(): void
    {
        $stale = $this->now->modify('-30 days')->format('Y-m-d H:i:s');
        $this->insertDeliveryContract($stale);

        $out = $this->metric()->measure('camp-1', $this->now);

        $this->assertFalse($out['phases']['delivery_contract'], 'a record 30d old is outside the hard 7d window');
        $this->assertSame(0, $out['score']);
        $this->assertSame('comprehend', $out['first_dead_phase']);
    }
}

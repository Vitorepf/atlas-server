<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProposalPromotionGate;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ACDE #8 — merge-boundary CONTRACT-SWAP guard. The auto-merger's reprove must assert the persisted
 * acceptance contract still hashes to the FROZEN fingerprint the judge stamped on the proposal at grind
 * time (single-source AtlasEvolutionFrozenJudge::acceptanceHash). A swap between cert and merge is then
 * caught fail-closed. Flag-gated default-OFF => byte-identical until armed.
 *
 * FLOOR: the guard only ever BLOCKS (returns ok=false) — it can never admit an unproven proposal.
 */
final class AtlasLoopReproveHashAssertTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // reproveSnippet() looks up the task row, so the downstream (non-mismatch) paths need the table.
        if (! Schema::hasTable('atlas_loop_tasks')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
    }

    /** @return array<string,mixed> */
    private function contract(): array
    {
        return ['commands' => ['php tests/atlas_generated_0.php'], 'allowed_globs' => ['src/**'], 'frozen_globs' => ['tests/**'], 'metric_kind' => 'gate'];
    }

    private function proposal(string $storedHash): AtlasLoopProposal
    {
        return new AtlasLoopProposal([
            'target_path' => 'src/Foo.php',
            'diff_text' => '',
            'proposal_hash' => 'p1',
            'quality' => ['_acceptance_contract' => $this->contract()],
            'acceptance_hash' => $storedHash,
        ]);
    }

    public function test_acceptance_hash_is_deterministic_and_discriminates(): void
    {
        $a = $this->contract();
        $this->assertSame(AtlasEvolutionFrozenJudge::acceptanceHash($a), AtlasEvolutionFrozenJudge::acceptanceHash($a), 'deterministic single source');
        $b = $a;
        $b['commands'] = ['php tests/other.php'];
        $this->assertNotSame(AtlasEvolutionFrozenJudge::acceptanceHash($a), AtlasEvolutionFrozenJudge::acceptanceHash($b), 'a swapped command changes the fingerprint');
    }

    public function test_armed_blocks_a_swapped_contract(): void
    {
        config()->set('atlas.loop.reprove_hash_assert_enabled', true);
        $gate = app(AtlasLoopProposalPromotionGate::class);
        // stored grind-time hash deliberately does NOT match the persisted contract => swap.
        $out = $gate->reprove($this->proposal('deadbeefdeadbeefdeadbeef'), sys_get_temp_dir());
        $this->assertFalse($out['ok']);
        $this->assertSame('acceptance_hash_mismatch', $out['reason'], 'a swapped contract is fail-closed when armed');
    }

    public function test_armed_passes_the_guard_when_the_hash_matches(): void
    {
        config()->set('atlas.loop.reprove_hash_assert_enabled', true);
        $gate = app(AtlasLoopProposalPromotionGate::class);
        $matching = AtlasEvolutionFrozenJudge::acceptanceHash($this->contract());
        $out = $gate->reprove($this->proposal($matching), sys_get_temp_dir());
        // a legitimate, hash-matching contract passes the guard and proceeds to the real reproof (which
        // fails downstream for OTHER reasons here) — the guard must NOT have blocked it.
        $this->assertNotSame('acceptance_hash_mismatch', $out['reason'], 'a matching hash is never falsely blocked');
    }

    public function test_off_is_byte_identical_guard_skipped(): void
    {
        config()->set('atlas.loop.reprove_hash_assert_enabled', false);
        $gate = app(AtlasLoopProposalPromotionGate::class);
        // even with a mismatched stored hash, OFF must never surface the mismatch reason (guard skipped).
        $out = $gate->reprove($this->proposal('deadbeefdeadbeefdeadbeef'), sys_get_temp_dir());
        $this->assertNotSame('acceptance_hash_mismatch', $out['reason'], 'OFF => the guard is inert (byte-identical)');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AtlasAobgBlackboardService;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * AOBG N2.F4 — the BLACKBOARD: multiple engines coordinate THROUGH the brain.
 *
 * Locks the coordination contract over a sqlite, COST-FREE fixture (no provider call
 * anywhere — pure local DB). The table is built from the real migration (plain portable
 * SQL, sqlite-safe) so the test exercises the SAME schema dev runs on pgsql.
 *
 * The non-negotiable assertions:
 *  - claim is idempotent (re-claim collapses onto one active row + refreshes TTL);
 *  - a SECOND engine claiming the same target CONFLICTS (does not steal, does not throw);
 *  - release frees the claim so another engine can then take it;
 *  - stale claims expire by TTL on read (a crashed engine never holds forever);
 *  - active() + conflictsFor() are workspace-SCOPED (never mix another project);
 *  - everything is provider-safe (labels/ids/timestamps only) + fail-open.
 */
final class AtlasAobgBlackboardServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createBlackboardTable();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_aobg_blackboard');
        parent::tearDown();
    }

    public function test_claim_creates_an_active_claim_and_is_provider_safe(): void
    {
        $result = $this->service()->claim('claude_code', 'file', 'app/Services/Foo.php');

        $this->assertSame(AtlasAobgBlackboardService::SCHEMA, $result['schema']);
        $this->assertTrue($result['ok']);
        $this->assertSame(AtlasAobgBlackboardService::STATUS_ACTIVE, $result['status']);
        $this->assertTrue($result['provider_bound']);
        $this->assertSame('claude_code', $result['claim']['engine']);
        $this->assertSame('app/Services/Foo.php', $result['claim']['target']);
        $this->assertNull($result['conflict']);
        // Provider-safe: only labels/ids/timestamps ride out — no content key anywhere.
        $this->assertSame(
            ['id', 'engine', 'kind', 'target', 'workspace_id', 'status', 'claimed_at', 'expires_at', 'ttl_seconds', 'meta'],
            array_keys($result['claim']),
        );
    }

    public function test_claim_is_idempotent_for_the_same_engine_and_target(): void
    {
        $first = $this->service()->claim('claude_code', 'file', 'app/Services/Foo.php');
        $second = $this->service()->claim('claude_code', 'file', 'app/Services/Foo.php');

        $this->assertTrue($first['ok']);
        $this->assertTrue($second['ok']);
        // Same deterministic id → one row, not two.
        $this->assertSame($first['claim']['id'], $second['claim']['id']);
        $this->assertSame(1, DB::table('atlas_aobg_blackboard')->count());
    }

    public function test_a_second_engine_claim_on_the_same_target_conflicts(): void
    {
        $this->service()->claim('claude_code', 'file', 'app/Services/Foo.php');
        $conflict = $this->service()->claim('codex', 'file', 'app/Services/Foo.php');

        $this->assertFalse($conflict['ok']);
        $this->assertSame('conflict', $conflict['status']);
        $this->assertNull($conflict['claim']);
        $this->assertNotNull($conflict['conflict']);
        $this->assertSame('claude_code', $conflict['conflict']['engine']);
        // The conflicting claim is NOT stolen — claude_code still holds it (no codex row).
        $this->assertSame(1, DB::table('atlas_aobg_blackboard')->where('status', 'active')->count());
        $this->assertSame(0, DB::table('atlas_aobg_blackboard')->where('engine', 'codex')->count());
    }

    public function test_release_frees_a_claim_so_another_engine_can_take_it(): void
    {
        $claimed = $this->service()->claim('claude_code', 'file', 'app/Services/Foo.php');

        $released = $this->service()->release($claimed['claim']['id']);
        $this->assertTrue($released['ok']);
        $this->assertTrue($released['released']);

        // After release, codex can now claim the same target cleanly (no conflict).
        $reclaim = $this->service()->claim('codex', 'file', 'app/Services/Foo.php');
        $this->assertTrue($reclaim['ok']);
        $this->assertNull($reclaim['conflict']);
        $this->assertSame('codex', $reclaim['claim']['engine']);
    }

    public function test_release_of_unknown_claim_is_a_clean_no_op(): void
    {
        $released = $this->service()->release('does-not-exist');
        $this->assertTrue($released['ok']);
        $this->assertFalse($released['released']);
    }

    public function test_stale_claims_expire_by_ttl_on_read(): void
    {
        // Claim with a 1s TTL, then advance the clock past it: the next read expires it.
        $this->service()->claim('codex', 'file', 'app/Services/Foo.php', ['ttl' => 1]);

        Carbon::setTestNow(Carbon::now()->addSeconds(5));
        try {
            $active = $this->service()->active();
            $this->assertSame(0, $active['count']);
            $this->assertSame([], $active['claims']);
            // The row is now marked stale (lazy expiry — no cron).
            $this->assertSame(1, DB::table('atlas_aobg_blackboard')->where('status', 'stale')->count());

            // And because it expired, a new engine can now claim the same target.
            $reclaim = $this->service()->claim('claude_code', 'file', 'app/Services/Foo.php');
            $this->assertTrue($reclaim['ok']);
            $this->assertNull($reclaim['conflict']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_active_lists_claims_scoped_to_the_workspace(): void
    {
        $this->service()->claim('claude_code', 'file', 'a.php');
        $this->service()->claim('codex', 'task', 'TASK-7');
        // A claim in ANOTHER workspace must NOT leak into this workspace's listing.
        $this->seedRawClaim('other-repo', 'cursor', 'file', 'b.php');

        $active = $this->service()->active();
        $this->assertSame(2, $active['count']);
        $engines = array_column($active['claims'], 'engine');
        $this->assertContains('claude_code', $engines);
        $this->assertContains('codex', $engines);
        $this->assertNotContains('cursor', $engines);
        foreach ($active['claims'] as $claim) {
            $this->assertSame($this->workspaceId(), $claim['workspace_id']);
        }
    }

    public function test_conflicts_for_excludes_the_asking_engine(): void
    {
        $this->service()->claim('claude_code', 'file', 'shared.php');
        $this->service()->claim('codex', 'file', 'shared.php', ['ttl' => 1]); // conflicts → no codex row...

        // ...so only claude_code holds it. From claude_code's view (except_engine), no
        // OTHER engine holds it → no conflict surfaced.
        $own = $this->service()->conflictsFor('shared.php', ['except_engine' => 'claude_code']);
        $this->assertSame(0, $own['count']);

        // From codex's view, claude_code's claim IS a cross-engine conflict.
        $other = $this->service()->conflictsFor('shared.php', ['except_engine' => 'codex']);
        $this->assertSame(1, $other['count']);
        $this->assertSame('claude_code', $other['claims'][0]['engine']);
    }

    public function test_blank_engine_or_target_fails_open_and_never_throws(): void
    {
        $blankEngine = $this->service()->claim('   ', 'file', 'a.php');
        $this->assertFalse($blankEngine['ok']);
        $this->assertTrue($blankEngine['fail_open'] ?? false);

        $blankTarget = $this->service()->claim('codex', 'file', '   ');
        $this->assertFalse($blankTarget['ok']);
        $this->assertTrue($blankTarget['fail_open'] ?? false);
    }

    public function test_ttl_is_clamped_to_the_configured_maximum(): void
    {
        config()->set('atlas.aobg.blackboard.max_ttl_seconds', 60);
        $claim = $this->service()->claim('codex', 'file', 'a.php', ['ttl' => 999999]);
        $this->assertSame(60, $claim['claim']['ttl_seconds']);
    }

    public function test_unknown_kind_is_normalised_to_file(): void
    {
        $claim = $this->service()->claim('codex', 'bogus-kind', 'a.php');
        $this->assertSame('file', $claim['claim']['kind']);
    }

    // ------------------------------------------------------------------
    // fixtures (sqlite, cost-free — no provider call)
    // ------------------------------------------------------------------

    private function service(): AtlasAobgBlackboardService
    {
        return $this->app->make(AtlasAobgBlackboardService::class);
    }

    private function workspaceId(): string
    {
        return $this->app->make(CodeGraphWorkspaceIdentity::class)->default();
    }

    private function createBlackboardTable(): void
    {
        Schema::dropIfExists('atlas_aobg_blackboard');
        $migration = require database_path('migrations/2026_06_10_120000_create_atlas_aobg_blackboard_table.php');
        $migration->up();
    }

    private function seedRawClaim(string $workspaceId, string $engine, string $kind, string $target): void
    {
        DB::table('atlas_aobg_blackboard')->insert([
            'id' => $engine.':'.$kind.':'.substr(hash('sha1', $workspaceId.'|'.$target), 0, 16),
            'workspace_id' => $workspaceId,
            'engine' => $engine,
            'kind' => $kind,
            'target' => $target,
            'status' => 'active',
            'claimed_at' => now(),
            'expires_at' => now()->addHour(),
            'meta' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

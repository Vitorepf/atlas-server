<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction;

use App\Models\AtlasSelfConstructCycle;
use App\Services\Ai\SelfConstruction\AtlasSelfImprovementMetaMetricService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * S3.F3 — the SAFE RUNNER + the --status surface (cost-free; no provider, no cycle that
 * spends). Proves:
 *  - the AUTONOMOUS --watch mode is DEFAULT-OFF: with the autonomous_enabled config flag
 *    off (the default), --watch REFUSES to start (FAILURE) even though the kill-switch
 *    and per-cycle bounds exist — the unattended self-modifying loop can never run by
 *    accident;
 *  - with the flag ON but the kill-switch already tripped, --watch honors it and exits
 *    cleanly WITHOUT running an unbounded loop (and without spending — it breaks at
 *    cycle 0);
 *  - --status reads the HONEST meta-metric from persisted history (live, not hardcoded).
 */
final class AtlasSelfConstructSafeRunnerTest extends TestCase
{
    private string $killFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->killFile = storage_path('app/atlas-self-construct.stop');
        @unlink($this->killFile);
        // Default-OFF is the contract; assert from the explicit baseline either way.
        config()->set('atlas.self_construction.autonomous_enabled', false);
    }

    protected function tearDown(): void
    {
        @unlink($this->killFile);
        parent::tearDown();
    }

    public function test_watch_refuses_to_start_when_autonomous_is_disabled_by_default(): void
    {
        $out = new BufferedOutput;
        // --watch with the autonomous opt-in OFF (default) must NOT loop — it returns
        // FAILURE immediately. (If it did not refuse, this test would hang on the
        // infinite watch loop, which itself proves the gate is load-bearing.)
        $code = Artisan::call('atlas:self-construct', ['--watch' => true], $out);

        $this->assertSame(1, $code, 'watch must FAIL-CLOSED when the autonomous flag is off');
        $text = $out->fetch();
        $this->assertStringContainsString('Autonomous --watch mode is OFF by default', $text);
        $this->assertStringContainsString('ATLAS_SELF_CONSTRUCTION_AUTONOMOUS_ENABLED', $text);
    }

    public function test_watch_with_autonomous_on_honors_a_pretripped_kill_switch_without_looping(): void
    {
        config()->set('atlas.self_construction.autonomous_enabled', true);
        // Pre-trip the kill-switch so the FIRST loop check breaks immediately — proves
        // the kill-switch is honored AND that nothing was spent (cycle 0).
        @touch($this->killFile);

        $out = new BufferedOutput;
        $code = Artisan::call('atlas:self-construct', ['--watch' => true, '--interval' => 60], $out);

        $this->assertSame(0, $code, 'a tripped kill-switch exits cleanly');
        $text = $out->fetch();
        $this->assertStringContainsString('kill-switch tripped', $text);
        $this->assertStringContainsString('after 0 cycle(s)', $text, 'it broke at cycle 0 — no cycle ran, no spend');
        // The stop file is consumed on exit (so the next run starts fresh).
        $this->assertFileDoesNotExist($this->killFile);
    }

    public function test_status_reports_live_meta_metric_from_persisted_history(): void
    {
        $this->bootCyclesTable();

        // Seed two REAL history rows via the meta-metric (the same path the loop uses).
        $meta = new AtlasSelfImprovementMetaMetricService;
        $meta->record([
            'receipt_hash' => str_repeat('a', 64),
            'detected' => 1, 'delivered_count' => 1, 'accepted_count' => 1,
            'rejected_count' => 0, 'branches' => ['atlas/materialize/c1'], 'brain_anchored' => true,
        ], brainNodesBefore: 10, brainNodesAfter: 12);
        $meta->record([
            'receipt_hash' => str_repeat('b', 64),
            'detected' => 1, 'delivered_count' => 1, 'accepted_count' => 0,
            'rejected_count' => 1, 'branches' => [], 'brain_anchored' => true,
        ], brainNodesBefore: 12, brainNodesAfter: 13);

        $this->assertSame(2, AtlasSelfConstructCycle::query()->count());

        $out = new BufferedOutput;
        $code = Artisan::call('atlas:self-construct', ['--status' => true, '--json' => true], $out);

        $this->assertSame(0, $code);
        $decoded = json_decode($out->fetch(), true);
        $this->assertSame(AtlasSelfImprovementMetaMetricService::SCHEMA, $decoded['schema_version']);
        $this->assertSame(2, $decoded['cycle_count']);
        // DERIVED live, not hardcoded: 1 passed / 2 generated = 0.5.
        $this->assertSame(0.5, $decoded['totals']['relevance_pass_rate']);
        $this->assertSame(1, $decoded['totals']['relevance_rejected'], 'the rejection is reported honestly');
        $this->assertSame('declining', $decoded['relevance_pass_rate_trend']['direction']);
        // Brain growth quantified from the real deltas (2 + 1 = 3 refs).
        $this->assertSame(3, $decoded['brain_growth']['nodes_added_total']);
        $this->assertStringContainsString('NOT asserted', $decoded['note']);
    }

    private function bootCyclesTable(): void
    {
        if (Schema::hasTable('atlas_self_construct_cycles')) {
            return;
        }
        $migration = require database_path('migrations/2026_06_10_120000_create_atlas_self_construct_cycles_table.php');
        $migration->up();
        $this->assertTrue(Schema::hasTable('atlas_self_construct_cycles'));
        // Silence the unused-import linter for Blueprint (kept for parity with the house
        // boot pattern); a no-op table touch.
        $this->assertInstanceOf(\Closure::class, static fn (Blueprint $t): bool => true);
    }
}

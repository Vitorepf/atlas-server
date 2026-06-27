<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainDoneSetLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainMasterSwitch;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainReflectionStream;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Frozen proof of the read-only `atlas:brain:state` snapshot. Proves the JSON shape, that done-set tail
 * counts reflect the ledger, that no origination happens (the command is non-mutating), and that the
 * state command itself is pétreo (a brain entry-point that ledgers + reads — réu never edits its own
 * observability seam).
 */
final class AtlasBrainStateCommandTest extends TestCase
{
    private string $doneSetRoot;

    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir().'/atlas-brain-state-'.bin2hex(random_bytes(6));
        $this->doneSetRoot = $base.'/done-set';
        @mkdir($this->doneSetRoot, 0o775, true);
        config()->set('atlas.brain.done_set_root', $this->doneSetRoot);
        config()->set('atlas.brain.default_scope', 'loop');
        config()->set('atlas.brain.scopes.loop', [
            'label' => 'test',
            'roots' => ['app/Services/Ai/AutonomousEvolution'],
            'docs_roots' => [],
            'meta_harness' => true,
        ]);

        $this->envPath = $base.'/.env';
        file_put_contents($this->envPath, "APP_ENV=testing\n".AtlasBrainMasterSwitch::KEY."=true\n");
        AtlasBrainMasterSwitch::$envPathOverride = $this->envPath;
    }

    protected function tearDown(): void
    {
        AtlasBrainMasterSwitch::$envPathOverride = null;
        parent::tearDown();
    }

    public function test_state_shape_includes_master_switch_scope_and_tails(): void
    {
        // Seed the done-set with a few rows so tail counts are non-zero.
        $ledger = new AtlasBrainDoneSetLedger('loop', $this->doneSetRoot);
        $ledger->record(['snapshot_id' => 's', 'status' => 'served', 'produced' => true, 'action' => 'origin', 'target_path' => 'A.php', 'task_packet_id' => 'p1', 'refusal' => false]);
        $ledger->record(['snapshot_id' => 's', 'status' => 'refused', 'produced' => false, 'action' => 'origin', 'target_path' => 'B.php', 'task_packet_id' => 'p2', 'refusal' => true]);

        Artisan::call('atlas:brain:state', ['--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        self::assertTrue($payload['brain_enabled']);
        self::assertSame('loop', $payload['default_scope']);
        self::assertSame('loop', $payload['scope']['slug']);
        self::assertTrue($payload['scope']['meta_harness']);
        self::assertSame(2, $payload['done_set']['recent_count']);
        self::assertSame(1, $payload['done_set']['recent_served']);
        self::assertSame(1, $payload['done_set']['recent_refused']);
        self::assertSame(50, $payload['done_set']['recent_ratio_pct']); // 1 served / (1+1) = 50%
        self::assertArrayHasKey('reflection', $payload);
        self::assertSame(0, $payload['gate_health']['inspector_holes']);
        self::assertSame(0, $payload['gate_health']['seed_gate_holes']);
    }

    public function test_last_brief_parses_newest_action_hint_reflection(): void
    {
        config()->set('atlas.brain.reflection_enabled', true);
        // Use a temp reflection path so the stream doesn't write to the live ndjson.
        $path = sys_get_temp_dir().'/atlas-brain-state-rflx-'.bin2hex(random_bytes(6)).'.ndjson';
        config()->set('atlas.brain.reflection_root', $path);
        $stream = new AtlasBrainReflectionStream($path);
        $stream->record(['scope' => 'loop', 'reflection' => 'leverage_brief: use_drafted_candidate — 2 drafts ready-to-seed', 'signals' => ['action_hint' => 'use_drafted_candidate']], 1);
        // re-bind so the command uses the same path
        $this->app->instance(AtlasBrainReflectionStream::class, $stream);

        Artisan::call('atlas:brain:state', ['--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        self::assertSame('use_drafted_candidate', $payload['last_brief']['hint']);
        self::assertStringContainsString('2 drafts', $payload['last_brief']['rationale']);
        @unlink($path);
    }

    public function test_all_flag_enumerates_every_configured_scope_in_cohorts(): void
    {
        config()->set('atlas.brain.scopes.muscle', [
            'label' => 'test-muscle',
            'roots' => ['app/Models'],
            'docs_roots' => [],
            'meta_harness' => false,
        ]);

        Artisan::call('atlas:brain:state', ['--all' => true, '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        self::assertArrayHasKey('cohorts', $payload);
        $slugs = array_column($payload['cohorts'], 'slug');
        self::assertContains('loop', $slugs);
        self::assertContains('muscle', $slugs);
        // cohort_health_ranking is computed alongside cohorts when --all is set.
        self::assertArrayHasKey('cohort_health_ranking', $payload);
        self::assertArrayHasKey('top', $payload['cohort_health_ranking']);
    }

    public function test_cascade_outcomes_block_joins_reflection_and_done_set(): void
    {
        config()->set('atlas.brain.reflection_enabled', true);
        $base = sys_get_temp_dir().'/atlas-brain-state-cascade-'.bin2hex(random_bytes(4));
        @mkdir($base, 0o775, true);
        $stream = $base.'/reflection.ndjson';
        config()->set('atlas.brain.reflection_root', $stream);

        foreach (['snap-A', 'snap-B'] as $cycle) {
            $row = ['schema' => 'x', 'scope' => 'loop', 'cycle_id' => $cycle, 'result_kind' => 'note', 'reflection' => 'leverage_brief: use_drafted_candidate — t', 'signals' => ['action_hint' => 'use_drafted_candidate'], 'recorded_at' => 0];
            file_put_contents($stream, json_encode($row).PHP_EOL, FILE_APPEND);
        }

        $ledger = new AtlasBrainDoneSetLedger('loop', $this->doneSetRoot);
        $ledger->record(['snapshot_id' => 'snap-A', 'status' => 'served', 'produced' => true]);
        $ledger->record(['snapshot_id' => 'snap-B', 'status' => 'refused', 'produced' => false]);

        Artisan::call('atlas:brain:state', ['--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        self::assertSame(2, $payload['cascade_outcomes']['joined_cycles']);
        self::assertSame('use_drafted_candidate', $payload['cascade_outcomes']['by_hint'][0]['hint']);
        self::assertSame(50, $payload['cascade_outcomes']['by_hint'][0]['served_rate_pct']);
    }

    public function test_hint_transitions_block_counts_adjacent_pairs(): void
    {
        config()->set('atlas.brain.reflection_enabled', true);
        $stream = sys_get_temp_dir().'/atlas-brain-state-transitions-'.bin2hex(random_bytes(4)).'.ndjson';
        config()->set('atlas.brain.reflection_root', $stream);

        // Sequence: A, A, B, A ⇒ transitions: A→A (1), A→B (1), B→A (1) ⇒ self_loops=1.
        foreach (['A', 'A', 'B', 'A'] as $i => $hint) {
            $row = ['schema' => 'x', 'scope' => 'loop', 'cycle_id' => "s{$i}", 'result_kind' => 'note', 'reflection' => "leverage_brief: {$hint} — t", 'signals' => ['action_hint' => $hint], 'recorded_at' => 0];
            file_put_contents($stream, json_encode($row).PHP_EOL, FILE_APPEND);
        }

        Artisan::call('atlas:brain:state', ['--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        self::assertSame(3, $payload['hint_transitions']['transitions']);
        self::assertSame(1, $payload['hint_transitions']['self_loops']);
        self::assertNotEmpty($payload['hint_transitions']['top_pairs']);
    }

    public function test_state_command_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Console/Commands/AtlasBrainStateCommand.php',
            true
        );

        self::assertSame('forbidden', $verdict);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AutonomousEvolution\Brain;

use App\Console\Commands\AtlasBrainStateCommand;
use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainDoneSetLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHeartbeatLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainMasterSwitch;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainProvenanceLedger;
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

    private string $provenanceRoot;

    private string $heartbeatRoot;

    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir().'/atlas-brain-state-'.bin2hex(random_bytes(6));
        $this->doneSetRoot = $base.'/done-set';
        $this->provenanceRoot = $base.'/provenance';
        $this->heartbeatRoot = $base.'/heartbeat';
        @mkdir($this->doneSetRoot, 0o775, true);
        @mkdir($this->provenanceRoot, 0o775, true);
        @mkdir($this->heartbeatRoot, 0o775, true);
        config()->set('atlas.brain.done_set_root', $this->doneSetRoot);
        config()->set('atlas.brain.provenance_root', $this->provenanceRoot);
        config()->set('atlas.brain.heartbeat_root', $this->heartbeatRoot);
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
        // health_score is computed from the surfaced inputs — gates airtight + ratio 50 + no reflections
        // ⇒ starvation 0% + entropy 0 (alphabet=0) + trend insufficient_data.
        // 40 (gates) + 10 (ratio*0.2) + 20 (100-0)*0.2 + 0 (entropy) + 5 (insufficient_data) = 75.
        self::assertSame(75, $payload['health_score']['score']);
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

    public function test_target_seed_quota_progress_is_read_only_from_done_set(): void
    {
        $ledger = new AtlasBrainDoneSetLedger('loop', $this->doneSetRoot);
        $ledger->record(['status' => 'seeded', 'produced' => true, 'target_path' => 'A.php']);
        $ledger->record(['status' => 'refused', 'produced' => false, 'target_path' => 'B.php']);
        $ledger->record(['status' => 'seeded', 'produced' => true, 'target_path' => 'C.php']);

        Artisan::call('atlas:brain:state', [
            '--json' => true,
            '--target-seeds' => 3,
            '--baseline-seeded' => 1,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        self::assertSame(3, $payload['quota']['target_seeds']);
        self::assertSame(1, $payload['quota']['baseline_seeded']);
        self::assertSame(2, $payload['quota']['current_seeded']);
        self::assertSame(1, $payload['quota']['valid_seeds']);
        self::assertSame(2, $payload['quota']['remaining']);
        self::assertSame('stalled_before_quota', $payload['quota']['status']);
        self::assertSame('atlas.brain.quota_watchdog.v1', $payload['quota']['watchdog']['schema']);
        self::assertSame(0, $payload['quota']['watchdog']['active_brain_commands']);
        self::assertSame('atlas.brain.quota_recovery_hint.v1', $payload['quota']['recovery_hint']['schema']);
        self::assertSame('resume_external_brain_step_1', $payload['quota']['recovery_hint']['action']);
        self::assertStringContainsString("atlas:brain:next 'loop' --scope-signals --json", $payload['quota']['recovery_hint']['command']);
        self::assertSame('external_actor_must_run_command_atlas_does_not_auto_start', $payload['quota']['recovery_hint']['note']);
        self::assertTrue($payload['quota']['recovery_hint']['external_actor_must_execute']);
        self::assertFalse($payload['quota']['recovery_hint']['operator_input_required']);
        self::assertFalse($payload['quota']['recovery_hint']['atlas_auto_started']);
    }

    public function test_quota_watchdog_counts_aobg_guard_over_brain_temp_file_as_active_brain_work(): void
    {
        $method = new \ReflectionMethod(AtlasBrainStateCommand::class, 'isActiveBrainCommandLine');
        $method->setAccessible(true);

        self::assertTrue((bool) $method->invoke(null, 'php artisan atlas:aobg:guard /tmp/brain-claude-brain-2-quota-100.json --json'));
        self::assertFalse((bool) $method->invoke(null, 'php artisan atlas:aobg:guard /tmp/not-a-brain.json --json'));
    }

    public function test_quota_watchdog_matches_active_brain_work_to_actor(): void
    {
        $method = new \ReflectionMethod(AtlasBrainStateCommand::class, 'isActiveBrainCommandLineForActor');
        $method->setAccessible(true);

        $line = "php artisan atlas:brain:next 'autonomous' --scope-signals --actor='claude-100' --json";

        self::assertTrue((bool) $method->invoke(null, $line, 'claude-100'));
        self::assertFalse((bool) $method->invoke(null, $line, 'claude-10'));
        self::assertTrue((bool) $method->invoke(null, 'php artisan atlas:aobg:guard /tmp/brain-claude-10.json --json', 'claude-10'));
    }

    public function test_external_engine_status_distinguishes_claude_open_from_brain_loop_active(): void
    {
        $method = new \ReflectionMethod(AtlasBrainStateCommand::class, 'externalEngineStatusFromLines');
        $method->setAccessible(true);

        $lines = [
            '/Applications/Claude.app/Contents/MacOS/Claude',
            "php artisan atlas:brain:state --watch-actors='claude-brain-quota-10:10:0'",
        ];

        $idle = $method->invoke(null, $lines, 0, [[
            'actor' => 'claude-brain-quota-10',
            'remaining' => 10,
            'stall_reason' => 'temp_spec_already_done',
            'recovery_hint' => [
                'existing_spec_action' => 'discard_done_set_spec_and_pull_next',
                'command' => "rm -f '/tmp/brain-claude-brain-quota-10.json' && php artisan atlas:brain:next 'loop' --actor='claude-brain-quota-10' --json",
                'external_actor_must_execute' => true,
                'operator_input_required' => false,
                'auto_recovery_required' => true,
                'analysis_allowed_before_recovery' => false,
            ],
        ]]);
        self::assertSame('engine_open_loop_idle', $idle['status']);
        self::assertSame(1, $idle['processes']);
        self::assertSame('external_engine_open_but_brain_loop_idle', $idle['diagnosis']);
        self::assertTrue($idle['obedience_failure']);
        self::assertSame('must_run_now_unexecuted', $idle['obedience_failure_reason']);
        self::assertSame('resume_external_brain_goal_or_run_actor_recovery_hint', $idle['next_action']);
        self::assertSame(1, $idle['stalled_actor_count']);
        self::assertSame('claude-brain-quota-10', $idle['stalled_actors'][0]['actor']);
        self::assertSame(10, $idle['stalled_actors'][0]['remaining']);
        self::assertSame('temp_spec_already_done', $idle['stalled_actors'][0]['stall_reason']);
        self::assertSame('discard_done_set_spec_and_pull_next', $idle['stalled_actors'][0]['recovery_action']);
        self::assertSame("rm -f '/tmp/brain-claude-brain-quota-10.json' && php artisan atlas:brain:next 'loop' --actor='claude-brain-quota-10' --json", $idle['stalled_actors'][0]['recovery_command']);
        self::assertSame('atlas.brain.first_action.v1', $idle['stalled_actors'][0]['first_action']['schema']);
        self::assertSame('must_run_now', $idle['stalled_actors'][0]['first_action']['type']);
        self::assertTrue($idle['stalled_actors'][0]['first_action']['execute_before_analysis']);
        self::assertFalse($idle['stalled_actors'][0]['first_action']['operator_input_required']);
        self::assertTrue($idle['stalled_actors'][0]['first_action']['analysis_before_execution_is_contract_violation']);
        self::assertSame($idle['stalled_actors'][0]['must_run_now'], $idle['stalled_actors'][0]['first_action']['command']);
        self::assertTrue($idle['stalled_actors'][0]['external_actor_must_execute']);
        self::assertFalse($idle['stalled_actors'][0]['operator_input_required']);
        self::assertTrue($idle['stalled_actors'][0]['auto_recovery_required']);
        self::assertFalse($idle['stalled_actors'][0]['analysis_allowed_before_recovery']);
        self::assertTrue($idle['stalled_actors'][0]['obedience_failure']);
        self::assertSame('must_run_now_unexecuted', $idle['stalled_actors'][0]['obedience_failure_reason']);

        $active = $method->invoke(null, $lines, 1);
        self::assertSame('brain_command_active', $active['status']);
        self::assertArrayNotHasKey('diagnosis', $active);
    }

    public function test_actor_seed_quota_progress_is_read_only_from_provenance(): void
    {
        $ledger = new AtlasBrainDoneSetLedger('loop', $this->doneSetRoot);
        $ledger->record(['status' => 'seeded', 'produced' => true, 'target_path' => 'A.php']);
        $ledger->record(['status' => 'seeded', 'produced' => true, 'target_path' => 'B.php']);
        $ledger->record(['status' => 'seeded', 'produced' => true, 'target_path' => 'C.php']);

        $provenance = new AtlasBrainProvenanceLedger($this->provenanceRoot);
        $provenance->append('loop', ['cycle_id' => 'a', 'task_packet_id' => 'a', 'actor' => 'claude brain 100']);
        $provenance->append('loop', ['cycle_id' => 'b', 'task_packet_id' => 'b', 'actor' => 'claude-10']);
        $provenance->append('loop', ['cycle_id' => 'c', 'task_packet_id' => 'c', 'actor' => 'claude brain 100']);

        Artisan::call('atlas:brain:state', [
            '--json' => true,
            '--target-seeds' => 3,
            '--baseline-seeded' => 1,
            '--actor' => 'claude brain 100',
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        self::assertSame('claude brain 100', $payload['quota']['actor']);
        self::assertSame(2, $payload['quota']['current_seeded']);
        self::assertSame(1, $payload['quota']['valid_seeds']);
        self::assertSame(2, $payload['quota']['remaining']);
        self::assertSame('claude brain 100', $payload['provenance']['top_actors'][0]['actor']);
        self::assertSame(2, $payload['provenance']['top_actors'][0]['count']);
        self::assertSame('atlas.brain.external_engine_status.v1', $payload['quota']['external_engine']['schema']);
        self::assertSame($payload['quota']['recovery_hint']['command'], $payload['quota']['next_command']);
        self::assertStringContainsString("--scope-signals --actor='claude brain 100' --json", $payload['quota']['recovery_hint']['command']);
        self::assertStringContainsString("--client='claude brain 100' --baseline-seeded=1 --target-seeds=3", $payload['quota']['recovery_hint']['worker_prompt_command']);
    }

    public function test_actor_quota_counts_only_credited_seed_rows(): void
    {
        $provenance = new AtlasBrainProvenanceLedger($this->provenanceRoot);
        $provenance->append('loop', ['cycle_id' => 'credited-a', 'task_packet_id' => 'a', 'actor' => 'brain-credit', 'credit_status' => 'credited']);
        $provenance->append('loop', ['cycle_id' => 'not-credit', 'task_packet_id' => 'b', 'actor' => 'brain-credit', 'credit_status' => 'not_credited']);

        Artisan::call('atlas:brain:state', [
            '--json' => true,
            '--target-seeds' => 2,
            '--actor' => 'brain-credit',
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        self::assertSame('credited_valid_seeds_only', $payload['quota']['credit_policy']);
        self::assertSame(1, $payload['quota']['current_seeded']);
        self::assertSame(1, $payload['quota']['current_credited_seeded']);
        self::assertSame(1, $payload['quota']['valid_seeds']);
        self::assertSame(1, $payload['quota']['credited_valid_seeds']);
        self::assertSame(1, $payload['quota']['remaining']);
    }

    public function test_top_level_actor_quota_watchdog_ignores_other_actor_active_work(): void
    {
        $command = PHP_BINARY.' -r '.escapeshellarg('sleep(20);').' artisan atlas:brain:next autonomous --scope-signals --actor=claude-100 --json';
        $process = proc_open($command, [
            ['pipe', 'r'],
            ['file', '/dev/null', 'w'],
            ['file', '/dev/null', 'w'],
        ], $pipes, base_path());

        self::assertIsResource($process);

        try {
            $seen = false;
            for ($i = 0; $i < 20; $i++) {
                $out = [];
                @exec('ps -axo command=', $out);
                $seen = (bool) array_filter($out, static fn (string $line): bool => str_contains($line, '--actor=claude-100') && str_contains($line, 'artisan atlas:brain:next'));
                if ($seen) {
                    break;
                }
                usleep(100_000);
            }
            self::assertTrue($seen, 'active brain fixture process did not appear in ps');

            Artisan::call('atlas:brain:state', [
                '--json' => true,
                '--target-seeds' => 10,
                '--actor' => 'claude-10',
            ]);
            $payload = json_decode(trim(Artisan::output()), true);

            self::assertSame('stalled_before_quota', $payload['quota']['status']);
            self::assertSame(0, $payload['quota']['watchdog']['active_brain_commands']);
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }

    public function test_actor_quota_recovery_discards_done_temp_spec_in_top_level_quota(): void
    {
        (new AtlasBrainDoneSetLedger('loop', $this->doneSetRoot))->record([
            'snapshot_id' => 's',
            'status' => 'seeded',
            'produced' => true,
            'action' => 'seed',
            'target_path' => 'B.php',
            'task_packet_id' => 'already-done',
            'refusal' => false,
        ]);
        $path = '/tmp/brain-claude-100.json';
        file_put_contents($path, json_encode([
            'packets' => [[
                'task_packet_id' => 'already-done',
                'allowed_files' => ['B.php'],
            ]],
        ], JSON_UNESCAPED_SLASHES));

        try {
            Artisan::call('atlas:brain:state', [
                '--json' => true,
                '--target-seeds' => 100,
                '--actor' => 'claude-100',
            ]);
            $payload = json_decode(trim(Artisan::output()), true);
            $hint = $payload['quota']['recovery_hint'];

            self::assertSame('temp_spec_already_done', $payload['quota']['stall_reason']);
            self::assertSame('atlas.brain.first_action.v1', $payload['quota']['first_action']['schema']);
            self::assertSame('must_run_now', $payload['quota']['first_action']['type']);
            self::assertTrue($payload['quota']['first_action']['execute_before_analysis']);
            self::assertFalse($payload['quota']['first_action']['operator_input_required']);
            self::assertSame($payload['quota']['must_run_now'], $payload['quota']['first_action']['command']);
            self::assertTrue($hint['never_reseed_done_set_spec']);
            self::assertTrue($hint['auto_recovery_required']);
            self::assertFalse($hint['analysis_allowed_before_recovery']);
            self::assertSame('discard_done_set_spec_and_pull_next', $hint['existing_spec_action']);
            self::assertSame('rm -f '.escapeshellarg($path), $hint['discard_existing_spec_command']);
            self::assertSame(
                'rm -f '.escapeshellarg($path)." && /opt/homebrew/bin/php -d memory_limit=4096M -d pcov.enabled=0 artisan atlas:brain:next 'loop' --scope-signals --actor='claude-100' --json",
                $hint['command']
            );
            self::assertArrayNotHasKey('seed_existing_spec_command', $hint);
        } finally {
            @unlink($path);
        }
    }

    public function test_actor_quota_explains_stalled_seed_without_heartbeat(): void
    {
        $provenance = new AtlasBrainProvenanceLedger($this->provenanceRoot);
        $provenance->append('loop', ['cycle_id' => 'old-seed', 'task_packet_id' => 'old-seed', 'target_path' => 'A.php', 'actor' => 'claude-100'], time() - 120);

        Artisan::call('atlas:brain:state', [
            '--json' => true,
            '--target-seeds' => 100,
            '--actor' => 'claude-100',
            '--stale-after' => 60,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        self::assertSame('seeded_then_silent', $payload['quota']['stall_reason']);
        self::assertStringContainsString('last seed is stale', $payload['quota']['stall_evidence']);
    }

    public function test_actor_quota_reports_pre_actor_history_when_only_unknown_rows_exist(): void
    {
        $provenance = new AtlasBrainProvenanceLedger($this->provenanceRoot);
        $provenance->append('loop', ['cycle_id' => 'old-a', 'task_packet_id' => 'old-a']);
        $provenance->append('loop', ['cycle_id' => 'old-b', 'task_packet_id' => 'old-b', 'actor' => '']);

        Artisan::call('atlas:brain:state', [
            '--json' => true,
            '--target-seeds' => 10,
            '--actor' => 'claude-100',
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        self::assertSame(0, $payload['quota']['current_seeded']);
        self::assertSame(2, $payload['quota']['unattributed_seeded']);
        self::assertSame('pre_actor_history_present', $payload['quota']['actor_history_note']['status']);
        self::assertSame(2, $payload['quota']['actor_history_note']['unknown_seeded']);
        self::assertSame('older_provenance_rows_have_no_actor', $payload['quota']['actor_history_note']['note']);
    }

    public function test_quota_supervisor_reports_multiple_external_brains_separately(): void
    {
        $provenance = new AtlasBrainProvenanceLedger($this->provenanceRoot);
        $provenance->append('loop', ['cycle_id' => 'seed-a', 'task_packet_id' => 'seed-a', 'target_path' => 'A.php', 'actor' => 'claude-10', 'recorded_at' => 100]);
        $provenance->append('loop', ['cycle_id' => 'seed-b', 'task_packet_id' => 'seed-b', 'target_path' => 'B.php', 'actor' => 'claude-100', 'recorded_at' => 200]);
        $provenance->append('loop', ['cycle_id' => 'seed-c', 'task_packet_id' => 'seed-c', 'target_path' => 'C.php', 'actor' => 'claude-100', 'recorded_at' => 300]);

        Artisan::call('atlas:brain:state', [
            '--json' => true,
            '--watch-actors' => 'claude-10:10:0,claude-100:100:0',
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        self::assertSame('atlas.brain.quota_supervisor.v1', $payload['quota_supervisor']['schema']);
        self::assertSame('stalled_before_quota', $payload['quota_supervisor']['status']);
        self::assertSame(110, $payload['quota_supervisor']['progress']['target_seeds']);
        self::assertSame(3, $payload['quota_supervisor']['progress']['valid_seeds']);
        self::assertSame(107, $payload['quota_supervisor']['progress']['remaining']);
        self::assertSame(2, $payload['quota_supervisor']['stall_reasons']['seeded_then_silent']);
        self::assertSame('atlas.brain.first_action.v1', $payload['quota_supervisor']['first_action']['schema']);
        self::assertSame($payload['quota_supervisor']['must_run_now'][0]['command'], $payload['quota_supervisor']['first_action']['command']);
        self::assertSame('claude-10', $payload['quota_supervisor']['actors'][0]['actor']);
        self::assertSame(1, $payload['quota_supervisor']['actors'][0]['valid_seeds']);
        self::assertSame(9, $payload['quota_supervisor']['actors'][0]['remaining']);
        self::assertSame('seed-a', $payload['quota_supervisor']['actors'][0]['last_seed']['cycle_id']);
        self::assertStringContainsString("--client='claude-10'", $payload['quota_supervisor']['actors'][0]['recovery_hint']['worker_prompt_command']);
        self::assertSame($payload['quota_supervisor']['actors'][0]['recovery_hint']['command'], $payload['quota_supervisor']['actors'][0]['next_command']);
        self::assertSame($payload['quota_supervisor']['actors'][0]['next_command'], $payload['quota_supervisor']['actors'][0]['must_run_now']);
        self::assertSame('claude-10', $payload['quota_supervisor']['next_commands'][0]['actor']);
        self::assertSame($payload['quota_supervisor']['actors'][0]['next_command'], $payload['quota_supervisor']['next_commands'][0]['command']);
        self::assertSame('claude-10', $payload['quota_supervisor']['must_run_now'][0]['actor']);
        self::assertSame($payload['quota_supervisor']['actors'][0]['must_run_now'], $payload['quota_supervisor']['must_run_now'][0]['command']);
        self::assertSame('atlas.brain.first_action.v1', $payload['quota_supervisor']['must_run_now'][0]['first_action']['schema']);
        self::assertTrue($payload['quota_supervisor']['must_run_now'][0]['first_action']['execute_before_analysis']);
        self::assertSame('claude-100', $payload['quota_supervisor']['actors'][1]['actor']);
        self::assertSame(2, $payload['quota_supervisor']['actors'][1]['valid_seeds']);
        self::assertSame(98, $payload['quota_supervisor']['actors'][1]['remaining']);
        self::assertSame('seed-c', $payload['quota_supervisor']['actors'][1]['last_seed']['cycle_id']);
        self::assertSame($payload['quota_supervisor']['actors'][1]['recovery_hint']['command'], $payload['quota_supervisor']['actors'][1]['next_command']);
        self::assertSame($payload['quota_supervisor']['actors'][1]['next_command'], $payload['quota_supervisor']['actors'][1]['must_run_now']);
        self::assertSame('claude-100', $payload['quota_supervisor']['next_commands'][1]['actor']);
        self::assertSame($payload['quota_supervisor']['actors'][1]['next_command'], $payload['quota_supervisor']['next_commands'][1]['command']);
        self::assertSame('claude-100', $payload['quota_supervisor']['must_run_now'][1]['actor']);
        self::assertSame($payload['quota_supervisor']['actors'][1]['must_run_now'], $payload['quota_supervisor']['must_run_now'][1]['command']);
    }

    public function test_quota_supervisor_does_not_hide_stalled_actor_when_another_actor_is_active(): void
    {
        $command = PHP_BINARY.' -r '.escapeshellarg('sleep(20);').' artisan atlas:brain:next autonomous --scope-signals --actor=claude-active --json';
        $process = proc_open($command, [
            ['pipe', 'r'],
            ['file', '/dev/null', 'w'],
            ['file', '/dev/null', 'w'],
        ], $pipes, base_path());

        self::assertIsResource($process);

        try {
            $seen = false;
            for ($i = 0; $i < 20; $i++) {
                $out = [];
                @exec('ps -axo command=', $out);
                $seen = (bool) array_filter($out, static fn (string $line): bool => str_contains($line, '--actor=claude-active') && str_contains($line, 'artisan atlas:brain:next'));
                if ($seen) {
                    break;
                }
                usleep(100_000);
            }
            self::assertTrue($seen, 'active brain fixture process did not appear in ps');

            Artisan::call('atlas:brain:state', [
                '--json' => true,
                '--watch-actors' => 'claude-active:10:0,claude-stalled:10:0',
            ]);
            $payload = json_decode(trim(Artisan::output()), true);

            self::assertSame('partial_active_with_stalls', $payload['quota_supervisor']['status']);
            self::assertSame(1, $payload['quota_supervisor']['active_actor_count']);
            self::assertSame(1, $payload['quota_supervisor']['stalled_actor_count']);
            self::assertSame('claude-stalled', $payload['quota_supervisor']['must_run_now'][0]['actor']);
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }

    public function test_quota_supervisor_reports_unattributed_seeded_rows(): void
    {
        $provenance = new AtlasBrainProvenanceLedger($this->provenanceRoot);
        $provenance->append('loop', ['cycle_id' => 'unknown-a', 'task_packet_id' => 'unknown-a', 'target_path' => 'A.php']);
        $provenance->append('loop', ['cycle_id' => 'unknown-b', 'task_packet_id' => 'unknown-b', 'target_path' => 'B.php', 'actor' => '']);
        $provenance->append('loop', ['cycle_id' => 'known', 'task_packet_id' => 'known', 'target_path' => 'C.php', 'actor' => 'claude-100']);

        Artisan::call('atlas:brain:state', [
            '--json' => true,
            '--watch-actors' => 'claude-100:100:0,claude-empty:10:0',
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        self::assertSame(2, $payload['quota_supervisor']['unattributed_seeded']);
        self::assertSame(2, $payload['quota_supervisor']['progress']['unattributed_seeded']);
        self::assertSame(1, $payload['quota_supervisor']['actors'][0]['valid_seeds']);
        self::assertSame('pre_actor_history_present', $payload['quota_supervisor']['actors'][1]['actor_history_note']['status']);
        self::assertSame(2, $payload['quota_supervisor']['actors'][1]['actor_history_note']['unknown_seeded']);
    }

    public function test_quota_supervisor_reports_actor_activity_age_and_staleness(): void
    {
        $provenance = new AtlasBrainProvenanceLedger($this->provenanceRoot);
        $provenance->append('loop', ['cycle_id' => 'old-seed', 'task_packet_id' => 'old-seed', 'target_path' => 'A.php', 'actor' => 'claude-100'], time() - 120);

        Artisan::call('atlas:brain:state', [
            '--json' => true,
            '--watch-actors' => 'claude-100:100:0',
            '--stale-after' => 60,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);
        $actor = $payload['quota_supervisor']['actors'][0];

        self::assertSame(60, $actor['stale_after_seconds']);
        self::assertGreaterThanOrEqual(120, $actor['last_seed_age_seconds']);
        self::assertSame('stale', $actor['activity_status']);
    }

    public function test_quota_supervisor_distinguishes_temp_spec_written_but_never_seeded(): void
    {
        $actor = 'claude-temp-'.bin2hex(random_bytes(4));
        $path = '/tmp/brain-'.$actor.'.json';
        file_put_contents($path, json_encode([
            'packets' => [[
                'task_packet_id' => 'temp-only',
                'allowed_files' => ['A.php'],
            ]],
        ], JSON_UNESCAPED_SLASHES));

        try {
            Artisan::call('atlas:brain:state', [
                '--json' => true,
                '--watch-actors' => $actor.':10:0',
                '--stale-after' => 60,
            ]);
            $payload = json_decode(trim(Artisan::output()), true);
            $actorState = $payload['quota_supervisor']['actors'][0];

            self::assertSame('temp_spec_unseeded', $actorState['stall_reason']);
            self::assertStringContainsString('temp spec exists', $actorState['stall_evidence']);
            self::assertTrue($actorState['temp_spec']['exists']);
            self::assertSame($path, $actorState['temp_spec']['path']);
            self::assertSame('temp-only', $actorState['temp_spec']['task_packet_id']);
            self::assertSame('seed_existing_spec_first', $actorState['recovery_hint']['action']);
            self::assertSame(
                '/opt/homebrew/bin/php -d memory_limit=4096M -d pcov.enabled=0 artisan atlas:brain:seed --specs='.escapeshellarg($path)." --scope='loop' --actor=".escapeshellarg($actor).' --require-actor --dry-run --json',
                $actorState['recovery_hint']['dry_run_existing_spec_command']
            );
            self::assertSame($actorState['recovery_hint']['dry_run_existing_spec_command'], $actorState['recovery_hint']['command']);
            self::assertSame(
                '/opt/homebrew/bin/php -d memory_limit=4096M -d pcov.enabled=0 artisan atlas:brain:seed --specs='.escapeshellarg($path)." --scope='loop' --actor=".escapeshellarg($actor).' --require-actor --cleanup-specs --json',
                $actorState['recovery_hint']['seed_existing_spec_command']
            );
        } finally {
            @unlink($path);
        }
    }

    public function test_quota_supervisor_recovery_prompt_preserves_actor_baseline(): void
    {
        $provenance = new AtlasBrainProvenanceLedger($this->provenanceRoot);
        $provenance->append('loop', ['cycle_id' => 'seed-a', 'task_packet_id' => 'seed-a', 'target_path' => 'A.php', 'actor' => 'claude-100']);
        $provenance->append('loop', ['cycle_id' => 'seed-b', 'task_packet_id' => 'seed-b', 'target_path' => 'B.php', 'actor' => 'claude-100']);

        Artisan::call('atlas:brain:state', [
            '--json' => true,
            '--watch-actors' => 'claude-100:100:2',
        ]);
        $payload = json_decode(trim(Artisan::output()), true);
        $actor = $payload['quota_supervisor']['actors'][0];

        self::assertStringContainsString('--baseline-seeded=2', $actor['recovery_hint']['worker_prompt_command']);
    }

    public function test_quota_supervisor_explains_seeded_actor_with_no_heartbeat(): void
    {
        $provenance = new AtlasBrainProvenanceLedger($this->provenanceRoot);
        $provenance->append('loop', ['cycle_id' => 'old-seed', 'task_packet_id' => 'old-seed', 'target_path' => 'A.php', 'actor' => 'claude-100'], time() - 120);
        $path = '/tmp/brain-claude-100.json';
        file_put_contents($path, json_encode([
            'packets' => [[
                'task_packet_id' => 'old-seed',
                'allowed_files' => ['A.php'],
            ]],
        ], JSON_UNESCAPED_SLASHES));

        try {
            Artisan::call('atlas:brain:state', [
                '--json' => true,
                '--watch-actors' => 'claude-100:100:0',
                '--stale-after' => 60,
            ]);
            $payload = json_decode(trim(Artisan::output()), true);
            $actor = $payload['quota_supervisor']['actors'][0];

            self::assertSame('seeded_then_silent', $actor['stall_reason']);
            self::assertStringContainsString('last seed is stale', $actor['stall_evidence']);
            self::assertArrayNotHasKey('dry_run_existing_spec_command', $actor['recovery_hint']);
            self::assertArrayNotHasKey('seed_existing_spec_command', $actor['recovery_hint']);
        } finally {
            @unlink($path);
        }
    }

    public function test_quota_supervisor_resumes_unseeded_temp_spec_after_previous_seed(): void
    {
        $provenance = new AtlasBrainProvenanceLedger($this->provenanceRoot);
        $provenance->append('loop', ['cycle_id' => 'old-seed', 'task_packet_id' => 'old-seed', 'target_path' => 'A.php', 'actor' => 'claude-100'], time() - 120);
        $path = '/tmp/brain-claude-100.json';
        file_put_contents($path, json_encode([
            'packets' => [[
                'task_packet_id' => 'new-temp-spec',
                'allowed_files' => ['B.php'],
            ]],
        ], JSON_UNESCAPED_SLASHES));

        try {
            Artisan::call('atlas:brain:state', [
                '--json' => true,
                '--watch-actors' => 'claude-100:100:0',
                '--stale-after' => 60,
            ]);
            $payload = json_decode(trim(Artisan::output()), true);
            $actor = $payload['quota_supervisor']['actors'][0];

            self::assertSame('temp_spec_unseeded', $actor['stall_reason']);
            self::assertSame('seed_existing_spec_first', $actor['recovery_hint']['action']);
            self::assertSame($actor['recovery_hint']['dry_run_existing_spec_command'], $actor['recovery_hint']['command']);
            self::assertStringContainsString('--specs='.escapeshellarg($path), $actor['recovery_hint']['command']);
            self::assertStringContainsString('--cleanup-specs --json', $actor['recovery_hint']['seed_existing_spec_command']);
        } finally {
            @unlink($path);
        }
    }

    public function test_quota_supervisor_does_not_reseed_temp_spec_already_in_done_set(): void
    {
        (new AtlasBrainDoneSetLedger('loop', $this->doneSetRoot))->record([
            'snapshot_id' => 's',
            'status' => 'seeded',
            'produced' => true,
            'action' => 'seed',
            'target_path' => 'B.php',
            'task_packet_id' => 'already-done',
            'refusal' => false,
        ]);
        $path = '/tmp/brain-claude-100.json';
        file_put_contents($path, json_encode([
            'packets' => [[
                'task_packet_id' => 'already-done',
                'allowed_files' => ['B.php'],
            ]],
        ], JSON_UNESCAPED_SLASHES));

        try {
            Artisan::call('atlas:brain:state', [
                '--json' => true,
                '--watch-actors' => 'claude-100:100:0',
                '--stale-after' => 60,
            ]);
            $payload = json_decode(trim(Artisan::output()), true);
            $actor = $payload['quota_supervisor']['actors'][0];

            self::assertSame('temp_spec_already_done', $actor['stall_reason']);
            self::assertTrue($actor['recovery_hint']['never_reseed_done_set_spec']);
            self::assertTrue($actor['recovery_hint']['auto_recovery_required']);
            self::assertFalse($actor['recovery_hint']['analysis_allowed_before_recovery']);
            self::assertTrue($actor['temp_spec']['done_set_hit']);
            self::assertSame('B.php', $actor['temp_spec']['target_path']);
            self::assertSame('resume_external_brain_step_1', $actor['recovery_hint']['action']);
            self::assertSame('discard_done_set_spec_and_pull_next', $actor['recovery_hint']['existing_spec_action']);
            self::assertSame($path, $actor['recovery_hint']['existing_spec_path']);
            self::assertSame('rm -f '.escapeshellarg($path), $actor['recovery_hint']['discard_existing_spec_command']);
            self::assertSame(
                'rm -f '.escapeshellarg($path)." && /opt/homebrew/bin/php -d memory_limit=4096M -d pcov.enabled=0 artisan atlas:brain:next 'loop' --scope-signals --actor='claude-100' --json",
                $actor['recovery_hint']['command']
            );
            self::assertSame($actor['recovery_hint']['command'], $actor['must_run_now']);
            self::assertSame('atlas.brain.first_action.v1', $actor['first_action']['schema']);
            self::assertSame('must_run_now', $actor['first_action']['type']);
            self::assertTrue($actor['first_action']['execute_before_analysis']);
            self::assertFalse($actor['first_action']['operator_input_required']);
            self::assertSame($actor['must_run_now'], $actor['first_action']['command']);
            self::assertArrayNotHasKey('dry_run_existing_spec_command', $actor['recovery_hint']);
            self::assertArrayNotHasKey('seed_existing_spec_command', $actor['recovery_hint']);
        } finally {
            @unlink($path);
        }
    }

    public function test_quota_supervisor_reports_actor_heartbeat_without_seed(): void
    {
        (new AtlasBrainHeartbeatLedger($this->heartbeatRoot))->record('loop', [
            'actor' => 'claude-10',
            'command' => 'next',
            'status' => 'refused',
        ], time() - 30);

        Artisan::call('atlas:brain:state', [
            '--json' => true,
            '--watch-actors' => 'claude-10:10:0',
            '--stale-after' => 60,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);
        $actor = $payload['quota_supervisor']['actors'][0];

        self::assertNull($actor['last_seed']);
        self::assertSame('next', $actor['last_heartbeat']['command']);
        self::assertSame('refused', $actor['last_heartbeat']['status']);
        self::assertFalse($actor['last_heartbeat']['dry_run']);
        self::assertGreaterThanOrEqual(30, $actor['last_heartbeat_age_seconds']);
        self::assertSame('fresh', $actor['activity_status']);
    }

    public function test_quota_supervisor_keeps_legacy_heartbeat_dry_run_unknown(): void
    {
        file_put_contents($this->heartbeatRoot.'/loop.ndjson', json_encode([
            'schema' => 'atlas.brain.heartbeat.v1',
            'scope' => 'loop',
            'actor' => 'claude-legacy',
            'command' => 'seed',
            'status' => 'ok',
            'recorded_at' => time() - 30,
        ], JSON_UNESCAPED_SLASHES).PHP_EOL);

        Artisan::call('atlas:brain:state', [
            '--json' => true,
            '--watch-actors' => 'claude-legacy:10:0',
            '--stale-after' => 60,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);
        $actor = $payload['quota_supervisor']['actors'][0];

        self::assertArrayHasKey('dry_run', $actor['last_heartbeat']);
        self::assertNull($actor['last_heartbeat']['dry_run']);
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

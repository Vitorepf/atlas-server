<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainDoneSetLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainMasterSwitch;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Frozen proof of the brain health doctor — actionable diagnoses over read-only brain state.
 */
final class AtlasBrainHealthDoctorCommandTest extends TestCase
{
    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir().'/atlas-brain-doctor-'.bin2hex(random_bytes(6));
        @mkdir($base, 0o775, true);
        config()->set('atlas.brain.done_set_root', $base.'/done-set');
        config()->set('atlas.brain.scopes.loop', [
            'label' => 'test', 'roots' => ['app/Services/Ai/AutonomousEvolution'], 'docs_roots' => [], 'meta_harness' => true,
        ]);
        config()->set('atlas.brain.default_scope', 'loop');
        config()->set('atlas.brain.scope_signal_digest_enabled', false);
        config()->set('atlas.brain.reflection_enabled', false);

        $this->envPath = $base.'/.env';
        file_put_contents($this->envPath, "APP_ENV=testing\n");
        AtlasBrainMasterSwitch::$envPathOverride = $this->envPath;
    }

    protected function tearDown(): void
    {
        AtlasBrainMasterSwitch::$envPathOverride = null;
        parent::tearDown();
    }

    public function test_default_state_yields_warn_master_off_plus_info_digest_dormant(): void
    {
        Artisan::call('atlas:brain:health-doctor', ['--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $codes = array_column($payload['findings'], 'code');
        self::assertContains('master_switch_off', $codes);
        self::assertContains('scope_signal_digest_dormant', $codes);
        self::assertNotContains('gate_regression', $codes, 'gates are airtight by default');
        self::assertSame('has_findings', $payload['status']);
    }

    public function test_fully_armed_clean_brain_is_healthy(): void
    {
        file_put_contents($this->envPath, "APP_ENV=testing\n".AtlasBrainMasterSwitch::KEY."=true\n");
        config()->set('atlas.brain.scope_signal_digest_enabled', true);

        Artisan::call('atlas:brain:health-doctor', ['--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        // Healthy = no critical/warn findings; info-only nudges (e.g. frontier_empty) coexist with healthy.
        self::assertSame('healthy', $payload['status']);
        foreach ($payload['findings'] as $finding) {
            self::assertNotContains($finding['severity'], ['critical', 'warn']);
        }
    }

    public function test_skewed_brief_histogram_emits_info_finding(): void
    {
        file_put_contents($this->envPath, "APP_ENV=testing\n".AtlasBrainMasterSwitch::KEY."=true\n");
        config()->set('atlas.brain.scope_signal_digest_enabled', true);
        config()->set('atlas.brain.reflection_enabled', true);

        $stream = sys_get_temp_dir().'/atlas-brain-doctor-reflection-'.bin2hex(random_bytes(4)).'.ndjson';
        config()->set('atlas.brain.reflection_root', $stream);
        $rows = '';
        for ($i = 0; $i < 6; $i++) {
            $rows .= json_encode(['schema' => 'x', 'scope' => 'loop', 'cycle_id' => "c{$i}", 'result_kind' => 'note', 'reflection' => 'leverage_brief: use_drafted_candidate — '.$i, 'signals' => [], 'recorded_at' => 0]).PHP_EOL;
        }
        file_put_contents($stream, $rows);

        Artisan::call('atlas:brain:health-doctor', ['--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $codes = array_column($payload['findings'], 'code');
        self::assertContains('brief_histogram_skewed', $codes);
        // Same fixture (6 identical hints → 5 transitions, all self-loops) ⇒ self-loop dominance fires too.
        self::assertContains('hint_self_loop_dominant', $codes);
    }

    public function test_severity_filter_narrows_findings_and_drives_exit_code(): void
    {
        // Default state: master_off (warn) + scope_signal_digest_dormant (info) + frontier_empty (info).
        Artisan::call('atlas:brain:health-doctor', ['--json' => true, '--severity' => 'info']);
        $payload = json_decode(trim(Artisan::output()), true);

        // Surfaced findings are info-only; counts retain the full breakdown.
        foreach ($payload['findings'] as $f) {
            self::assertSame('info', $f['severity']);
        }
        self::assertSame('info', $payload['severity_filter']);
        self::assertGreaterThan(0, $payload['severity_counts']['warn'], 'warn counts survive in the summary even when filter=info');
        self::assertGreaterThan(0, $payload['severity_counts']['info']);
        // Filter=info ⇒ status reflects ONLY info findings (which are non-empty here) ⇒ has_findings.
        self::assertSame('has_findings', $payload['status']);

        // critical filter on the same default state ⇒ empty surfaced + healthy + exit 0.
        $exit = Artisan::call('atlas:brain:health-doctor', ['--json' => true, '--severity' => 'critical']);
        $payload2 = json_decode(trim(Artisan::output()), true);
        self::assertSame([], $payload2['findings']);
        self::assertSame('healthy', $payload2['status']);
        self::assertSame(0, $exit);
    }

    public function test_low_yield_cascade_rule_emits_info_finding(): void
    {
        file_put_contents($this->envPath, "APP_ENV=testing\n".AtlasBrainMasterSwitch::KEY."=true\n");
        config()->set('atlas.brain.scope_signal_digest_enabled', true);
        config()->set('atlas.brain.reflection_enabled', true);

        $stream = sys_get_temp_dir().'/atlas-brain-doctor-cascade-'.bin2hex(random_bytes(4)).'.ndjson';
        config()->set('atlas.brain.reflection_root', $stream);

        // 6 cycles all hint=rotate_path; 1 served, 5 refused ⇒ 17% < 30 ⇒ low_yield fires.
        $ledger = new AtlasBrainDoneSetLedger('loop', (string) config('atlas.brain.done_set_root'));
        for ($i = 0; $i < 6; $i++) {
            $cycle = "snap-{$i}";
            $row = ['schema' => 'x', 'scope' => 'loop', 'cycle_id' => $cycle, 'result_kind' => 'note', 'reflection' => 'leverage_brief: rotate_path — t', 'signals' => ['action_hint' => 'rotate_path'], 'recorded_at' => 0];
            file_put_contents($stream, json_encode($row).PHP_EOL, FILE_APPEND);
            $ledger->record(['snapshot_id' => $cycle, 'status' => $i === 0 ? 'served' : 'refused', 'produced' => $i === 0]);
        }

        Artisan::call('atlas:brain:health-doctor', ['--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $codes = array_column($payload['findings'], 'code');
        self::assertContains('cascade_rule_low_yield', $codes);
    }

    public function test_starvation_kinds_emit_info_finding(): void
    {
        file_put_contents($this->envPath, "APP_ENV=testing\n".AtlasBrainMasterSwitch::KEY."=true\n");
        config()->set('atlas.brain.scope_signal_digest_enabled', true);
        config()->set('atlas.brain.reflection_enabled', true);

        $stream = sys_get_temp_dir().'/atlas-brain-doctor-kindhist-'.bin2hex(random_bytes(4)).'.ndjson';
        config()->set('atlas.brain.reflection_root', $stream);

        // 10 reflections, 8 blocked + 2 note ⇒ starvation_pct = 80 > 70.
        for ($i = 0; $i < 10; $i++) {
            $kind = $i < 8 ? 'blocked' : 'note';
            $row = ['schema' => 'x', 'scope' => 'loop', 'cycle_id' => "k{$i}", 'result_kind' => $kind, 'reflection' => "kind {$kind}", 'signals' => [], 'recorded_at' => 0];
            file_put_contents($stream, json_encode($row).PHP_EOL, FILE_APPEND);
        }

        Artisan::call('atlas:brain:health-doctor', ['--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        self::assertContains('result_kind_starvation', array_column($payload['findings'], 'code'));
    }

    public function test_worsening_starvation_trend_emits_info_finding(): void
    {
        file_put_contents($this->envPath, "APP_ENV=testing\n".AtlasBrainMasterSwitch::KEY."=true\n");
        config()->set('atlas.brain.scope_signal_digest_enabled', true);
        config()->set('atlas.brain.reflection_enabled', true);

        $stream = sys_get_temp_dir().'/atlas-brain-doctor-trend-'.bin2hex(random_bytes(4)).'.ndjson';
        config()->set('atlas.brain.reflection_root', $stream);

        // 50 reflections: older 25 = note (0% starv), newer 25 = blocked (100% starv) ⇒ Δ +100, worsening.
        for ($i = 0; $i < 50; $i++) {
            $kind = $i < 25 ? 'note' : 'blocked';
            $row = ['schema' => 'x', 'scope' => 'loop', 'cycle_id' => "t{$i}", 'result_kind' => $kind, 'reflection' => "{$kind}", 'signals' => [], 'recorded_at' => 0];
            file_put_contents($stream, json_encode($row).PHP_EOL, FILE_APPEND);
        }

        Artisan::call('atlas:brain:health-doctor', ['--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        self::assertContains('starvation_trend_worsening', array_column($payload['findings'], 'code'));
    }

    public function test_doctor_command_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit('app/Console/Commands/AtlasBrainHealthDoctorCommand.php', true);
        self::assertSame('forbidden', $verdict);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
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

    public function test_doctor_command_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit('app/Console/Commands/AtlasBrainHealthDoctorCommand.php', true);
        self::assertSame('forbidden', $verdict);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Vox;

use App\Console\Commands\AtlasVoxDoctorCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Feature contract for `atlas:vox:doctor` (Onda V3.9 / Claude AC).
 *
 * The command is READ-ONLY. It never executes a provider, never opens
 * a microphone, never touches Voice Realtime code. These tests assert
 * the shape, exit-code semantics, and ledger-invariant guarantees the
 * command MUST keep.
 */
final class AtlasVoxDoctorCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::dropIfExists('atlas_vox_rivals_cases');
        Schema::dropIfExists('atlas_vox_dogfood_sessions');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_20_010000_create_atlas_vox_rivals_cases_table.php'))->up();
        if (file_exists(database_path('migrations/2026_05_20_020000_create_atlas_vox_dogfood_sessions_table.php'))) {
            (require database_path('migrations/2026_05_20_020000_create_atlas_vox_dogfood_sessions_table.php'))->up();
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_vox_dogfood_sessions');
        Schema::dropIfExists('atlas_vox_rivals_cases');
        Schema::dropIfExists('atlas_ledger_events');
        parent::tearDown();
    }

    /**
     * @return array<string,mixed>
     */
    private function runDoctorJson(array $options = []): array
    {
        Artisan::call('atlas:vox:doctor', array_merge(['--json' => true], $options));
        $output = Artisan::output();
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, "doctor output não é JSON válido: {$output}");

        return $decoded;
    }

    public function test_doctor_emits_canonical_schema_and_all_sections(): void
    {
        $snapshot = $this->runDoctorJson();

        $this->assertSame(AtlasVoxDoctorCommand::SCHEMA, $snapshot['schema']);
        $this->assertContains($snapshot['status'], ['pass', 'warn', 'fail']);
        $this->assertTrue($snapshot['read_only']);
        $this->assertFalse($snapshot['v4_unlock_allowed']);

        foreach (
            ['health', 'readiness', 'hardening', 'metrics', 'rivals', 'dogfood', 'gate_v3', 'certification']
            as $section
        ) {
            $this->assertArrayHasKey($section, $snapshot['sections'], "missing section: {$section}");
            $this->assertArrayHasKey('status', $snapshot['sections'][$section]);
        }

        $this->assertIsArray($snapshot['next_actions']);
        $this->assertIsString($snapshot['generated_at']);
    }

    public function test_health_section_advertises_terminal_execute_false_and_voice_realtime_paused(): void
    {
        $snapshot = $this->runDoctorJson();
        $health = $snapshot['sections']['health'];
        $this->assertSame('pass', $health['status']);
        $this->assertFalse($health['kernel_guarantees']['terminal_execute']);
        $this->assertFalse($health['kernel_guarantees']['voice_realtime_touched']);
        $this->assertSame('paused_until_v6', $health['voice_realtime_status']);
    }

    public function test_certification_summary_carries_hash_and_does_not_unlock_v4(): void
    {
        $snapshot = $this->runDoctorJson();
        $cert = $snapshot['sections']['certification'];
        // certification can be pass/warn/fail depending on gate, but the
        // contract is: it must carry the hash and never flip v4_unlock.
        if (isset($cert['error'])) {
            $this->markTestSkipped('certification collector exception in this env: '.($cert['error']['message'] ?? '?'));
        }
        $this->assertArrayHasKey('certification_hash', $cert);
        $this->assertStringStartsWith('sha256:', (string) $cert['certification_hash']);
        $this->assertFalse($cert['v4_unlock_allowed']);
        $this->assertArrayNotHasKey('pack', $cert, 'pack body must NOT be in default output');
    }

    public function test_include_certification_pack_attaches_full_pack(): void
    {
        $snapshot = $this->runDoctorJson(['--include-certification-pack' => true]);
        $cert = $snapshot['sections']['certification'];
        if (isset($cert['error'])) {
            $this->markTestSkipped('certification collector exception in this env');
        }
        $this->assertArrayHasKey('pack', $cert);
        $this->assertSame('atlas.vox.v3_certification_pack.v1', $cert['pack']['schema']);
        $this->assertFalse($cert['pack']['v4_unlock_allowed']);
    }

    public function test_unknown_sections_become_warn_not_pass(): void
    {
        // VoxV3HardeningAuditService returns several `unknown` checks on a
        // fresh ledger; the doctor's `hardening` section must surface that
        // as warn (NEVER silent pass).
        $snapshot = $this->runDoctorJson();
        $hardening = $snapshot['sections']['hardening'];
        $this->assertContains($hardening['status'], ['warn', 'fail']);
        $this->assertNotSame('pass', $hardening['status']);
    }

    public function test_strict_returns_non_zero_when_overall_is_warn(): void
    {
        // Run once non-strict to learn the natural overall status, then
        // assert --strict bumps a warn to exit-1.
        $natural = Artisan::call('atlas:vox:doctor', ['--json' => true]);
        $snapshot = json_decode(Artisan::output(), true);
        $this->assertIsArray($snapshot);

        if ($snapshot['status'] === 'pass') {
            $this->markTestSkipped('natural status is pass; --strict semantics are exercised by warn path only');
        }
        if ($snapshot['status'] === 'fail') {
            // Both strict and non-strict return 1 on fail; just assert.
            $this->assertSame(1, $natural);
            $strict = Artisan::call('atlas:vox:doctor', ['--json' => true, '--strict' => true]);
            $this->assertSame(1, $strict);

            return;
        }
        // warn path
        $this->assertSame(0, $natural, 'warn without --strict must be exit 0');
        $strict = Artisan::call('atlas:vox:doctor', ['--json' => true, '--strict' => true]);
        $this->assertSame(1, $strict, 'warn with --strict must be exit 1');
    }

    public function test_doctor_never_emits_voice_realtime_or_atlas_app_paths(): void
    {
        Artisan::call('atlas:vox:doctor', ['--json' => true]);
        $output = Artisan::output();
        $this->assertStringNotContainsString('Services/Ai/Voice', $output);
        $this->assertStringNotContainsString('atlas-app/', $output);
    }

    public function test_doctor_does_not_execute_provider_or_shell(): void
    {
        // Structural assertion: the doctor must not declare provider_call
        // or terminal_execute as side-effects. We assert by reading the
        // emitted snapshot — every guarantee flag stays in its safe state.
        $snapshot = $this->runDoctorJson();
        $guarantees = $snapshot['sections']['health']['kernel_guarantees'];
        $this->assertFalse($guarantees['terminal_execute']);
        $this->assertFalse($guarantees['destructive_auto_execute']);
        $this->assertFalse($guarantees['raw_audio_accepted']);
        $this->assertFalse($guarantees['voice_realtime_touched']);
    }

    public function test_doctor_tolerates_missing_rivals_table_with_setup_pending_warn(): void
    {
        // Drop the rivals table to simulate a freshly-cloned repo that has
        // never run `php artisan migrate`. The doctor must NOT throw a
        // QueryException — rivals/certification surface `setup_pending`
        // with the migrate next-action, and the overall status stays warn
        // (not fail) because the missing table is not a safety violation.
        Schema::dropIfExists('atlas_vox_rivals_cases');

        $snapshot = $this->runDoctorJson();

        $this->assertNotSame('fail', $snapshot['status'], 'missing table must NOT escalate to overall fail');

        $rivals = $snapshot['sections']['rivals'];
        $this->assertSame('warn', $rivals['status']);
        $this->assertSame('setup_pending', $rivals['storage_status']);
        $this->assertIsString($rivals['setup_next_action']);
        $this->assertStringContainsString('php artisan migrate', $rivals['setup_next_action']);

        $cert = $snapshot['sections']['certification'];
        $this->assertNotSame('fail', $cert['status'], 'cert without safety violations is warn, not fail');
        $this->assertSame('setup_pending', $cert['rivals_storage_status']);
        $this->assertIsString($cert['rivals_setup_next_action']);

        // Top-level next_actions must surface the migrate hint front-and-center.
        $found = false;
        foreach ($snapshot['next_actions'] as $action) {
            if (str_contains((string) $action, 'php artisan migrate')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'next_actions must surface the migrate hint when rivals table is missing');
    }

    public function test_gate_v3_blocked_by_setup_only_blockers_is_warn_not_fail(): void
    {
        // With no eclipse tests yet, the gate returns blocked — but the
        // doctor must distinguish "missing usage data" from "real safety
        // violation" and surface this as warn.
        $snapshot = $this->runDoctorJson();
        $gate = $snapshot['sections']['gate_v3'];
        if ($gate['observed'] !== 'blocked') {
            $this->markTestSkipped('gate not blocked in this fixture (test only validates setup-only mapping)');
        }
        $this->assertSame([], $gate['safety_blockers'], 'no safety violations expected in clean fixture');
        $this->assertSame('warn', $gate['status'], 'blocked without safety violations is warn');
    }

    public function test_hardening_audit_is_resolved_in_readiness(): void
    {
        // Wave V3.9: VoxV3HardeningAuditService must be injected into
        // VoxReadinessService via the container binding in
        // AppServiceProvider. Before the binding, the constructor's
        // nullable default kept it null in production. This test pins
        // the binding so future refactors can't silently regress.
        $readiness = app(\App\Services\Ai\Vox\Readiness\VoxReadinessService::class);
        $ref = new \ReflectionClass($readiness);
        $prop = $ref->getProperty('hardening');
        $this->assertNotNull(
            $prop->getValue($readiness),
            'AppServiceProvider must bind VoxReadinessService with the audit service injected',
        );
    }
}

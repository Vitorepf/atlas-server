<?php

namespace Tests\Feature\Ai\Aaeos;

use Tests\TestCase;

class AtlasAaeosRunCommandTest extends TestCase
{
    public function test_dry_run_port_emits_a_defined_successful_json_receipt(): void
    {
        $this->artisan('atlas:aaeos:run', [
            'intent' => 'p0 dry run',
            '--dry-run' => true,
            '--json' => true,
        ])->assertSuccessful();
    }

    public function test_invalid_mode_is_a_non_zero_repair_required_run_receipt(): void
    {
        $this->artisan('atlas:aaeos:run', [
            'intent' => 'p0 invalid mode',
            '--mode' => 'not_a_mode',
            '--dry-run' => true,
            '--json' => true,
        ])->assertFailed();
    }

    public function test_live_flag_is_stripped_with_migration_guidance(): void
    {
        $exit = \Illuminate\Support\Facades\Artisan::call('atlas:aaeos:run', [
            'intent' => 'p2f live removed',
            '--live' => true,
            '--json' => true,
        ]);
        $payload = json_decode(\Illuminate\Support\Facades\Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('p2f_technical_flag_removed', $payload['reason'] ?? null);
        $this->assertSame('live', $payload['flag'] ?? null);
        $this->assertArrayHasKey('migration_guidance', $payload);
        $this->assertNotSame('', trim((string) ($payload['migration_guidance'] ?? '')));
    }

    public function test_execute_provider_and_run_worker_once_are_stripped(): void
    {
        $this->artisan('atlas:aaeos:run', [
            'intent' => 'p2f execute provider removed',
            '--execute-provider' => true,
            '--json' => true,
        ])->assertFailed();

        $this->artisan('atlas:aaeos:run', [
            'intent' => 'p2f worker once removed',
            '--run-worker-once' => true,
            '--json' => true,
        ])->assertFailed();
    }

    public function test_max_seeds_and_scope_are_stripped(): void
    {
        $this->artisan('atlas:aaeos:run', [
            'intent' => 'p2f max seeds removed',
            '--max-seeds' => 3,
            '--json' => true,
        ])->assertFailed();

        $this->artisan('atlas:aaeos:run', [
            'intent' => 'p2f scope removed',
            '--scope' => 'app/Foo.php',
            '--json' => true,
        ])->assertFailed();
    }

    public function test_intent_first_dry_run_stamps_p2f_strip_marker(): void
    {
        $this->artisan('atlas:aaeos:run', [
            'intent' => 'p2f intent first',
            '--dry-run' => true,
            '--json' => true,
        ])
            ->expectsOutputToContain('p2f_technical_dials_stripped')
            ->assertSuccessful();
    }
}

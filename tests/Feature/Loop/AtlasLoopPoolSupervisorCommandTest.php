<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerPoolSupervisor;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the native-worker pool supervisor is live at the operator surface: it emits a structured plan and
 * (preview-only) reports no dispatch — dry_run is forced even when the supplied options ask to apply.
 */
final class AtlasLoopPoolSupervisorCommandTest extends TestCase
{
    private function plan(array $options = []): array
    {
        $params = ['--json' => true];
        if ($options !== []) {
            $params['--options'] = (string) json_encode($options);
        }
        $exit = Artisan::call('atlas:loop:pool-supervisor', $params);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_default_plan_is_dry_run_no_dispatch(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->plan();

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasNativeWorkerPoolSupervisor::SCHEMA, $d['schema_version']);
        $this->assertTrue($d['dry_run'], (string) json_encode($d));
        $this->assertSame(0, $d['cycle_count']); // nothing dispatched
        $this->assertArrayHasKey('supervisor_hash', $d);
    }

    public function test_apply_request_is_forced_to_dry_run(): void
    {
        // even asking to apply 5 cycles ⇒ preview forces dry-run, nothing dispatched
        ['d' => $d] = $this->plan(['apply' => true, 'max_cycles' => 5]);

        $this->assertTrue($d['dry_run'], (string) json_encode($d));
        $this->assertSame(0, $d['cycle_count']);
    }

    public function test_invalid_options_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:pool-supervisor', ['--options' => 'not json', '--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}

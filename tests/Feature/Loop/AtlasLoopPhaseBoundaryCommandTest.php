<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasLoopPhaseBoundaryCommandTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas-pb-'.bin2hex(random_bytes(6)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->tmp);
        parent::tearDown();
    }

    public function test_schemas_returns_all_boundaries(): void
    {
        Artisan::call('atlas:loop:phase:boundary', ['action' => 'schemas', '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertIsArray($payload);
        $this->assertGreaterThanOrEqual(7, count($payload));
        $this->assertArrayHasKey('architect->decompose', $payload);
    }

    public function test_validate_ok_for_well_formed_fact(): void
    {
        file_put_contents($this->tmp, json_encode([
            'design_contract' => ['x' => 1],
            'obligations' => ['o1'],
            'consumer_contracts' => ['cc'],
            'emitted_by_phase' => 'architect',
            'emitted_at' => '2026-06-25T06:00:00+00:00',
            'cycle_id' => 'cyc-1',
        ]));

        Artisan::call('atlas:loop:phase:boundary', ['action' => 'validate', '--boundary' => 'architect->decompose', '--fact' => $this->tmp, '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertTrue($payload['ok']);
        $this->assertSame([], $payload['missing_keys']);
    }

    public function test_validate_not_ok_lists_missing_keys(): void
    {
        file_put_contents($this->tmp, json_encode([
            'design_contract' => ['x' => 1],
            // missing obligations + consumer_contracts
        ]));

        Artisan::call('atlas:loop:phase:boundary', ['action' => 'validate', '--boundary' => 'architect->decompose', '--fact' => $this->tmp, '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertFalse($payload['ok']);
        $this->assertNotEmpty($payload['missing_keys']);
        $this->assertContains('obligations', $payload['missing_keys']);
        $this->assertContains('consumer_contracts', $payload['missing_keys']);
    }

    public function test_drift_returns_empty_envelope_when_ledger_not_bound(): void
    {
        Artisan::call('atlas:loop:phase:boundary', ['action' => 'drift', '--cycles' => 30, '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(30, $payload['window_cycles']);
        $this->assertArrayHasKey('drift', $payload);
    }

    public function test_command_is_registered_in_artisan_list(): void
    {
        $all = array_keys(Artisan::all());
        $this->assertContains('atlas:loop:phase:boundary', $all);
    }
}

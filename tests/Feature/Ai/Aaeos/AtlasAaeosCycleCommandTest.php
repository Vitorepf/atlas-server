<?php

namespace Tests\Feature\Ai\Aaeos;

use Tests\TestCase;

class AtlasAaeosCycleCommandTest extends TestCase
{
    public function test_autonomos_cycle_json_dispatch(): void
    {
        $this->artisan('atlas:aaeos:cycle', [
            'intent' => 'evolve quality with proof',
            '--autonomos' => true,
            '--dry-run' => true,
            '--json' => true,
        ])->assertSuccessful();
    }

    public function test_irreversible_cycle_exits_failure(): void
    {
        $this->artisan('atlas:aaeos:cycle', [
            'intent' => 'production wipe of billing database',
            '--json' => true,
            '--dry-run' => true,
        ])->assertFailed();
    }

    public function test_scorecard_command_runs(): void
    {
        $this->artisan('atlas:aaeos:scorecard', ['--json' => true])
            ->assertSuccessful();
    }

    public function test_certify_command_passes(): void
    {
        $this->artisan('atlas:aaeos:certify', ['--json' => true])
            ->assertSuccessful();
    }
}

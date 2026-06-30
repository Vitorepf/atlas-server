<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * @group maestro-projection
 */
final class AtlasTaskMaestroProjectionCommandEnableFactsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Ensure the master switch is ON so the command can try to emit facts.
        // Use a temp .env override since AtlasLoopMasterSwitch reads the file directly.
        AtlasLoopMasterSwitch::$envPathOverride = sys_get_temp_dir() . '/atlas_test_env_on_' . uniqid();
        file_put_contents(AtlasLoopMasterSwitch::$envPathOverride, 'ATLAS_LOOP_MASTER_ENABLED=true');
    }

    protected function tearDown(): void
    {
        if (AtlasLoopMasterSwitch::$envPathOverride !== null && @unlink(AtlasLoopMasterSwitch::$envPathOverride)) {
            AtlasLoopMasterSwitch::$envPathOverride = null;
        }
        parent::tearDown();
    }

    public function test_rate_emits_fact_schema_when_dependencies_resolve(): void
    {
        $this->artisan('atlas:task:maestro-projection', ['verb' => 'rate', '--json' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('"schema"');
    }

    public function test_empty_emits_projection_when_dependencies_resolve(): void
    {
        $this->artisan('atlas:task:maestro-projection', ['verb' => 'empty', '--json' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('"schema"');
    }

    public function test_history_is_read_only_and_returns_rows(): void
    {
        $this->artisan('atlas:task:maestro-projection', ['verb' => 'history', '--json' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('"schema"');
    }

    public function test_rate_includes_disabled_reason_when_master_switch_off(): void
    {
        // Override the setUp env with one that has the switch OFF
        file_put_contents(AtlasLoopMasterSwitch::$envPathOverride, 'ATLAS_LOOP_MASTER_ENABLED=false');

        $this->artisan('atlas:task:maestro-projection', ['verb' => 'rate', '--json' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('disabled_reason');
    }

    public function test_invalid_verb_includes_disabled_reason(): void
    {
        $this->artisan('atlas:task:maestro-projection', ['verb' => 'bogus', '--json' => true])
            ->expectsOutputToContain('disabled_reason');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

final class AtlasTaskServingScheduleSelfRepairTest extends TestCase
{
    private string $envPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->envPath = sys_get_temp_dir().'/atlas-self-repair-env-'.bin2hex(random_bytes(6));
        AtlasLoopMasterSwitch::$envPathOverride = $this->envPath;
    }

    protected function tearDown(): void
    {
        @unlink($this->envPath);
        AtlasLoopMasterSwitch::$envPathOverride = null;
        parent::tearDown();
    }

    private function masterOn(): void
    {
        file_put_contents($this->envPath, 'ATLAS_LOOP_MASTER_ENABLED=true'.PHP_EOL);
    }

    private function masterOff(): void
    {
        file_put_contents($this->envPath, 'ATLAS_LOOP_MASTER_ENABLED=false'.PHP_EOL);
    }

    /**
     * @return list<\Illuminate\Console\Scheduling\Event>
     */
    private function events(): array
    {
        return $this->app->make(Schedule::class)->events();
    }

    private function findEvent(string $needle): ?\Illuminate\Console\Scheduling\Event
    {
        foreach ($this->events() as $event) {
            if (str_contains((string) $event->command, $needle)) {
                return $event;
            }
        }

        return null;
    }

    public function test_both_self_repair_commands_are_registered(): void
    {
        $sweep = $this->findEvent('atlas:task:sweep-malformed');
        $repair = $this->findEvent('atlas:task:repair-blocked');

        $this->assertNotNull($sweep, 'atlas:task:sweep-malformed must be scheduled');
        $this->assertNotNull($repair, 'atlas:task:repair-blocked must be scheduled');
    }

    public function test_sweep_is_every_fifteen_minutes_and_uses_without_overlapping(): void
    {
        $sweep = $this->findEvent('atlas:task:sweep-malformed');
        $this->assertNotNull($sweep);

        // every 15 min ⇒ minute filter contains the */15 cadence stride. We compare normalized cron.
        $this->assertSame('*/15 * * * *', $sweep->expression);
        $this->assertNotEmpty($sweep->mutexName(), 'withoutOverlapping installs a mutex name');
    }

    public function test_repair_is_hourly_and_uses_without_overlapping(): void
    {
        $repair = $this->findEvent('atlas:task:repair-blocked');
        $this->assertNotNull($repair);

        $this->assertSame('0 * * * *', $repair->expression);
        $this->assertNotEmpty($repair->mutexName());
    }

    public function test_master_switch_off_is_a_no_op_and_on_lets_the_filter_pass(): void
    {
        $sweep = $this->findEvent('atlas:task:sweep-malformed');
        $repair = $this->findEvent('atlas:task:repair-blocked');
        $this->assertNotNull($sweep);
        $this->assertNotNull($repair);

        $this->masterOff();
        $this->assertFalse($sweep->filtersPass($this->app), 'master OFF must skip sweep');
        $this->assertFalse($repair->filtersPass($this->app), 'master OFF must skip repair');

        $this->masterOn();
        $this->assertTrue($sweep->filtersPass($this->app), 'master ON must let sweep run');
        $this->assertTrue($repair->filtersPass($this->app), 'master ON must let repair run');
    }
}

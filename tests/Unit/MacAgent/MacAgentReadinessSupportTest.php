<?php

declare(strict_types=1);

namespace Tests\Unit\MacAgent;

use App\Services\MacAgent\Support\MacAgentReadinessSupport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MacAgentReadinessSupportTest extends TestCase
{
    #[Test]
    public function ready_background_jobs_yields_none_action(): void
    {
        $action = MacAgentReadinessSupport::readinessPrimaryAction([], [], true, true);
        $this->assertSame('none', $action['code']);
        $this->assertSame('ready', $action['kind']);
    }

    #[Test]
    public function blockers_are_priority_sorted(): void
    {
        $action = MacAgentReadinessSupport::readinessPrimaryAction([
            ['code' => 'battery_too_low_for_background_jobs', 'severity' => 'warning', 'message' => 'battery', 'action' => 'a'],
            ['code' => 'caffeinate_unavailable', 'severity' => 'warning', 'message' => 'caff', 'action' => 'b'],
        ], [], false, true);
        $this->assertSame('caffeinate_unavailable', $action['code']);
        $this->assertSame('b', $action['command']);
    }
}

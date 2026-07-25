<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\Support\ForgeExecutionStageSupport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ForgeExecutionStageSupportTest extends TestCase
{
    #[Test]
    public function phase_for_stage_maps_known_stages(): void
    {
        $this->assertSame('verify', ForgeExecutionStageSupport::phaseForStage('test_run'));
        $this->assertSame('repair', ForgeExecutionStageSupport::phaseForStage('repair_loop'));
        $this->assertSame('runtime', ForgeExecutionStageSupport::phaseForStage('unknown_stage'));
    }

    #[Test]
    public function stage_is_blocking_detects_blocker_or_status(): void
    {
        $this->assertTrue(ForgeExecutionStageSupport::stageIsBlocking('ok', 'hard blocker'));
        $this->assertTrue(ForgeExecutionStageSupport::stageIsBlocking('failed', null));
        $this->assertFalse(ForgeExecutionStageSupport::stageIsBlocking('succeeded', null));
    }
}

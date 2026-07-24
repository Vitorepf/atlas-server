<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Control;

use App\Services\Ai\Aaeos\Control\AaeosScorecardProjector;
use PHPUnit\Framework\TestCase;

final class AaeosMeasuredScorecardTest extends TestCase
{
    public function test_missing_runtime_measurements_stay_unknown_and_cannot_mint_god_sota(): void
    {
        $card = (new AaeosScorecardProjector)->project();

        $this->assertSame('unknown', $card['measurement_status']);
        $this->assertNull($card['dimensions']['operate_path_wiring']);
        $this->assertNull($card['dimensions']['spine_enforced']);
        $this->assertNull($card['dimensions']['antifragile_loop']);
        $this->assertNull($card['composite']);
        $this->assertFalse($card['god_sota']);
    }
}

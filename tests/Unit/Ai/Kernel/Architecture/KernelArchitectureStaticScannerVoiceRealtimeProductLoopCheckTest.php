<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\KernelArchitectureStaticScanner;
use Tests\TestCase;

class KernelArchitectureStaticScannerVoiceRealtimeProductLoopCheckTest extends TestCase
{
    public function test_ap687_voice_realtime_product_loop_check_scan_stays_green(): void
    {
        $report = app(KernelArchitectureStaticScanner::class)->complianceReport();

        $this->assertTrue(data_get($report, 'ap687_voice_realtime_production_promotion_gate.valid'));
        $this->assertSame([], data_get($report, 'ap687_voice_realtime_production_promotion_gate.violations'));
    }
}

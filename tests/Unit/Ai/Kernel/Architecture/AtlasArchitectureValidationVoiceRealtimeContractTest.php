<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasAiArchitectureValidationService;
use Tests\TestCase;

final class AtlasArchitectureValidationVoiceRealtimeContractTest extends TestCase
{
    public function test_shared_architecture_validation_payload_keeps_voice_realtime_contracts_green(): void
    {
        $payload = app(AtlasAiArchitectureValidationService::class)->payload();

        $this->assertSame('ok', $payload['status']);
        $this->assertContains('voice_realtime', data_get($payload, 'kernel.surface_adapters.surfaces'));
        $this->assertSame('voice_realtime', data_get($payload, 'kernel.surface_adapters.aliases.voice'));
        $this->assertTrue(data_get($payload, 'kernel.static_scan.ap687_voice_realtime_production_promotion_gate.valid'));
        $this->assertSame([], data_get($payload, 'kernel.static_scan.ap687_voice_realtime_production_promotion_gate.violations'));
        $this->assertContains(
            'voice_realtime',
            data_get($payload, 'kernel.static_scan.ap35_surface_adapter_parity_map_coverage.mapped_adapters'),
        );
    }
}

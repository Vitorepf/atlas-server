<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AtlasDecide;

use Tests\TestCase;

final class AtlasDecideGatewayIntegrationTest extends TestCase
{
    public function test_gateway_consults_learned_routes_before_operational_decision(): void
    {
        $source = (string) file_get_contents(app_path('Services/Ai/AiGatewayService.php'));

        $this->assertStringContainsString('AtlasDecideGatewayConsultationService', $source);
        $this->assertStringContainsString('$this->gatewayConsultation->consult(', $source);
        $this->assertStringContainsString("'gateway_consultation' => \$gatewayConsultation", $source);
        $this->assertStringContainsString("'route_applied' => \$learnedRouteApplied", $source);
        $this->assertSame('shadow', config('atlas.atlas_decide.gateway_consultation_mode'));
    }
}

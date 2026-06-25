<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Tests\TestCase;

final class AtlasLoopRootDiscoveryCycleBreakerTest extends TestCase
{
    public function test_campaign_supervisor_imports_the_port_not_the_discovery_concrete(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/AutonomousEvolution/Campaign/AtlasLoopCampaignSupervisor.php'));

        $this->assertStringContainsString(
            'use App\\Services\\Ai\\AutonomousEvolution\\Consolidation\\AtlasLoopRefillerPort;',
            $src,
            'supervisor MUST type-hint the Consolidation port',
        );
        $this->assertStringNotContainsString(
            'use App\\Services\\Ai\\AutonomousEvolution\\Discovery\\AtlasLoopQueueRefiller;',
            $src,
            'supervisor MUST NOT import the Discovery concrete',
        );
    }

    public function test_no_root_adjacent_consumer_imports_the_discovery_refiller_concrete(): void
    {
        // The campaign supervisor is the canonical root-adjacent consumer the packet calls out.
        $rootAdjacent = [
            'app/Services/Ai/AutonomousEvolution/Campaign/AtlasLoopCampaignSupervisor.php',
        ];

        foreach ($rootAdjacent as $rel) {
            $src = (string) file_get_contents(base_path($rel));
            $this->assertStringNotContainsString(
                'use App\\Services\\Ai\\AutonomousEvolution\\Discovery\\AtlasLoopQueueRefiller;',
                $src,
                $rel.' MUST resolve the refiller via AtlasLoopRefillerPort',
            );
        }
    }

    public function test_consolidation_port_does_not_import_root_collaborator_concretes(): void
    {
        // The port interface is the cycle-breaker SEAM — it must never import root concretes itself,
        // otherwise the cycle reappears one layer deeper.
        $portSrc = (string) file_get_contents(base_path('app/Services/Ai/AutonomousEvolution/Consolidation/AtlasLoopRefillerPort.php'));

        $this->assertStringNotContainsString('use App\\Services\\Ai\\AutonomousEvolution\\Persistence\\AtlasLoopStore;', $portSrc);
        $this->assertStringNotContainsString('use App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopBackService;', $portSrc);
        $this->assertStringNotContainsString('Discovery\\AtlasLoopQueueRefiller', $portSrc);
    }

    public function test_app_service_provider_binds_the_port_to_the_refiller_concrete(): void
    {
        $provider = (string) file_get_contents(base_path('app/Providers/AppServiceProvider.php'));

        $this->assertStringContainsString('AtlasLoopRefillerPort::class', $provider);
        $this->assertStringContainsString('AtlasLoopRefillerRootCollaborators::class', $provider);
    }
}

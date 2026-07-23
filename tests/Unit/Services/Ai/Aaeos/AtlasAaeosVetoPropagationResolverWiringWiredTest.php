<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\Aaeos;

use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasImplementationEvidenceResolver;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasCapabilityTestExecutionService;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasVetoPropagationResolver;
use Tests\TestCase;

/**
 * Proves AtlasVetoPropagationResolver is wired into a real call path:
 * AtlasCapabilityTestExecutionService now resolves a delivery-stage veto whenever a run is red. It is
 * no longer an orphan.
 */
final class AtlasAaeosVetoPropagationResolverWiringWiredTest extends TestCase
{
    public function test_red_run_resolves_and_surfaces_veto_propagation(): void
    {
        // A resolver that never resolves a test ref always yields a red, no-process-spawned run —
        // deterministic without depending on the real code_symbols index table.
        $resolver = $this->createMock(AtlasImplementationEvidenceResolver::class);
        $resolver->method('resolveTestFqn')->willReturn(null);
        $service = new AtlasCapabilityTestExecutionService(180.0, $resolver);

        $payload = $service->runAndRecord(
            'capability-unknown',
            'TotallyUnresolvableTestRef__no_match',
        );

        $this->assertFalse($payload['passed']);
        $this->assertArrayHasKey('veto_propagation', $payload);
        $this->assertSame('redirect_upstream', $payload['veto_propagation']['resolution']);
        $this->assertSame(['dev', 'forge'], $payload['veto_propagation']['redirect_to']);
    }

    public function test_resolver_matches_the_same_review_delivery_rule_the_service_now_relies_on(): void
    {
        $direct = (new AtlasVetoPropagationResolver)->resolve('review', 'delivery');

        $this->assertSame('redirect_upstream', $direct['resolution']);
        $this->assertSame(['dev', 'forge'], $direct['redirect_to']);
    }
}

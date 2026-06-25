<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Consolidation\AtlasLoopRefillerPort;
use App\Services\Ai\AutonomousEvolution\Consolidation\AtlasLoopRefillerRootCollaborators;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopQueueRefiller;
use ReflectionClass;
use Tests\TestCase;

final class AtlasLoopRefillerPortContractTest extends TestCase
{
    public function test_refiller_port_interface_lives_in_consolidation_and_declares_refill(): void
    {
        $this->assertTrue(interface_exists(AtlasLoopRefillerPort::class));
        $ref = new ReflectionClass(AtlasLoopRefillerPort::class);
        $this->assertTrue($ref->isInterface());
        $this->assertTrue($ref->hasMethod('refill'));

        $sig = $ref->getMethod('refill');
        $params = $sig->getParameters();
        $this->assertCount(2, $params, 'port.refill must be refill(campaign, want)');
        $this->assertSame('campaign', $params[0]->getName());
        $this->assertSame('want', $params[1]->getName());
    }

    public function test_queue_refiller_implements_the_port_with_matching_signature(): void
    {
        $this->assertTrue(is_subclass_of(AtlasLoopQueueRefiller::class, AtlasLoopRefillerPort::class));

        $portMethod = (new ReflectionClass(AtlasLoopRefillerPort::class))->getMethod('refill');
        $refillerMethod = (new ReflectionClass(AtlasLoopQueueRefiller::class))->getMethod('refill');

        $this->assertSame(
            count($portMethod->getParameters()),
            count($refillerMethod->getParameters()),
            'refiller must match the port parameter count',
        );
        $this->assertSame(
            (string) $portMethod->getParameters()[0]->getType(),
            (string) $refillerMethod->getParameters()[0]->getType(),
            'campaign type must match',
        );
    }

    public function test_root_collaborators_class_exists_in_consolidation(): void
    {
        $this->assertTrue(class_exists(AtlasLoopRefillerRootCollaborators::class));
        $this->assertSame(
            'App\\Services\\Ai\\AutonomousEvolution\\Consolidation',
            (new ReflectionClass(AtlasLoopRefillerRootCollaborators::class))->getNamespaceName(),
        );
    }

    public function test_port_resolves_through_container_to_the_queue_refiller_concrete(): void
    {
        $resolved = $this->app->make(AtlasLoopRefillerPort::class);
        $this->assertInstanceOf(AtlasLoopQueueRefiller::class, $resolved);
    }
}

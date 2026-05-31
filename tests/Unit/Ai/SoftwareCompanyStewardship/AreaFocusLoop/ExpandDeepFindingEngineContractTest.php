<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ExpandDeepFindingEngineContract;
use PHPUnit\Framework\TestCase;

final class ExpandDeepFindingEngineContractTest extends TestCase
{
    public function testContractExists(): void
    {
        $this->assertTrue(
            interface_exists(ExpandDeepFindingEngineContract::class),
            'ExpandDeepFindingEngineContract interface should exist'
        );
    }

    public function testContractHasExpandMethod(): void
    {
        $this->assertTrue(
            interface_exists(ExpandDeepFindingEngineContract::class),
            'ExpandDeepFindingEngineContract interface should exist'
        );

        $reflection = new \ReflectionClass(ExpandDeepFindingEngineContract::class);
        $this->assertTrue(
            $reflection->hasMethod('expand'),
            'Contract should declare expand method'
        );
    }

    public function testExpandMethodHasStringParameter(): void
    {
        $reflection = new \ReflectionClass(ExpandDeepFindingEngineContract::class);
        $method = $reflection->getMethod('expand');
        $parameters = $method->getParameters();

        $this->assertCount(1, $parameters);
        $this->assertSame('findingId', $parameters[0]->getName());
        $this->assertSame('string', $parameters[0]->getType()->getName());
    }

    public function testExpandMethodReturnsArray(): void
    {
        $reflection = new \ReflectionClass(ExpandDeepFindingEngineContract::class);
        $method = $reflection->getMethod('expand');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertSame('array', $returnType->getName());
    }
}
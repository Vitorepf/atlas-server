<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ImproveMergeGovernorThroughputWithoutLoweringSafetyContract;
use PHPUnit\Framework\TestCase;

final class ImproveMergeGovernorThroughputWithoutLoweringSafetyContractTest extends TestCase
{
    public function testContractIsInterface(): void
    {
        $reflection = new \ReflectionClass(ImproveMergeGovernorThroughputWithoutLoweringSafetyContract::class);
        $this->assertTrue($reflection->isInterface());
    }

    public function testContractDefinesRequiredMethods(): void
    {
        $requiredMethods = [
            'getCurrentThroughput',
            'getSafetyThreshold',
            'calculateOptimalBatchSize',
            'shouldProceedWithMerge',
        ];

        $reflection = new \ReflectionClass(ImproveMergeGovernorThroughputWithoutLoweringSafetyContract::class);

        foreach ($requiredMethods as $methodName) {
            $this->assertTrue(
                $reflection->hasMethod($methodName),
                "Contract should define method: {$methodName}"
            );
        }
    }

    public function testGetCurrentThroughputReturnsInt(): void
    {
        $reflection = new \ReflectionClass(ImproveMergeGovernorThroughputWithoutLoweringSafetyContract::class);
        $method = $reflection->getMethod('getCurrentThroughput');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertEquals('int', $returnType->getName());
    }

    public function testGetSafetyThresholdReturnsFloat(): void
    {
        $reflection = new \ReflectionClass(ImproveMergeGovernorThroughputWithoutLoweringSafetyContract::class);
        $method = $reflection->getMethod('getSafetyThreshold');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertEquals('float', $returnType->getName());
    }

    public function testCalculateOptimalBatchSizeAcceptsIntReturnsInt(): void
    {
        $reflection = new \ReflectionClass(ImproveMergeGovernorThroughputWithoutLoweringSafetyContract::class);
        $method = $reflection->getMethod('calculateOptimalBatchSize');

        $parameters = $method->getParameters();
        $this->assertCount(1, $parameters);
        $this->assertEquals('queueDepth', $parameters[0]->getName());

        $paramType = $parameters[0]->getType();
        $this->assertNotNull($paramType);
        $this->assertEquals('int', $paramType->getName());

        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('int', $returnType->getName());
    }

    public function testShouldProceedWithMergeAcceptsIntReturnsBool(): void
    {
        $reflection = new \ReflectionClass(ImproveMergeGovernorThroughputWithoutLoweringSafetyContract::class);
        $method = $reflection->getMethod('shouldProceedWithMerge');

        $parameters = $method->getParameters();
        $this->assertCount(1, $parameters);
        $this->assertEquals('safetyScore', $parameters[0]->getName());

        $paramType = $parameters[0]->getType();
        $this->assertNotNull($paramType);
        $this->assertEquals('int', $paramType->getName());

        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('bool', $returnType->getName());
    }
}
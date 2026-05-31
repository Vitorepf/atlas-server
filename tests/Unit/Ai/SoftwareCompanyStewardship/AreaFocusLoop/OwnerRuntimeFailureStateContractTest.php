<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerRuntimeFailureStateContract;
use PHPUnit\Framework\TestCase;

final class OwnerRuntimeFailureStateContractTest extends TestCase
{
    public function testContractExists(): void
    {
        $this->assertTrue(
            interface_exists(OwnerRuntimeFailureStateContract::class),
            'OwnerRuntimeFailureStateContract interface must exist'
        );
    }

    public function testContractDefinesIsInFailureStateMethod(): void
    {
        $reflection = new \ReflectionClass(OwnerRuntimeFailureStateContract::class);
        $this->assertTrue(
            $reflection->hasMethod('isInFailureState'),
            'Contract must define isInFailureState method'
        );
    }

    public function testIsInFailureStateMethodReturnsBool(): void
    {
        $reflection = new \ReflectionClass(OwnerRuntimeFailureStateContract::class);
        $method = $reflection->getMethod('isInFailureState');
        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame('bool', $returnType->getName());
    }

    public function testContractDefinesGetFailureTypeMethod(): void
    {
        $reflection = new \ReflectionClass(OwnerRuntimeFailureStateContract::class);
        $this->assertTrue(
            $reflection->hasMethod('getFailureType'),
            'Contract must define getFailureType method'
        );
    }

    public function testGetFailureTypeMethodReturnsNullableString(): void
    {
        $reflection = new \ReflectionClass(OwnerRuntimeFailureStateContract::class);
        $method = $reflection->getMethod('getFailureType');
        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertTrue($returnType->allowsNull());
        $this->assertSame('string', $returnType->getName());
    }

    public function testContractDefinesClearFailureMethod(): void
    {
        $reflection = new \ReflectionClass(OwnerRuntimeFailureStateContract::class);
        $this->assertTrue(
            $reflection->hasMethod('clearFailure'),
            'Contract must define clearFailure method'
        );
    }

    public function testClearFailureMethodReturnsVoid(): void
    {
        $reflection = new \ReflectionClass(OwnerRuntimeFailureStateContract::class);
        $method = $reflection->getMethod('clearFailure');
        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame('void', $returnType->getName());
    }
}
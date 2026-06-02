<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\WorkspaceIntelligence;

use App\Services\Ai\WorkspaceIntelligence\ExecutionGateVerdictResolver;
use PHPUnit\Framework\TestCase;

final class ExecutionGateVerdictResolverTest extends TestCase
{
    private ExecutionGateVerdictResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ExecutionGateVerdictResolver();
    }

    public function testMutativeWithNoBlockersAndRuntimeReadyIsAllowedAndReady(): void
    {
        $result = $this->resolver->resolve(true, [], true);

        $this->assertTrue($result['allowed']);
        $this->assertSame('ready', $result['status']);
    }

    public function testMutativeWithBlockersOverridesRuntimeReadyAndIsBlocked(): void
    {
        $result = $this->resolver->resolve(true, ['x'], true);

        $this->assertFalse($result['allowed']);
        $this->assertSame('blocked', $result['status']);
    }

    public function testMutativeWithNoBlockersButRuntimeNotReadyIsAllowedAndLimited(): void
    {
        $result = $this->resolver->resolve(true, [], false);

        $this->assertTrue($result['allowed']);
        $this->assertSame('limited', $result['status']);
    }

    public function testNonMutativeIgnoresBlockersAndIsNeverBlocked(): void
    {
        $result = $this->resolver->resolve(false, ['workspace_not_ready'], false);

        $this->assertTrue($result['allowed']);
        $this->assertSame('limited', $result['status']);
    }

    public function testNonMutativeWithRuntimeReadyIsAllowedAndReady(): void
    {
        $result = $this->resolver->resolve(false, [], true);

        $this->assertTrue($result['allowed']);
        $this->assertSame('ready', $result['status']);
    }
}

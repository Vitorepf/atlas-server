<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\Readiness\CodexReviewMerge\CodexReviewMergeLaterCycleChain;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;
use PHPUnit\Framework\TestCase;

final class CodexReviewMergeLaterCycleChainTest extends TestCase
{
    public function test_resolve_scalar_passthrough_and_hash_op(): void
    {
        $host = new class {
            use CodexReviewMergeLaterCycleChain;

            public function call(mixed $node, array $env, bool $ready, ?array $payload): mixed
            {
                return $this->laterCycleChainResolve($node, $env, $ready, $payload);
            }
        };

        $this->assertSame('x', $host->call('x', [], true, null));
        $payload = ['a' => 1];
        $hash = $host->call(['@h'], [], true, $payload);
        $this->assertSame(ReadinessHash::stable($payload), $hash);
        $this->assertSame('yes', $host->call(['@t', 'yes', 'no'], [], true, null));
        $this->assertSame('no', $host->call(['@t', 'yes', 'no'], [], false, null));
    }
}

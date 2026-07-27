<?php

namespace Tests\Unit\Ai\SelfConstruction;

use PHPUnit\Framework\TestCase;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneScopeLockPlanner;

final class AgentControlPlaneScopeLockPlannerHashHardeningTest extends TestCase
{
    /**
     * An unencodable plan payload (containing a PHP resource) must fail
     * closed with a descriptive hash-computation error instead of silently
     * hashing an empty string and persisting a corrupt plan.
     */
    public function test_json_encode_failure_throws_instead_of_hashing_empty_string(): void
    {
        $planner = new AgentControlPlaneScopeLockPlanner();
        $method = new \ReflectionMethod($planner, 'stableHash');

        // A resource cannot be JSON-encoded — json_encode returns false.
        $unencodable = ['_resource' => fopen('php://memory', 'r')];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('json_encode failed on scope-lock plan payload');
        $method->invoke($planner, $unencodable);
    }

    /**
     * Confirm that a normal encodable payload still produces a valid hash.
     */
    public function test_stable_hash_produces_sha256_for_encodable_payload(): void
    {
        $planner = new AgentControlPlaneScopeLockPlanner();
        $method = new \ReflectionMethod($planner, 'stableHash');

        $payload = [
            'write_set' => ['app/Foo.php', 'app/Bar.php'],
            'read_set' => ['app/Foo.php'],
            'status' => 'planned_safe',
        ];

        $hash = $method->invoke($planner, $payload);

        $this->assertIsString($hash);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    /**
     * Verify idempotency: the same payload always produces the same hash.
     */
    public function test_stable_hash_is_idempotent(): void
    {
        $planner = new AgentControlPlaneScopeLockPlanner();
        $method = new \ReflectionMethod($planner, 'stableHash');

        $payload = [
            'write_set' => ['app/Zoo.php', 'app/Alpha.php'],
            'status' => 'planned_blocked',
            'blocking_reasons' => ['write_set_empty'],
        ];

        $hashA = $method->invoke($planner, $payload);
        $hashB = $method->invoke($planner, $payload);

        $this->assertSame($hashA, $hashB);
    }
}

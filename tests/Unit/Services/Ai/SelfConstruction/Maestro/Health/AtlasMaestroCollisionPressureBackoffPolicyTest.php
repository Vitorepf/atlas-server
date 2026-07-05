<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Maestro\Health;

use App\Services\Ai\SelfConstruction\Maestro\Health\AtlasMaestroCollisionPressureBackoffPolicy;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroCollisionPressureBackoffPolicyTest extends TestCase
{
    private AtlasMaestroCollisionPressureBackoffPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new AtlasMaestroCollisionPressureBackoffPolicy;
    }

    public function test_pre_existing_collisions_produce_pivot_without_blaming_new_targets(): void
    {
        $result = $this->policy->evaluate([
            'pre_existing_collision_count' => 3,
            'new_emitted_collision_count' => 0,
        ]);

        $this->assertSame(AtlasMaestroCollisionPressureBackoffPolicy::ACTION_PIVOT, $result['action']);
        $this->assertFalse($result['blames_new_targets']);
        $this->assertTrue($result['should_pivot']);
    }

    public function test_new_emitted_collisions_produce_hard_backoff(): void
    {
        $result = $this->policy->evaluate([
            'pre_existing_collision_count' => 0,
            'new_emitted_collision_count' => 2,
        ]);

        $this->assertSame(AtlasMaestroCollisionPressureBackoffPolicy::ACTION_BACKOFF, $result['action']);
        $this->assertTrue($result['blames_new_targets']);
    }

    public function test_no_collisions_proceeds(): void
    {
        $result = $this->policy->evaluate([]);

        $this->assertSame(AtlasMaestroCollisionPressureBackoffPolicy::ACTION_PROCEED, $result['action']);
        $this->assertFalse($result['should_pivot']);
    }

    public function test_both_pre_existing_and_new_collisions_backoff(): void
    {
        $result = $this->policy->evaluate([
            'pre_existing_collision_count' => 1,
            'new_emitted_collision_count' => 1,
        ]);

        $this->assertSame(AtlasMaestroCollisionPressureBackoffPolicy::ACTION_BACKOFF, $result['action']);
    }

    public function test_schema_present(): void
    {
        $result = $this->policy->evaluate([]);
        $this->assertSame(AtlasMaestroCollisionPressureBackoffPolicy::SCHEMA, $result['schema']);
    }
}

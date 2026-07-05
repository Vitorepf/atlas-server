<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainGoodhartResistantCreditPolicy;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainGoodhartResistantCreditPolicyTest extends TestCase
{
    private AtlasExternalBrainGoodhartResistantCreditPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new AtlasExternalBrainGoodhartResistantCreditPolicy;
    }

    public function test_raw_task_count_gains_zero_credit(): void
    {
        $result = $this->policy->evaluate(['task_count' => 100]);

        $this->assertSame(0.0, $result['credit']);
        $this->assertFalse($result['goodhart_safe']);
    }

    public function test_raw_wrapper_count_gains_zero_credit(): void
    {
        $result = $this->policy->evaluate(['wrapper_count' => 50]);

        $this->assertSame(0.0, $result['credit']);
        $this->assertFalse($result['goodhart_safe']);
    }

    public function test_closed_loop_learning_receives_credit(): void
    {
        $result = $this->policy->evaluate(['closed_loop_learning_evidence' => true]);

        $this->assertGreaterThan(0.0, $result['credit']);
        $this->assertTrue($result['goodhart_safe']);
    }

    public function test_repair_enabling_receives_credit(): void
    {
        $result = $this->policy->evaluate(['repair_enabling_evidence' => true]);

        $this->assertGreaterThan(0.0, $result['credit']);
        $this->assertTrue($result['goodhart_safe']);
    }

    public function test_capability_delta_receives_credit(): void
    {
        $result = $this->policy->evaluate(['capability_delta' => 'new feature X']);

        $this->assertGreaterThan(0.0, $result['credit']);
        $this->assertTrue($result['goodhart_safe']);
    }

    public function test_proxies_alone_do_not_confer_credit(): void
    {
        $result = $this->policy->evaluate([
            'task_count' => 100,
            'test_count' => 50,
            'wrapper_count' => 20,
            'queue_depth' => 30,
        ]);

        $this->assertSame(0.0, $result['credit']);
        $this->assertFalse($result['goodhart_safe']);
    }

    public function test_schema_present(): void
    {
        $result = $this->policy->evaluate([]);
        $this->assertSame(AtlasExternalBrainGoodhartResistantCreditPolicy::SCHEMA, $result['schema']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\MultiProvider\AtlasMaestroPacketClassifier;
use App\Services\Ai\SelfConstruction\Maestro\MultiProvider\AtlasMaestroProviderAssignmentPolicy;
use DomainException;
use Tests\TestCase;

final class AtlasMaestroProviderAssignmentPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas.provider_defaults.execution_runtime', 'minimax-m3');
        config()->set('atlas.provider_defaults.brain_default', 'codex');
    }

    public function test_grind_and_architecture_primary_providers_follow_memory_anchor(): void
    {
        $policy = new AtlasMaestroProviderAssignmentPolicy;

        $this->assertSame('minimax-m3', $policy->assignmentFor(AtlasMaestroPacketClassifier::GRIND)['primary']);
        $this->assertSame('minimax-m3', $policy->assignmentFor(AtlasMaestroPacketClassifier::DOC)['primary']);
        $this->assertSame('codex-gpt-5-5', $policy->assignmentFor(AtlasMaestroPacketClassifier::ARCHITECTURE)['primary']);
    }

    public function test_grind_primary_follows_execution_runtime_config_value(): void
    {
        config()->set('atlas.provider_defaults.execution_runtime', 'glm-5-2');
        $policy = new AtlasMaestroProviderAssignmentPolicy;

        $this->assertSame('glm-5-2', $policy->assignmentFor(AtlasMaestroPacketClassifier::GRIND)['primary']);
        $this->assertSame('glm-5-2', $policy->assignmentFor(AtlasMaestroPacketClassifier::DOC)['primary']);
    }

    public function test_missing_execution_runtime_config_fails_closed(): void
    {
        config()->set('atlas.provider_defaults.execution_runtime', null);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('atlas_provider_defaults_execution_runtime_missing');
        new AtlasMaestroProviderAssignmentPolicy;
    }

    public function test_missing_referenced_provider_fails_closed_at_construction(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('atlas_maestro_assignment_unknown_provider:architecture:codex-gpt-5-5');

        new AtlasMaestroProviderAssignmentPolicy(new class
        {
            public function providers(): array
            {
                return [
                    'minimax-m3' => [],
                    'glm-5-2' => [],
                    'claude-opus' => [],
                ];
            }
        });
    }

    public function test_queue_repair_and_learning_loop_primary_is_atlas_native(): void
    {
        $policy = new AtlasMaestroProviderAssignmentPolicy;

        $this->assertSame(
            AtlasMaestroProviderAssignmentPolicy::ATLAS_NATIVE,
            $policy->assignmentFor(AtlasMaestroPacketClassifier::QUEUE_REPAIR)['primary'],
        );
        $this->assertSame(
            AtlasMaestroProviderAssignmentPolicy::ATLAS_NATIVE,
            $policy->assignmentFor(AtlasMaestroPacketClassifier::LEARNING_LOOP)['primary'],
        );
    }

    public function test_decide_returns_native_first_reason_when_no_unavailability(): void
    {
        $policy = new AtlasMaestroProviderAssignmentPolicy;
        $decision = $policy->decide(AtlasMaestroPacketClassifier::QUEUE_REPAIR);

        $this->assertSame(AtlasMaestroProviderAssignmentPolicy::ATLAS_NATIVE, $decision['provider']);
        $this->assertSame('native-first', $decision['reason']);
        $this->assertFalse($decision['failover']);
        $this->assertSame([], $decision['tried']);
    }

    public function test_decide_falls_back_with_failover_reason_when_native_unavailable(): void
    {
        $policy = new AtlasMaestroProviderAssignmentPolicy;
        $decision = $policy->decide(
            AtlasMaestroPacketClassifier::QUEUE_REPAIR,
            [AtlasMaestroProviderAssignmentPolicy::ATLAS_NATIVE],
        );

        $this->assertNotSame(AtlasMaestroProviderAssignmentPolicy::ATLAS_NATIVE, $decision['provider']);
        $this->assertSame('failover:preferred-unavailable', $decision['reason']);
        $this->assertTrue($decision['failover']);
        $this->assertContains(AtlasMaestroProviderAssignmentPolicy::ATLAS_NATIVE, $decision['tried']);
    }

    public function test_every_class_has_non_empty_fallback(): void
    {
        $policy = new AtlasMaestroProviderAssignmentPolicy;

        foreach ([
            AtlasMaestroPacketClassifier::ARCHITECTURE,
            AtlasMaestroPacketClassifier::MULTI_FILE,
            AtlasMaestroPacketClassifier::GRIND,
            AtlasMaestroPacketClassifier::DOC,
            AtlasMaestroPacketClassifier::QUEUE_REPAIR,
            AtlasMaestroPacketClassifier::LEARNING_LOOP,
        ] as $class) {
            $assignment = $policy->assignmentFor($class);
            $this->assertNotSame('', $assignment['primary']);
            $this->assertNotEmpty($assignment['fallback']);
        }
    }
}

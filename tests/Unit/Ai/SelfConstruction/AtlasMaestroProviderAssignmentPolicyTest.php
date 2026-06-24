<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\MultiProvider\AtlasMaestroPacketClassifier;
use App\Services\Ai\SelfConstruction\Maestro\MultiProvider\AtlasMaestroProviderAssignmentPolicy;
use DomainException;
use Tests\TestCase;

final class AtlasMaestroProviderAssignmentPolicyTest extends TestCase
{
    public function test_grind_and_architecture_primary_providers_follow_memory_anchor(): void
    {
        $policy = new AtlasMaestroProviderAssignmentPolicy;

        $this->assertSame('minimax-m3', $policy->assignmentFor(AtlasMaestroPacketClassifier::GRIND)['primary']);
        $this->assertSame('codex-gpt-5-5', $policy->assignmentFor(AtlasMaestroPacketClassifier::ARCHITECTURE)['primary']);
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

    public function test_every_class_has_non_empty_fallback(): void
    {
        $policy = new AtlasMaestroProviderAssignmentPolicy;

        foreach ([
            AtlasMaestroPacketClassifier::ARCHITECTURE,
            AtlasMaestroPacketClassifier::MULTI_FILE,
            AtlasMaestroPacketClassifier::GRIND,
            AtlasMaestroPacketClassifier::DOC,
        ] as $class) {
            $assignment = $policy->assignmentFor($class);
            $this->assertNotSame('', $assignment['primary']);
            $this->assertNotEmpty($assignment['fallback']);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOperatorIndependenceVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AtlasExternalBrainOperatorIndependenceVerifierTest extends TestCase
{
    private function verifier(): AtlasExternalBrainOperatorIndependenceVerifier
    {
        return new AtlasExternalBrainOperatorIndependenceVerifier;
    }

    #[DataProvider('blockingDependencyTypes')]
    public function test_dependency_on_critical_path_is_blocking_unless_proven_bootstrap_only(string $depType): void
    {
        $blocked = $this->verifier()->verify([
            'plan_id' => 'plan-1',
            'steps' => [
                ['step' => 'step-a', 'dependency_type' => $depType, 'on_critical_path' => true],
            ],
        ]);

        $this->assertFalse($blocked['passed']);
        $this->assertSame($depType, $blocked['blocking_dependencies'][0]['dependency_type']);

        $bootstrapProven = $this->verifier()->verify([
            'plan_id' => 'plan-2',
            'steps' => [
                [
                    'step' => 'step-a',
                    'dependency_type' => $depType,
                    'on_critical_path' => true,
                    'is_bootstrap_only' => true,
                    'atlas_native_path_exists' => true,
                ],
            ],
        ]);

        $this->assertTrue($bootstrapProven['passed']);
        $this->assertSame([], $bootstrapProven['blocking_dependencies']);
        $this->assertSame(
            'bootstrap_only_with_proven_atlas_native_path',
            $bootstrapProven['optional_dependencies'][0]['reason'],
        );
    }

    public static function blockingDependencyTypes(): array
    {
        return [
            'human' => [AtlasExternalBrainOperatorIndependenceVerifier::DEP_HUMAN],
            'operator' => [AtlasExternalBrainOperatorIndependenceVerifier::DEP_OPERATOR],
            'claude_codex' => [AtlasExternalBrainOperatorIndependenceVerifier::DEP_CLAUDE_CODEX],
            'external_provider' => [AtlasExternalBrainOperatorIndependenceVerifier::DEP_EXTERNAL_PROVIDER],
        ];
    }

    public function test_exceptional_audit_or_policy_review_by_human_or_operator_is_optional_and_does_not_break_autonomy(): void
    {
        $result = $this->verifier()->verify([
            'plan_id' => 'plan-3',
            'steps' => [
                [
                    'step' => 'periodic-audit',
                    'dependency_type' => AtlasExternalBrainOperatorIndependenceVerifier::DEP_HUMAN,
                    'on_critical_path' => true,
                    'dependency_context' => AtlasExternalBrainOperatorIndependenceVerifier::CONTEXT_EXCEPTIONAL,
                ],
            ],
        ]);

        $this->assertTrue($result['passed']);
        $this->assertSame('fully_autonomous_steady_state', $result['autonomy_status']);
        $this->assertSame([], $result['blocking_dependencies']);
        $this->assertSame(
            'exceptional_audit_or_policy_review_not_steady_state',
            $result['optional_dependencies'][0]['reason'],
        );
    }

    public function test_next_unblock_action_is_severity_ordered_and_points_to_atlas_native_replacement(): void
    {
        $result = $this->verifier()->verify([
            'plan_id' => 'plan-4',
            'steps' => [
                ['step' => 'external-step', 'dependency_type' => AtlasExternalBrainOperatorIndependenceVerifier::DEP_EXTERNAL_PROVIDER, 'on_critical_path' => true],
                ['step' => 'human-step', 'dependency_type' => AtlasExternalBrainOperatorIndependenceVerifier::DEP_HUMAN, 'on_critical_path' => true],
            ],
        ]);

        $this->assertFalse($result['passed']);
        // human is first in severity order regardless of input order.
        $this->assertSame(AtlasExternalBrainOperatorIndependenceVerifier::DEP_HUMAN, $result['blocking_dependencies'][0]['dependency_type']);
        $this->assertSame('replace_human_step_with_atlas_native_automation', $result['next_unblock_action']);
    }
}

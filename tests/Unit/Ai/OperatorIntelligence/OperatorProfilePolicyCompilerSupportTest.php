<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\OperatorIntelligence;

use App\Services\Ai\OperatorIntelligence\Support\OperatorProfilePolicyCompilerSupport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OperatorProfilePolicyCompilerSupportTest extends TestCase
{
    #[Test]
    public function effect_prefers_explicit_allowed_value(): void
    {
        $this->assertSame(
            'approval_gate',
            OperatorProfilePolicyCompilerSupport::effect(
                ['effect' => 'approval_gate'],
                'OP-001',
                'op_001.preference',
                'some summary',
            ),
        );
    }

    #[Test]
    public function effect_ignores_unknown_explicit_and_maps_col_to_response_style(): void
    {
        $this->assertSame(
            'response_style',
            OperatorProfilePolicyCompilerSupport::effect(
                ['effect' => 'not_a_real_effect'],
                'COL-156',
                'col_156.collaboration',
                'prefers short answers',
            ),
        );
    }

    #[Test]
    public function effect_maps_boundary_key_and_nao_mexa_summary_to_do_not_do(): void
    {
        $this->assertSame(
            'do_not_do',
            OperatorProfilePolicyCompilerSupport::effect(
                [],
                'OP-140',
                'op_140.boundary.prod',
                'never touch production',
            ),
        );
        $this->assertSame(
            'do_not_do',
            OperatorProfilePolicyCompilerSupport::effect(
                [],
                'OP-140',
                'op_140.preference',
                'Nao mexa no vault sem pedir',
            ),
        );
    }

    #[Test]
    public function effect_defaults_to_context_hint(): void
    {
        $this->assertSame(
            'context_hint',
            OperatorProfilePolicyCompilerSupport::effect(
                [],
                'OP-071',
                'op_071.preference',
                'likes morning reviews',
            ),
        );
    }

    #[Test]
    public function priority_scales_by_effect_and_confidence(): void
    {
        $this->assertSame(95, OperatorProfilePolicyCompilerSupport::priority('do_not_do', 0.0));
        $this->assertSame(100, OperatorProfilePolicyCompilerSupport::priority('do_not_do', 1.0));
        $this->assertSame(90, OperatorProfilePolicyCompilerSupport::priority('approval_gate', 0.0));
        $this->assertSame(90, OperatorProfilePolicyCompilerSupport::priority('autonomy_limit', 0.0));
        $this->assertSame(70, OperatorProfilePolicyCompilerSupport::priority('workflow_preference', 0.0));
        $this->assertSame(70, OperatorProfilePolicyCompilerSupport::priority('tool_preference', 0.0));
        $this->assertSame(70, OperatorProfilePolicyCompilerSupport::priority('handoff_preference', 0.0));
        $this->assertSame(60, OperatorProfilePolicyCompilerSupport::priority('response_style', 0.0));
        $this->assertSame(50, OperatorProfilePolicyCompilerSupport::priority('context_hint', 0.0));
        $this->assertSame(53, OperatorProfilePolicyCompilerSupport::priority('context_hint', 0.5));
        $this->assertSame(55, OperatorProfilePolicyCompilerSupport::priority('context_hint', 1.0));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\OperatorIntelligence;

use App\Services\Ai\OperatorIntelligence\Support\OperatorLearningCandidateSupport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OperatorLearningCandidateSupportTest extends TestCase
{
    #[Test]
    public function profile_key_normalizes_taxonomy_and_kind(): void
    {
        $this->assertSame(
            'op_124.operator_preference',
            OperatorLearningCandidateSupport::profileKey('OP-124', 'operator_preference'),
        );
    }

    #[Test]
    public function effect_for_taxonomy_maps_boundary_and_collaboration(): void
    {
        $this->assertSame('do_not_do', OperatorLearningCandidateSupport::effectForTaxonomy('OP-140', 'operator_boundary'));
        $this->assertSame('response_style', OperatorLearningCandidateSupport::effectForTaxonomy('COL-156', 'operator_preference'));
        $this->assertSame('context_hint', OperatorLearningCandidateSupport::effectForTaxonomy('OP-071', 'operator_preference'));
    }
}

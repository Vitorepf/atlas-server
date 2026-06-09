<?php

namespace Tests\Unit\Ai\OperatorIntelligence;

use App\Services\Ai\OperatorIntelligence\OperatorLearningGate;
use App\Services\Ai\OperatorIntelligence\OperatorTaxonomyRegistry;
use Tests\TestCase;

final class OperatorLearningGateTest extends TestCase
{
    public function test_low_risk_high_confidence_signal_with_trusted_provenance_is_auto_apply_eligible(): void
    {
        config(['atlas_operator_intelligence.min_auto_apply_confidence' => 0.85]);

        $gate = app(OperatorLearningGate::class)->evaluate([
            'privacy_class' => 'normal',
            'risk_level' => 'low',
            'confidence' => 0.95,
            'scope_type' => 'global',
            'taxonomy_item_id' => 'COL-156',
            'metadata' => ['auto_apply_provenance' => OperatorLearningGate::AUTO_APPLY_PROVENANCE],
        ]);

        $this->assertFalse($gate['requires_confirmation']);
        $this->assertTrue($gate['auto_apply_eligible']);
        $this->assertSame('candidate', $gate['status']);
    }

    public function test_identical_signal_without_trusted_provenance_can_never_auto_apply(): void
    {
        config(['atlas_operator_intelligence.min_auto_apply_confidence' => 0.85]);

        // Same safe signal but from an untrusted producer (e.g. the passive regex detector,
        // no provenance marker) — it captures for review but can NEVER auto-apply.
        $gate = app(OperatorLearningGate::class)->evaluate([
            'privacy_class' => 'normal',
            'risk_level' => 'low',
            'confidence' => 0.95,
            'scope_type' => 'global',
            'taxonomy_item_id' => 'COL-156',
        ]);

        $this->assertFalse($gate['auto_apply_eligible']);
        $this->assertFalse($gate['gate_receipt']['trusted_producer']);
        $this->assertContains('auto_apply_requires_trusted_provenance', $gate['gate_receipt']['reasons']);
    }

    public function test_registry_high_stakes_item_can_never_auto_apply_even_with_provenance(): void
    {
        config(['atlas_operator_intelligence.min_auto_apply_confidence' => 0.85]);

        // OP-145 ("what Atlas can never touch") is registry high-stakes — blocked at the
        // gate for ANY producer, even a trusted one claiming high confidence.
        $gate = app(OperatorLearningGate::class)->evaluate([
            'privacy_class' => 'normal',
            'risk_level' => 'low',
            'confidence' => 0.99,
            'scope_type' => 'project',
            'taxonomy_item_id' => 'OP-145',
            'metadata' => ['auto_apply_provenance' => OperatorLearningGate::AUTO_APPLY_PROVENANCE],
        ]);

        $this->assertTrue($gate['requires_confirmation']);
        $this->assertFalse($gate['auto_apply_eligible']);
        $this->assertContains('registry_high_stakes_requires_review', $gate['gate_receipt']['reasons']);
    }

    public function test_private_or_low_confidence_signal_requires_review(): void
    {
        $gate = app(OperatorLearningGate::class)->evaluate([
            'privacy_class' => 'private',
            'risk_level' => 'low',
            'confidence' => 0.6,
            'scope_type' => 'global',
        ]);

        $this->assertTrue($gate['requires_confirmation']);
        $this->assertFalse($gate['auto_apply_eligible']);
        $this->assertSame('needs_review', $gate['status']);
        $this->assertContains('privacy_requires_review:private', $gate['gate_receipt']['reasons']);
    }

    public function test_unavailable_taxonomy_fails_closed_to_review(): void
    {
        config(['atlas_operator_intelligence.min_auto_apply_confidence' => 0.85]);

        // Canon doc unreadable → empty registry. The registry-safety consult would
        // otherwise treat every id as benign; instead it must route everything to review.
        $registry = new OperatorTaxonomyRegistry();
        $registry->setDocPathForTesting('/nonexistent/atlas-taxonomy-missing.md');
        $gate = new OperatorLearningGate($registry);

        $result = $gate->evaluate([
            'privacy_class' => 'normal',
            'risk_level' => 'low',
            'confidence' => 0.95,
            'scope_type' => 'project',
            'taxonomy_item_id' => 'COL-156',
            'metadata' => ['auto_apply_provenance' => OperatorLearningGate::AUTO_APPLY_PROVENANCE],
        ]);

        $this->assertTrue($result['requires_confirmation']);
        $this->assertFalse($result['auto_apply_eligible']);
        $this->assertContains('taxonomy_unavailable_fail_closed', $result['gate_receipt']['reasons']);

        $registry->setDocPathForTesting(null); // restore for other tests
    }
}

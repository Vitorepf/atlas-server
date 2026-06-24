<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\ConversionAuditor;
use PHPUnit\Framework\TestCase;

/**
 * The honesty layer (cycles 43-45 meta-lesson operationalized): the audit must label which signals are
 * structural TRUTH vs heuristic vocabulary PRIORS, so a prior is never mistaken for proven conversion.
 */
class ConversionAuditorHonestyTest extends TestCase
{
    public function test_audit_labels_signal_confidence(): void
    {
        $audit = (new ConversionAuditor)->audit('For women over 40 who tried everything: it was never your willpower.');

        $this->assertArrayHasKey('signal_confidence', $audit);
        $sc = $audit['signal_confidence'];

        // Structural-truth facts are labeled as such.
        $this->assertContains('structural_flaws', $sc['structural_truth']);

        // Vocabulary/heuristic scores are honestly demoted to priors — NOT proven conversion.
        $this->assertContains('by_library', $sc['heuristic_prior']);
        $this->assertContains('audience_score', $sc['heuristic_prior']);

        $this->assertNotEmpty($sc['note']);
        $this->assertStringContainsString('NÃO conversão provada', $sc['note']);
    }

    public function test_library_and_audience_are_never_labeled_structural_truth(): void
    {
        $sc = (new ConversionAuditor)->audit('Some sample copy with a guarantee and a study.')['signal_confidence'];

        $this->assertNotContains('by_library', $sc['structural_truth']);
        $this->assertNotContains('audience_score', $sc['structural_truth']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition;

use App\Services\Ai\Cognition\CognitiveImmunePromotionGateEvaluator;
use PHPUnit\Framework\TestCase;

final class CognitiveImmunePromotionGateEvaluatorTest extends TestCase
{
    private CognitiveImmunePromotionGateEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new CognitiveImmunePromotionGateEvaluator();
    }

    /**
     * A fully-clean candidate: consent + retention + privacy, a complete atomic
     * claim, a future signal, provider-safe, no contradiction, outcome
     * validated, recognised scope, an auto promotion mode and no probation.
     *
     * @return array<string,mixed>
     */
    private function cleanTrustedSignals(): array
    {
        return [
            'consent_granted' => true,
            'privacy_class' => 'internal',
            'retention_ok' => true,
            'atomic_claim_present' => true,
            'claim_type' => 'technical_learning_candidate',
            'claim_source_present' => true,
            'future_utility' => true,
            'novelty' => true,
            'recurrence_count' => 3,
            'provider_safe' => true,
            'contains_secret' => false,
            'contains_sensitive_unnecessary' => false,
            'contradicts_newer' => false,
            'outcome_validated' => true,
            'scope' => 'project',
            'promotion_mode_hint' => 'auto',
            'on_probation' => false,
        ];
    }

    public function test_schema_version_is_canonical(): void
    {
        $result = $this->evaluator->evaluate($this->cleanTrustedSignals());

        $this->assertSame(
            'atlas.cognition.cognitive_immune_promotion_gate.v1',
            $result['schema_version'],
        );
    }

    public function test_fully_clean_candidate_is_trusted_with_every_gate_pass(): void
    {
        $result = $this->evaluator->evaluate($this->cleanTrustedSignals());

        foreach (['G0', 'G1', 'G2', 'G3', 'G4', 'G5', 'G6', 'G7', 'G8'] as $gateId) {
            $this->assertSame(
                'pass',
                $result['gate_statuses'][$gateId],
                "gate {$gateId} must pass on a fully-clean candidate",
            );
        }

        $this->assertSame('trusted', $result['promotion_status']);
        $this->assertSame([], $result['blocking_gate_ids']);
        $this->assertSame([], $result['pending_gate_ids']);
        $this->assertSame([], $result['reasons']);
        $this->assertTrue($result['autonomous_promotion_allowed']);
    }

    public function test_contains_secret_blocks_g3_and_blocks_promotion(): void
    {
        $signals = $this->cleanTrustedSignals();
        $signals['contains_secret'] = true;

        $result = $this->evaluator->evaluate($signals);

        $this->assertSame('block', $result['gate_statuses']['G3']);
        $this->assertSame('blocked', $result['promotion_status']);
        $this->assertContains('G3', $result['blocking_gate_ids']);
        $this->assertSame('secret_or_sensitive_present', $result['reasons']['G3']);
        $this->assertFalse($result['autonomous_promotion_allowed']);
    }

    public function test_clean_candidate_on_probation_is_watch_and_not_autonomous(): void
    {
        $signals = $this->cleanTrustedSignals();
        $signals['on_probation'] = true;

        $result = $this->evaluator->evaluate($signals);

        $this->assertSame('watch', $result['promotion_status']);
        $this->assertFalse($result['autonomous_promotion_allowed']);
        $this->assertSame('pending', $result['gate_statuses']['G8']);
        $this->assertContains('G8', $result['pending_gate_ids']);
        $this->assertSame([], $result['blocking_gate_ids']);
        $this->assertSame('probation_not_cleared', $result['reasons']['G8']);
    }

    public function test_missing_outcome_validation_leaves_g5_pending_and_status_candidate(): void
    {
        $signals = $this->cleanTrustedSignals();
        unset($signals['outcome_validated']);

        $result = $this->evaluator->evaluate($signals);

        $this->assertSame('pending', $result['gate_statuses']['G5']);
        $this->assertSame('candidate', $result['promotion_status']);
        $this->assertNotSame('trusted', $result['promotion_status']);
        $this->assertFalse($result['autonomous_promotion_allowed']);
        $this->assertContains('G5', $result['pending_gate_ids']);
        $this->assertSame('outcome_not_validated', $result['reasons']['G5']);
    }

    public function test_empty_signals_are_unclassified_with_no_gate_spuriously_pass(): void
    {
        $result = $this->evaluator->evaluate([]);

        $this->assertContains($result['promotion_status'], ['unclassified', 'blocked']);
        $this->assertSame('unclassified', $result['promotion_status']);
        $this->assertFalse($result['autonomous_promotion_allowed']);

        foreach (['G0', 'G1', 'G2', 'G3', 'G4', 'G5', 'G6', 'G7', 'G8'] as $gateId) {
            $this->assertNotSame(
                'pass',
                $result['gate_statuses'][$gateId],
                "gate {$gateId} must not spuriously pass on empty signals",
            );
        }

        $this->assertSame([], $result['blocking_gate_ids']);
    }

    public function test_identical_signals_yield_identical_output(): void
    {
        $signals = $this->cleanTrustedSignals();

        $first = $this->evaluator->evaluate($signals);
        $second = $this->evaluator->evaluate($signals);

        $this->assertSame($first, $second);
    }

    public function test_canonical_key_order_is_stable(): void
    {
        $result = $this->evaluator->evaluate($this->cleanTrustedSignals());

        $this->assertSame(
            [
                'schema_version',
                'gate_statuses',
                'promotion_status',
                'blocking_gate_ids',
                'pending_gate_ids',
                'reasons',
                'autonomous_promotion_allowed',
            ],
            array_keys($result),
        );

        $this->assertSame(
            ['G0', 'G1', 'G2', 'G3', 'G4', 'G5', 'G6', 'G7', 'G8'],
            array_keys($result['gate_statuses']),
        );
    }

    public function test_provider_unsafe_flag_blocks_g3_independently_of_secret(): void
    {
        $signals = $this->cleanTrustedSignals();
        $signals['contains_secret'] = false;
        $signals['provider_safe'] = false;

        $result = $this->evaluator->evaluate($signals);

        $this->assertSame('block', $result['gate_statuses']['G3']);
        $this->assertSame('secret_or_sensitive_present', $result['reasons']['G3']);
        $this->assertSame('blocked', $result['promotion_status']);
    }

    public function test_contradiction_with_newer_authority_blocks_g4(): void
    {
        $signals = $this->cleanTrustedSignals();
        $signals['contradicts_newer'] = true;

        $result = $this->evaluator->evaluate($signals);

        $this->assertSame('block', $result['gate_statuses']['G4']);
        $this->assertContains('G4', $result['blocking_gate_ids']);
        $this->assertSame('contradicts_newer_authority', $result['reasons']['G4']);
        $this->assertSame('blocked', $result['promotion_status']);
        $this->assertFalse($result['autonomous_promotion_allowed']);
    }

    public function test_explicit_block_promotion_mode_blocks_g7(): void
    {
        $signals = $this->cleanTrustedSignals();
        $signals['promotion_mode_hint'] = 'blocked';

        $result = $this->evaluator->evaluate($signals);

        $this->assertSame('block', $result['gate_statuses']['G7']);
        $this->assertSame('promotion_blocked_by_policy', $result['reasons']['G7']);
        $this->assertSame('blocked', $result['promotion_status']);
    }

    public function test_recurrence_alone_satisfies_g2_signal_gate(): void
    {
        $signals = $this->cleanTrustedSignals();
        $signals['future_utility'] = false;
        $signals['novelty'] = false;
        $signals['recurrence_count'] = 2;

        $result = $this->evaluator->evaluate($signals);

        $this->assertSame('pass', $result['gate_statuses']['G2']);

        $signals['recurrence_count'] = 1;
        $belowThreshold = $this->evaluator->evaluate($signals);

        $this->assertSame('pending', $belowThreshold['gate_statuses']['G2']);
        $this->assertSame('future_signal_unconfirmed', $belowThreshold['reasons']['G2']);
    }

    public function test_unrecognised_scope_leaves_g6_pending(): void
    {
        $signals = $this->cleanTrustedSignals();
        $signals['scope'] = 'galaxy';

        $result = $this->evaluator->evaluate($signals);

        $this->assertSame('pending', $result['gate_statuses']['G6']);
        $this->assertSame('scope_unresolved', $result['reasons']['G6']);
        $this->assertSame('candidate', $result['promotion_status']);
    }

    public function test_incomplete_capture_leaves_g0_pending(): void
    {
        $signals = $this->cleanTrustedSignals();
        $signals['retention_ok'] = false;

        $result = $this->evaluator->evaluate($signals);

        $this->assertSame('pending', $result['gate_statuses']['G0']);
        $this->assertSame('consent_privacy_retention_unconfirmed', $result['reasons']['G0']);
        $this->assertSame('unclassified', $result['promotion_status']);
    }

    public function test_multiple_blocks_are_all_reported(): void
    {
        $signals = $this->cleanTrustedSignals();
        $signals['contains_secret'] = true;
        $signals['contradicts_newer'] = true;

        $result = $this->evaluator->evaluate($signals);

        $this->assertContains('G3', $result['blocking_gate_ids']);
        $this->assertContains('G4', $result['blocking_gate_ids']);
        $this->assertSame('blocked', $result['promotion_status']);
        $this->assertArrayHasKey('G3', $result['reasons']);
        $this->assertArrayHasKey('G4', $result['reasons']);
    }
}

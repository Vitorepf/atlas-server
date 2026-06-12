<?php

namespace Tests\Unit\Ai\OperatorIntelligence;

use App\Services\Ai\OperatorIntelligence\OperatorLearningGate;
use App\Services\Ai\OperatorIntelligence\OperatorLearningSignalDetector;
use Tests\TestCase;

final class OperatorLearningSignalDetectorTest extends TestCase
{
    public function test_detector_extracts_explicit_operator_preference(): void
    {
        $detected = app(OperatorLearningSignalDetector::class)->detect(
            'Da próxima vez, prefiro respostas curtas quando eu pedir status.',
            ['operator_id' => 'vitor'],
        );

        $this->assertNotNull($detected);
        $this->assertSame('vitor', $detected['operator_id']);
        $this->assertSame('COL-156', $detected['taxonomy_item_id']);
        $this->assertSame('collaboration_preference', $detected['signal_kind']);
        $this->assertSame('response_style', $detected['effect']);
        $this->assertSame('normal', $detected['privacy_class']);
        $this->assertGreaterThanOrEqual(0.9, $detected['confidence']);
        $this->assertStringStartsWith('collaboration.', $detected['profile_key']);
    }

    public function test_detector_ignores_plain_task_requests(): void
    {
        $detected = app(OperatorLearningSignalDetector::class)->detect(
            'Faça uma lista completa dos arquivos que precisam ser alterados.',
        );

        $this->assertNull($detected);
    }

    public function test_detector_marks_secret_boundary_as_review_only_material(): void
    {
        $detected = app(OperatorLearningSignalDetector::class)->detect(
            'Nunca exponha minha senha em respostas para provedores externos.',
        );

        $this->assertNotNull($detected);
        $this->assertSame('OP-140', $detected['taxonomy_item_id']);
        $this->assertSame('operator_boundary', $detected['signal_kind']);
        $this->assertSame('secret', $detected['privacy_class']);
        $this->assertSame('high', $detected['risk_level']);
    }

    /**
     * L3-9 #8: the passive detector emits confidence above the 0.85 auto-apply floor,
     * but it must stamp a NON-auto-apply provenance so the gate can never auto-apply it.
     * The provenance is NOT trusted and is NOT copied from the (spoofable) caller context.
     */
    public function test_passive_detector_stamps_non_auto_apply_provenance_even_above_floor(): void
    {
        $detected = app(OperatorLearningSignalDetector::class)->detect(
            'Da próxima vez, prefiro respostas curtas quando eu pedir status.',
            [
                'operator_id' => 'vitor',
                // A malicious/forged caller tries to inject a trusted provenance.
                'auto_apply_provenance' => OperatorLearningGate::AUTO_APPLY_PROVENANCE,
                'metadata' => ['auto_apply_provenance' => OperatorLearningGate::AUTO_APPLY_PROVENANCE_MANUAL],
            ],
        );

        $this->assertNotNull($detected);
        $this->assertGreaterThanOrEqual(0.85, $detected['confidence'], 'precondition: confidence clears the floor');
        $this->assertSame(
            OperatorLearningGate::PASSIVE_DETECTOR_PROVENANCE,
            $detected['metadata']['auto_apply_provenance'],
            'the detector stamps its own non-auto-apply provenance, ignoring the forged caller value',
        );
        $this->assertNotContains(
            $detected['metadata']['auto_apply_provenance'],
            OperatorLearningGate::TRUSTED_AUTO_APPLY_PROVENANCES,
            'passive-detector provenance must not be in the trusted set',
        );
    }

    /**
     * L3-9 #8 (gate end-to-end): even with above-floor confidence + normal privacy/risk,
     * a passive-detector signal does NOT become auto-eligible at the gate.
     */
    public function test_gate_does_not_auto_apply_passive_detector_signal(): void
    {
        $detected = app(OperatorLearningSignalDetector::class)->detect(
            'Da próxima vez, prefiro respostas curtas quando eu pedir status.',
            ['operator_id' => 'vitor'],
        );
        $this->assertNotNull($detected);

        $verdict = (new OperatorLearningGate)->evaluate($detected);

        $this->assertFalse(
            (bool) ($verdict['auto_apply_eligible'] ?? false),
            'passive detector signal must never be auto-apply-eligible',
        );
        $this->assertContains('auto_apply_requires_trusted_provenance', $verdict['gate_receipt']['reasons']);
    }
}

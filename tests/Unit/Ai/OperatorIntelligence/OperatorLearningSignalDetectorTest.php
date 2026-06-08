<?php

namespace Tests\Unit\Ai\OperatorIntelligence;

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
}

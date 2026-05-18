<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Vox;

use App\Services\Ai\Vox\VoxRiskClassifier;
use App\Services\Ai\Vox\VoxSchema;
use PHPUnit\Framework\TestCase;

final class VoxRiskClassifierTest extends TestCase
{
    public function test_dictation_is_classified_as_r0_with_humanly_readable_reasoning(): void
    {
        $classifier = new VoxRiskClassifier();
        $result = $classifier->classify(['text' => 'qualquer ditado'], VoxSchema::MODE_DICTATION);
        $this->assertSame('R0', $result['risk_class']);
        $this->assertStringContainsString('dictation', $result['risk_reasoning']);
        $this->assertStringContainsString('clipboard', $result['risk_reasoning']);
    }

    public function test_prompt_polish_is_classified_as_r0_with_polish_reasoning(): void
    {
        $classifier = new VoxRiskClassifier();
        $result = $classifier->classify(['text' => 'polir esse texto'], VoxSchema::MODE_PROMPT_POLISH);
        $this->assertSame('R0', $result['risk_class']);
        $this->assertStringContainsString('prompt polish', $result['risk_reasoning']);
        $this->assertStringContainsString('clipboard', $result['risk_reasoning']);
    }

    public function test_intent_compile_with_extractor_signal_returns_classifier_label(): void
    {
        $classifier = new VoxRiskClassifier();
        $result = $classifier->classify(['text' => 'x'], VoxSchema::MODE_INTENT_COMPILE, [
            'risk_class' => 'R3',
            'risk_reasoning' => 'comando externo proposto',
        ]);
        $this->assertSame('R3', $result['risk_class']);
        $this->assertSame('comando externo proposto', $result['risk_reasoning']);
    }

    public function test_intent_compile_without_signal_defaults_to_r0(): void
    {
        $classifier = new VoxRiskClassifier();
        $result = $classifier->classify(['text' => 'x'], VoxSchema::MODE_INTENT_COMPILE);
        $this->assertSame('R0', $result['risk_class']);
    }

    public function test_intent_compile_ignores_invalid_signal_class(): void
    {
        $classifier = new VoxRiskClassifier();
        $result = $classifier->classify(['text' => 'x'], VoxSchema::MODE_INTENT_COMPILE, [
            'risk_class' => 'R9',
            'risk_reasoning' => 'invalid',
        ]);
        $this->assertSame('R0', $result['risk_class']);
    }

    public function test_classifier_refuses_unimplemented_modes(): void
    {
        $classifier = new VoxRiskClassifier();
        $this->expectException(\LogicException::class);
        // governed_execute is supported now (V3 / Wave 6). Use a sentinel
        // mode the classifier does not know to keep covering the failure
        // branch.
        $classifier->classify(['text' => 'x'], 'voice_realtime_streaming_v6');
    }

    public function test_classifier_supports_governed_execute_mode(): void
    {
        $classifier = new VoxRiskClassifier();
        $result = $classifier->classify(['text' => 'x'], VoxSchema::MODE_GOVERNED_EXECUTE, [
            'risk_class' => VoxSchema::RISK_R2,
            'risk_reasoning' => 'edição local solicitada via fala',
        ]);
        $this->assertSame(VoxSchema::RISK_R2, $result['risk_class']);
        $this->assertSame('edição local solicitada via fala', $result['risk_reasoning']);
    }
}

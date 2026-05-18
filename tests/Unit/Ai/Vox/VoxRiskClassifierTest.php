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

    public function test_classifier_refuses_unimplemented_modes(): void
    {
        $classifier = new VoxRiskClassifier();
        $this->expectException(\LogicException::class);
        $classifier->classify(['text' => 'x'], VoxSchema::MODE_GOVERNED_EXECUTE);
    }
}

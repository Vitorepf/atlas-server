<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\OperatorIntelligence;

use App\Services\Ai\OperatorIntelligence\Support\OperatorLearningDetectSupport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OperatorLearningDetectSupportTest extends TestCase
{
    #[Test]
    public function matching_sentence_and_family_table(): void
    {
        $claim = OperatorLearningDetectSupport::matchingSentence(
            'Da próxima vez, prefiro respostas curtas quando eu pedir status.',
        );
        $this->assertNotNull($claim);
        $normalized = OperatorLearningDetectSupport::normalizeForMatch((string) $claim);
        // Family table is ordered; "prefiro" hits preference before "da proxima vez".
        $this->assertSame('preference', OperatorLearningDetectSupport::matchedFamily($normalized));
        // signalKind still elevates to collaboration when "status"/pergunta appears.
        $this->assertSame('collaboration_preference', OperatorLearningDetectSupport::signalKind($normalized));
        $this->assertSame('COL-156', OperatorLearningDetectSupport::taxonomy($normalized, 'collaboration_preference'));
        $this->assertSame('response_style', OperatorLearningDetectSupport::effect('COL-156', 'collaboration_preference'));
        $this->assertGreaterThanOrEqual(0.9, OperatorLearningDetectSupport::confidence($normalized, 'collaboration_preference'));
    }

    #[Test]
    public function ignores_plain_tasks_and_maps_boundary(): void
    {
        $this->assertNull(OperatorLearningDetectSupport::matchingSentence(
            'Faça uma lista completa dos arquivos que precisam ser alterados.',
        ));

        $boundary = OperatorLearningDetectSupport::normalizeForMatch('Nunca exponha minha senha em respostas.');
        $this->assertSame('boundary', OperatorLearningDetectSupport::matchedFamily($boundary));
        $this->assertSame('operator_boundary', OperatorLearningDetectSupport::signalKind($boundary));
        $this->assertSame('OP-140', OperatorLearningDetectSupport::taxonomy($boundary, 'operator_boundary'));
        $this->assertSame('do_not_do', OperatorLearningDetectSupport::effect('OP-140', 'operator_boundary'));
    }
}

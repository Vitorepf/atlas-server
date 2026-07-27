<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\OperatorIntelligence;

use App\Services\Ai\OperatorIntelligence\Support\OperatorLearningRuntimeCaptureSupport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OperatorLearningRuntimeCaptureSupportTest extends TestCase
{
    #[Test]
    public function declared_operator_words_only(): void
    {
        $this->assertSame(
            'o que eu digitei',
            OperatorLearningRuntimeCaptureSupport::declaredOperatorWords([
                'payload' => ['operator_text' => '  o que eu digitei  '],
            ]),
        );
        // Sem declaração não há fallback: o texto do fio é o prompt montado.
        $this->assertNull(OperatorLearningRuntimeCaptureSupport::declaredOperatorWords(['payload' => []]));
    }

    #[Test]
    public function resolve_operator_id_and_receipt_shape(): void
    {
        $this->assertSame('op-1', OperatorLearningRuntimeCaptureSupport::resolveOperatorId([
            'payload' => ['operator_id' => 'op-1'],
        ]));
        $this->assertSame('default', OperatorLearningRuntimeCaptureSupport::resolveOperatorId([]));

        $receipt = OperatorLearningRuntimeCaptureSupport::receipt([
            'signal' => ['id' => 's1', 'taxonomy_item_id' => 'OP-001'],
            'candidate' => ['id' => 'c1', 'status' => 'candidate'],
            'automation' => ['apply' => false],
        ]);
        $this->assertSame('captured', $receipt['status']);
        $this->assertSame('s1', $receipt['signal_id']);
        $this->assertSame('OP-001', $receipt['taxonomy_item_id']);
    }

    #[Test]
    public function source_type_and_scope_gates(): void
    {
        $denied = OperatorLearningRuntimeCaptureSupport::sourceTypeGate('agent', ['manual', 'app']);
        $this->assertFalse($denied['available']);
        $this->assertSame('source_type_not_allowed', $denied['reason']);

        $ok = OperatorLearningRuntimeCaptureSupport::sourceTypeGate('app', ['manual', 'app']);
        $this->assertTrue($ok['available']);

        $this->assertNull(OperatorLearningRuntimeCaptureSupport::scopeIdFromOptions([], 't1', 'global'));
        $this->assertSame('ws', OperatorLearningRuntimeCaptureSupport::scopeIdFromOptions(
            ['payload' => ['workspace' => 'ws']],
            't1',
            'project',
        ));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\RuntimeBoundary;

use App\Services\Ai\RuntimeBoundary\PythonBoundaryReceiptGuard;
use RuntimeException;
use Tests\TestCase;

final class PythonBoundaryReceiptGuardTest extends TestCase
{
    public function test_accepts_required_true_and_false_boundary_receipt(): void
    {
        PythonBoundaryReceiptGuard::assertReal(
            ['boundary' => ['engine_in_python' => true, 'fabricated' => false]],
            ['engine_in_python'],
            ['fabricated'],
            'failed',
        );

        $this->assertTrue(true);
    }

    public function test_rejects_missing_true_boundary_key(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('failed');

        PythonBoundaryReceiptGuard::assertReal(
            ['boundary' => ['fabricated' => false]],
            ['engine_in_python'],
            ['fabricated'],
            'failed',
        );
    }

    public function test_rejects_false_boundary_key_when_it_is_true_or_missing(): void
    {
        foreach ([['fabricated' => true], []] as $boundary) {
            try {
                PythonBoundaryReceiptGuard::assertReal(
                    ['boundary' => $boundary],
                    [],
                    ['fabricated'],
                    'failed',
                );
                $this->fail('Expected boundary guard to reject false-key violation.');
            } catch (RuntimeException $exception) {
                $this->assertSame('failed', $exception->getMessage());
            }
        }
    }
}

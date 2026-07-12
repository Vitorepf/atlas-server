<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\Adapters\EliteKernelWorkcellExecutorAdapter;
use App\Services\Ai\EngineeringKernel\EliteExecutorKernel;
use InvalidArgumentException;
use Tests\TestCase;

final class EliteKernelWorkcellExecutorAdapterTest extends TestCase
{
    public function test_workcell_requires_canonical_execution_order(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('workcell_execution_order_required');

        (new EliteKernelWorkcellExecutorAdapter($this->kernelWithoutConstruction()))->execute([]);
    }

    public function test_workcell_rejects_invalid_order_before_kernel_invocation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new EliteKernelWorkcellExecutorAdapter($this->kernelWithoutConstruction()))->execute(['execution_order' => ['mode' => 'dev']]);
    }

    private function kernelWithoutConstruction(): EliteExecutorKernel
    {
        /** @var EliteExecutorKernel $kernel */
        $kernel = (new \ReflectionClass(EliteExecutorKernel::class))->newInstanceWithoutConstructor();

        return $kernel;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev;

use App\Http\Controllers\AtlasDev\Support\KernelRunExecutor;
use App\Http\Controllers\AtlasDev\Support\RunExecutor;
use Tests\TestCase;

final class KernelRunExecutorBindingTest extends TestCase
{
    public function test_dev_run_surfaces_resolve_to_the_shared_kernel_translator(): void
    {
        self::assertInstanceOf(KernelRunExecutor::class, app(RunExecutor::class));
    }
}

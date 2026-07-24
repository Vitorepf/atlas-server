<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Support;

use App\Services\Ai\SelfConstruction\Support\OneShotTickInvokerCatalog;
use PHPUnit\Framework\TestCase;

final class OneShotTickInvokerCatalogTest extends TestCase
{
    public function test_catalog_lists_control_plane_invokers(): void
    {
        $names = OneShotTickInvokerCatalog::invokerClassNames();
        $this->assertGreaterThanOrEqual(50, OneShotTickInvokerCatalog::count());
        $this->assertContains('AgentAutomaticDispatchSchedulerOneShotTickAdapterInvocationBoundaryInvoker', $names);
        $this->assertSame($names, array_values(array_unique($names)));
    }
}

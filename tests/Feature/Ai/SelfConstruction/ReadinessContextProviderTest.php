<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Readiness\ReadinessContextProvider;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ReadinessContextProviderTest extends TestCase
{
    public function test_each_table_is_probed_exactly_once_per_context_build(): void
    {
        Schema::shouldReceive('hasTable')->once()->with('atlas_alpha')->andReturn(true);
        Schema::shouldReceive('hasTable')->once()->with('atlas_beta')->andReturn(false);

        $context = (new ReadinessContextProvider)->build(
            ['atlas_alpha', 'atlas_beta', 'atlas_alpha', ''],
            ['agg' => 41],
        );

        $this->assertTrue($context->hasTable('atlas_alpha'));
        $this->assertFalse($context->hasTable('atlas_beta'));
        $this->assertSame(['atlas_alpha', 'atlas_beta'], $context->probedTables());
        $this->assertSame(41, $context->snapshot('agg'));
        // A second lookup never re-probes: the mock would throw on an extra call.
        $this->assertTrue($context->hasTable('atlas_alpha'));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use Tests\TestCase;

final class AtlasLoopProxyPatternCatalogTest extends TestCase
{
    public function test_dead_class_was_deleted(): void
    {
        $this->assertFalse(
            class_exists(\App\Services\Ai\AutonomousEvolution\AtlasLoopProxyPatternCatalog::class),
            'AtlasLoopProxyPatternCatalog must remain deleted (0 production references).',
        );
        $this->assertFileDoesNotExist(
            app_path('Services/Ai/AutonomousEvolution/AtlasLoopProxyPatternCatalog.php'),
        );
    }
}

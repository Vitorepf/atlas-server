<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Surface;

use Tests\TestCase;

final class AtlasDevSurfaceArchitectureContractTest extends TestCase
{
    public function test_active_surfaces_share_the_canonical_run_executor_and_do_not_own_provider_or_workspace_mutation(): void
    {
        $root = base_path();
        $provider = (string) file_get_contents($root.'/app/Providers/AtlasDevServiceProvider.php');
        self::assertStringContainsString('bind(RunExecutor::class, KernelRunExecutor::class)', $provider);

        $surfaces = [
            $root.'/app/Http/Controllers/AtlasDev/RunController.php',
            $root.'/app/Services/Ai/Cli/AtlasCliDevEfficientHandler.php',
            $root.'/app/Console/Commands/AtlasDevDesktopRealSmokeCommand.php',
            $root.'/app/Services/Ai/Programming/AtlasDev/SeniorLoop/SeniorEngineerLoopExecutor.php',
        ];

        foreach ($surfaces as $path) {
            $source = (string) file_get_contents($path);
            self::assertStringContainsString('RunExecutor', $source, $path.' must delegate execution through RunExecutor.');
            foreach (['AiProvider', 'PatchApplier', 'Symfony\\Component\\Process\\Process', "['git'"] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $source, $path.' owns forbidden surface behavior: '.$forbidden);
            }
        }
    }
}

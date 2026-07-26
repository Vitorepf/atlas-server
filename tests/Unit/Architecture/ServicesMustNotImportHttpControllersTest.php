<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use Tests\TestCase;

/**
 * ASDD D4: operate-path application services must not import Http Controllers.
 *
 * Business orchestration for Forge Fast Path, enterprise certification and
 * continuum certification goes through application services / non-Http owners.
 * Controllers remain thin HTTP adapters; transitional façades may still wrap
 * them, but the three operate-path orchestrators listed below must stay clean.
 */
final class ServicesMustNotImportHttpControllersTest extends TestCase
{
    /** @var list<string> */
    private const OPERATE_PATH_SERVICES = [
        'app/Services/Ai/Programming/AtlasCodeForgeFastPathService.php',
        'app/Services/Ai/Programming/AtlasCodeEnterpriseCertificationService.php',
        'app/Services/Ai/Programming/AtlasForgeContinuumCertificationService.php',
    ];

    public function test_live_run_executor_is_kernel_not_pipeline(): void
    {
        $provider = file_get_contents(base_path('app/Providers/AtlasDevServiceProvider.php'));
        self::assertStringContainsString('KernelRunExecutor::class', (string) $provider);
        self::assertStringNotContainsString(
            'bind(RunExecutor::class, PipelineRunExecutor::class)',
            (string) $provider,
        );
    }

    public function test_operate_path_services_do_not_import_http_controllers(): void
    {
        $violations = [];
        foreach (self::OPERATE_PATH_SERVICES as $relative) {
            $path = base_path($relative);
            self::assertFileExists($path, "Missing operate-path service: {$relative}");
            $text = (string) file_get_contents($path);
            if (preg_match('/use\\s+App\\\\Http\\\\Controllers\\\\/', $text)) {
                $violations[] = $relative;
            }
        }

        self::assertSame(
            [],
            $violations,
            'Operate-path services must not use App\\Http\\Controllers: '.implode(', ', $violations),
        );
    }

    public function test_services_http_controller_import_inventory_is_finite(): void
    {
        $hits = [];
        $root = base_path('app/Services');
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            $text = (string) file_get_contents($file->getPathname());
            if (preg_match('/use\\s+App\\\\Http\\\\Controllers\\\\/', $text)) {
                $hits[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
        // Residual known from ASDD census. Guard against explosion; operate-path
        // orchestration is banned above even while inventory remains non-zero.
        self::assertLessThan(40, count($hits), 'Services→Http import count exploded: '.implode(', ', $hits));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\ExternalDeps\AtlasLoopExternalDepInventoryReporter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AtlasLoopExternalDepInventoryReporterTest extends TestCase
{
    #[Test]
    public function it_reports_declared_dependencies_with_resolved_versions_deterministically(): void
    {
        $dir = $this->makeTempDirectory();
        file_put_contents($dir.'/composer.json', json_encode([
            'require' => [
                'laravel/framework' => '^13.0',
                'php' => '^8.4',
            ],
            'require-dev' => [
                'phpunit/phpunit' => '^12.5',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($dir.'/composer.lock', json_encode([
            'packages' => [
                ['name' => 'brick/math', 'version' => '0.14.8'],
                ['name' => 'laravel/framework', 'version' => 'v13.0.1'],
            ],
            'packages-dev' => [
                ['name' => 'phpunit/phpunit', 'version' => '12.5.15'],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $reporter = new AtlasLoopExternalDepInventoryReporter($dir.'/composer.json', $dir.'/composer.lock');

        $first = $reporter->inventory();
        $second = $reporter->inventory();

        self::assertSame($first, $second);
        self::assertTrue($first['lock_present']);
        self::assertTrue($first['composer_json_present']);
        self::assertSame([
            [
                'name' => 'laravel/framework',
                'dependency_type' => 'require',
                'declared_constraint' => '^13.0',
                'resolved_version' => 'v13.0.1',
            ],
            [
                'name' => 'php',
                'dependency_type' => 'require',
                'declared_constraint' => '^8.4',
                'resolved_version' => null,
            ],
            [
                'name' => 'phpunit/phpunit',
                'dependency_type' => 'require-dev',
                'declared_constraint' => '^12.5',
                'resolved_version' => '12.5.15',
            ],
        ], $first['packages']);
        self::assertStringNotContainsString('^13.0', (string) $first['packages'][0]['resolved_version']);
    }

    #[Test]
    public function it_degrades_gracefully_when_composer_lock_is_missing(): void
    {
        $dir = $this->makeTempDirectory();
        file_put_contents($dir.'/composer.json', json_encode([
            'require' => [
                'laravel/framework' => '^13.0',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $reporter = new AtlasLoopExternalDepInventoryReporter($dir.'/composer.json', $dir.'/composer.lock');

        self::assertSame([
            'packages' => [],
            'lock_present' => false,
            'composer_json_present' => true,
        ], $reporter->inventory());
    }

    private function makeTempDirectory(): string
    {
        $path = sys_get_temp_dir().'/atlas-loop-external-deps-'.bin2hex(random_bytes(8));
        mkdir($path, 0777, true);

        return $path;
    }
}

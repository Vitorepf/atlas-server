<?php

declare(strict_types=1);

namespace Tests\Unit\AtlasCode;

use App\Services\AtlasCode\AtlasCodeReposService;
use PHPUnit\Framework\TestCase;

/**
 * M3 radar: saudável é silêncio; ausência nunca vira zero.
 */
final class AtlasCodeReposServiceTest extends TestCase
{
    public function test_healthy_repo_says_nothing_beyond_its_name(): void
    {
        $service = new AtlasCodeReposService();

        $repo = $service->projectRepo(
            ['slug' => 'atlas-native', 'name' => 'Atlas Native', 'repo_root' => sys_get_temp_dir()],
            [],
        );

        self::assertSame('atlas-native', $repo['slug']);
        self::assertTrue($repo['readable']);
        // Silêncio: sem chave de violação, sem contador decorativo.
        self::assertArrayNotHasKey('violations', $repo);
        self::assertArrayNotHasKey('rules', $repo);
    }

    public function test_exception_reports_count_and_rule_names(): void
    {
        $service = new AtlasCodeReposService();

        $repo = $service->projectRepo(
            ['slug' => 'blackink-app', 'name' => 'Blackink', 'repo_root' => sys_get_temp_dir()],
            [
                ['rule_id' => 'main_only', 'target' => 'hotfix-rapido'],
                ['rule_id' => 'main_only', 'target' => 'wip'],
                ['rule_id' => 'orphan_branch', 'target' => 'antiga'],
            ],
        );

        self::assertSame(3, $repo['violations']);
        self::assertSame(['main_only', 'orphan_branch'], $repo['rules']);
    }

    public function test_unreadable_repo_is_honest_not_healthy(): void
    {
        $service = new AtlasCodeReposService();

        $repo = $service->projectRepo(
            ['slug' => 'sumido', 'repo_root' => '/caminho/que/nao/existe/'.uniqid()],
            [],
        );

        self::assertFalse($repo['readable']);
        self::assertSame('repository_path_unreadable', $repo['unreadable_reason']);
        self::assertArrayNotHasKey('violations', $repo);
    }

    public function test_scanner_silence_is_not_health(): void
    {
        $service = new AtlasCodeReposService();

        $repo = $service->projectRepo(
            ['slug' => 'atlas-server', 'repo_root' => sys_get_temp_dir()],
            null,
        );

        // Scanner mudo ≠ repo saudável: a UI precisa saber a diferença.
        self::assertSame('unavailable', $repo['scan']);
        self::assertArrayNotHasKey('violations', $repo);
    }
}

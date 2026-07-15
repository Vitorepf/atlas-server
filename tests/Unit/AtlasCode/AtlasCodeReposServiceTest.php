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
    /** @return string diretório temporário que se parece com um repo git */
    private function fakeRepo(): string
    {
        $path = sys_get_temp_dir().'/atlas-code-'.uniqid();
        mkdir($path.'/.git', 0o777, true);

        return $path;
    }

    public function test_healthy_repo_says_nothing_beyond_its_name(): void
    {
        $service = new AtlasCodeReposService();

        $repo = $service->projectRepo(
            ['slug' => 'atlas-native', 'name' => 'Atlas Native', 'repo_root' => $this->fakeRepo()],
            [],
        );

        self::assertSame('atlas-native', $repo['slug']);
        self::assertTrue($repo['readable']);
        // Silêncio: sem chave de violação, sem contador decorativo.
        self::assertArrayNotHasKey('violations', $repo);
        self::assertArrayNotHasKey('issues', $repo);
    }

    public function test_exception_reports_count_and_rule_names(): void
    {
        $service = new AtlasCodeReposService();

        $repo = $service->projectRepo(
            ['slug' => 'blackink-app', 'name' => 'Blackink', 'repo_root' => $this->fakeRepo()],
            [
                ['rule_id' => 'main_only', 'target' => 'hotfix-rapido'],
                ['rule_id' => 'main_only', 'target' => 'wip'],
                ['rule_id' => 'orphan_branch', 'target' => 'antiga'],
            ],
        );

        self::assertSame(3, $repo['violations']);
        self::assertSame('main_only', $repo['issues'][0]['rule_id']);
        self::assertSame(2, $repo['issues'][0]['count']);
        self::assertSame('orphan_branch', $repo['issues'][1]['rule_id']);
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

    public function test_issues_group_by_rule_with_real_age_and_worst_first(): void
    {
        $service = new AtlasCodeReposService();
        $now = strtotime('2026-07-15T12:00:00Z');

        $issues = $service->groupIssues([
            ['rule_id' => 'obra_return_deadline', 'severity' => 'medium', 'since' => '2026-06-23T00:00:00Z'],
            ['rule_id' => 'obra_return_deadline', 'severity' => 'medium', 'since' => '2026-07-08T00:00:00Z'],
            ['rule_id' => 'orphan_branch', 'severity' => 'high', 'since' => '2026-07-11T00:00:00Z'],
        ], $now);

        // O grave vem primeiro, mesmo sendo menos numeroso.
        self::assertSame('orphan_branch', $issues[0]['rule_id']);
        self::assertSame(1, $issues[0]['count']);
        self::assertSame(4, $issues[0]['oldest_days']);

        self::assertSame('obra_return_deadline', $issues[1]['rule_id']);
        self::assertSame(2, $issues[1]['count']);
        // A idade é a do caso mais antigo — 22 dias, não a do mais recente.
        self::assertSame(22, $issues[1]['oldest_days']);
    }

    public function test_issue_without_readable_date_never_invents_age(): void
    {
        $service = new AtlasCodeReposService();

        $issues = $service->groupIssues([
            ['rule_id' => 'worktree_allowlist', 'severity' => 'medium', 'since' => null],
        ]);

        self::assertSame(1, $issues[0]['count']);
        self::assertArrayNotHasKey('oldest_days', $issues[0]);
    }

    public function test_folder_without_git_is_not_a_repository(): void
    {
        $service = new AtlasCodeReposService();

        // O perfil guarda-chuva do workspace é uma pasta real, mas não um repo:
        // dizer que está "saudável" seria juízo sobre o que não foi lido.
        $repo = $service->projectRepo(
            ['slug' => 'atlas', 'repo_root' => sys_get_temp_dir()],
            [],
        );

        self::assertFalse($repo['readable']);
        self::assertSame('not_a_git_repository', $repo['unreadable_reason']);
    }

    public function test_scanner_silence_is_not_health(): void
    {
        $service = new AtlasCodeReposService();

        $repo = $service->projectRepo(
            ['slug' => 'atlas-server', 'repo_root' => $this->fakeRepo()],
            null,
        );

        // Scanner mudo ≠ repo saudável: a UI precisa saber a diferença.
        self::assertSame('unavailable', $repo['scan']);
        self::assertArrayNotHasKey('violations', $repo);
    }
}

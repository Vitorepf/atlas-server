<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\ForgeRivals\Corpus;

use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsCorpusFixtureRunnerService;
use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use App\Services\Ai\Programming\WorkspaceHygieneService;
use PHPUnit\Framework\TestCase;

/**
 * Atlas Forge Rivals · Corpus Fixture Runner — unit tests.
 *
 * Uses a temporary repo root + temporary runs root so the suite stays
 * hermetic — no git, no provider, no shared state.
 */
final class AtlasForgeRivalsCorpusFixtureRunnerTest extends TestCase
{
    private string $tempRepo;

    private string $tempRuns;

    private AtlasForgeRivalsProviderArenaCorpusService $corpus;

    private AtlasForgeRivalsCorpusFixtureRunnerService $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempRepo = sys_get_temp_dir().'/atlas-corpus-test-repo-'.uniqid();
        $this->tempRuns = sys_get_temp_dir().'/atlas-corpus-test-runs-'.uniqid();
        mkdir($this->tempRepo, 0o755, true);
        mkdir($this->tempRuns, 0o755, true);

        $this->corpus = new AtlasForgeRivalsProviderArenaCorpusService;

        $this->runner = new AtlasForgeRivalsCorpusFixtureRunnerService(
            $this->corpus,
            new WorkspaceHygieneService,
            $this->tempRepo,
            $this->tempRuns,
        );

        $this->seedCaseFixture('arena-frontend-button-loading-state', [
            'CaptureForm.tsx' => "// seed file\n",
            'Button.tsx' => "// seed file\n",
            '__tests__/CaptureForm.test.tsx' => "// seed test\n",
            'README.md' => "# seed\n",
        ]);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tempRepo);
        $this->rrmdir($this->tempRuns);
        parent::tearDown();
    }

    public function test_prepare_creates_clean_isolated_workspace(): void
    {
        $result = $this->runner->prepare('run-123', 'arena-frontend-button-loading-state');

        $this->assertSame('ok', $result['status']);
        $this->assertNotNull($result['workspace']);
        $this->assertSame($this->tempRuns.'/run-123/corpus/arena-frontend-button-loading-state/workspace', $result['workspace']);
        $this->assertDirectoryExists($result['workspace']);
        $this->assertFileExists($result['workspace'].'/CaptureForm.tsx');
        $this->assertFileExists($result['workspace'].'/Button.tsx');
        $this->assertFileExists($result['workspace'].'/__tests__/CaptureForm.test.tsx');
        $this->assertContains('CaptureForm.tsx', $result['files_copied']);
        $this->assertContains('__tests__/CaptureForm.test.tsx', $result['files_copied']);
        $this->assertFalse($result['external_provider_call']);
    }

    public function test_prepare_blocks_when_seed_dir_missing(): void
    {
        $result = $this->runner->prepare('run-x', 'arena-backend-pagination-cursor');
        $this->assertSame('blocked', $result['status']);
        $this->assertNotEmpty(array_filter(
            (array) $result['blockers'],
            static fn (string $b): bool => str_starts_with($b, 'seed_dir_missing:'),
        ));
    }

    public function test_prepare_blocks_unknown_case_id(): void
    {
        $result = $this->runner->prepare('run-x', 'does-not-exist');
        $this->assertSame('blocked', $result['status']);
        $this->assertNotEmpty(array_filter(
            (array) $result['blockers'],
            static fn (string $b): bool => str_starts_with($b, 'unknown_case_id:'),
        ));
    }

    public function test_check_scope_flags_paths_outside_allowed_scope(): void
    {
        // atlas-server/app/** is in this case's forbidden_files_scope, so the
        // model path triggers forbidden_path_touched (deny-first). A path that
        // is neither allowed nor forbidden surfaces as scope_violation.
        $violations = $this->runner->checkScope(
            'arena-frontend-button-loading-state',
            [
                'atlas-desktop/src/components/inbox/CaptureForm.tsx',     // allowed
                'atlas-server/app/Models/Capture.php',                    // forbidden (atlas-server/app/**)
                'atlas-cartografia/cockpit.tsx',                          // forbidden (atlas-cartografia/**)
                'unrelated/Random.go',                                    // truly outside any scope
            ],
        );

        $this->assertContains('forbidden_path_touched:atlas-server/app/Models/Capture.php', $violations);
        $this->assertContains('forbidden_path_touched:atlas-cartografia/cockpit.tsx', $violations);
        $this->assertContains('scope_violation:unrelated/Random.go', $violations);
        $this->assertNotContains(
            'scope_violation:atlas-desktop/src/components/inbox/CaptureForm.tsx',
            $violations,
        );
    }

    public function test_check_scope_blocks_voice_paths_even_when_in_allowed(): void
    {
        $violations = $this->runner->checkScope(
            'arena-frontend-button-loading-state',
            ['atlas-desktop/src/voice/CaptureVoice.tsx'],
        );
        $this->assertContains('forbidden_path_touched:atlas-desktop/src/voice/CaptureVoice.tsx', $violations);
    }

    public function test_cleanup_removes_workspace_but_returns_ok_when_absent(): void
    {
        $absent = $this->runner->cleanup('never-existed', 'arena-frontend-button-loading-state');
        $this->assertSame('ok', $absent['status']);
        $this->assertFalse($absent['removed']);

        $this->runner->prepare('run-clean', 'arena-frontend-button-loading-state');
        $this->assertDirectoryExists($this->tempRuns.'/run-clean/corpus/arena-frontend-button-loading-state/workspace');
        $cleaned = $this->runner->cleanup('run-clean', 'arena-frontend-button-loading-state');
        $this->assertSame('ok', $cleaned['status']);
        $this->assertTrue($cleaned['removed']);
        $this->assertDirectoryDoesNotExist($this->tempRuns.'/run-clean/corpus/arena-frontend-button-loading-state/workspace');
    }

    private function seedCaseFixture(string $caseId, array $files): void
    {
        $base = $this->tempRepo.'/storage/forge-rivals-corpus/'.$caseId.'/seed';
        mkdir($base, 0o755, true);
        foreach ($files as $rel => $content) {
            $full = $base.'/'.$rel;
            if (! is_dir(dirname($full))) {
                mkdir(dirname($full), 0o755, true);
            }
            file_put_contents($full, $content);
        }
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $file) {
            $p = (string) $file;
            is_dir($p) ? @rmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}

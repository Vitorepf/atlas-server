<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\ForgeRivals\Corpus;

use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsCorpusFixtureRunnerService;
use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use App\Services\Ai\Programming\WorkspaceHygieneService;
use PHPUnit\Framework\TestCase;

/**
 * Atlas Forge Rivals · Corpus Fixture Runner — unit tests (Release v1).
 *
 * Hermético: tempRepo + tempRuns próprios, sem git, sem provider, sem DB.
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

        $this->seedCaseFixture('backend-pagination-off-by-one', [
            'PageCalculator.php' => "// seed file\n",
            'PageCalculatorTest.php' => "// seed test\n",
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
        $result = $this->runner->prepare('run-123', 'backend-pagination-off-by-one');

        $this->assertSame('ok', $result['status']);
        $this->assertNotNull($result['workspace']);
        $this->assertSame(
            $this->tempRuns.'/run-123/corpus/backend-pagination-off-by-one/workspace',
            $result['workspace'],
        );
        $this->assertDirectoryExists($result['workspace']);
        $this->assertFileExists($result['workspace'].'/PageCalculator.php');
        $this->assertFileExists($result['workspace'].'/PageCalculatorTest.php');
        $this->assertContains('PageCalculator.php', $result['files_copied']);
        $this->assertContains('PageCalculatorTest.php', $result['files_copied']);
        $this->assertFalse($result['external_provider_call']);
    }

    public function test_prepare_blocks_when_seed_dir_missing(): void
    {
        $result = $this->runner->prepare('run-x', 'frontend-execution-status-panel');
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
        // backend-pagination-off-by-one tem allowed=app/Services/Pagination/PageCalculator.php +
        // tests/Unit/Pagination/PageCalculatorTest.php. forbidden inclui app/Http/**, app/Services/Ai/**,
        // database/migrations/**, atlas-desktop/src/voice/**, atlas-cartografia/**.
        $violations = $this->runner->checkScope(
            'backend-pagination-off-by-one',
            [
                'app/Services/Pagination/PageCalculator.php', // allowed
                'app/Http/Controllers/PageController.php',    // forbidden (app/Http/**)
                'atlas-cartografia/cockpit.tsx',              // forbidden (atlas-cartografia/**)
                'unrelated/Random.go',                        // truly outside
            ],
        );

        $this->assertContains('forbidden_path_touched:app/Http/Controllers/PageController.php', $violations);
        $this->assertContains('forbidden_path_touched:atlas-cartografia/cockpit.tsx', $violations);
        $this->assertContains('scope_violation:unrelated/Random.go', $violations);
        $this->assertNotContains(
            'scope_violation:app/Services/Pagination/PageCalculator.php',
            $violations,
        );
    }

    public function test_check_scope_blocks_voice_paths_even_when_not_in_allowed(): void
    {
        $violations = $this->runner->checkScope(
            'frontend-form-validation-accessibility',
            ['atlas-desktop/src/voice/CaptureVoice.tsx'],
        );
        $this->assertContains('forbidden_path_touched:atlas-desktop/src/voice/CaptureVoice.tsx', $violations);
    }

    public function test_cleanup_removes_workspace_but_returns_ok_when_absent(): void
    {
        $absent = $this->runner->cleanup('never-existed', 'backend-pagination-off-by-one');
        $this->assertSame('ok', $absent['status']);
        $this->assertFalse($absent['removed']);

        $this->runner->prepare('run-clean', 'backend-pagination-off-by-one');
        $workspace = $this->tempRuns.'/run-clean/corpus/backend-pagination-off-by-one/workspace';
        $this->assertDirectoryExists($workspace);
        $cleaned = $this->runner->cleanup('run-clean', 'backend-pagination-off-by-one');
        $this->assertSame('ok', $cleaned['status']);
        $this->assertTrue($cleaned['removed']);
        $this->assertDirectoryDoesNotExist($workspace);
    }

    /**
     * @param  array<string,string>  $files
     */
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

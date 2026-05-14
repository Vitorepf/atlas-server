<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\AtlasRivalsRunOrchestrator;
use App\Services\Ai\Programming\RivalsForgeRunLogStreamService;
use Tests\TestCase;

class AtlasRivalsRunOrchestratorTest extends TestCase
{
    /** @var list<string> */
    private array $createdWorkspaces = [];

    protected function tearDown(): void
    {
        foreach ($this->createdWorkspaces as $workspace) {
            $this->purge($workspace);
        }
        $this->createdWorkspaces = [];

        $stream = app(RivalsForgeRunLogStreamService::class);
        foreach ($stream->listRuns() as $runId) {
            $dir = $stream->runDirectory($runId);
            if (str_contains($dir, 'rivals_orchestrator_test_')) {
                $this->purge($dir);
            }
        }
        parent::tearDown();
    }

    public function test_dry_run_mode_emits_lifecycle_events_and_never_calls_provider(): void
    {
        $workspace = $this->makeCleanGitWorkspaceWithDocs();
        $runId = $this->newRunId();

        $result = app(AtlasRivalsRunOrchestrator::class)->run([
            'run_id' => $runId,
            'mode' => AtlasRivalsRunOrchestrator::MODE_DRY_RUN,
            'atlas_workspace' => $workspace,
            'preset' => 'quick',
            'atlas_model' => 'sonnet',
            'baseline_model' => 'sonnet',
        ]);

        $this->assertSame(AtlasRivalsRunOrchestrator::VERDICT_PASSED, $result['verdict']);
        $this->assertFalse($result['external_provider_call']);
        $this->assertFalse($result['claim_ready']);
        $this->assertNull($result['score']);

        $kinds = $this->eventKinds($runId);
        $this->assertContains('run_started', $kinds);
        $this->assertContains('preflight', $kinds);
        $this->assertContains('dry_run', $kinds);
        $this->assertContains('final_report', $kinds);
        $this->assertNotContains('provider_start', $kinds);
        $this->assertNotContains('provider_done', $kinds);
    }

    public function test_fake_provider_clean_run_passes_and_records_after_clean_check_clean(): void
    {
        $workspace = $this->makeCleanGitWorkspaceWithDocs();
        $runId = $this->newRunId();

        $result = app(AtlasRivalsRunOrchestrator::class)->run([
            'run_id' => $runId,
            'mode' => AtlasRivalsRunOrchestrator::MODE_FAKE_PROVIDER,
            'atlas_workspace' => $workspace,
            'preset' => 'quick',
            'atlas_model' => 'sonnet',
            'baseline_model' => 'sonnet',
            'fake_provider_command' => 'php -r \'fwrite(STDOUT, "fake-provider-ok"); usleep(100000);\'',
        ]);

        $this->assertSame(AtlasRivalsRunOrchestrator::VERDICT_PASSED, $result['verdict']);
        $this->assertTrue((bool) data_get($result, 'details.after_clean_check.clean'));

        $kinds = $this->eventKinds($runId);
        $this->assertContains('provider_start', $kinds);
        $this->assertContains('provider_done', $kinds);
        $this->assertContains('after_clean_check', $kinds);
        $this->assertContains('evidence_pack', $kinds);
        $this->assertContains('final_report', $kinds);
    }

    public function test_fake_provider_that_dirties_workspace_returns_invalid_dirty_after_run(): void
    {
        $workspace = $this->makeCleanGitWorkspaceWithDocs();
        $runId = $this->newRunId();

        $result = app(AtlasRivalsRunOrchestrator::class)->run([
            'run_id' => $runId,
            'mode' => AtlasRivalsRunOrchestrator::MODE_FAKE_PROVIDER,
            'atlas_workspace' => $workspace,
            'preset' => 'quick',
            'atlas_model' => 'sonnet',
            'baseline_model' => 'sonnet',
            'fake_provider_command' => "touch orchestrator_dirty.tmp && echo done",
        ]);

        $this->assertSame(AtlasRivalsRunOrchestrator::VERDICT_INVALID_DIRTY_AFTER_RUN, $result['verdict']);
        $this->assertFalse((bool) data_get($result, 'details.after_clean_check.clean'));
        $this->assertNull($result['score']);
    }

    public function test_fake_provider_that_stalls_returns_stalled_runner_verdict(): void
    {
        $workspace = $this->makeCleanGitWorkspaceWithDocs();
        $runId = $this->newRunId();

        $result = app(AtlasRivalsRunOrchestrator::class)->run([
            'run_id' => $runId,
            'mode' => AtlasRivalsRunOrchestrator::MODE_FAKE_PROVIDER,
            'atlas_workspace' => $workspace,
            'preset' => 'quick',
            'atlas_model' => 'sonnet',
            'baseline_model' => 'sonnet',
            // Sleep 4 seconds with no output; stall budget below is 1 second.
            'fake_provider_command' => "php -r 'sleep(4);'",
            'fake_provider_timeout_seconds' => 6,
            'stall_budget_seconds' => 1,
        ]);

        $this->assertSame(AtlasRivalsRunOrchestrator::VERDICT_STALLED_RUNNER, $result['verdict']);
        $this->assertNull($result['score']);

        $kinds = $this->eventKinds($runId);
        $this->assertContains('stalled', $kinds);
    }

    public function test_fingerprint_mismatch_blocks_run_before_provider(): void
    {
        $workspace = $this->makeCleanGitWorkspaceWithDocs();
        $runId = $this->newRunId();

        $expectedFingerprint = app(\App\Services\Ai\Programming\RivalsForgeReadinessFingerprintService::class)->compute([
            'atlas_workspace' => $workspace,
            'preset' => 'quick',
            'atlas_model' => 'opus',
            'baseline_model' => 'opus',
        ]);

        $result = app(AtlasRivalsRunOrchestrator::class)->run([
            'run_id' => $runId,
            'mode' => AtlasRivalsRunOrchestrator::MODE_FAKE_PROVIDER,
            'atlas_workspace' => $workspace,
            'preset' => 'quick',
            'atlas_model' => 'sonnet',  // mismatch
            'baseline_model' => 'sonnet',
            'fake_provider_command' => 'php -r \'echo "should not run";\'',
            'expected_fingerprint' => $expectedFingerprint,
        ]);

        $this->assertSame(AtlasRivalsRunOrchestrator::VERDICT_BLOCKED_FINGERPRINT_MISMATCH, $result['verdict']);
        $this->assertContains('atlas_model', data_get($result, 'details.fingerprint_diagnosis.diff_fields', []));
        $this->assertNotContains('provider_start', $this->eventKinds($runId));
    }

    public function test_real_provider_mode_refuses_dispatch_without_operator_command(): void
    {
        $workspace = $this->makeCleanGitWorkspaceWithDocs();
        $runId = $this->newRunId();

        $result = app(AtlasRivalsRunOrchestrator::class)->run([
            'run_id' => $runId,
            'mode' => AtlasRivalsRunOrchestrator::MODE_REAL_PROVIDER,
            'atlas_workspace' => $workspace,
            'baseline_workspace' => $this->makeCleanGitWorkspace(),
            'preset' => 'quick',
            'atlas_model' => 'sonnet',
            'baseline_model' => 'sonnet',
            'provider_cost_approved' => true,
            'runbook_reviewed' => true,
        ]);

        $this->assertSame(
            AtlasRivalsRunOrchestrator::VERDICT_REAL_PROVIDER_REQUIRES_OPERATOR,
            $result['verdict'],
        );
    }

    public function test_preflight_block_short_circuits_lifecycle(): void
    {
        $workspace = $this->makeDirtyNonGitWorkspace();
        $runId = $this->newRunId();

        $result = app(AtlasRivalsRunOrchestrator::class)->run([
            'run_id' => $runId,
            'mode' => AtlasRivalsRunOrchestrator::MODE_FAKE_PROVIDER,
            'atlas_workspace' => $workspace,
            'preset' => 'quick',
            'atlas_model' => 'sonnet',
            'baseline_model' => 'sonnet',
            'fake_provider_command' => 'php -r \'echo "must not run";\'',
        ]);

        $this->assertSame(AtlasRivalsRunOrchestrator::VERDICT_BLOCKED_PREFLIGHT, $result['verdict']);
        $kinds = $this->eventKinds($runId);
        $this->assertContains('preflight', $kinds);
        $this->assertNotContains('provider_start', $kinds);
    }

    private function eventKinds(string $runId): array
    {
        return array_map(
            static fn (array $e): string => (string) ($e['kind'] ?? ''),
            app(RivalsForgeRunLogStreamService::class)->tail($runId),
        );
    }

    private function newRunId(): string
    {
        return 'rivals_orchestrator_test_'.bin2hex(random_bytes(6));
    }

    private function makeCleanGitWorkspace(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'atlas_orchestrator_clean_'.bin2hex(random_bytes(8));
        $this->createdWorkspaces[] = $path;
        @mkdir($path, 0o755, true);
        file_put_contents($path.'/README.md', "seed\n");
        $this->runGit($path, ['init', '--quiet']);
        $this->runGit($path, ['config', 'user.email', 'tests@atlas.local']);
        $this->runGit($path, ['config', 'user.name', 'Atlas Tests']);
        $this->runGit($path, ['add', '-A']);
        $this->runGit($path, ['commit', '--quiet', '-m', 'seed']);

        return $path;
    }

    private function makeCleanGitWorkspaceWithDocs(): string
    {
        $path = $this->makeCleanGitWorkspace();
        $stagingDocs = $this->copyCanonicalDocs();
        $this->mirrorDirectory($stagingDocs, $path);
        $this->runGit($path, ['add', '-A']);
        $this->runGit($path, ['commit', '--quiet', '-m', 'docs']);

        return $path;
    }

    private function makeDirtyNonGitWorkspace(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'atlas_orchestrator_dirty_'.bin2hex(random_bytes(8));
        $this->createdWorkspaces[] = $path;
        @mkdir($path, 0o755, true);
        file_put_contents($path.'/note.txt', 'not a git workspace');

        return $path;
    }

    private function copyCanonicalDocs(): string
    {
        $stage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'atlas_orchestrator_docs_'.bin2hex(random_bytes(8));
        $this->createdWorkspaces[] = $stage;
        @mkdir($stage.'/docs/engineering-knowledge-base/domains', 0o755, true);
        $sources = [
            'docs/engineering-knowledge-base/atlas-forge-native-rivals-protocol-v1.md',
            'docs/engineering-knowledge-base/atlas-programming-forge-flow.md',
            'docs/engineering-knowledge-base/domains/programming-professional-completion-audit.md',
        ];
        foreach ($sources as $rel) {
            $src = base_path($rel);
            $dst = $stage.DIRECTORY_SEPARATOR.$rel;
            @mkdir(dirname($dst), 0o755, true);
            if (is_file($src)) {
                copy($src, $dst);
            }
        }

        return $stage;
    }

    private function mirrorDirectory(string $source, string $destination): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            $target = $destination.DIRECTORY_SEPARATOR.$iterator->getSubPathname();
            if ($item->isDir()) {
                @mkdir($target, 0o755, true);
            } else {
                @mkdir(dirname($target), 0o755, true);
                copy($item->getPathname(), $target);
            }
        }
    }

    /**
     * @param  array<int,string>  $args
     */
    private function runGit(string $cwd, array $args): void
    {
        $process = new \Symfony\Component\Process\Process(array_merge(['git'], $args), $cwd);
        $process->setTimeout(10);
        $process->run();
    }

    private function purge(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->purge($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

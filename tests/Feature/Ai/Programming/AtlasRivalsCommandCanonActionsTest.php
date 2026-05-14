<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\RivalsForgeRunLogStreamService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasRivalsCommandCanonActionsTest extends TestCase
{
    /** @var list<string> */
    private array $createdWorkspaces = [];

    /** @var list<string> */
    private array $createdRunIds = [];

    protected function tearDown(): void
    {
        foreach ($this->createdWorkspaces as $w) {
            $this->purge($w);
        }
        $this->createdWorkspaces = [];

        $stream = app(RivalsForgeRunLogStreamService::class);
        foreach ($this->createdRunIds as $runId) {
            $dir = $stream->runDirectory($runId);
            $this->purge($dir);
        }
        $this->createdRunIds = [];

        parent::tearDown();
    }

    public function test_atlas_rivals_preflight_canon_action_returns_json(): void
    {
        $workspace = $this->makeCleanGitWorkspaceWithDocs();

        $exitCode = Artisan::call('atlas:engineering:benchmark:rivals', [
            'action' => 'preflight',
            '--workspace' => $workspace,
            '--model' => 'sonnet',
            '--baseline-model' => 'sonnet',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload, 'preflight canon action must emit JSON when --json is set');
        $this->assertSame('atlas.programming.forge_native_rivals_preflight.v1', $payload['schema_version'] ?? null);
        $this->assertSame(64, strlen((string) data_get($payload, 'readiness_fingerprint.value', '')));
        // Workspace is clean → ready_for_dry_run.
        $this->assertSame('ready_for_dry_run', $payload['status'] ?? null);
        $this->assertSame(0, $exitCode);
    }

    public function test_atlas_rivals_dry_run_canon_action_returns_replay_manifest_planned(): void
    {
        $workspace = $this->makeCleanGitWorkspaceWithDocs();

        $exitCode = Artisan::call('atlas:engineering:benchmark:rivals', [
            'action' => 'dry-run',
            '--workspace' => $workspace,
            '--model' => 'sonnet',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('atlas.programming.forge_native_rivals_dry_run.v1', $payload['schema_version'] ?? null);
        $this->assertSame('dry_run_passed', $payload['status'] ?? null);
        $this->assertSame('planned', data_get($payload, 'replay_manifest.state'));
        $this->assertFalse((bool) ($payload['external_provider_call'] ?? true));
        $this->assertSame(0, $exitCode);
    }

    public function test_atlas_rivals_dry_run_strict_fails_on_dirty_workspace(): void
    {
        $dirty = $this->makeDirtyNonGitWorkspace();

        $exitCode = Artisan::call('atlas:engineering:benchmark:rivals', [
            'action' => 'dry-run',
            '--workspace' => $dirty,
            '--model' => 'sonnet',
            '--json' => true,
            '--strict' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertSame('dry_run_blocked', $payload['status'] ?? null);
        $this->assertSame(1, $exitCode);
    }

    public function test_atlas_rivals_replay_returns_no_run_when_no_runs_exist(): void
    {
        // Force a fresh runs directory by clearing it (only within our test scope).
        $stream = app(RivalsForgeRunLogStreamService::class);
        $root = $stream->rootDirectory();
        $this->purge($root);

        $exitCode = Artisan::call('atlas:engineering:benchmark:rivals', [
            'action' => 'replay',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertSame('no_run_available', $payload['status'] ?? null);
        $this->assertSame(1, $exitCode);
    }

    public function test_atlas_rivals_replay_returns_latest_run_events(): void
    {
        $stream = app(RivalsForgeRunLogStreamService::class);
        $runId = 'rivals_orchestrator_test_canon_'.bin2hex(random_bytes(6));
        $this->createdRunIds[] = $runId;
        $stream->start($runId, ['mode' => 'fake_provider']);
        $stream->event($runId, 'final_report', ['verdict' => 'passed', 'run_id' => $runId]);

        $exitCode = Artisan::call('atlas:engineering:benchmark:rivals', [
            'action' => 'replay',
            '--run-id' => $runId,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertSame($runId, $payload['run_id'] ?? null);
        $this->assertGreaterThanOrEqual(2, (int) ($payload['event_count'] ?? 0));
        $this->assertSame('passed', data_get($payload, 'final_report.verdict'));
        $this->assertSame(0, $exitCode);
    }

    private function makeCleanGitWorkspaceWithDocs(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'atlas_rivals_canon_'.bin2hex(random_bytes(8));
        $this->createdWorkspaces[] = $path;
        @mkdir($path, 0o755, true);
        foreach (['docs/engineering-knowledge-base/atlas-forge-native-rivals-protocol-v1.md',
            'docs/engineering-knowledge-base/atlas-programming-forge-flow.md',
            'docs/engineering-knowledge-base/domains/programming-professional-completion-audit.md'] as $rel) {
            $src = base_path($rel);
            $dst = $path.DIRECTORY_SEPARATOR.$rel;
            @mkdir(dirname($dst), 0o755, true);
            if (is_file($src)) {
                copy($src, $dst);
            }
        }
        $this->runGit($path, ['init', '--quiet']);
        $this->runGit($path, ['config', 'user.email', 'tests@atlas.local']);
        $this->runGit($path, ['config', 'user.name', 'Atlas Tests']);
        $this->runGit($path, ['add', '-A']);
        $this->runGit($path, ['commit', '--quiet', '-m', 'seed']);

        return $path;
    }

    private function makeDirtyNonGitWorkspace(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'atlas_rivals_canon_dirty_'.bin2hex(random_bytes(8));
        $this->createdWorkspaces[] = $path;
        @mkdir($path, 0o755, true);
        file_put_contents($path.'/note.txt', 'not git');

        return $path;
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

<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves atlas:self-construction:knowledge-sync: inspect lists services + non-execution guarantees;
 * artifacts derives required artifacts; gate over fresh observed evidence is conformant=true; gate over
 * missing observed evidence is conformant=false; plan emits operator-runnable command hints; unknown
 * action returns unknown_action.
 */
final class AtlasSelfConstructionKnowledgeSyncCommandTest extends TestCase
{
    private string $candidatePath;

    private string $observedPath;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = bin2hex(random_bytes(6));
        $this->candidatePath = sys_get_temp_dir().'/atlas_ks_cand_'.$tag.'.json';
        $this->observedPath = sys_get_temp_dir().'/atlas_ks_obs_'.$tag.'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->candidatePath);
        @unlink($this->observedPath);
        parent::tearDown();
    }

    private function writeJson(string $path, array $data): void
    {
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    public function test_inspect_lists_services_and_non_execution_guarantees(): void
    {
        $exit = Artisan::call('atlas:self-construction:knowledge-sync', ['action' => 'inspect', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $exit);
        $this->assertSame('ok', $p['status']);
        $this->assertFalse($p['non_execution_guarantees']['runs_sync_command']);
        $this->assertFalse($p['non_execution_guarantees']['runs_index_command']);
    }

    public function test_artifacts_derives_required_artifacts_from_candidate(): void
    {
        $this->writeJson($this->candidatePath, ['changed_files' => ['docs/architecture.md', 'app/Demo/Foo.php']]);
        Artisan::call('atlas:self-construction:knowledge-sync', ['action' => 'artifacts', '--candidate' => $this->candidatePath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $ids = array_column($p['artifact_map']['required_artifacts'], 'artifact_id');
        $this->assertContains('docs-health-check', $ids);
        $this->assertContains('code-intelligence-index', $ids);
    }

    public function test_gate_pass_with_fresh_observed_evidence(): void
    {
        $now = time();
        $this->writeJson($this->candidatePath, ['changed_files' => ['docs/architecture.md']]);
        $this->writeJson($this->observedPath, [
            'docs_health' => ['ok' => true, 'observed_at_unix' => $now - 60],
            'sync_result' => ['ok' => true, 'observed_at_unix' => $now - 60],
            'now_unix' => $now,
        ]);
        Artisan::call('atlas:self-construction:knowledge-sync', ['action' => 'gate', '--candidate' => $this->candidatePath, '--observed' => $this->observedPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertTrue($p['docs_drift_gate']['conformant']);
    }

    public function test_gate_blocked_when_observed_evidence_missing(): void
    {
        $this->writeJson($this->candidatePath, ['changed_files' => ['docs/x.md']]);
        $this->writeJson($this->observedPath, []); // observed empty
        Artisan::call('atlas:self-construction:knowledge-sync', ['action' => 'gate', '--candidate' => $this->candidatePath, '--observed' => $this->observedPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertFalse($p['docs_drift_gate']['conformant']);
        $this->assertContains('docs_health_missing', $p['docs_drift_gate']['blockers']);
    }

    public function test_plan_emits_operator_runnable_command_hints(): void
    {
        $this->writeJson($this->candidatePath, ['changed_files' => ['docs/x.md', 'app/Foo.php']]);
        Artisan::call('atlas:self-construction:knowledge-sync', ['action' => 'plan', '--candidate' => $this->candidatePath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertNotEmpty($p['post_merge_actions']);
        foreach ($p['post_merge_actions'] as $a) {
            $this->assertSame('CLI does not execute — operator/orchestrator runs the command', $a['note']);
        }
    }

    public function test_unknown_action_returns_unknown_action(): void
    {
        $exit = Artisan::call('atlas:self-construction:knowledge-sync', ['action' => 'bogus', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertNotSame(0, $exit);
        $this->assertSame('unknown_action', $p['status']);
    }
}

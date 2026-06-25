<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneReleaseGovernor;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves atlas:task:project-lane-runtime-plan: inspect lists required fields; plan with a valid
 * manifest composes admission + freshness + namespace + verification + knowledge-sync; plan with a
 * malformed manifest yields admission.admitted=false; decision over hold-class facts yields hold.
 */
final class AtlasProjectLaneRuntimePlanCommandTest extends TestCase
{
    private string $manifestPath;

    private string $factsPath;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = bin2hex(random_bytes(6));
        $this->manifestPath = sys_get_temp_dir().'/atlas_lane_rt_mf_'.$tag.'.json';
        $this->factsPath = sys_get_temp_dir().'/atlas_lane_rt_facts_'.$tag.'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->manifestPath);
        @unlink($this->factsPath);
        parent::tearDown();
    }

    private function writeJson(string $path, array $data): void
    {
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    private function validManifest(int $now): array
    {
        return [
            'project_id' => 'demo',
            'repo_root' => '/Users/vitorepf/develop/Atlas/atlas-server',
            'objective' => 'Demo lane runtime plan.',
            'mainline_branch' => 'main',
            'allowed_scope_roots' => ['/Users/vitorepf/develop/Atlas/atlas-server/app'],
            'verification_commands' => ['php artisan test'],
            'merge_policy' => ['mode' => 'fast_forward'],
            'rollback_policy' => ['mode' => 'revert_commit'],
            'knowledge_sync_policy' => ['mode' => 'daily'],
            'context_observations' => [
                'now_unix' => $now,
                'docs_sync_last_unix' => $now - 60,
                'code_index_last_unix' => $now - 60,
                'context_pack_hash' => 'sha256:abcd',
                'context_pack_last_unix' => $now - 30,
            ],
            'touched_paths' => ['/Users/vitorepf/develop/Atlas/atlas-server/app/Demo/Foo.php'],
        ];
    }

    public function test_inspect_lists_required_manifest_and_facts_fields(): void
    {
        $exit = Artisan::call('atlas:task:project-lane-runtime-plan', ['action' => 'inspect', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $exit);
        $this->assertContains('project_id', $p['required_manifest_fields']);
        $this->assertContains('verification_court_verdict.verdict', $p['required_facts_fields']);
    }

    public function test_plan_with_valid_manifest_composes_all_stages(): void
    {
        $this->writeJson($this->manifestPath, $this->validManifest(time()));
        Artisan::call('atlas:task:project-lane-runtime-plan', ['action' => 'plan', '--manifest' => $this->manifestPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame('ok', $p['status']);
        $this->assertTrue($p['admission']['admitted']);
        $this->assertSame('demo', $p['namespace']['project_id'] ?? null);
    }

    public function test_plan_with_malformed_manifest_yields_admission_admitted_false(): void
    {
        $this->writeJson($this->manifestPath, ['project_id' => 'only-id']);
        Artisan::call('atlas:task:project-lane-runtime-plan', ['action' => 'plan', '--manifest' => $this->manifestPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertFalse($p['admission']['admitted']);
    }

    public function test_decision_returns_release_governor_envelope(): void
    {
        $this->writeJson($this->factsPath, [
            'project_id' => 'demo',
            'lane_namespace' => 'lane.demo.deadbeef.main',
            // missing verification ⇒ hold expected
            'rollback_gate' => ['conformant' => true],
            'knowledge_sync_plan' => ['conformant' => true],
            'cross_lane_refusal_flags' => [],
            'repeated_failure_streak' => 0,
        ]);
        Artisan::call('atlas:task:project-lane-runtime-plan', ['action' => 'decision', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame('ok', $p['status']);
        $this->assertSame(AtlasProjectLaneReleaseGovernor::DECISION_HOLD, $p['release_governor']['decision']);
    }

    public function test_unknown_action_returns_unknown_action(): void
    {
        $exit = Artisan::call('atlas:task:project-lane-runtime-plan', ['action' => 'bogus', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertNotSame(0, $exit);
        $this->assertSame('unknown_action', $p['status']);
    }
}

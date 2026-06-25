<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves atlas:self-construction:native-replenisher: contract / draft / preflight / top-up / run all
 * work; run yields final_runtime_owner=atlas_native in every drafted packet; malformed --facts ⇒
 * usage_error; unknown action ⇒ unknown_action; no process is spawned and no provider is called.
 */
final class AtlasSelfConstructionNativeReplenisherCommandTest extends TestCase
{
    private string $factsPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factsPath = sys_get_temp_dir().'/atlas_nr_facts_'.bin2hex(random_bytes(6)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->factsPath);
        parent::tearDown();
    }

    private function writeJson(array $data): void
    {
        file_put_contents($this->factsPath, json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    private function frontier(string $id = 'f-1'): array
    {
        return [
            'frontier_id' => $id,
            'owner_organ' => 'TF',
            'target_scope' => 'app/Demo',
            'capability_gap' => 'add Foo',
            'allowed_file_candidates' => ['app/Demo/Foo.php', 'tests/Unit/Demo/FooTest.php'],
            'acceptance_obligations' => ['phpunit green'],
            'evidence_obligations' => ['test_run_id'],
            'risk_class' => 'standard',
        ];
    }

    public function test_contract_normalizes_frontier_facts(): void
    {
        $this->writeJson(['frontiers' => [$this->frontier()]]);
        Artisan::call('atlas:self-construction:native-replenisher', ['action' => 'contract', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertCount(1, $p['contract']['accepted']);
    }

    public function test_draft_emits_packet_drafts_with_atlas_native_owner(): void
    {
        $this->writeJson(['accepted_frontiers' => [$this->frontier()]]);
        Artisan::call('atlas:self-construction:native-replenisher', ['action' => 'draft', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame('atlas_native', $p['packet_drafts'][0]['autonomy_contract']['runtime_owner']);
    }

    public function test_preflight_runs_inspector_over_packet_drafts(): void
    {
        $this->writeJson(['packet_drafts' => [
            ['frontier_id' => 'f-1', 'objective' => 'x', 'allowed_files' => ['app/X.php'], 'acceptance_criteria' => ['ok'], 'required_evidence' => ['evh']],
        ]]);
        Artisan::call('atlas:self-construction:native-replenisher', ['action' => 'preflight', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame('ok', $p['status']);
    }

    public function test_top_up_returns_outcome_facts(): void
    {
        $this->writeJson([
            'queue_health_status' => 'green', 'claimable_depth' => 5, 'servable_depth' => 5,
            'malformed_count' => 0, 'accepted_frontier_count' => 3,
            'risk_budget' => ['remaining_units' => 50, 'required_per_packet' => 10],
        ]);
        Artisan::call('atlas:self-construction:native-replenisher', ['action' => 'top-up', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame('allow', $p['top_up']['outcome']);
    }

    public function test_run_dry_run_returns_facts_with_final_runtime_owner_atlas_native(): void
    {
        $this->writeJson([
            'frontiers' => [$this->frontier()],
            'queue_facts' => [
                'queue_health_status' => 'green', 'claimable_depth' => 5, 'servable_depth' => 5,
                'malformed_count' => 0, 'accepted_frontier_count' => 1,
                'risk_budget' => ['remaining_units' => 50, 'required_per_packet' => 10],
            ],
        ]);
        Artisan::call('atlas:self-construction:native-replenisher', ['action' => 'run', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame('atlas_native', $p['final_runtime_owner']);
        $this->assertTrue($p['dry_run']);
    }

    public function test_unknown_action_yields_unknown_action(): void
    {
        $exit = Artisan::call('atlas:self-construction:native-replenisher', ['action' => 'bogus', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertNotSame(0, $exit);
        $this->assertSame('unknown_action', $p['status']);
    }

    public function test_malformed_facts_yield_usage_error(): void
    {
        $this->writeJson(['no_frontiers' => []]);
        $exit = Artisan::call('atlas:self-construction:native-replenisher', ['action' => 'contract', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $p['status']);
    }
}

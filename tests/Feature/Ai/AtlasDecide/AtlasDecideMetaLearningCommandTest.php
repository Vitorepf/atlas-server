<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AtlasDecide;

use App\Services\Ai\AtlasDecide\AtlasDecideMetaLearningService;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AtlasDecideMetaLearningCommandTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().'/atlas_meta_cli_'.uniqid('', true);
        @mkdir($this->tmpRoot, 0775, true);
        config(['atlas_rivals.ledger_root' => $this->tmpRoot.'/ledger']);
        $svc = $this->app->make(AtlasDecideMetaLearningService::class);
        $svc->setActivationLogPathForTesting($this->tmpRoot.'/routing_activations.jsonl');
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpRoot);
        parent::tearDown();
    }

    private function rmdirRecursive(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $full = $path.'/'.$f;
            is_dir($full) ? $this->rmdirRecursive($full) : @unlink($full);
        }
        @rmdir($path);
    }

    public function test_list_command_runs_with_zero_exit(): void
    {
        $out = new BufferedOutput;
        $code = Artisan::call('atlas:atlas-decide:meta-learning', [], $out);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('[atlas:atlas-decide:meta-learning]', $out->fetch());
    }

    public function test_json_output_is_well_formed(): void
    {
        $out = new BufferedOutput;
        $code = Artisan::call('atlas:atlas-decide:meta-learning', ['--json' => true], $out);
        $this->assertSame(0, $code);
        $decoded = json_decode($out->fetch(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('meta-learning:list', $decoded['action']);
        $this->assertArrayHasKey('recommendations', $decoded);
    }

    public function test_routing_table_command_runs(): void
    {
        $out = new BufferedOutput;
        $code = Artisan::call('atlas:atlas-decide:routing-table', ['--json' => true], $out);
        $this->assertSame(0, $code);
        $decoded = json_decode($out->fetch(), true);
        $this->assertSame('routing-table', $decoded['action']);
        $this->assertArrayHasKey('entries', $decoded['table']);
        $this->assertSame(AtlasDecideMetaLearningService::TABLE_SCHEMA, $decoded['table']['schema_version']);
    }

    public function test_advisory_map_json_output_is_read_only_and_advisory(): void
    {
        $out = new BufferedOutput;
        $code = Artisan::call('atlas:atlas-decide:meta-learning', [
            '--map' => true,
            '--json' => true,
        ], $out);

        $this->assertSame(0, $code);
        $decoded = json_decode($out->fetch(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('meta-learning:advisory-map', $decoded['action']);
        $this->assertSame(AtlasDecideMetaLearningService::ADVISORY_MAP_SCHEMA, $decoded['advisory_map']['schema_version']);
        $this->assertTrue($decoded['advisory_map']['advisory_only']);
        $this->assertFalse($decoded['advisory_map']['should_update_provider_topology']);
        $this->assertTrue($decoded['advisory_map']['never_changes_atlas_decide_topology']);
        $this->assertSame('atlas_decide', $decoded['advisory_map']['owner_of_model_routing']);
        $this->assertSame('none', $decoded['advisory_map']['routing_effect']);
        $this->assertFalse($decoded['advisory_map']['external_provider_call']);
        $this->assertFalse($decoded['advisory_map']['provider_tokens_spent']);
    }

    public function test_scoped_json_exposes_cost_outcome_diagnostics_when_enabled(): void
    {
        config(['atlas.patamar4.adml_cost_outcome' => [
            'enabled' => true,
            'min_evidence' => 3,
            'min_certification_rate' => 0.8,
            'min_score' => 80.0,
            'max_score_drop' => 3.0,
            'require_measured_cost' => true,
            'min_cost_samples' => 1,
        ]]);

        $out = new BufferedOutput;
        $code = Artisan::call('atlas:atlas-decide:meta-learning', [
            '--task-category' => 'bugfix',
            '--role' => 'repair_agent',
            '--framework' => 'python',
            '--json' => true,
        ], $out);

        $this->assertSame(0, $code);
        $decoded = json_decode($out->fetch(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('meta-learning:list', $decoded['action']);
        $this->assertTrue($decoded['recommendations'][0]['cost_outcome']['enabled']);
        $this->assertSame('blocked', $decoded['recommendations'][0]['cost_outcome']['status']);
        $this->assertContains('cost_outcome_no_relevant_ledger_evidence', $decoded['recommendations'][0]['reason']);
    }

    public function test_activate_requires_apply_and_confirm(): void
    {
        $out = new BufferedOutput;
        $code = Artisan::call('atlas:atlas-decide:meta-learning:activate', [
            '--task-category' => 'frontend',
            '--role' => 'builder',
            '--mode' => 'plan',
            '--json' => true,
        ], $out);
        $this->assertSame(0, $code);
        $decoded = json_decode($out->fetch(), true);
        $this->assertSame('planned', $decoded['status']);
    }

    public function test_reset_plan_does_not_mutate(): void
    {
        $out = new BufferedOutput;
        $code = Artisan::call('atlas:atlas-decide:meta-learning:reset', [
            '--mode' => 'plan',
            '--json' => true,
        ], $out);
        $this->assertSame(0, $code);
        $decoded = json_decode($out->fetch(), true);
        $this->assertSame('planned', $decoded['status']);
    }
}

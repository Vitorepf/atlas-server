<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Core\ArmRegistry;
use App\Services\Ai\Rivals\Core\RunAutopsy;
use App\Services\Ai\Rivals\Core\RunPlan;
use App\Services\Ai\Rivals\Support\RunPaths;
use App\Services\Ai\Rivals\Support\SchemaContract;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RunAutopsyTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals_autopsy_'.uniqid();
        config()->set('atlas_rivals.storage_root', $this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_autopsy_marks_incomplete_events_as_not_atlas_fact(): void
    {
        $arm = (new ArmRegistry)->parse('verboo_kimi_k2_7@bare', 'bfcl');
        $plan = RunPlan::make(
            'bfcl',
            ['case_a'],
            [$arm],
            1,
            ['max_usd' => 1.0, 'max_minutes' => 5],
            1,
        );
        $runId = $plan->persist();
        file_put_contents(RunPaths::adjudicationPath($runId), json_encode([
            'pipeline_valid' => true,
            'internal_claim_allowed' => true,
            'pipeline_blockers' => [],
            'internal_claim_blockers' => [],
            'not_ready_reasons' => [],
        ], JSON_UNESCAPED_SLASHES));

        $autopsy = (new RunAutopsy)->build($runId);
        $this->assertSame('atlas.rivals2.autopsy.v1', $autopsy['schema_version']);
        $this->assertFalse($autopsy['events']['complete']);
        $this->assertFalse($autopsy['trust']['is_atlas_fact']);
        $this->assertContains('events_incomplete', $autopsy['trust']['blockers']);
        $this->assertArrayHasKey('git_head', $autopsy['plan_provenance']);
        $md = (new RunAutopsy)->toMarkdown($autopsy);
        $this->assertStringContainsString('events_complete: false', $md);
    }

    public function test_autopsy_complete_with_events_and_claim(): void
    {
        $arm = (new ArmRegistry)->parse('verboo_kimi_k2_7@bare', 'bfcl');
        $plan = RunPlan::make(
            'bfcl',
            ['case_a'],
            [$arm],
            1,
            ['max_usd' => 1.0, 'max_minutes' => 5],
            1,
        );
        $runId = $plan->persist();
        file_put_contents(RunPaths::eventsPath($runId), json_encode([
            'timestamp' => now()->toIso8601String(),
            'event_type' => 'unit_finished',
            'data' => ['execution_id' => 'u1'],
        ], JSON_UNESCAPED_SLASHES).PHP_EOL);
        file_put_contents(RunPaths::adjudicationPath($runId), json_encode([
            'pipeline_valid' => true,
            'internal_claim_allowed' => true,
            'pipeline_blockers' => [],
            'internal_claim_blockers' => [],
            'not_ready_reasons' => [],
        ], JSON_UNESCAPED_SLASHES));
        file_put_contents(RunPaths::reportPath($runId), json_encode([
            'schema_version' => SchemaContract::REPORT,
            'run_id' => $runId,
            'rows' => [[
                'arm_id' => 'verboo_kimi_k2_7@bare',
                'success_rate_itt' => 1.0,
                'intelligence_rate' => 1.0,
                'avg_tokens_in' => 100.0,
                'avg_tokens_out' => 20.0,
                'median_wall_ms' => 1000.0,
                'environment_failure_rate' => 0.0,
            ]],
            'pipeline_valid' => true,
            'internal_claim_allowed' => true,
            'report_hash' => 'x',
        ], JSON_UNESCAPED_SLASHES));

        $autopsy = (new RunAutopsy)->build($runId);
        $this->assertTrue($autopsy['events']['complete']);
        $this->assertTrue($autopsy['trust']['is_atlas_fact']);
        $this->assertNotNull($autopsy['events']['heartbeat_age_seconds']);
    }

    public function test_autopsy_empty_report_rows_block_atlas_fact(): void
    {
        $arm = (new ArmRegistry)->parse('verboo_kimi_k2_7@bare', 'bfcl');
        $plan = RunPlan::make(
            'bfcl',
            ['case_a'],
            [$arm],
            1,
            ['max_usd' => 1.0, 'max_minutes' => 5],
            1,
        );
        $runId = $plan->persist();
        file_put_contents(RunPaths::eventsPath($runId), json_encode([
            'timestamp' => now()->toIso8601String(),
            'event_type' => 'unit_finished',
            'data' => ['execution_id' => 'u1'],
        ], JSON_UNESCAPED_SLASHES).PHP_EOL);
        file_put_contents(RunPaths::adjudicationPath($runId), json_encode([
            'pipeline_valid' => true,
            'internal_claim_allowed' => true,
            'pipeline_blockers' => [],
            'internal_claim_blockers' => [],
            'not_ready_reasons' => [],
        ], JSON_UNESCAPED_SLASHES));
        file_put_contents(RunPaths::reportPath($runId), json_encode([
            'schema_version' => SchemaContract::REPORT,
            'run_id' => $runId,
            'rows' => [],
            'pipeline_valid' => true,
            'internal_claim_allowed' => true,
            'report_hash' => 'x',
        ], JSON_UNESCAPED_SLASHES));

        $autopsy = (new RunAutopsy)->build($runId);
        $this->assertFalse($autopsy['trust']['is_atlas_fact']);
        $this->assertContains('measurement_incomplete', $autopsy['trust']['blockers']);
    }
}

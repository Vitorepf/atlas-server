<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\AtlasForgeLiveExecutionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasForgeLiveExecutionTest extends TestCase
{
    private const OBRA = '33333333-3333-3333-3333-333333333333';

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('atlas_ledger_events')) {
            Schema::create('atlas_ledger_events', function (Blueprint $t) {
                $t->string('event_id')->primary();
                $t->string('schema_version')->nullable();
                $t->string('tenant_id')->nullable();
                $t->string('operator_id')->nullable();
                $t->string('envelope_id')->nullable();
                $t->string('receipt_id')->nullable();
                $t->string('trace_id')->nullable();
                $t->string('correlation_id')->nullable();
                $t->string('causation_id')->nullable();
                $t->string('event_type')->nullable();
                $t->string('emitter_stage')->nullable();
                $t->string('emitter_version')->nullable();
                $t->json('payload')->nullable();
                $t->string('payload_hash')->nullable();
                $t->timestamp('occurred_at')->nullable();
                $t->timestamps();
            });
        }
    }

    public function test_live_execution_fails_closed_without_obra(): void
    {
        $report = app(AtlasForgeLiveExecutionService::class)->execute([]);

        $this->assertSame('blocked', $report['forge_live_execution_status']);
        $this->assertNull($report['inputs']['obra_id']);
        $this->assertFalse($report['inputs']['obra_provided']);
        $this->assertTrue($report['inputs']['requires_obra']);
        $this->assertContains('obra_required', $report['remaining_blockers']);

        $stages = collect($report['stages'])->keyBy('name');
        $this->assertTrue($stages->has('obra_binding'));
        $this->assertSame('blocked', $stages['obra_binding']['status']);
        $this->assertSame('obra_required', $stages['obra_binding']['blocker']);

        $this->assertFalse($stages->has('sandbox_provision'), 'Sandbox must NOT be provisioned without obra.');
        $this->assertFalse($stages->has('patch_apply'), 'Patch must NOT be applied without obra.');
        $this->assertFalse($stages->has('test_run'), 'Test must NOT run without obra.');
    }

    public function test_live_execution_runs_full_chain_with_obra(): void
    {
        $report = app(AtlasForgeLiveExecutionService::class)->execute([
            'obra_id' => self::OBRA,
        ]);

        $this->assertSame('atlas.forge_live_execution_certification.v1', $report['schema_version']);
        $this->assertSame('passed', $report['forge_live_execution_status']);
        $this->assertFalse($report['external_provider_call']);
        $this->assertSame(self::OBRA, $report['inputs']['obra_id']);
        $this->assertTrue($report['inputs']['obra_provided']);
        $this->assertSame('atlas_code', $report['inputs']['surface_id']);
        $this->assertSame('programming.forge', $report['inputs']['flow_id']);
        $this->assertSame([], $report['remaining_blockers']);
    }

    public function test_live_execution_exposes_all_canonical_stages(): void
    {
        $report = app(AtlasForgeLiveExecutionService::class)->execute([
            'obra_id' => self::OBRA,
        ]);

        $stages = collect($report['stages'])->keyBy('name');

        $expected = [
            'obra_binding',
            'sandbox_provision',
            'context_pack',
            'patch_apply',
            'action_manifest',
            'patch_verifier',
            'test_run',
            'stage_receipts',
            'repair_loop',
            'evidence_ledger',
            'sandbox_rollback',
        ];

        foreach ($expected as $name) {
            $this->assertTrue($stages->has($name), "Missing stage [{$name}].");
            $this->assertContains(
                $stages[$name]['status'],
                ['passed', 'degraded', 'skipped_not_needed'],
                "Stage [{$name}] returned blocked when only passed/degraded/skipped_not_needed expected: ".json_encode($stages[$name]),
            );
        }
    }

    public function test_context_pack_has_canonical_minimum_with_non_empty_ranked_refs(): void
    {
        $report = app(AtlasForgeLiveExecutionService::class)->execute([
            'obra_id' => self::OBRA,
        ]);

        $stages = collect($report['stages'])->keyBy('name');
        $contextPack = $stages['context_pack']['context_pack'];

        $this->assertSame('passed', $stages['context_pack']['status']);
        $this->assertSame('canonical_minimum', $contextPack['context_completeness']);
        $this->assertNotEmpty($contextPack['ranked_refs']);
        $this->assertGreaterThanOrEqual(11, count($contextPack['ranked_refs']));

        $kinds = collect($contextPack['ranked_refs'])->pluck('kind')->unique()->values()->all();
        $this->assertContains('canonical_doc', $kinds);
        $this->assertContains('service_implementation', $kinds);
        $this->assertContains('console_command', $kinds);
        $this->assertContains('test_evidence', $kinds);
        $this->assertContains('runtime_component', $kinds);

        $paths = collect($contextPack['ranked_refs'])->pluck('path')->all();
        $this->assertContains('docs/engineering-knowledge-base/atlas-programming-forge-flow.md', $paths);
        $this->assertContains('docs/engineering-knowledge-base/atlas-forge-live-execution-e2e-v1.md', $paths);
        $this->assertContains('app/Services/Ai/Programming/AtlasForgeLiveExecutionService.php', $paths);
        $this->assertContains('app/Console/Commands/AtlasForgeLiveExecuteCommand.php', $paths);
        $this->assertContains('tests/Feature/Ai/Programming/AtlasForgeLiveExecutionTest.php', $paths);

        foreach ($contextPack['ranked_refs'] as $ref) {
            $this->assertArrayHasKey('path', $ref);
            $this->assertArrayHasKey('kind', $ref);
            $this->assertArrayHasKey('reason', $ref);
            $this->assertArrayHasKey('evidence_marker', $ref);
            if ($ref['evidence_marker'] === 'present') {
                $this->assertNotNull($ref['content_hash']);
            }
        }
    }

    public function test_repair_loop_is_skipped_not_needed_when_test_passes(): void
    {
        $report = app(AtlasForgeLiveExecutionService::class)->execute([
            'obra_id' => self::OBRA,
        ]);

        $stages = collect($report['stages'])->keyBy('name');
        $repair = $stages['repair_loop'];

        $this->assertSame('skipped_not_needed', $repair['status']);
        $this->assertFalse($repair['triggered']);
        $this->assertNull($repair['plan']);
        $this->assertNull($repair['failure_packet']);
        $this->assertSame('test_passed_no_repair_required', $repair['reason']);
    }

    public function test_repair_loop_is_triggered_when_test_simulated_failure(): void
    {
        $report = app(AtlasForgeLiveExecutionService::class)->execute([
            'obra_id' => self::OBRA,
            'simulate_test_failure' => true,
        ]);

        $stages = collect($report['stages'])->keyBy('name');
        $repair = $stages['repair_loop'];

        $this->assertTrue($repair['triggered']);
        $this->assertSame('passed', $repair['status']);
        $this->assertSame('planned', $repair['plan_status']);
        $this->assertNotNull($repair['failure_packet']);
        $this->assertSame('test_failure', $repair['failure_packet']['failure_type']);
        $this->assertTrue($repair['failure_packet']['simulated_failure']);
        $this->assertSame('atlas.programming.repair_attempt.plan.v1', $repair['plan']['schema_version']);
        $this->assertSame('patch_repair_then_retest', $repair['plan']['next_action']);
    }

    public function test_live_execution_writes_and_cleans_sandbox_workspace(): void
    {
        $report = app(AtlasForgeLiveExecutionService::class)->execute([
            'obra_id' => self::OBRA,
        ]);

        $stages = collect($report['stages'])->keyBy('name');

        $this->assertSame('passed', $stages['sandbox_provision']['status']);
        $this->assertIsString($stages['sandbox_provision']['execution_workspace']);
        $this->assertSame('passed', $stages['patch_apply']['status']);
        $this->assertContains('forge-live-execution-fixture.txt', $stages['patch_apply']['changed_files']);

        $this->assertSame('passed', $stages['sandbox_rollback']['status']);
        $this->assertTrue($stages['sandbox_rollback']['workspace_cleaned']);
        $this->assertFalse(is_dir((string) $stages['sandbox_provision']['execution_workspace']));
    }

    public function test_live_execution_emits_ledger_events_when_table_present(): void
    {
        $report = app(AtlasForgeLiveExecutionService::class)->execute([
            'obra_id' => self::OBRA,
        ]);

        $stages = collect($report['stages'])->keyBy('name');
        $ledgerStage = $stages['evidence_ledger'];

        $this->assertContains($ledgerStage['status'], ['passed', 'degraded']);

        if ($ledgerStage['status'] === 'passed') {
            $this->assertNotEmpty($ledgerStage['ledger_event_ids']);
            $this->assertContains('execution_started', $ledgerStage['events_recorded']);
            $this->assertContains('evidence_packed', $ledgerStage['events_recorded']);
        }
    }

    public function test_live_execution_test_run_is_real_process_not_mock(): void
    {
        $report = app(AtlasForgeLiveExecutionService::class)->execute([
            'obra_id' => self::OBRA,
        ]);

        $stages = collect($report['stages'])->keyBy('name');
        $testResult = $stages['test_run']['result'];

        $this->assertSame(0, $testResult['exit_code']);
        $this->assertTrue($testResult['passed']);
        $this->assertStringContainsString('forge-live-execution-test-ok', $testResult['stdout_excerpt']);
        $this->assertNotSame(hash('sha256', ''), $testResult['stdout_hash']);
        $this->assertFalse($testResult['simulated_failure']);
    }
}

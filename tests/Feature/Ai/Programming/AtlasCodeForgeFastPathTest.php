<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Jobs\AtlasCodeForgeLiveExecutionJob;
use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasCodeForgeFastPathService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AtlasCodeForgeFastPathTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSchema();
        Queue::fake();
    }

    public function test_fast_path_fails_closed_without_obra(): void
    {
        $report = app(AtlasCodeForgeFastPathService::class)->run(null, []);

        $this->assertSame('atlas.code.forge_fast_path.v1', $report['schema_version']);
        $this->assertSame('blocked', $report['status']);
        $this->assertContains('obra_required', $report['blockers']);
        $this->assertNull($report['obra_id']);
        $this->assertNull($report['work_item_id']);
        $this->assertNull($report['execution_id']);
        $this->assertFalse($report['external_provider_call']);

        $obraStage = collect($report['stages'])->firstWhere('name', 'obra_binding');
        $this->assertNotNull($obraStage);
        $this->assertSame('blocked', $obraStage['status']);
        $this->assertSame('obra_required', $obraStage['blocker']);

        $this->assertNull(collect($report['stages'])->firstWhere('name', 'workspace_binding'));
    }

    public function test_fast_path_fails_closed_when_obra_not_found(): void
    {
        $report = app(AtlasCodeForgeFastPathService::class)->run(null, [
            'obra_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
        ]);

        $this->assertSame('blocked', $report['status']);
        $this->assertContains('obra_not_found', $report['blockers']);
    }

    public function test_fast_path_prepare_only_creates_work_item_and_compiles_spec_plan(): void
    {
        $obra = $this->makeObra();

        $report = app(AtlasCodeForgeFastPathService::class)->run($obra, [
            'obra_id' => (string) $obra->id,
            'mode' => AtlasCodeForgeFastPathService::MODE_PREPARE_ONLY,
            'start_execution' => false,
        ]);

        $this->assertSame('prepared', $report['status']);
        $this->assertSame((string) $obra->id, $report['obra_id']);
        $this->assertNotNull($report['work_item_id']);
        $this->assertNotNull($report['work_item_code']);
        $this->assertNotNull($report['spec_hash']);
        $this->assertNotNull($report['plan_hash']);
        $this->assertGreaterThan(0, $report['task_count']);
        $this->assertNull($report['execution_id']);
        $this->assertNull($report['history_id']);
        $this->assertSame([], $report['blockers']);
        $this->assertSame(
            $report['work_item_id'],
            data_get($obra->refresh()->metadata, 'latest_atlas_code_forge_fast_path.work_item_id'),
        );
        $this->assertSame(
            'prepared',
            data_get($obra->metadata, 'atlas_code_forge_fast_path_history.0.status'),
        );

        $this->getJson('/atlas-code/works/'.$obra->id.'/state', $this->headers())
            ->assertOk()
            ->assertJsonPath('forge_fast_path.schema_version', 'atlas.code.forge_fast_path.v1')
            ->assertJsonPath('forge_fast_path.status', 'prepared')
            ->assertJsonPath('forge_fast_path.work_item_id', $report['work_item_id']);

        $stageNames = collect($report['stages'])->pluck('name')->all();
        foreach ([
            'obra_binding',
            'workspace_binding',
            'work_item_resolution',
            'spec_plan_resolution',
            'task_queue_resolution',
            'execution_dispatch',
            'state_projection',
            'operator_next_action',
        ] as $expected) {
            $this->assertContains($expected, $stageNames, "Missing canonical stage [{$expected}].");
        }

        $workspaceStage = collect($report['stages'])->firstWhere('name', 'workspace_binding');
        $this->assertSame('passed', $workspaceStage['status']);
        $this->assertSame('atlas.workspace_intelligence.execution_gate.v1', data_get($workspaceStage, 'workspace_execution_gate.schema_version'));
        $this->assertTrue((bool) data_get($workspaceStage, 'workspace_execution_gate.allowed'));

        $dispatchStage = collect($report['stages'])->firstWhere('name', 'execution_dispatch');
        $this->assertSame(AtlasCodeForgeFastPathService::MODE_PREPARE_ONLY, $dispatchStage['mode']);
        $this->assertFalse($dispatchStage['dispatched']);

        Queue::assertNothingPushed();
    }

    public function test_fast_path_blocks_when_awis_workspace_is_not_registered(): void
    {
        $obra = $this->makeObra([
            'workspace_slug' => 'missing-workspace',
            'workspace_path' => '/tmp/unregistered-awis-workspace',
            'origin' => 'atlas-code-fast-path-test',
        ]);

        $report = app(AtlasCodeForgeFastPathService::class)->run($obra, [
            'obra_id' => (string) $obra->id,
            'mode' => AtlasCodeForgeFastPathService::MODE_EXECUTE_ASYNC,
        ]);

        $this->assertSame('blocked', $report['status']);
        $this->assertContains('awis_execution_gate_blocked', $report['blockers']);
        $this->assertNull($report['work_item_id']);
        $this->assertNull($report['execution_id']);

        $workspaceStage = collect($report['stages'])->firstWhere('name', 'workspace_binding');
        $this->assertSame('blocked', $workspaceStage['status']);
        $this->assertSame('awis_execution_gate_blocked', $workspaceStage['blocker']);
        $this->assertFalse((bool) data_get($workspaceStage, 'workspace_execution_gate.allowed'));
        Queue::assertNothingPushed();
    }

    public function test_fast_path_execute_async_dispatches_queued_status(): void
    {
        $obra = $this->makeObra();

        $report = app(AtlasCodeForgeFastPathService::class)->run($obra, [
            'obra_id' => (string) $obra->id,
            'mode' => AtlasCodeForgeFastPathService::MODE_EXECUTE_ASYNC,
        ]);

        $this->assertSame('queued', $report['status']);
        $this->assertNotNull($report['execution_id']);
        $this->assertSame('poll_async_execution_and_open_review_when_passed', $report['next_action']);
        $this->assertSame([], $report['blockers']);
        $this->assertFalse($report['external_provider_call']);

        $dispatchStage = collect($report['stages'])->firstWhere('name', 'execution_dispatch');
        $this->assertSame('passed', $dispatchStage['status']);
        $this->assertSame(AtlasCodeForgeFastPathService::MODE_EXECUTE_ASYNC, $dispatchStage['mode']);
        $this->assertTrue($dispatchStage['dispatched']);

        $this->assertArrayHasKey('forge_live_execution_async_start', $report['commands']);
        $this->assertArrayHasKey('forge_live_execution_async_show', $report['commands']);
        $this->assertSame(
            $report['execution_id'],
            data_get($obra->refresh()->metadata, 'latest_atlas_code_forge_fast_path.execution_id'),
        );

        Queue::assertPushed(AtlasCodeForgeLiveExecutionJob::class);
    }

    public function test_fast_path_persists_snapshot_and_exposes_state_projection(): void
    {
        $obra = $this->makeObra();

        $report = app(AtlasCodeForgeFastPathService::class)->run($obra, [
            'obra_id' => (string) $obra->id,
            'mode' => AtlasCodeForgeFastPathService::MODE_PREPARE_ONLY,
            'start_execution' => false,
        ]);

        $obra->refresh();

        $this->assertSame(
            $report['work_item_id'],
            data_get($obra->metadata, 'latest_atlas_code_forge_fast_path.work_item_id'),
        );
        $this->assertSame(
            $report['spec_hash'],
            data_get($obra->metadata, 'atlas_code_forge_fast_path_history.0.spec_hash'),
        );

        $this->getJson('/atlas-code/works/'.$obra->id.'/state', $this->headers())
            ->assertOk()
            ->assertJsonPath('forge_fast_path.schema_version', 'atlas.code.forge_fast_path.v1')
            ->assertJsonPath('forge_fast_path.status', 'prepared')
            ->assertJsonPath('forge_fast_path.work_item_id', $report['work_item_id'])
            ->assertJsonPath('forge_fast_path.spec_hash', $report['spec_hash']);
    }

    public function test_fast_path_blocks_when_obra_lacks_intent(): void
    {
        $obra = AtlasProject::create([
            'id' => (string) Str::uuid(),
            'title' => '',
            'description' => '',
            'status' => 'active',
            'domain' => 'atlas',
            'priority' => 'normal',
            'metadata' => [
                'workspace_slug' => 'atlas',
                'workspace_path' => base_path('..'),
            ],
        ]);

        $report = app(AtlasCodeForgeFastPathService::class)->run($obra, [
            'obra_id' => (string) $obra->id,
            'mode' => AtlasCodeForgeFastPathService::MODE_PREPARE_ONLY,
        ]);

        $this->assertSame('blocked', $report['status']);
        $this->assertContains('work_item_intent_required', $report['blockers']);
    }

    public function test_fast_path_status_returns_not_found_for_unknown_run(): void
    {
        $obra = $this->makeObra();
        $statusService = app(\App\Services\Ai\Programming\AtlasCodeForgeFastPathStatusService::class);

        $payload = $statusService->status($obra, '01KRBOGUSRUNZZZZZZZZZZZ');

        $this->assertSame('atlas.code.forge_fast_path_run_status.v1', $payload['schema_version']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('fast_path_run_not_found', $payload['blocker']);
        $this->assertFalse($payload['run_found']);
        $this->assertSame(404, $payload['http_status']);
    }

    public function test_fast_path_status_rejects_run_from_other_obra(): void
    {
        $owner = $this->makeObra();
        app(AtlasCodeForgeFastPathService::class)->run($owner, [
            'obra_id' => (string) $owner->id,
            'mode' => AtlasCodeForgeFastPathService::MODE_PREPARE_ONLY,
        ]);
        $owner->refresh();
        $ownerRun = data_get($owner->metadata, 'latest_atlas_code_forge_fast_path_run');
        $runId = (string) data_get($ownerRun, 'fast_path_run_id');

        $otherObra = $this->makeObra();
        $metadata = is_array($otherObra->metadata) ? $otherObra->metadata : [];
        $metadata['latest_atlas_code_forge_fast_path_run'] = $ownerRun;
        $otherObra->forceFill(['metadata' => $metadata])->save();

        $statusService = app(\App\Services\Ai\Programming\AtlasCodeForgeFastPathStatusService::class);
        $payload = $statusService->status($otherObra->refresh(), $runId);

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('fast_path_run_obra_mismatch', $payload['blocker']);
        $this->assertSame(403, $payload['http_status']);
    }

    public function test_fast_path_status_returns_canonical_lifecycle_payload(): void
    {
        $obra = $this->makeObra();
        app(AtlasCodeForgeFastPathService::class)->run($obra, [
            'obra_id' => (string) $obra->id,
            'mode' => AtlasCodeForgeFastPathService::MODE_PREPARE_ONLY,
        ]);
        $obra->refresh();
        $runId = (string) data_get($obra->metadata, 'latest_atlas_code_forge_fast_path_run.fast_path_run_id');

        $payload = app(\App\Services\Ai\Programming\AtlasCodeForgeFastPathStatusService::class)->status($obra, $runId);

        $this->assertSame('atlas.code.forge_fast_path_run_status.v1', $payload['schema_version']);
        $this->assertTrue($payload['run_found']);
        $this->assertSame($runId, $payload['fast_path_run_id']);
        $this->assertSame((string) $obra->id, $payload['obra_id']);
        $this->assertArrayHasKey('review_gate', $payload);
        $this->assertArrayHasKey('repair', $payload);
        $this->assertArrayHasKey('current_stage', $payload);
        $this->assertArrayHasKey('progress_percent', $payload);
        $this->assertArrayHasKey('commands', $payload);
        $this->assertTrue($payload['review_gate']['no_auto_completion_without_review']);
        $this->assertFalse($payload['external_provider_call']);
    }

    public function test_fast_path_resume_reuses_existing_work_item(): void
    {
        $obra = $this->makeObra();
        $first = app(AtlasCodeForgeFastPathService::class)->run($obra, [
            'obra_id' => (string) $obra->id,
            'mode' => AtlasCodeForgeFastPathService::MODE_PREPARE_ONLY,
        ]);
        $obra->refresh();
        $runId = (string) data_get($obra->metadata, 'latest_atlas_code_forge_fast_path_run.fast_path_run_id');
        $firstWorkItemId = $first['work_item_id'];

        // Resume e idempotente — nao recria WorkItem nem execucao.
        $resume = app(\App\Services\Ai\Programming\AtlasCodeForgeFastPathStatusService::class)->resume($obra, $runId);

        $this->assertSame($runId, $resume['fast_path_run_id']);
        $this->assertSame($firstWorkItemId, $resume['work_item_id']);

        // Re-rodar prepare nao deve criar novo WorkItem porque o existente e reusado.
        $second = app(AtlasCodeForgeFastPathService::class)->run($obra->refresh(), [
            'obra_id' => (string) $obra->id,
            'mode' => AtlasCodeForgeFastPathService::MODE_PREPARE_ONLY,
        ]);
        $this->assertSame($firstWorkItemId, $second['work_item_id']);
    }

    public function test_fast_path_does_not_auto_complete_without_review(): void
    {
        $obra = $this->makeObra();
        $report = app(AtlasCodeForgeFastPathService::class)->run($obra, [
            'obra_id' => (string) $obra->id,
            'mode' => AtlasCodeForgeFastPathService::MODE_EXECUTE_ASYNC,
        ]);
        $obra->refresh();
        $runId = (string) data_get($obra->metadata, 'latest_atlas_code_forge_fast_path_run.fast_path_run_id');
        $executionId = (string) ($report['execution_id'] ?? '');

        $metadata = is_array($obra->metadata) ? $obra->metadata : [];
        $asyncRuns = collect((array) data_get($metadata, 'atlas_code_forge_live_execution_async_history', []))
            ->map(function (mixed $entry) use ($executionId): mixed {
                if (! is_array($entry) || (string) ($entry['execution_id'] ?? '') !== $executionId) {
                    return $entry;
                }

                $entry['status'] = 'completed';
                $entry['finished_at'] = now()->toIso8601String();
                $entry['snapshot_status'] = 'passed';
                $entry['run_id'] = 'engineering-run-for-fast-path-review';
                $entry['evidence_id'] = 'engineering-evidence-for-fast-path-review';

                return $entry;
            })
            ->values()
            ->all();
        $metadata['atlas_code_forge_live_execution_async_history'] = $asyncRuns;
        $metadata['latest_forge_live_execution_async'] = $asyncRuns[0] ?? null;
        $metadata['latest_forge_live_execution'] = [
            'status' => 'passed',
            'obra_id' => (string) $obra->id,
            'run_id' => 'engineering-run-for-fast-path-review',
            'evidence_id' => 'engineering-evidence-for-fast-path-review',
            'last_run_at' => now()->toIso8601String(),
            'diff_scope' => ['completion_gate' => ['completion_claim_allowed' => true]],
        ];
        $obra->forceFill(['metadata' => $metadata])->save();

        $payload = app(\App\Services\Ai\Programming\AtlasCodeForgeFastPathStatusService::class)->status($obra->refresh(), $runId);

        $this->assertSame('review_required', $payload['status']);
        $this->assertTrue($payload['review_gate']['review_required']);
        $this->assertSame('pending', $payload['review_gate']['review_status']);
        $this->assertSame('open_human_review', $payload['next_action']);
    }

    public function test_fast_path_status_ignores_unrelated_latest_forge_execution(): void
    {
        $obra = $this->makeObra();
        app(AtlasCodeForgeFastPathService::class)->run($obra, [
            'obra_id' => (string) $obra->id,
            'mode' => AtlasCodeForgeFastPathService::MODE_PREPARE_ONLY,
        ]);
        $obra->refresh();
        $runId = (string) data_get($obra->metadata, 'latest_atlas_code_forge_fast_path_run.fast_path_run_id');

        $metadata = is_array($obra->metadata) ? $obra->metadata : [];
        $metadata['latest_forge_live_execution'] = [
            'status' => 'passed',
            'obra_id' => (string) $obra->id,
            'run_id' => 'different-engineering-run',
            'evidence_id' => 'different-engineering-evidence',
            'last_run_at' => now()->toIso8601String(),
            'diff_scope' => ['completion_gate' => ['completion_claim_allowed' => true]],
        ];
        $obra->forceFill(['metadata' => $metadata])->save();

        $payload = app(\App\Services\Ai\Programming\AtlasCodeForgeFastPathStatusService::class)->status($obra->refresh(), $runId);

        $this->assertSame('prepared', $payload['status']);
        $this->assertFalse($payload['review_gate']['review_required']);
        $this->assertSame('review_spec_plan_then_dispatch_forge', $payload['next_action']);
        $this->assertNull($payload['forge_live_execution']);
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function makeObra(array $metadata = []): AtlasProject
    {
        return AtlasProject::create([
            'id' => (string) Str::uuid(),
            'title' => 'Fast Path test Obra',
            'description' => 'Refatorar Atlas Code Forge Fast Path read-model',
            'status' => 'active',
            'domain' => 'atlas',
            'goal' => 'Validar Atlas Code Forge Fast Path runtime',
            'desired_outcome' => 'Validar Atlas Code Forge Fast Path runtime profissionalmente',
            'priority' => 'normal',
            'metadata' => array_merge([
                'workspace_slug' => 'atlas',
                'workspace_path' => base_path('..'),
                'origin' => 'atlas-code-fast-path-test',
            ], $metadata),
        ]);
    }

    /**
     * @return array<string,string>
     */
    private function headers(): array
    {
        return ['X-Atlas-Token' => 'testing-atlas-token-with-enough-length'];
    }

    private function ensureSchema(): void
    {
        if (! Schema::hasTable('atlas_projects')) {
            Schema::create('atlas_projects', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('title')->nullable();
                $t->text('description')->nullable();
                $t->string('status')->default('active');
                $t->string('domain')->default('atlas');
                $t->uuid('source_capture_id')->nullable();
                $t->text('goal')->nullable();
                $t->text('next_action')->nullable();
                $t->string('project_type')->nullable();
                $t->text('desired_outcome')->nullable();
                $t->text('minimum_viable_outcome')->nullable();
                $t->text('definition_of_done')->nullable();
                $t->text('why_now')->nullable();
                $t->timestamp('deadline_at')->nullable();
                $t->string('deadline_kind')->nullable();
                $t->string('priority')->default('normal');
                $t->string('energy_profile')->nullable();
                $t->text('avoidance_reason')->nullable();
                $t->uuid('active_next_task_id')->nullable();
                $t->uuid('current_step_id')->nullable();
                $t->timestamp('last_touched_at')->nullable();
                $t->timestamp('next_review_at')->nullable();
                $t->timestamp('completed_at')->nullable();
                $t->timestamp('paused_until')->nullable();
                $t->json('metadata')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }

        if (! Schema::hasTable('ai_threads')) {
            Schema::create('ai_threads', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('title')->nullable();
                $t->string('status')->default('active');
                $t->string('surface')->nullable();
                $t->string('workspace')->nullable();
                $t->string('source_type')->nullable();
                $t->uuid('source_id')->nullable();
                $t->integer('message_count')->default(0);
                $t->timestamp('last_message_at')->nullable();
                $t->json('metadata')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('ai_messages')) {
            Schema::create('ai_messages', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('thread_id');
                $t->uuid('trace_id')->nullable();
                $t->integer('position')->default(1);
                $t->string('role');
                $t->string('status')->default('completed');
                $t->text('content')->nullable();
                $t->timestamp('occurred_at')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('ai_traces')) {
            Schema::create('ai_traces', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('thread_id')->nullable();
                $t->string('source_type')->default('app');
                $t->uuid('source_id')->nullable();
                $t->string('status')->default('completed');
                $t->text('operator_input')->nullable();
                $t->text('response_text')->nullable();
                $t->json('metadata')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_programming_work_items')) {
            Schema::create('atlas_programming_work_items', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('code', 64)->unique();
                $t->text('intent_text');
                $t->string('intent_type', 40)->index();
                $t->string('scope_mode', 24)->index();
                $t->string('risk_level', 16)->default('medium')->index();
                $t->string('owner', 80)->nullable()->index();
                $t->string('workspace', 255)->nullable();
                $t->string('status', 32)->index();
                $t->string('current_stage', 32)->index();
                $t->string('spec_hash', 64)->nullable()->index();
                $t->string('plan_hash', 64)->nullable()->index();
                $t->json('placement_json')->default('{}');
                $t->json('code_intelligence_json')->default('{}');
                $t->json('spec_json')->default('{}');
                $t->json('plan_json')->default('{}');
                $t->json('tasks_json')->default('[]');
                $t->json('evidence_refs_json')->default('[]');
                $t->json('gaps_json')->default('[]');
                $t->json('metadata_json')->default('{}');
                $t->timestamp('closed_at')->nullable()->index();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_engineering_runs')) {
            Schema::create('atlas_engineering_runs', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('project_id')->nullable();
                $t->string('status')->nullable();
                $t->string('decision')->nullable();
                $t->timestamp('finished_at')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_engineering_evidence')) {
            Schema::create('atlas_engineering_evidence', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('project_id')->nullable();
                $t->uuid('task_id')->nullable();
                $t->string('evidence_type')->nullable();
                $t->string('target_id')->nullable();
                $t->string('status')->nullable();
                $t->float('confidence')->nullable();
                $t->text('summary')->nullable();
                $t->text('command')->nullable();
                $t->text('output_excerpt')->nullable();
                $t->json('files')->nullable();
                $t->json('metadata')->nullable();
                $t->string('source')->nullable();
                $t->timestamp('recorded_at')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_tool_runs')) {
            Schema::create('atlas_tool_runs', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('tool_slug')->nullable();
                $t->text('workspace')->nullable();
                $t->string('run_context_type')->nullable();
                $t->string('run_context_id')->nullable();
                $t->string('status')->nullable();
                $t->json('summary_json')->nullable();
                $t->timestamps();
            });
        }

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
}

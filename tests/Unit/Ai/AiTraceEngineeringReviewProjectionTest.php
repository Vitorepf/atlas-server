<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Models\AiStreamEvent;
use App\Models\AiTrace;
use App\Models\AtlasEngineeringControlResult;
use App\Models\AtlasEngineeringPatchArtifact;
use App\Models\AtlasEngineeringReviewFinding;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasEngineeringRunOperatorAction;
use App\Models\AtlasEngineeringTestRun;
use App\Services\Ai\Instrumentation\AiTraceEngineeringReviewProjection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AiTraceEngineeringReviewProjectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();
        config()->set('atlas.token', 'testing-atlas-token-with-enough-length');
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_engineering_file_review_decisions');
        Schema::dropIfExists('atlas_engineering_run_operator_actions');
        Schema::dropIfExists('atlas_engineering_review_findings');
        Schema::dropIfExists('atlas_engineering_test_runs');
        Schema::dropIfExists('atlas_engineering_control_results');
        Schema::dropIfExists('atlas_engineering_patch_artifacts');
        Schema::dropIfExists('atlas_engineering_runs');
        Schema::dropIfExists('ai_stream_events');
        Schema::dropIfExists('ai_traces');

        parent::tearDown();
    }

    public function test_projects_only_the_run_bound_to_the_trace_with_real_artifacts_and_review(): void
    {
        $trace = $this->trace('trace-visible');
        $run = $this->engineeringRun($trace->id, 'passed', 'resolved');
        $otherRun = $this->engineeringRun('trace-not-visible', 'failed', 'rejected');

        $patch = AtlasEngineeringPatchArtifact::create([
            'engineering_run_id' => $run->id,
            'base_ref' => 'base-123',
            'head_ref' => 'head-456',
            'diff_hash' => hash('sha256', 'diff'),
            'changed_files_json' => ['Sources/AtlasCore/InteractionRun.swift'],
            'created_files_json' => ['Sources/AtlasCore/New.swift'],
            'deleted_files_json' => [],
            'risk_flags_json' => ['networking'],
        ]);
        AtlasEngineeringPatchArtifact::create([
            'engineering_run_id' => $otherRun->id,
            'base_ref' => 'secret-base',
            'head_ref' => 'secret-head',
            'changed_files_json' => ['never/expose.swift'],
        ]);
        AtlasEngineeringControlResult::create([
            'engineering_run_id' => $run->id,
            'control_slug' => 'swift-core-checks',
            'status' => 'passed',
            'signal_summary' => 'green',
            'duration_ms' => 842,
        ]);
        AtlasEngineeringTestRun::create([
            'engineering_run_id' => $run->id,
            'command' => 'swift run AtlasCoreChecks',
            'status' => 'passed',
            'exit_code' => 0,
            'duration_ms' => 842,
        ]);
        AtlasEngineeringReviewFinding::create([
            'engineering_run_id' => $run->id,
            'source' => 'reviewer',
            'severity' => 'p1',
            'status' => 'open',
            'confidence' => 0.9,
            'title' => 'Verificar retomada',
            'file_path' => 'Sources/AtlasCore/InteractionRun.swift',
        ]);
        AtlasEngineeringRunOperatorAction::create([
            'engineering_run_id' => $run->id,
            'action' => 'needs_human',
            'actor' => 'operator',
            'status_before' => 'running',
            'status_after' => 'running',
            'acted_at' => now(),
        ]);

        $payload = app(AiTraceEngineeringReviewProjection::class)->forTrace($trace);

        $this->assertSame('atlas.trace_change_review.v1', $payload['schema_version']);
        $this->assertSame('available', $payload['state']);
        $this->assertSame($trace->id, $payload['trace_id']);
        $this->assertSame('passed', data_get($payload, 'run.status'));
        $this->assertSame('resolved', data_get($payload, 'run.decision'));
        $this->assertArrayNotHasKey('engineering_run_id', $payload['run']);
        $this->assertSame($patch->id, data_get($payload, 'patches.0.id'));
        $this->assertSame(['Sources/AtlasCore/InteractionRun.swift'], data_get($payload, 'patches.0.changed_files'));
        $this->assertStringContainsString("/ai/interactions/{$trace->id}/change-review/patches/{$patch->id}/diff", (string) data_get($payload, 'patches.0.diff_url'));
        $this->assertSame('swift-core-checks', data_get($payload, 'controls.0.slug'));
        $this->assertSame('swift run AtlasCoreChecks', data_get($payload, 'test_runs.0.command'));
        $this->assertSame('Verificar retomada', data_get($payload, 'review.findings.0.title'));
        $this->assertSame('needs_human', data_get($payload, 'review.operator_actions.0.action'));
        $this->assertSame(['accept', 'reject'], data_get($payload, 'review.available_actions'));
        $this->assertStringNotContainsString('never/expose.swift', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_fails_closed_when_a_trace_has_no_unique_engineering_run(): void
    {
        $trace = $this->trace('trace-ambiguous');
        $this->engineeringRun($trace->id, 'running', null);
        $this->engineeringRun($trace->id, 'running', null);

        $payload = app(AiTraceEngineeringReviewProjection::class)->forTrace($trace);

        $this->assertSame('unavailable', $payload['state']);
        $this->assertSame('ambiguous_linked_runs', $payload['reason']);
        $this->assertArrayNotHasKey('run', $payload);
        $this->assertSame([], $payload['patches']);
        $this->assertSame([], $payload['review']['available_actions']);
    }

    public function test_trace_scoped_api_exposes_only_the_public_projection(): void
    {
        $trace = $this->trace('trace-api');
        $run = $this->engineeringRun($trace->id, 'passed', 'resolved');
        AtlasEngineeringPatchArtifact::create([
            'engineering_run_id' => $run->id,
            'base_ref' => 'base',
            'head_ref' => 'head',
            'diff_excerpt' => "diff --git a/Atlas.swift b/Atlas.swift\n+safe diff\n",
            'changed_files_json' => ['Sources/AtlasCore/InteractionRun.swift'],
        ]);

        $response = $this->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->getJson("/ai/interactions/{$trace->id}/change-review");

        $response->assertOk();
        $response->assertJsonPath('change_review.state', 'available');
        $response->assertJsonPath('change_review.trace_id', $trace->id);
        $response->assertJsonMissingPath('change_review.run.engineering_run_id');
        $response->assertJsonPath('change_review.patches.0.changed_files.0', 'Sources/AtlasCore/InteractionRun.swift');
    }

    public function test_trace_scoped_diff_returns_only_the_bound_patch_without_an_internal_run_id(): void
    {
        $trace = $this->trace('trace-diff');
        $run = $this->engineeringRun($trace->id, 'passed', 'resolved');
        $patch = AtlasEngineeringPatchArtifact::create([
            'engineering_run_id' => $run->id,
            'diff_hash' => hash('sha256', 'safe diff'),
            'diff_excerpt' => 'safe diff',
            'changed_files_json' => ['Sources/AtlasCore/InteractionRun.swift'],
        ]);

        $response = $this->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->getJson("/ai/interactions/{$trace->id}/change-review/patches/{$patch->id}/diff");

        $response->assertOk();
        $response->assertJsonPath('patch.id', $patch->id);
        $response->assertJsonMissingPath('patch.engineering_run_id');
        $response->assertJsonPath('diff.content', 'safe diff');
        $response->assertJsonPath('diff.source', 'excerpt');
    }

    public function test_trace_scoped_review_action_records_a_trace_receipt_and_updates_the_bound_run(): void
    {
        $trace = $this->trace('trace-decision');
        $run = $this->engineeringRun($trace->id, 'running', null);
        $patch = AtlasEngineeringPatchArtifact::create([
            'engineering_run_id' => $run->id,
            'diff_hash' => hash('sha256', 'all-safe-diff'),
            'changed_files_json' => ['Sources/AtlasCore/InteractionRun.swift'],
        ]);

        $response = $this->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->postJson("/ai/interactions/{$trace->id}/change-review/action", [
                'action' => 'accept',
                'note' => 'verificado no aparelho',
            ]);

        $response->assertOk();
        $response->assertJsonPath('review_receipt.action', 'accept');
        $response->assertJsonPath('review_receipt.status_after', 'passed');
        $response->assertJsonMissingPath('review_receipt.engineering_run_id');
        $this->assertSame('passed', $run->refresh()->status);
        $this->assertSame('resolved', $run->decision);
        $this->assertSame(1, AiStreamEvent::query()->where('trace_id', $trace->id)->count());
        $this->assertSame('trace_change_review_decided', AiStreamEvent::query()->firstOrFail()->metadata['name']);
        $this->assertDatabaseHas('atlas_engineering_file_review_decisions', [
            'engineering_run_id' => $run->id,
            'patch_artifact_id' => $patch->id,
            'file_path' => 'Sources/AtlasCore/InteractionRun.swift',
            'action' => 'accept',
        ]);
    }

    public function test_trace_scoped_file_review_action_persists_a_bound_file_decision_and_ledger_receipt(): void
    {
        $trace = $this->trace('trace-file-decision');
        $run = $this->engineeringRun($trace->id, 'running', null);
        $patch = AtlasEngineeringPatchArtifact::create([
            'engineering_run_id' => $run->id,
            'diff_hash' => hash('sha256', 'single-file-diff'),
            'changed_files_json' => ['Sources/AtlasCore/InteractionRun.swift'],
        ]);

        $response = $this->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->postJson("/ai/interactions/{$trace->id}/change-review/file-action", [
                'patch_id' => $patch->id,
                'file_path' => 'Sources/AtlasCore/InteractionRun.swift',
                'action' => 'accept',
                'note' => 'Revisado contra os checks.',
            ]);

        $response->assertOk();
        $response->assertJsonPath('file_review_receipt.action', 'accept');
        $response->assertJsonPath('file_review_receipt.file_path', 'Sources/AtlasCore/InteractionRun.swift');
        $response->assertJsonPath('change_review.patches.0.file_reviews.0.action', 'accept');
        $this->assertDatabaseHas('atlas_engineering_file_review_decisions', [
            'engineering_run_id' => $run->id,
            'patch_artifact_id' => $patch->id,
            'file_path' => 'Sources/AtlasCore/InteractionRun.swift',
            'action' => 'accept',
        ]);
        $this->assertSame('trace_change_review_file_decided', AiStreamEvent::query()->firstOrFail()->metadata['name']);
    }

    public function test_trace_scoped_file_review_refuses_a_file_not_present_in_the_bound_patch(): void
    {
        $trace = $this->trace('trace-file-not-present');
        $run = $this->engineeringRun($trace->id, 'running', null);
        $patch = AtlasEngineeringPatchArtifact::create([
            'engineering_run_id' => $run->id,
            'changed_files_json' => ['Sources/AtlasCore/InteractionRun.swift'],
        ]);

        $response = $this->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->postJson("/ai/interactions/{$trace->id}/change-review/file-action", [
                'patch_id' => $patch->id,
                'file_path' => 'App/Atlas/RootView.swift',
                'action' => 'reject',
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('atlas_engineering_file_review_decisions', 0);
        $this->assertDatabaseCount('ai_stream_events', 0);
    }

    private function trace(string $key): AiTrace
    {
        return AiTrace::create([
            'trace_key' => $key,
            'status' => 'completed',
            'operator_input' => 'revisar mudança',
        ]);
    }

    private function engineeringRun(string $traceId, string $status, ?string $decision): AtlasEngineeringRun
    {
        return AtlasEngineeringRun::create([
            'trace_id' => $traceId,
            'workspace_path_hash' => hash('sha256', $traceId),
            'workspace_label' => 'atlas-native',
            'provider_strategy_json' => [],
            'status' => $status,
            'decision' => $decision,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'metadata' => [],
        ]);
    }

    private function createTables(): void
    {
        Schema::dropIfExists('atlas_engineering_file_review_decisions');
        Schema::dropIfExists('atlas_engineering_run_operator_actions');
        Schema::dropIfExists('atlas_engineering_review_findings');
        Schema::dropIfExists('atlas_engineering_test_runs');
        Schema::dropIfExists('atlas_engineering_control_results');
        Schema::dropIfExists('atlas_engineering_patch_artifacts');
        Schema::dropIfExists('atlas_engineering_runs');
        Schema::dropIfExists('ai_stream_events');
        Schema::dropIfExists('ai_traces');

        Schema::create('ai_traces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('trace_key')->nullable();
            $table->string('status')->default('queued');
            $table->text('operator_input')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('ai_stream_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->uuid('ai_job_id')->nullable();
            $table->uuid('ai_job_attempt_id')->nullable();
            $table->unsignedBigInteger('sequence');
            $table->string('event_type');
            $table->string('channel')->nullable();
            $table->text('content')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->nullable();
        });
        Schema::create('atlas_engineering_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->string('workspace_path_hash', 64);
            $table->string('workspace_label', 180);
            $table->json('provider_strategy_json')->default('{}');
            $table->string('status', 32)->default('queued');
            $table->string('decision', 32)->nullable();
            $table->unsignedSmallInteger('score')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });
        Schema::create('atlas_engineering_patch_artifacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('engineering_run_id');
            $table->string('base_ref', 120)->nullable();
            $table->string('head_ref', 120)->nullable();
            $table->string('diff_hash', 64)->nullable();
            $table->text('diff_excerpt')->nullable();
            $table->json('changed_files_json')->default('[]');
            $table->json('created_files_json')->default('[]');
            $table->json('deleted_files_json')->default('[]');
            $table->json('risk_flags_json')->default('[]');
            $table->timestamps();
        });
        Schema::create('atlas_engineering_file_review_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('engineering_run_id');
            $table->uuid('patch_artifact_id');
            $table->string('file_path', 1024);
            $table->string('patch_diff_hash', 64)->nullable();
            $table->string('action', 16);
            $table->string('actor', 120)->default('operator');
            $table->text('note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['patch_artifact_id', 'file_path'], 'atlas_eng_file_review_patch_file_unique');
        });
        Schema::create('atlas_engineering_control_results', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('engineering_run_id');
            $table->string('control_slug', 120);
            $table->string('status', 32);
            $table->text('signal_summary');
            $table->integer('duration_ms')->default(0);
            $table->timestamps();
        });
        Schema::create('atlas_engineering_test_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('engineering_run_id');
            $table->string('command', 500)->nullable();
            $table->integer('exit_code')->nullable();
            $table->string('status', 32);
            $table->integer('duration_ms')->default(0);
            $table->timestamps();
        });
        Schema::create('atlas_engineering_review_findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('engineering_run_id');
            $table->string('source', 80)->default('manual_review');
            $table->string('severity', 8)->default('p2');
            $table->string('status', 32)->default('open');
            $table->decimal('confidence', 5, 3)->nullable();
            $table->string('title', 180);
            $table->text('file_path')->nullable();
            $table->timestamps();
        });
        Schema::create('atlas_engineering_run_operator_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('engineering_run_id');
            $table->string('action', 40);
            $table->string('actor', 120)->default('operator');
            $table->string('status_before', 32)->nullable();
            $table->string('decision_before', 32)->nullable();
            $table->string('status_after', 32)->nullable();
            $table->string('decision_after', 32)->nullable();
            $table->text('note')->nullable();
            $table->json('payload_json')->default('{}');
            $table->timestamp('acted_at')->nullable();
            $table->timestamps();
        });
    }
}

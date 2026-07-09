<?php

namespace Tests\Feature\Console;

use App\Models\AtlasTask;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasReviewDeepTaskReviewPacketTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureTables();
    }

    protected function tearDown(): void
    {
        $this->dropTables();
        parent::tearDown();
    }

    private function ensureTables(): void
    {
        if (! Schema::hasTable('atlas_tasks')) {
            Schema::create('atlas_tasks', function ($table) {
                $table->uuid('id')->primary();
                $table->string('title')->nullable();
                $table->text('description')->nullable();
                $table->string('status')->default('open');
                $table->string('priority')->nullable();
                $table->string('domain')->nullable();
                $table->uuid('project_id')->nullable();
                $table->uuid('project_step_id')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->timestamp('deleted_at')->nullable();
            });
        }

        if (! Schema::hasTable('atlas_engineering_runs')) {
            Schema::create('atlas_engineering_runs', function ($table) {
                $table->uuid('id')->primary();
                $table->uuid('task_id')->nullable();
                $table->uuid('project_id')->nullable();
                $table->uuid('project_step_id')->nullable();
                $table->string('workspace_path_hash')->nullable();
                $table->string('workspace_label')->nullable();
                $table->json('provider_strategy_json')->nullable();
                $table->string('status')->default('pending');
                $table->string('decision')->nullable();
                $table->integer('score')->nullable();
                $table->integer('max_attempts')->default(0);
                $table->integer('attempt_count')->default(0);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_engineering_review_findings')) {
            Schema::create('atlas_engineering_review_findings', function ($table) {
                $table->uuid('id')->primary();
                $table->uuid('engineering_run_id')->nullable();
                $table->uuid('attempt_id')->nullable();
                $table->uuid('task_id')->nullable();
                $table->string('source')->nullable();
                $table->string('severity')->nullable();
                $table->string('status')->default('open');
                $table->float('confidence')->default(0.5);
                $table->string('category')->nullable();
                $table->string('title')->nullable();
                $table->text('body')->nullable();
                $table->string('file_path')->nullable();
                $table->integer('start_line')->nullable();
                $table->integer('end_line')->nullable();
                $table->json('evidence_json')->nullable();
                $table->text('recommendation')->nullable();
                $table->json('resolution_json')->nullable();
                $table->timestamp('detected_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_evidence')) {
            Schema::create('atlas_evidence', function ($table) {
                $table->uuid('id')->primary();
                $table->uuid('task_id')->nullable();
                $table->string('evidence_type')->nullable();
                $table->string('target_id')->nullable();
                $table->string('status')->nullable();
                $table->float('confidence')->nullable();
                $table->text('summary')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
    }

    private function dropTables(): void
    {
        Schema::dropIfExists('atlas_evidence');
        Schema::dropIfExists('atlas_engineering_review_findings');
        Schema::dropIfExists('atlas_engineering_runs');
        Schema::dropIfExists('atlas_tasks');
    }

    public function test_structured_packet_on_existing_task(): void
    {
        $task = AtlasTask::create([
            'title' => 'test-review-task',
            'status' => 'open',
        ]);

        $exitCode = Artisan::call('atlas:review:deep', [
            '--task-id' => $task->id,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $output = json_decode(Artisan::output(), true);

        // Verify schema version is present
        $this->assertArrayHasKey('schema_version', $output);
        $this->assertSame('atlas.review_deep.packet.v1', $output['schema_version']);

        // Verify task_ref is present and correct
        $this->assertArrayHasKey('task_ref', $output);
        $this->assertSame($task->id, $output['task_ref']['task_id']);
        $this->assertSame('test-review-task', $output['task_ref']['title']);

        // Verify run_ref is present
        $this->assertArrayHasKey('run_ref', $output);
        $this->assertArrayHasKey('run_id', $output['run_ref']);
        $this->assertArrayHasKey('status', $output['run_ref']);

        // Verify files_reviewed is an array
        $this->assertArrayHasKey('files_reviewed', $output);
        $this->assertIsArray($output['files_reviewed']);

        // Verify findings is an array
        $this->assertArrayHasKey('findings', $output);
        $this->assertIsArray($output['findings']);

        // Verify risk_level is present
        $this->assertArrayHasKey('risk_level', $output);
        $this->assertContains($output['risk_level'], ['low', 'medium', 'high', 'critical']);

        // Verify operator_next_actions is an array
        $this->assertArrayHasKey('operator_next_actions', $output);
        $this->assertIsArray($output['operator_next_actions']);

        // Verify recommendation is present
        $this->assertArrayHasKey('recommendation', $output);
        $this->assertContains($output['recommendation'], ['approve', 'reject']);

        // No findings means approve and low risk
        $this->assertSame('approve', $output['recommendation']);
        $this->assertSame('low', $output['risk_level']);
    }

    public function test_fail_closed_on_nonexistent_task(): void
    {
        $exitCode = Artisan::call('atlas:review:deep', [
            '--task-id' => 'nonexistent-task-id',
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);

        $output = json_decode(Artisan::output(), true);

        // Verify fail-closed packet structure
        $this->assertArrayHasKey('schema_version', $output);
        $this->assertSame('atlas.review_deep.packet.v1', $output['schema_version']);

        // Verify error and remediation are present
        $this->assertArrayHasKey('error', $output);
        $this->assertSame('task_not_found', $output['error']);

        $this->assertArrayHasKey('remediation', $output);
        $this->assertStringContainsString('task-id', $output['remediation']);

        // Verify recommendation is reject for fail-closed
        $this->assertSame('reject', $output['recommendation']);

        // Verify risk_level is unknown for fail-closed
        $this->assertSame('unknown', $output['risk_level']);

        // Verify operator_next_actions has remediation steps
        $this->assertIsArray($output['operator_next_actions']);
        $this->assertNotEmpty($output['operator_next_actions']);
    }

    public function test_packet_with_findings_produces_correct_risk_and_recommendation(): void
    {
        $task = AtlasTask::create([
            'title' => 'test-review-with-findings',
            'status' => 'open',
        ]);

        $finding = [
            'severity' => 'p0',
            'confidence' => 0.95,
            'category' => 'security',
            'title' => 'Critical security vulnerability',
            'body' => 'Unvalidated input in user-facing endpoint',
            'file_path' => 'app/Http/Controllers/UserController.php',
            'start_line' => 42,
            'end_line' => 45,
            'recommendation' => 'Add input validation and sanitization',
        ];

        $exitCode = Artisan::call('atlas:review:deep', [
            '--task-id' => $task->id,
            '--json' => true,
            '--finding' => [json_encode($finding)],
        ]);

        $this->assertSame(1, $exitCode);

        $output = json_decode(Artisan::output(), true);

        // P0 finding should produce critical risk and reject recommendation
        $this->assertSame('critical', $output['risk_level']);
        $this->assertSame('reject', $output['recommendation']);

        // Verify findings array contains the finding
        $this->assertNotEmpty($output['findings']);
        $this->assertCount(1, $output['findings']);
        $this->assertSame('p0', $output['findings'][0]['severity']);

        // Verify files_reviewed contains the file path
        $this->assertContains('app/Http/Controllers/UserController.php', $output['files_reviewed']);

        // Verify operator_next_actions includes verification commands
        $this->assertNotEmpty($output['operator_next_actions']);
    }

    public function test_empty_task_id_produces_fail_closed(): void
    {
        $exitCode = Artisan::call('atlas:review:deep', [
            '--task-id' => '',
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);

        $output = json_decode(Artisan::output(), true);
        $this->assertSame('task_not_found', $output['error']);
        $this->assertSame('reject', $output['recommendation']);
    }
}

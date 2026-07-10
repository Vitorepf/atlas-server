<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\AtlasEngineeringReviewFinding;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasTask;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AtlasReviewDeepTaskReviewPacketTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureTables();
    }

    public function test_structured_packet_on_existing_task(): void
    {
        $task = AtlasTask::create([
            'title' => 'test-review-task',
            'status' => 'open',
        ]);

        $exit = Artisan::call('atlas:review:deep', [
            '--task-id' => $task->id,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $output = json_decode(Artisan::output(), true);
        $this->assertIsArray($output);
        $this->assertSame('atlas.review.deep_packet.v1', $output['schema_version']);
        $this->assertSame($task->id, $output['task_ref']);
        $this->assertArrayHasKey('run_ref', $output);
        $this->assertArrayHasKey('files_reviewed', $output);
        $this->assertArrayHasKey('findings', $output);
        $this->assertArrayHasKey('risk_level', $output);
        $this->assertArrayHasKey('verification_commands', $output);
        $this->assertArrayHasKey('recommendation', $output);
        $this->assertContains('atlas:review:deep --task-id='.$task->id.' --json', $output['verification_commands']);
    }

    public function test_fail_closed_on_nonexistent_task(): void
    {
        $exit = Artisan::call('atlas:review:deep', [
            '--task-id' => 'nonexistent-task-id',
            '--json' => true,
        ]);

        $this->assertSame(1, $exit);
        $output = json_decode(Artisan::output(), true);
        $this->assertIsArray($output);
        $this->assertFalse($output['ok']);
        $this->assertArrayHasKey('error', $output);
        $this->assertArrayHasKey('remediation', $output);
    }

    public function test_packet_with_findings_produces_correct_risk_and_recommendation(): void
    {
        $task = AtlasTask::create([
            'title' => 'test-review-with-findings',
            'status' => 'open',
        ]);

        $run = AtlasEngineeringRun::create([
            'task_id' => $task->id,
            'workspace_label' => 'manual-review',
            'workspace_path_hash' => hash('sha256', 'manual_review:'.$task->id),
            'provider_strategy_json' => ['source' => 'atlas_review'],
            'status' => 'passed',
            'decision' => 'partial',
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        AtlasEngineeringReviewFinding::create([
            'engineering_run_id' => $run->id,
            'task_id' => $task->id,
            'severity' => 'p0',
            'status' => 'open',
            'confidence' => 0.95,
            'category' => 'logic',
            'title' => 'Blocking issue',
            'body' => 'Something is wrong',
            'file_path' => 'app/Foo.php',
            'start_line' => 10,
            'end_line' => 12,
            'evidence_json' => [],
            'recommendation' => 'Fix it',
        ]);

        $exit = Artisan::call('atlas:review:deep', [
            '--task-id' => $task->id,
            '--json' => true,
        ]);

        $this->assertSame(1, $exit);
        $output = json_decode(Artisan::output(), true);
        $this->assertSame('blocking', $output['risk_level']);
        $this->assertSame('reject', $output['recommendation']);
        $this->assertContains('app/Foo.php', $output['files_reviewed']);
        $this->assertNotEmpty($output['findings']);
        $this->assertSame('Blocking issue', $output['findings'][0]['title']);
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
                $table->string('project_id')->nullable();
                $table->string('project_step_id')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_engineering_runs')) {
            Schema::create('atlas_engineering_runs', function ($table) {
                $table->uuid('id')->primary();
                $table->string('task_id')->nullable();
                $table->string('project_id')->nullable();
                $table->string('project_step_id')->nullable();
                $table->string('workspace_path_hash')->nullable();
                $table->string('workspace_label')->nullable();
                $table->json('provider_strategy_json')->nullable();
                $table->string('status')->nullable();
                $table->string('decision')->nullable();
                $table->float('score')->nullable();
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
                $table->string('engineering_run_id')->nullable();
                $table->string('task_id')->nullable();
                $table->string('severity')->nullable();
                $table->string('status')->default('open');
                $table->float('confidence')->nullable();
                $table->string('category')->nullable();
                $table->string('title')->nullable();
                $table->text('body')->nullable();
                $table->string('file_path')->nullable();
                $table->integer('start_line')->nullable();
                $table->integer('end_line')->nullable();
                $table->json('evidence_json')->nullable();
                $table->text('recommendation')->nullable();
                $table->timestamps();
            });
        }
    }
}

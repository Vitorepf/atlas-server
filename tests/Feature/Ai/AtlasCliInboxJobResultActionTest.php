<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AiInboxItem;
use App\Models\AiJob;
use App\Services\Ai\Mobile\JobResultInboxEmitter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AtlasCliInboxJobResultActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureTables();
    }

    public function test_job_result_item_exposes_operator_actions(): void
    {
        $job = $this->createJob('failed', 'critical');

        $item = $this->emit($job);

        $actionIds = collect($item->available_actions ?? [])->pluck('id')->all();

        $this->assertContains('approve_job_result', $actionIds);
        $this->assertContains('reject_job_result', $actionIds);
        $this->assertContains('rerun_job_result', $actionIds);
        $this->assertContains('view_trace', $actionIds);
        $this->assertContains('dismiss', $actionIds);
    }

    public function test_approve_job_result_resolves_item_via_cli(): void
    {
        $job = $this->createJob('succeeded', 'high');
        $item = $this->emit($job);

        $exit = Artisan::call('atlas:cli:inbox', [
            'action' => 'respond',
            'id' => $item->id,
            '--action' => 'approve_job_result',
            '--reason' => 'result verified by operator',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);

        $item->refresh();
        $this->assertSame('resolved', $item->status);
        $this->assertNotNull($item->resolved_at);
        $this->assertSame('approve_job_result', $item->response['action'] ?? null);
        $this->assertSame('result verified by operator', $item->response['reason'] ?? null);
        $this->assertSame($job->id, $item->response['job_id'] ?? null);
    }

    public function test_reject_job_result_keeps_item_open_for_investigation(): void
    {
        $job = $this->createJob('failed', 'high');
        $item = $this->emit($job);

        $exit = Artisan::call('atlas:cli:inbox', [
            'action' => 'respond',
            'id' => $item->id,
            '--action' => 'reject_job_result',
            '--reason' => 'needs investigation',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);

        $item->refresh();
        $this->assertSame('read', $item->status);
        $this->assertNull($item->resolved_at);
        $this->assertSame('reject_job_result', $item->response['action'] ?? null);
    }

    public function test_rerun_job_result_is_available_for_failed_jobs(): void
    {
        $job = $this->createJob('failed', 'high');
        $item = $this->emit($job);

        $exit = Artisan::call('atlas:cli:inbox', [
            'action' => 'respond',
            'id' => $item->id,
            '--action' => 'rerun_job_result',
            '--reason' => 'transient failure',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);

        $item->refresh();
        $this->assertSame('rerun_job_result', $item->response['action'] ?? null);
        $this->assertSame($job->id, $item->response['job_id'] ?? null);
    }

    public function test_rerun_job_result_fails_closed_for_non_failed_job(): void
    {
        $job = $this->createJob('succeeded', 'high');
        $item = $this->emit($job);

        $exit = Artisan::call('atlas:cli:inbox', [
            'action' => 'respond',
            'id' => $item->id,
            '--action' => 'rerun_job_result',
            '--reason' => 'should not work',
            '--json' => true,
        ]);

        $this->assertSame(1, $exit);
    }

    public function test_job_result_action_is_idempotent(): void
    {
        $job = $this->createJob('succeeded', 'high');
        $item = $this->emit($job);

        $idempotencyKey = 'cli-approve_job_result-'.$item->id;

        $first = Artisan::call('atlas:cli:inbox', [
            'action' => 'respond',
            'id' => $item->id,
            '--action' => 'approve_job_result',
            '--reason' => 'verified',
            '--json' => true,
        ]);
        $this->assertSame(0, $first);

        $second = Artisan::call('atlas:cli:inbox', [
            'action' => 'respond',
            'id' => $item->id,
            '--action' => 'approve_job_result',
            '--reason' => 'verified',
            '--json' => true,
        ]);
        $this->assertSame(0, $second);

        $item->refresh();
        $this->assertSame('resolved', $item->status);
    }

    private function emit(AiJob $job): AiInboxItem
    {
        $item = app(JobResultInboxEmitter::class)->emitIfImportant($job);
        $this->assertInstanceOf(AiInboxItem::class, $item);

        return $item;
    }

    private function createJob(string $status, string $importance): AiJob
    {
        return AiJob::query()->create([
            'trace_id' => null,
            'client_id' => 'test',
            'kind' => 'test_kind',
            'status' => $status,
            'priority' => 50,
            'agent_slug' => 'test-agent',
            'provider' => 'test-provider',
            'model' => 'test-model',
            'input_text' => 'input',
            'prompt' => 'prompt',
            'context_refs' => [],
            'payload' => ['mobile' => ['importance' => $importance]],
            'result_text' => 'result',
            'result_json' => [],
            'error_code' => null,
            'error_message' => null,
            'available_at' => now(),
            'reserved_at' => null,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'attempts' => 1,
            'max_attempts' => 3,
            'timeout_seconds' => 60,
            'worker_id' => null,
            'metadata' => [],
        ]);
    }

    private function ensureTables(): void
    {
        if (! Schema::hasTable('ai_jobs')) {
            Schema::create('ai_jobs', function ($table) {
                $table->uuid('id')->primary();
                $table->string('trace_id')->nullable();
                $table->string('client_id')->nullable();
                $table->string('kind')->nullable();
                $table->string('status')->default('pending');
                $table->integer('priority')->default(0);
                $table->string('agent_slug')->nullable();
                $table->string('provider')->nullable();
                $table->string('model')->nullable();
                $table->text('input_text')->nullable();
                $table->text('prompt')->nullable();
                $table->json('context_refs')->nullable();
                $table->json('payload')->nullable();
                $table->text('result_text')->nullable();
                $table->json('result_json')->nullable();
                $table->string('error_code')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('available_at')->nullable();
                $table->timestamp('reserved_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->integer('attempts')->default(0);
                $table->integer('max_attempts')->default(1);
                $table->integer('timeout_seconds')->nullable();
                $table->string('worker_id')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_context_bundles')) {
            Schema::create('ai_context_bundles', function ($table) {
                $table->uuid('id')->primary();
                $table->string('user_id')->default('vitor');
                $table->string('purpose', 64);
                $table->string('title', 180);
                $table->text('summary');
                $table->text('body_for_thread');
                $table->json('source_refs')->default('[]');
                $table->json('trace_refs')->default('[]');
                $table->json('job_refs')->default('[]');
                $table->json('metric_refs')->default('[]');
                $table->json('file_refs')->default('[]');
                $table->json('diff_refs')->default('[]');
                $table->json('raw_payload')->default('{}');
                $table->string('redaction_status', 24)->default('clean');
                $table->integer('token_estimate')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_inbox_items')) {
            Schema::create('ai_inbox_items', function ($table) {
                $table->uuid('id')->primary();
                $table->string('user_id')->nullable();
                $table->string('type')->default('alert');
                $table->string('category')->nullable();
                $table->string('severity')->default('info');
                $table->string('status')->default('unread');
                $table->string('title')->nullable();
                $table->text('summary')->nullable();
                $table->text('body')->nullable();
                $table->string('source_type')->nullable();
                $table->string('source_id')->nullable();
                $table->string('initiator')->default('system');
                $table->string('context_bundle_id')->nullable();
                $table->string('dedupe_key')->nullable();
                $table->json('available_actions')->nullable();
                $table->json('response')->nullable();
                $table->json('payload')->nullable();
                $table->string('deep_link')->nullable();
                $table->json('push_policy')->nullable();
                $table->integer('priority_score')->default(50);
                $table->float('confidence_score')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('snoozed_until')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamp('dismissed_at')->nullable();
                $table->timestamps();
            });
        }
    }
}

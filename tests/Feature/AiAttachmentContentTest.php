<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\AiThread;
use App\Models\AiTrace;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AiAttachmentContentTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'testing-atlas-token-with-enough-length'];

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'testing-atlas-token-with-enough-length');
        $this->createTables();
        $this->root = storage_path('app/ai/attachments/test-content-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        $this->dropTables();

        parent::tearDown();
    }

    public function test_trace_resource_exposes_safe_attachment_urls_and_serves_content(): void
    {
        $pdfPath = $this->root.'/document.pdf';
        $pagePath = $this->root.'/page-1.png';
        File::put($pdfPath, '%PDF-test');
        File::put($pagePath, 'png-page');
        $hash = hash_file('sha256', $pdfPath);

        $trace = $this->traceWithPayload([
            'attachments' => [
                'files' => [[
                    'path' => $pdfPath,
                    'original_name' => 'documento.pdf',
                    'mime_type' => 'application/pdf',
                    'bytes' => File::size($pdfPath),
                    'sha256' => $hash,
                    'source' => 'mobile_upload',
                    'text_available' => true,
                    'text_truncated' => false,
                    'pdf_page_count' => 1,
                    'pdf_processing_status' => 'processed',
                    'pdf_render_status' => 'rendered',
                    'pdf_rendered_page_count' => 1,
                    'pdf_ocr_status' => 'unavailable',
                    'pdf_rendered_pages' => [[
                        'page' => 1,
                        'path' => $pagePath,
                    ]],
                ]],
            ],
        ]);

        $this
            ->withHeaders($this->headers)
            ->getJson('/ai/interactions/'.$trace->id)
            ->assertOk()
            ->assertJsonPath('trace.attachments.0.id', $hash)
            ->assertJsonMissingPath('trace.job.payload.attachments.files.0.path')
            ->assertJsonPath('trace.attachments.0.preview_pages.0.page', 1);

        $this
            ->withHeaders($this->headers)
            ->get('/ai/interactions/'.$trace->id.'/attachments/'.$hash.'/content')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this
            ->withHeaders($this->headers)
            ->get('/ai/interactions/'.$trace->id.'/attachments/'.$hash.'/pages/1')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_attachment_content_refuses_paths_outside_attachment_root(): void
    {
        $outsidePath = sys_get_temp_dir().'/atlas-outside-'.bin2hex(random_bytes(4)).'.pdf';
        File::put($outsidePath, '%PDF-outside');
        $hash = hash_file('sha256', $outsidePath);

        $trace = $this->traceWithPayload([
            'attachments' => [
                'files' => [[
                    'path' => $outsidePath,
                    'original_name' => 'outside.pdf',
                    'mime_type' => 'application/pdf',
                    'bytes' => File::size($outsidePath),
                    'sha256' => $hash,
                ]],
            ],
        ]);

        $this
            ->withHeaders($this->headers)
            ->get('/ai/interactions/'.$trace->id.'/attachments/'.$hash.'/content')
            ->assertNotFound();

        File::delete($outsidePath);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function traceWithPayload(array $payload): AiTrace
    {
        $thread = AiThread::query()->create([
            'title' => 'Thread teste',
            'status' => 'active',
            'surface' => 'test',
            'metadata' => [],
        ]);

        $trace = AiTrace::query()->create([
            'trace_key' => 'trace-'.bin2hex(random_bytes(4)),
            'thread_id' => $thread->id,
            'source_type' => 'app',
            'status' => 'queued',
            'operator_input' => 'analise o pdf',
            'intent' => 'test',
            'agent_slug' => 'atlas',
            'metadata' => [],
        ]);

        AiJob::query()->create([
            'trace_id' => $trace->id,
            'client_id' => (string) \Illuminate\Support\Str::uuid(),
            'kind' => 'interaction',
            'status' => 'queued',
            'priority' => 5,
            'agent_slug' => 'atlas',
            'input_text' => 'analise o pdf',
            'prompt' => 'prompt',
            'context_refs' => [],
            'payload' => $payload,
            'metadata' => [],
        ]);

        return $trace;
    }

    private function createTables(): void
    {
        if (! Schema::hasTable('ai_threads')) {
            Schema::create('ai_threads', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('title')->nullable();
                $table->text('summary')->nullable();
                $table->string('status')->default('active');
                $table->string('surface')->nullable();
                $table->string('workspace')->nullable();
                $table->string('source_type')->nullable();
                $table->uuid('source_id')->nullable();
                $table->uuid('last_trace_id')->nullable();
                $table->string('last_provider')->nullable();
                $table->integer('message_count')->default(0);
                $table->timestamp('last_message_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_traces')) {
            Schema::create('ai_traces', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('trace_key')->unique();
                $table->uuid('thread_id')->nullable();
                $table->uuid('session_id')->nullable();
                $table->string('source_type')->nullable();
                $table->string('source_id')->nullable();
                $table->string('status')->default('queued');
                $table->text('operator_input');
                $table->string('intent')->nullable();
                $table->string('agent_slug')->nullable();
                $table->string('provider')->nullable();
                $table->string('model')->nullable();
                $table->json('skill_versions')->nullable();
                $table->json('context_refs')->nullable();
                $table->string('prompt_hash')->nullable();
                $table->string('response_hash')->nullable();
                $table->text('response_text')->nullable();
                $table->integer('latency_ms')->nullable();
                $table->integer('feedback_score')->nullable();
                $table->string('feedback_action')->nullable();
                $table->text('feedback_comment')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_jobs')) {
            Schema::create('ai_jobs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('trace_id')->nullable();
                $table->uuid('client_id')->nullable();
                $table->string('kind')->default('interaction');
                $table->string('status')->default('queued');
                $table->integer('priority')->default(5);
                $table->string('agent_slug')->nullable();
                $table->string('provider')->nullable();
                $table->string('model')->nullable();
                $table->text('input_text')->nullable();
                $table->longText('prompt')->nullable();
                $table->json('context_refs')->nullable();
                $table->json('payload')->nullable();
                $table->longText('result_text')->nullable();
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

        if (! Schema::hasTable('ai_job_attempts')) {
            Schema::create('ai_job_attempts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('ai_job_id')->nullable();
                $table->integer('attempt_number')->default(1);
                $table->string('worker_id')->nullable();
                $table->string('provider')->nullable();
                $table->string('model')->nullable();
                $table->json('command')->nullable();
                $table->string('command_hash')->nullable();
                $table->string('prompt_hash')->nullable();
                $table->string('response_hash')->nullable();
                $table->string('status')->default('queued');
                $table->integer('exit_code')->nullable();
                $table->integer('duration_ms')->nullable();
                $table->longText('output_text')->nullable();
                $table->text('stdout_excerpt')->nullable();
                $table->text('stderr_excerpt')->nullable();
                $table->string('error_code')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
    }

    private function dropTables(): void
    {
        Schema::dropIfExists('ai_job_attempts');
        Schema::dropIfExists('ai_jobs');
        Schema::dropIfExists('ai_traces');
        Schema::dropIfExists('ai_threads');
    }
}

<?php

namespace Tests\Feature;

use App\Models\AiQualityEvaluation;
use App\Models\AiTrace;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AiQualityBackfillCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
    }

    protected function tearDown(): void
    {
        $this->dropTables();
        parent::tearDown();
    }

    public function test_dry_run_counts_candidates_without_writing(): void
    {
        $this->seedTrace('trace_a', 'succeeded', 'Resposta válida do Atlas.');
        $this->seedTrace('trace_b', 'failed', 'Erro: rate limited.');
        $this->seedTrace('trace_c', 'cancelled', null);                  // null response → not a candidate
        $this->seedTrace('trace_d', 'succeeded', '');                    // empty response → not a candidate
        $this->seedTrace('trace_e', 'queued', 'inflight, no terminal');  // not terminal → not a candidate

        $exitCode = $this->artisan('atlas:ai:quality:backfill', [
            '--hours' => 24,
            '--dry-run' => true,
        ])->run();

        $this->assertSame(0, $exitCode);
        $this->assertSame(
            0,
            AiQualityEvaluation::query()->count(),
            'Dry-run must not create any evaluations even when 2 candidates exist.'
        );
    }

    public function test_backfill_creates_evaluations_for_eligible_traces(): void
    {
        $this->seedTrace('trace_a', 'succeeded', 'Atlas respondeu corretamente sobre a tarefa.');
        $this->seedTrace('trace_b', 'failed', 'Error: provider timed out after retries.');

        $exitCode = $this->artisan('atlas:ai:quality:backfill', ['--hours' => 24])->run();

        $this->assertSame(0, $exitCode);
        $this->assertSame(2, AiQualityEvaluation::query()->count(),
            'Both candidates (succeeded + failed with response) must produce evaluations.');
        $this->assertSame(
            2,
            AiQualityEvaluation::query()->where('evaluator_version', 'heuristic-v1')->count(),
            'Backfill must tag rows with evaluator_version=heuristic-v1.'
        );
    }

    public function test_backfill_skips_non_terminal_and_empty_response_traces(): void
    {
        $this->seedTrace('queued_one', 'queued', 'inflight content');
        $this->seedTrace('processing_one', 'processing', 'mid-stream');
        $this->seedTrace('succeeded_empty', 'succeeded', '');
        $this->seedTrace('succeeded_null', 'succeeded', null);

        $this->artisan('atlas:ai:quality:backfill', ['--hours' => 24])->run();

        $this->assertSame(0, AiQualityEvaluation::query()->count(),
            'None of these traces meet the (terminal status + non-empty response) criteria.');
    }

    public function test_backfill_is_idempotent(): void
    {
        $this->seedTrace('trace_a', 'succeeded', 'Resposta normal.');

        $this->artisan('atlas:ai:quality:backfill', ['--hours' => 24])->run();
        $firstCount = AiQualityEvaluation::query()->count();
        $firstId = AiQualityEvaluation::query()->first()->id ?? null;

        $this->artisan('atlas:ai:quality:backfill', ['--hours' => 24])->run();

        $this->assertSame($firstCount, AiQualityEvaluation::query()->count(),
            'Re-running backfill must not duplicate — whereDoesntHave excludes evaluated traces.');
        $this->assertSame($firstId, AiQualityEvaluation::query()->first()->id,
            'The original evaluation row must remain (no rewriting on rerun).');
    }

    public function test_since_option_overrides_hours_window(): void
    {
        // Trace from 5 days ago — out of default 24h window, in custom 7d window.
        // Insert via DB::table because created_at/updated_at are not in AiTrace::$fillable
        // and Eloquent's automatic-timestamps would overwrite explicit values.
        \Illuminate\Support\Facades\DB::table('ai_traces')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'trace_key' => 'trace_old',
            'source_type' => 'manual',
            'status' => 'succeeded',
            'operator_input' => 'old question',
            'agent_slug' => 'orquestrador',
            'response_text' => 'Resposta antiga.',
            'completed_at' => now()->subDays(5),
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
            'metadata' => json_encode([]),
        ]);

        // 24h window misses the 5-day-old trace.
        $this->artisan('atlas:ai:quality:backfill', ['--hours' => 24])->run();
        $this->assertSame(0, AiQualityEvaluation::query()->count(),
            '24h --hours window must not pick up a 5-day-old trace.');

        // --since 7 days ago does.
        $this->artisan('atlas:ai:quality:backfill', [
            '--since' => now()->subDays(7)->toDateString(),
        ])->run();
        $this->assertSame(1, AiQualityEvaluation::query()->count(),
            '--since=7d must pick up the trace that --hours=24 missed.');
    }


    private function seedTrace(string $key, string $status, ?string $response): AiTrace
    {
        return AiTrace::query()->create([
            'trace_key' => $key,
            'source_type' => 'manual',
            'status' => $status,
            'operator_input' => 'q',
            'agent_slug' => 'orquestrador',
            'response_text' => $response,
            'completed_at' => $status === 'queued' ? null : now(),
            'metadata' => [],
        ]);
    }

    private function createTables(): void
    {
        $this->dropTables();

        Schema::create('ai_traces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('trace_key')->nullable();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('source_type')->nullable();
            $table->string('status')->default('queued');
            $table->text('operator_input')->nullable();
            $table->string('agent_slug')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->json('skill_versions')->nullable();
            $table->json('context_refs')->nullable();
            $table->text('response_text')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->integer('feedback_score')->nullable();
            $table->string('feedback_action')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_quality_evaluations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('agent_slug')->nullable();
            $table->string('evaluator_version')->default('heuristic-v1');
            $table->unsignedTinyInteger('score');
            $table->string('status');
            $table->json('dimensions')->nullable();
            $table->json('flags')->nullable();
            $table->json('suggested_actions')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    private function dropTables(): void
    {
        foreach (['ai_quality_evaluations', 'ai_traces'] as $table) {
            Schema::dropIfExists($table);
        }
    }
}

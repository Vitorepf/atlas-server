<?php

namespace Tests\Feature\Ai;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiInteractionClientIdFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'testing-atlas-token-with-enough-length');

        Schema::dropIfExists('ai_jobs');
        Schema::dropIfExists('ai_traces');

        Schema::create('ai_traces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('trace_key')->nullable();
            $table->string('source_type')->default('app');
            $table->string('source_id')->nullable();
            $table->string('status')->default('queued');
            $table->text('operator_input')->nullable();
            $table->text('response_text')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->string('client_id')->nullable()->index();
            $table->string('kind')->nullable();
            $table->string('status')->nullable();
            $table->json('payload')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_jobs');
        Schema::dropIfExists('ai_traces');

        parent::tearDown();
    }

    public function test_ai_interactions_can_be_filtered_by_job_client_id(): void
    {
        $wantedTraceId = (string) Str::uuid();
        $otherTraceId = (string) Str::uuid();
        $now = now();

        DB::table('ai_traces')->insert([
            [
                'id' => $wantedTraceId,
                'trace_key' => 'voice_trace_wanted',
                'source_type' => 'voice_realtime',
                'status' => 'queued',
                'operator_input' => 'turno de voz esperado',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => $otherTraceId,
                'trace_key' => 'voice_trace_other',
                'source_type' => 'voice_realtime',
                'status' => 'queued',
                'operator_input' => 'turno de voz errado',
                'created_at' => $now->copy()->subMinute(),
                'updated_at' => $now->copy()->subMinute(),
            ],
        ]);

        DB::table('ai_jobs')->insert([
            [
                'id' => (string) Str::uuid(),
                'trace_id' => $wantedTraceId,
                'client_id' => 'voice:session-a:turn-a',
                'kind' => 'interaction',
                'status' => 'queued',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => (string) Str::uuid(),
                'trace_id' => $otherTraceId,
                'client_id' => 'voice:session-b:turn-b',
                'kind' => 'interaction',
                'status' => 'queued',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $this->withHeaders([
            'Accept' => 'application/json',
            'X-Atlas-Token' => 'testing-atlas-token-with-enough-length',
        ])
            ->getJson('/ai/interactions?client_id=voice:session-a:turn-a')
            ->assertOk()
            ->assertJsonCount(1, 'traces')
            ->assertJsonPath('traces.0.id', $wantedTraceId)
            ->assertJsonPath('traces.0.jobs.0.client_id', 'voice:session-a:turn-a');
    }
}

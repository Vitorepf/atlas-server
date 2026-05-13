<?php

namespace Tests\Feature\Ai;

use App\Models\AiTrace;
use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAiLedgerBackfillTracesCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTraceTable();
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        Schema::dropIfExists('ai_traces');

        parent::tearDown();
    }

    public function test_command_backfills_provider_events_from_existing_traces_without_raw_text(): void
    {
        $trace = AiTrace::query()->create([
            'trace_key' => 'trace_backfill_test',
            'status' => 'succeeded',
            'operator_input' => 'private operator prompt',
            'intent' => 'feature',
            'agent_slug' => 'programming.frontend',
            'provider' => 'codex_cli',
            'model' => 'gpt-5.2',
            'prompt_hash' => hash('sha256', 'prompt'),
            'response_hash' => hash('sha256', 'response'),
            'response_text' => 'private provider response',
            'latency_ms' => 1234,
            'metadata' => [
                'decision_receipt' => [
                    'envelope_id' => 'env_trace_backfill',
                    'receipt_id' => 'receipt_trace_backfill',
                    'metadata' => [
                        'tenant_id' => 'tenant_trace',
                        'operator_id' => 'operator_trace',
                    ],
                ],
                'task_request' => [
                    'domain' => 'programming',
                    'flow' => 'programming.feature',
                ],
            ],
        ]);

        $exit = Artisan::call('atlas:ai:ledger-backfill-traces', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame(1, $payload['candidate_count']);
        $this->assertSame(1, $payload['backfilled_count']);

        $event = AtlasLedgerEvent::query()->firstOrFail();
        $this->assertSame(LedgerEventType::ProviderReturned->value, $event->event_type);
        $this->assertSame('atlas.ledger_backfill.ai_traces', $event->emitter_stage);
        $this->assertSame($trace->id, $event->trace_id);
        $this->assertSame('codex_cli', data_get($event->payload, 'provider_cli'));
        $this->assertSame('programming', data_get($event->payload, 'domain'));
        $this->assertSame('succeeded', data_get($event->payload, 'exit_status'));
        $this->assertFalse(data_get($event->payload, 'raw_prompt_in_ledger'));
        $this->assertFalse(data_get($event->payload, 'raw_response_in_ledger'));
        $this->assertFalse(data_get($event->payload, 'raw_operator_input_in_ledger'));
        $this->assertArrayNotHasKey('response_text', $event->payload);

        $performanceExit = Artisan::call('atlas:ai:provider-performance', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $performance = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $performanceExit);
        $this->assertSame('ok', $performance['status']);
        $this->assertSame(1, data_get($performance, 'provider_performance.event_count'));
        $this->assertSame(1, data_get($performance, 'provider_performance.success_count'));
    }

    public function test_command_is_idempotent_and_supports_dry_run(): void
    {
        AiTrace::query()->create([
            'trace_key' => 'trace_backfill_idempotent',
            'status' => 'succeeded',
            'intent' => 'review',
            'agent_slug' => 'programming.architecture',
            'provider' => 'claude_cli',
            'model' => 'claude-sonnet-4-6',
            'metadata' => [],
        ]);

        $dryRunExit = Artisan::call('atlas:ai:ledger-backfill-traces', [
            '--dry-run' => true,
            '--json' => true,
        ]);
        $dryRun = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $dryRunExit);
        $this->assertSame(1, $dryRun['candidate_count']);
        $this->assertSame(0, $dryRun['backfilled_count']);
        $this->assertSame('would_backfill', data_get($dryRun, 'events.0.status'));
        $this->assertSame(0, AtlasLedgerEvent::query()->count());

        Artisan::call('atlas:ai:ledger-backfill-traces', ['--json' => true]);
        $secondExit = Artisan::call('atlas:ai:ledger-backfill-traces', ['--json' => true]);
        $second = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $secondExit);
        $this->assertSame(1, AtlasLedgerEvent::query()->count());
        $this->assertSame(0, $second['backfilled_count']);
        $this->assertSame(1, $second['skipped_count']);
        $this->assertSame('skipped_existing', data_get($second, 'events.0.status'));
    }

    private function createTraceTable(): void
    {
        Schema::dropIfExists('ai_traces');

        Schema::create('ai_traces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('trace_key')->nullable();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('source_type')->nullable();
            $table->uuid('source_id')->nullable();
            $table->string('status')->default('queued');
            $table->text('operator_input')->nullable();
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
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }
}

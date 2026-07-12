<?php

namespace Tests\Feature\Ai\Compaction;

use App\Jobs\GenerateVerifiedL2HierarchicalSummaryJob;
use App\Models\AiCompaction;
use App\Models\AiMessage;
use App\Models\AiSession;
use App\Models\AiSessionState;
use App\Models\AiThread;
use App\Models\AtlasL2HierarchicalSummary;
use App\Models\AtlasLongHorizonCompactionReceipt;
use App\Services\Ai\AiCompactionService;
use App\Services\Ai\Compaction\LocalL2SummaryRewriter;
use App\Services\Ai\Compaction\VerifiedL2HierarchicalSummaryService;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesLongHorizonPersistenceTables;
use Tests\TestCase;

final class VerifiedL2HierarchicalSummaryTest extends TestCase
{
    use CreatesLongHorizonPersistenceTables;

    private string $evidencePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evidencePath = storage_path('app/atlas/evidence/test-l2-hierarchical-summary.jsonl');
        File::delete($this->evidencePath);

        config()->set('atlas.compaction.l2_hierarchical_summary_evidence_path', $this->evidencePath);
        config()->set('atlas.compaction.l2_hierarchical_summary_consumption_enabled', false);

        $this->createLongHorizonPersistenceTables();
        $this->createAiRuntimeTables();
        $this->createL2SummaryTable();
    }

    protected function tearDown(): void
    {
        File::delete($this->evidencePath);

        foreach ([
            'atlas_l2_hierarchical_summaries',
            'ai_compactions',
            'ai_session_states',
            'ai_messages',
            'ai_sessions',
            'ai_threads',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        $this->dropLongHorizonPersistenceTables();

        parent::tearDown();
    }

    public function test_job_records_rejected_l2_without_touching_canonical_l1_summary(): void
    {
        [$thread, $compaction] = $this->seedCompactionWithReceipt(
            str_repeat('L1 verbose context. ', 20).'Alpha retained. Beta retained.',
        );
        $originalThreadSummary = $thread->summary;
        $originalCompactionSummary = $compaction->summary;
        $originalReceiptHash = AtlasLongHorizonCompactionReceipt::query()->firstOrFail()->receipt_hash;

        $this->bindRewriterReturning('Alpha retained.');

        (new GenerateVerifiedL2HierarchicalSummaryJob((string) $compaction->id))
            ->handle(app(VerifiedL2HierarchicalSummaryService::class));

        $row = AtlasL2HierarchicalSummary::query()->firstOrFail();
        $this->assertSame('rejected', $row->status);
        $this->assertContains('coverage_below_1', $row->rejection_reasons);
        $this->assertContains('retention_below_l1', $row->rejection_reasons);
        $this->assertSame(0.5, $row->coverage_score);
        $this->assertSame(0.5, $row->l2_context_retention_score);

        $this->assertSame($originalThreadSummary, $thread->fresh()->summary);
        $this->assertSame($originalCompactionSummary, $compaction->fresh()->summary);
        $this->assertSame($originalReceiptHash, AtlasLongHorizonCompactionReceipt::query()->firstOrFail()->receipt_hash);

        $evidence = AppendOnlyJsonlStore::read($this->evidencePath);
        $this->assertSame('rejected', $evidence[0]['status'] ?? null);
        $this->assertSame(false, $evidence[0]['canonical_l1_changed'] ?? null);
    }

    public function test_accepted_l2_is_versioned_separate_and_consumed_only_when_flag_is_on(): void
    {
        [$thread, $compaction] = $this->seedCompactionWithReceipt(
            str_repeat('L1 verbose context that should not be consumer-visible when L2 is disabled. ', 12)
            .'mk-alpha Alpha retained. mk-beta Beta retained.',
        );
        $l1Summary = $compaction->summary;

        $this->bindRewriterReturning('mk-alpha Alpha retained. mk-beta Beta retained.');

        $row = app(VerifiedL2HierarchicalSummaryService::class)
            ->generateForCompaction((string) $compaction->id);

        $this->assertSame('accepted', $row?->status);
        $this->assertSame(1, $row?->version);
        $this->assertSame(1.0, $row?->coverage_score);
        $this->assertSame(1.0, $row?->l2_context_retention_score);
        $this->assertSame(1.0, $row?->l1_context_retention_score);
        $this->assertLessThan(0.6, $row?->compression_ratio);
        $this->assertSame(hash('sha256', $l1Summary), $row?->l1_summary_hash);
        $this->assertSame($l1Summary, $thread->fresh()->summary);

        $reader = app(VerifiedL2HierarchicalSummaryService::class);
        $this->assertSame('l1_canonical', $reader->summaryForReading($compaction->fresh())['source']);
        $this->assertSame($l1Summary, $reader->summaryForReading($compaction->fresh())['summary']);

        config()->set('atlas.compaction.l2_hierarchical_summary_consumption_enabled', true);

        $visible = $reader->summaryForReading($compaction->fresh());
        $this->assertSame('l2_hierarchical_verified', $visible['source']);
        $this->assertSame($row?->l2_summary, $visible['summary']);

        $evidence = AppendOnlyJsonlStore::read($this->evidencePath);
        $this->assertSame('accepted', $evidence[0]['status'] ?? null);
        $this->assertEquals(1.0, $evidence[0]['retention'] ?? null);
        $this->assertLessThan(0.6, $evidence[0]['compression_ratio'] ?? 1.0);
    }

    public function test_generation_flag_controls_async_job_dispatch_without_inline_l2_consumption(): void
    {
        Queue::fake();

        config()->set('atlas.compaction.l2_hierarchical_summary_generation_enabled', false);
        [$threadOff, $sessionOff] = $this->seedConversationForCompact('MAXF-09 flag off');

        app(AiCompactionService::class)->compact($threadOff, $sessionOff, 'manual');

        Queue::assertNotPushed(GenerateVerifiedL2HierarchicalSummaryJob::class);

        config()->set('atlas.compaction.l2_hierarchical_summary_generation_enabled', true);
        [$threadOn, $sessionOn] = $this->seedConversationForCompact('MAXF-09 flag on');

        app(AiCompactionService::class)->compact($threadOn, $sessionOn, 'manual');

        Queue::assertPushed(GenerateVerifiedL2HierarchicalSummaryJob::class, 1);
        $this->assertSame(
            'l1_canonical',
            app(VerifiedL2HierarchicalSummaryService::class)
                ->summaryForReading(AiCompaction::query()->latest('created_at')->firstOrFail())['source'],
        );
    }

    private function bindRewriterReturning(string $candidate): void
    {
        $this->app->bind(LocalL2SummaryRewriter::class, static fn (): object => new class($candidate) implements LocalL2SummaryRewriter
        {
            public function __construct(private readonly string $candidate) {}

            public function rewrite(string $l1Summary, array $context = []): array
            {
                return [
                    'summary' => $this->candidate,
                    'runtime' => 'test-local-rewriter',
                    'metadata' => ['test_double' => true],
                ];
            }
        });
    }

    /**
     * @return array{0:AiThread,1:AiCompaction}
     */
    private function seedCompactionWithReceipt(string $l1Summary): array
    {
        $thread = AiThread::query()->create([
            'title' => 'MAXF-09 L2 thread',
            'summary' => $l1Summary,
            'status' => 'active',
            'surface' => 'cli',
            'workspace' => base_path(),
            'metadata' => [],
        ]);
        $session = AiSession::query()->create([
            'thread_id' => $thread->id,
            'status' => 'active',
            'purpose' => 'dev',
            'message_count' => 2,
            'token_estimate' => 1200,
            'metadata' => [],
        ]);
        $compaction = AiCompaction::query()->create([
            'thread_id' => $thread->id,
            'session_id' => $session->id,
            'reason' => 'manual',
            'source_position_start' => 1,
            'source_position_end' => 2,
            'source_message_count' => 2,
            'summary' => $l1Summary,
            'structured_state' => [],
            'token_estimate_before' => 1200,
            'token_estimate_after' => max(1, (int) ceil(mb_strlen($l1Summary) / 4)),
            'quality_gate_status' => 'passed',
            'metadata' => ['candidate_summary_hash' => hash('sha256', $l1Summary)],
        ]);

        $receiptPayload = [
            'schema_version' => AtlasLongHorizonCanon::COMPACTION_RECEIPT_SCHEMA_VERSION,
            'uuid' => 'maxf-09-'.str_replace('-', '', (string) $compaction->id),
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_CONVERSATION,
            'scope_id' => (string) $thread->id,
            'source_context_refs' => ['ai_compaction:'.$compaction->id],
            'retained_items' => [
                ['id' => 'mk-alpha', 'kind' => 'decision', 'digest' => 'Alpha retained'],
                ['id' => 'mk-beta', 'kind' => 'decision', 'digest' => 'Beta retained'],
            ],
            'discarded_items' => [],
            'discarded_reason' => null,
            'must_keep_items' => [
                ['id' => 'mk-alpha', 'kind' => 'decision', 'digest' => 'Alpha retained'],
                ['id' => 'mk-beta', 'kind' => 'decision', 'digest' => 'Beta retained'],
            ],
            'must_keep_coverage' => 1.0,
            'unresolved_loss' => [],
            'loss_risk' => AtlasLongHorizonCanon::LOSS_RISK_LOW,
            'recovery_queries' => [],
            'evidence_refs' => [],
            'summary_hash' => hash('sha256', $l1Summary),
            'context_retention_score' => 1.0,
            'quality_score' => 1.0,
            'detected_contradictions' => [],
            'stale_risks' => [],
        ];
        $receiptPayload['receipt_hash'] = AtlasLongHorizonCompactionReceipt::canonicalReceiptHash($receiptPayload);
        AtlasLongHorizonCompactionReceipt::query()->create($receiptPayload);

        return [$thread, $compaction];
    }

    /**
     * @return array{0:AiThread,1:AiSession}
     */
    private function seedConversationForCompact(string $title): array
    {
        $thread = AiThread::query()->create([
            'title' => $title,
            'status' => 'active',
            'surface' => 'cli',
            'workspace' => base_path(),
            'metadata' => [],
        ]);
        $session = AiSession::query()->create([
            'thread_id' => $thread->id,
            'status' => 'active',
            'purpose' => 'dev',
            'message_count' => 2,
            'token_estimate' => 1200,
            'metadata' => [],
        ]);
        AiSessionState::query()->create([
            'thread_id' => $thread->id,
            'session_id' => $session->id,
            'version' => 1,
            'active' => true,
            'objective' => 'Prove MAXF-09 async dispatch',
            'current_phase' => 'verification',
            'decisions' => [
                ['id' => 'mk-alpha', 'text' => 'Alpha retained'],
            ],
            'open_loops' => [],
            'next_steps' => [],
            'relevant_artifacts' => [],
            'constraints' => [],
            'metadata' => [],
        ]);
        AiMessage::query()->create([
            'thread_id' => $thread->id,
            'position' => 1,
            'role' => 'user',
            'status' => 'final',
            'content' => 'Please keep Alpha retained in the compacted conversation summary.',
            'token_estimate' => 600,
            'metadata' => [],
        ]);
        AiMessage::query()->create([
            'thread_id' => $thread->id,
            'position' => 2,
            'role' => 'assistant',
            'status' => 'final',
            'content' => 'Decision recorded: Alpha retained.',
            'token_estimate' => 600,
            'metadata' => [],
        ]);

        return [$thread, $session];
    }

    private function createAiRuntimeTables(): void
    {
        Schema::create('ai_threads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->string('status')->default('active');
            $table->string('surface')->default('app');
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

        Schema::create('ai_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id');
            $table->string('status')->default('active');
            $table->text('purpose')->nullable();
            $table->integer('message_count')->default(0);
            $table->integer('token_estimate')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id');
            $table->uuid('trace_id')->nullable();
            $table->integer('position');
            $table->string('role');
            $table->string('status')->default('final');
            $table->text('content');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('agent_slug')->nullable();
            $table->integer('token_estimate')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_session_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->integer('version')->default(1);
            $table->boolean('active')->default(true);
            $table->text('objective')->nullable();
            $table->text('current_phase')->nullable();
            $table->text('current_topic')->nullable();
            $table->text('user_position')->nullable();
            $table->json('decisions')->nullable();
            $table->json('open_loops')->nullable();
            $table->json('next_steps')->nullable();
            $table->json('relevant_artifacts')->nullable();
            $table->json('constraints')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_compactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id');
            $table->uuid('session_id')->nullable();
            $table->string('reason');
            $table->integer('source_position_start')->nullable();
            $table->integer('source_position_end')->nullable();
            $table->integer('source_message_count')->default(0);
            $table->text('summary');
            $table->json('structured_state');
            $table->integer('token_estimate_before')->nullable();
            $table->integer('token_estimate_after')->nullable();
            $table->string('quality_gate_status')->default('passed');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    private function createL2SummaryTable(): void
    {
        Schema::create('atlas_l2_hierarchical_summaries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('compaction_id')->index();
            $table->uuid('thread_id')->nullable()->index();
            $table->unsignedInteger('version');
            $table->string('status', 20)->index();
            $table->string('local_runtime', 120);
            $table->string('l1_summary_hash', 64);
            $table->string('l2_summary_hash', 64)->nullable();
            $table->text('l2_summary')->nullable();
            $table->decimal('coverage_score', 5, 4);
            $table->decimal('l1_context_retention_score', 5, 4);
            $table->decimal('l2_context_retention_score', 5, 4);
            $table->decimal('compression_ratio', 8, 4)->nullable();
            $table->json('required_items');
            $table->json('scorer_report');
            $table->json('rejection_reasons');
            $table->json('metadata');
            $table->timestamps();
        });
    }
}

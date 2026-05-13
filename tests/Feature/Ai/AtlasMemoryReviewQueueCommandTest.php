<?php

namespace Tests\Feature\Ai;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AtlasMemoryReviewQueueCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['semantic_curation_proposals', 'ai_memory_deltas'] as $table) {
            Schema::dropIfExists($table);
        }

        $this->createTables();
    }

    protected function tearDown(): void
    {
        foreach (['semantic_curation_proposals', 'ai_memory_deltas'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_review_queue_includes_capture_curation_and_memory_delta_work(): void
    {
        $proposalId = (string) Str::uuid();
        $deltaId = (string) Str::uuid();

        DB::table('semantic_curation_proposals')->insert([
            'id' => $proposalId,
            'source_type' => 'capture',
            'source_refs' => json_encode(['capture_id' => (string) Str::uuid()], JSON_THROW_ON_ERROR),
            'proposed_note_type' => 'principle',
            'proposed_title' => 'Captured principle',
            'proposed_summary' => 'Operator should decide whether this capture belongs in Open Brain.',
            'proposed_frontmatter' => json_encode([], JSON_THROW_ON_ERROR),
            'score' => 0.82,
            'reason' => 'high_signal_capture',
            'status' => 'pending',
            'metadata' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now()->subMinutes(12),
            'updated_at' => now()->subMinutes(12),
        ]);

        DB::table('ai_memory_deltas')->insert([
            'id' => $deltaId,
            'type' => 'decision',
            'claim' => 'Accepted decision should be promoted with a receipt.',
            'evidence' => json_encode([['type' => 'operator_review']], JSON_THROW_ON_ERROR),
            'scope' => 'project:atlas',
            'confidence' => 0.91,
            'requires_confirmation' => true,
            'status' => 'accepted',
            'created_at' => now()->subMinutes(8),
            'updated_at' => now()->subMinutes(8),
        ]);

        $exit = Artisan::call('atlas:memory:review-queue', [
            '--area' => ['semantic_curation', 'memory_delta'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['semantic_curation', 'memory_delta'], data_get($payload, 'review_queue.areas'));
        $this->assertSame(2, data_get($payload, 'review_queue.total'));
        $this->assertSame(1, data_get($payload, 'review_queue.counts.semantic_curation'));
        $this->assertSame(1, data_get($payload, 'review_queue.counts.memory_delta'));

        $semantic = collect(data_get($payload, 'review_queue.items'))->firstWhere('kind', 'semantic_curation');
        $delta = collect(data_get($payload, 'review_queue.items'))->firstWhere('kind', 'memory_delta');

        $this->assertSame('semantic_curation:'.$proposalId, data_get($semantic, 'id'));
        $this->assertSame('capture_to_open_brain', data_get($semantic, 'review_type'));
        $this->assertTrue(data_get($semantic, 'safety.operator_review_required'));
        $this->assertFalse(data_get($semantic, 'safety.memory_write_allowed'));
        $this->assertFalse(data_get($semantic, 'safety.open_brain_context_allowed'));

        $this->assertSame('memory_delta:'.$deltaId, data_get($delta, 'id'));
        $this->assertSame('accepted_delta_promotion', data_get($delta, 'review_type'));
        $this->assertTrue(data_get($delta, 'safety.memory_write_allowed'));
        $this->assertTrue(data_get($delta, 'safety.promotion_requires_receipt'));
        $this->assertStringContainsString('atlas:cli:memory promote '.$deltaId, data_get($delta, 'action_hint'));
    }

    private function createTables(): void
    {
        Schema::create('semantic_curation_proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source_type');
            $table->json('source_refs');
            $table->string('proposed_note_type');
            $table->string('proposed_title');
            $table->text('proposed_summary');
            $table->string('proposed_path')->nullable();
            $table->json('proposed_frontmatter');
            $table->text('proposed_body')->nullable();
            $table->float('score')->nullable();
            $table->text('reason');
            $table->string('status')->default('pending');
            $table->timestamp('shown_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->json('metadata');
            $table->timestamps();
        });

        Schema::create('ai_memory_deltas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('source_trace_id')->nullable();
            $table->uuid('source_session_id')->nullable();
            $table->string('source_workspace')->nullable();
            $table->string('type')->default('process');
            $table->text('claim');
            $table->json('evidence');
            $table->string('scope')->default('global');
            $table->float('confidence')->default(0.5);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->json('use_when')->nullable();
            $table->json('do_not_use_when')->nullable();
            $table->boolean('requires_confirmation')->default(true);
            $table->string('status')->default('pending');
            $table->uuid('superseded_by')->nullable();
            $table->uuid('promoted_memory_entry_id')->nullable();
            $table->timestamp('promoted_at')->nullable();
            $table->timestamps();
        });
    }
}

<?php

namespace Tests\Feature\Ai;

use App\Models\SemanticCurationProposal;
use App\Models\SemanticNote;
use App\Services\Semantic\CurationProposalService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

final class SemanticCurationReviewCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('semantic_curation_proposals');
        $this->createSemanticCurationProposalTable();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('semantic_curation_proposals');

        parent::tearDown();
    }

    public function test_accepts_pending_proposal_and_returns_receipt_hashes(): void
    {
        $proposalId = (string) Str::uuid();
        $noteId = (string) Str::uuid();
        $memoryHash = hash('sha256', 'memory-receipt');
        $verbatimHash = hash('sha256', 'verbatim-receipt');

        $this->insertProposal($proposalId);

        $this->mock(CurationProposalService::class, function (MockInterface $mock) use ($memoryHash, $noteId, $proposalId, $verbatimHash): void {
            $mock->shouldReceive('accept')
                ->once()
                ->with(
                    Mockery::on(fn (SemanticCurationProposal $proposal): bool => $proposal->id === $proposalId),
                    Mockery::on(fn (array $edits): bool => ($edits['promote_to_memory'] ?? null) === true
                        && ($edits['promote_to_verbatim'] ?? null) === true
                        && ($edits['memory_type'] ?? null) === 'strategic_insight'
                        && ($edits['promoted_by'] ?? null) === 'feature-test'
                        && ($edits['verbatim_type'] ?? null) === 'evidence')
                )
                ->andReturnUsing(function (SemanticCurationProposal $proposal) use ($memoryHash, $noteId, $verbatimHash): SemanticNote {
                    $proposal->forceFill([
                        'status' => 'accepted',
                        'resolved_at' => now(),
                        'metadata' => [
                            'semantic_note_id' => $noteId,
                            'memory_promotion' => [
                                'schema_version' => 'atlas.memory.promotion_receipt.v1',
                                'status' => 'promoted',
                                'memory_entry_id' => (string) Str::uuid(),
                                'receipt_hash' => $memoryHash,
                                'receipt_hash_algorithm' => 'sha256',
                            ],
                            'verbatim_promotion' => [
                                'schema_version' => 'atlas.verbatim_memory.promotion_receipt.v1',
                                'status' => 'promoted',
                                'verbatim_memory_id' => (string) Str::uuid(),
                                'receipt_hash' => $verbatimHash,
                                'receipt_hash_algorithm' => 'sha256',
                            ],
                        ],
                    ])->save();

                    $note = new SemanticNote([
                        'note_key' => 'note_review_command',
                        'path' => 'Atlas/Strategy/Review Command.md',
                        'title' => 'Review Command Note',
                        'type' => 'synthesis',
                        'status' => 'active',
                        'confidence' => 'low',
                        'maturity' => 'seed',
                        'domains' => ['atlas'],
                        'summary' => 'Ratified proposal.',
                        'body_excerpt' => 'Ratified proposal.',
                        'frontmatter' => [],
                        'when_to_use' => [],
                        'trigger_signals' => [],
                        'do_not_use_when' => [],
                        'postgres_refs' => [],
                        'content_hash' => hash('sha256', 'note'),
                    ]);
                    $note->id = $noteId;
                    $note->exists = true;

                    return $note;
                });
        });

        $exit = Artisan::call('atlas:semantic:curation-review', [
            'proposal' => $proposalId,
            '--decision' => 'accept',
            '--promote-to-memory' => true,
            '--memory-type' => 'strategic_insight',
            '--promote-to-verbatim' => true,
            '--verbatim-type' => 'evidence',
            '--promoted-by' => 'feature-test',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertTrue($payload['ok']);
        $this->assertSame('accept', $payload['decision']);
        $this->assertSame('accepted', data_get($payload, 'proposal.status'));
        $this->assertSame($noteId, data_get($payload, 'note.id'));
        $this->assertSame($memoryHash, data_get($payload, 'receipt_hashes.memory'));
        $this->assertSame($verbatimHash, data_get($payload, 'receipt_hashes.verbatim'));
        $this->assertTrue(data_get($payload, 'safety.operator_decision_recorded'));
        $this->assertTrue(data_get($payload, 'safety.memory_write_requested'));
        $this->assertTrue(data_get($payload, 'safety.verbatim_write_requested'));
        $this->assertFalse(data_get($payload, 'safety.provider_export_allowed_by_command'));
        $this->assertFalse(data_get($payload, 'safety.context_injection_allowed_by_command'));
        $this->assertFalse(data_get($payload, 'safety.raw_capture_text_exposed'));
    }

    public function test_dismiss_records_operator_decision_without_memory_write(): void
    {
        $proposalId = (string) Str::uuid();
        $this->insertProposal($proposalId);

        $this->mock(CurationProposalService::class, function (MockInterface $mock) use ($proposalId): void {
            $mock->shouldReceive('dismiss')
                ->once()
                ->with(Mockery::on(fn (SemanticCurationProposal $proposal): bool => $proposal->id === $proposalId))
                ->andReturnUsing(function (SemanticCurationProposal $proposal): void {
                    $proposal->forceFill([
                        'status' => 'dismissed',
                        'resolved_at' => now(),
                    ])->save();
                });
        });

        $exit = Artisan::call('atlas:semantic:curation-review', [
            'proposal' => $proposalId,
            '--decision' => 'dismiss',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('dismiss', $payload['decision']);
        $this->assertSame('dismissed', data_get($payload, 'proposal.status'));
        $this->assertFalse(data_get($payload, 'safety.memory_write_requested'));
        $this->assertFalse(data_get($payload, 'safety.verbatim_write_requested'));
    }

    private function insertProposal(string $proposalId): void
    {
        DB::table('semantic_curation_proposals')->insert([
            'id' => $proposalId,
            'source_type' => 'capture',
            'source_refs' => json_encode(['capture_id' => (string) Str::uuid()], JSON_THROW_ON_ERROR),
            'proposed_note_type' => 'synthesis',
            'proposed_title' => 'Estrutura mae capture',
            'proposed_summary' => 'Operator should decide whether this belongs in Open Brain.',
            'proposed_path' => 'Atlas/Strategy/Estrutura Mae Capture.md',
            'proposed_frontmatter' => json_encode([], JSON_THROW_ON_ERROR),
            'proposed_body' => 'Draft body.',
            'score' => 0.91,
            'reason' => 'high_signal_capture',
            'status' => 'pending',
            'metadata' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);
    }

    private function createSemanticCurationProposalTable(): void
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
    }
}

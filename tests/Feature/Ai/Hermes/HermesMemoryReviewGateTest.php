<?php

namespace Tests\Feature\Ai\Hermes;

use App\Models\AiMemoryDelta;
use App\Services\Ai\Hermes\HermesMemoryReviewGate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

class HermesMemoryReviewGateTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    private HermesMemoryReviewGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasMemoryEntryTable();
        $this->createMemoryDeltaTable();
        $this->gate = app(HermesMemoryReviewGate::class);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_memory_deltas');
        $this->dropAtlasMemoryEntryTable();
        parent::tearDown();
    }

    public function test_pending_hermes_delta_with_operator_confirmation_is_promoted(): void
    {
        $delta = $this->seedHermesDelta(0.9, 'Hermes runtime learned to retry tool X');

        $receipt = $this->gate->review(['operator_confirmed' => true]);

        $this->assertSame('promoted', data_get($receipt, 'status'));
        $this->assertSame(1, data_get($receipt, 'promoted_count'));
        $this->assertTrue((bool) data_get($receipt, 'promotion_allowed_now'));
        $this->assertContains($delta->id, data_get($receipt, 'promoted_delta_ids'));

        $delta->refresh();
        $this->assertSame('promoted', $delta->status);
        $this->assertNotNull($delta->promoted_memory_entry_id);

        $this->assertDatabaseHas('atlas_memory_entries', [
            'source_type' => 'ai_memory_delta',
            'source_id' => $delta->id,
            'status' => 'active',
        ]);
    }

    public function test_no_operator_confirmation_does_not_promote(): void
    {
        $delta = $this->seedHermesDelta(0.9, 'Hermes learned to skip flaky step');

        $receipt = $this->gate->review();

        $this->assertSame(0, data_get($receipt, 'promoted_count'));
        $this->assertSame('held_for_confirmation', data_get($receipt, 'status'));
        $this->assertSame(1, data_get($receipt, 'held_count'));
        $this->assertFalse((bool) data_get($receipt, 'promotion_allowed_now'));
        $this->assertSame('operator_confirmation_required', data_get($receipt, 'decisions.0.reason'));

        $delta->refresh();
        $this->assertSame('pending', $delta->status);

        $this->assertDatabaseMissing('atlas_memory_entries', ['source_id' => $delta->id]);
    }

    public function test_low_confidence_delta_is_not_promoted_even_with_confirmation(): void
    {
        $delta = $this->seedHermesDelta(0.3, 'Low confidence Hermes hunch');

        $receipt = $this->gate->review(['operator_confirmed' => true]);

        $this->assertSame(0, data_get($receipt, 'promoted_count'));
        $this->assertFalse((bool) data_get($receipt, 'promotion_allowed_now'));
        $this->assertSame('low_confidence', data_get($receipt, 'decisions.0.reason'));

        $delta->refresh();
        $this->assertSame('pending', $delta->status);
        $this->assertDatabaseMissing('atlas_memory_entries', ['source_id' => $delta->id]);
    }

    public function test_conflicting_claim_is_not_promoted(): void
    {
        $delta = $this->seedHermesDelta(0.9, 'Claim that conflicts with canonical Atlas docs');

        $receipt = $this->gate->review([
            'operator_confirmed' => true,
            'conflicts_with_canonical_docs_ids' => [$delta->id],
        ]);

        $this->assertSame(0, data_get($receipt, 'promoted_count'));
        $this->assertSame('conflicts_with_canonical_docs', data_get($receipt, 'decisions.0.reason'));

        $delta->refresh();
        $this->assertSame('pending', $delta->status);
        $this->assertDatabaseMissing('atlas_memory_entries', ['source_id' => $delta->id]);
    }

    public function test_pending_delta_can_be_rejected(): void
    {
        $delta = $this->seedHermesDelta(0.9, 'Hermes claim the operator rejects');

        $receipt = $this->gate->review([
            'operator_confirmed' => true,
            'reject_delta_ids' => [$delta->id],
        ]);

        $this->assertGreaterThanOrEqual(1, data_get($receipt, 'rejected_count'));
        $this->assertContains($delta->id, data_get($receipt, 'rejected_delta_ids'));

        $delta->refresh();
        $this->assertSame('rejected', $delta->status);
        $this->assertDatabaseMissing('atlas_memory_entries', ['source_id' => $delta->id]);
    }

    public function test_duplicate_delta_is_marked_superseded_not_re_promoted(): void
    {
        $deltaA = $this->seedHermesDelta(0.9, 'Identical claim X', 'global');
        $entry = $this->gate->review(['operator_confirmed' => true]);
        $entryId = data_get($entry, 'promoted_memory_entry_ids.0');
        $this->assertNotNull($entryId);

        $deltaB = $this->seedHermesDelta(0.9, 'Identical claim X', 'global');

        $receipt = $this->gate->review(['operator_confirmed' => true]);

        $this->assertGreaterThanOrEqual(1, data_get($receipt, 'deduped_count'));
        $this->assertContains($deltaB->id, data_get($receipt, 'deduped_delta_ids'));

        $deltaB->refresh();
        $this->assertSame('superseded', $deltaB->status);
        $this->assertSame($entryId, $deltaB->superseded_by);

        $this->assertSame(
            1,
            AiMemoryDelta::query()->where('claim', 'Identical claim X')->where('status', 'promoted')->count(),
        );

        $entries = \App\Models\AtlasMemoryEntry::query()
            ->where('source_type', 'ai_memory_delta')
            ->where('body', 'Identical claim X')
            ->count();
        $this->assertSame(1, $entries, 'exactly one canonical entry for claim X');
    }

    public function test_non_hermes_delta_is_skipped(): void
    {
        $delta = $this->seedHermesDelta(0.9, 'Not a hermes delta', 'global', 'some_other_origin');

        $receipt = $this->gate->review(['operator_confirmed' => true]);

        $this->assertSame(0, data_get($receipt, 'candidate_count'));
        $this->assertSame(0, data_get($receipt, 'promoted_count'));

        $delta->refresh();
        $this->assertSame('pending', $delta->status);
    }

    public function test_non_hermes_delta_alongside_hermes_delta_is_skipped_with_reason(): void
    {
        $hermes = $this->seedHermesDelta(0.9, 'A hermes claim', 'global');
        $foreign = $this->seedHermesDelta(0.9, 'A foreign claim', 'global', 'some_other_origin');

        $receipt = $this->gate->review(['operator_confirmed' => true]);

        // Only the Hermes delta is a candidate; the foreign one is filtered out.
        $this->assertSame(1, data_get($receipt, 'candidate_count'));
        $this->assertContains($hermes->id, data_get($receipt, 'promoted_delta_ids'));

        $foreign->refresh();
        $this->assertSame('pending', $foreign->status);
    }

    public function test_non_hermes_delta_targeted_by_id_is_skipped_with_reason(): void
    {
        $foreign = $this->seedHermesDelta(0.9, 'A foreign claim', 'global', 'some_other_origin');

        $receipt = $this->gate->review([
            'operator_confirmed' => true,
            'delta_ids' => [$foreign->id],
        ]);

        $this->assertSame(1, data_get($receipt, 'candidate_count'));
        $this->assertSame(0, data_get($receipt, 'promoted_count'));
        $this->assertSame(1, data_get($receipt, 'skipped_count'));
        $this->assertSame('not_a_hermes_delta', data_get($receipt, 'decisions.0.reason'));
        $this->assertSame('not_a_hermes_delta', data_get($receipt, 'skipped_candidates.0.reason'));

        $foreign->refresh();
        $this->assertSame('pending', $foreign->status);
    }

    public function test_receipt_is_sealed_and_carries_packet_ref_and_hash(): void
    {
        $this->seedHermesDelta(0.9, 'Sealable Hermes claim');

        $receipt = $this->gate->review(['operator_confirmed' => true]);

        $this->assertSame('atlas.hermes.memory_gate_receipt.v1', data_get($receipt, 'schema_version'));
        $this->assertSame('hermes_memory_review_gate', data_get($receipt, 'gate'));
        $this->assertSame('atlas', data_get($receipt, 'memory_authority'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($receipt, 'receipt_hash'));
        $this->assertSame('hermes_result_test', data_get($receipt, 'hermes_result_packet_refs.0.result_id'));
        $this->assertSame('rh', data_get($receipt, 'hermes_result_packet_refs.0.result_hash'));
    }

    public function test_table_missing_returns_fail_closed_receipt(): void
    {
        Schema::dropIfExists('ai_memory_deltas');

        $receipt = $this->gate->review(['operator_confirmed' => true]);

        $this->assertSame('memory_gate_unavailable', data_get($receipt, 'status'));
        $this->assertSame(0, data_get($receipt, 'promoted_count'));
        $this->assertFalse((bool) data_get($receipt, 'promotion_allowed_now'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($receipt, 'receipt_hash'));
    }

    private function seedHermesDelta(
        float $confidence,
        string $claim,
        string $scope = 'global',
        string $evidenceKind = 'hermes_result_packet',
    ): AiMemoryDelta {
        return AiMemoryDelta::query()->create([
            'id' => (string) Str::uuid(),
            'source_workspace' => '/tmp/hermes-memory-gate',
            'type' => 'technical_context',
            'claim' => $claim,
            'evidence' => [[
                'kind' => $evidenceKind,
                'candidate_id' => 'hermes_memory_candidate_x',
                'result_id' => 'hermes_result_test',
                'result_hash' => 'rh',
                'mission_id' => 'm',
                'mission_hash' => 'mh',
            ]],
            'scope' => $scope,
            'confidence' => $confidence,
            'valid_from' => now(),
            'valid_until' => now()->addDays(90),
            'use_when' => ['operator or Atlas Memory Gate approves this Hermes runtime learning'],
            'do_not_use_when' => ['candidate remains pending or rejected'],
            'requires_confirmation' => true,
            'status' => 'pending',
        ]);
    }

    private function createMemoryDeltaTable(): void
    {
        Schema::dropIfExists('ai_memory_deltas');
        Schema::create('ai_memory_deltas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('source_trace_id')->nullable()->index();
            $table->uuid('source_session_id')->nullable()->index();
            $table->string('source_workspace')->nullable();
            $table->string('type', 32)->default('process');
            $table->text('claim');
            $table->json('evidence');
            $table->string('scope', 255)->default('global');
            $table->float('confidence')->default(0.5);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->json('use_when')->nullable();
            $table->json('do_not_use_when')->nullable();
            $table->boolean('requires_confirmation')->default(true);
            $table->string('status', 16)->default('pending');
            $table->uuid('superseded_by')->nullable();
            $table->uuid('promoted_memory_entry_id')->nullable()->index();
            $table->timestamp('promoted_at')->nullable()->index();
            $table->timestamps();
        });
    }
}

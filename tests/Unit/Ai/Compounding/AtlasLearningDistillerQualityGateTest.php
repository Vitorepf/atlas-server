<?php

namespace Tests\Unit\Ai\Compounding;

use App\Models\AiLearningCandidate;
use App\Models\AiRunOutcome;
use App\Services\Ai\Compounding\AtlasLearningDistiller;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * T1.2 — o gate de qualidade no CHOKEPOINT do distiller: nenhum produtor de
 * recordExecution consegue promover claim boilerplate a memória em enforce.
 */
class AtlasLearningDistillerQualityGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('ai_run_outcomes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version')->nullable();
            $table->string('run_id')->nullable();
            $table->string('flow_id')->nullable();
            $table->string('outcome_status')->nullable();
            $table->integer('evidence_quality')->nullable();
            $table->boolean('learning_required')->default(false);
            $table->json('evidence_refs')->nullable();
            $table->string('outcome_hash')->nullable();
            $table->timestamps();
        });
        Schema::create('ai_learning_candidates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version')->nullable();
            $table->uuid('run_outcome_id')->nullable();
            $table->string('status')->nullable();
            $table->string('decision')->nullable();
            $table->string('memory_type')->nullable();
            $table->string('scope')->nullable();
            $table->text('claim')->nullable();
            $table->integer('confidence')->nullable();
            $table->boolean('promotion_allowed')->default(false);
            $table->json('evidence_refs')->nullable();
            $table->json('payload')->nullable();
            $table->string('candidate_hash')->nullable()->unique();
            $table->string('receipt_hash')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_learning_candidates');
        Schema::dropIfExists('ai_run_outcomes');

        parent::tearDown();
    }

    public function test_enforce_holds_meta_stub_claim_and_blocks_promotion(): void
    {
        config(['atlas.ai.capture_quality_gate.mode' => 'enforce']);

        $candidate = $this->distill(
            'Specialist flow atlas_conversation emitted a learning signal contract for future routing, retrieval and execution evaluation.',
        );

        $this->assertSame('hold', $candidate->decision);
        $this->assertFalse((bool) $candidate->promotion_allowed);
        $this->assertSame('held_low_quality', $candidate->status);
        $this->assertSame('meta_stub', data_get($candidate->payload, 'capture_quality.reason'));
    }

    public function test_enforce_still_promotes_substantive_claim(): void
    {
        config(['atlas.ai.capture_quality_gate.mode' => 'enforce']);

        $candidate = $this->distill(
            'Hermes CLI fails with decision_expired when the DecisionReceipt TTL elapses before provider start; pre-issue the receipt inside the dispatch transaction to avoid it.',
        );

        $this->assertSame('promote', $candidate->decision);
        $this->assertTrue((bool) $candidate->promotion_allowed);
        $this->assertSame('ok', data_get($candidate->payload, 'capture_quality.reason'));
    }

    public function test_observe_annotates_but_does_not_change_behavior(): void
    {
        config(['atlas.ai.capture_quality_gate.mode' => 'observe']);

        $candidate = $this->distill(
            'Specialist flow atlas_conversation emitted a learning signal contract for future routing, retrieval and execution evaluation.',
        );

        $this->assertSame('promote', $candidate->decision);
        $this->assertFalse((bool) data_get($candidate->payload, 'capture_quality.admit'));
        $this->assertSame('observe', data_get($candidate->payload, 'capture_quality.mode'));
    }

    private function distill(string $claim): AiLearningCandidate
    {
        $outcome = AiRunOutcome::query()->create([
            'id' => (string) Str::uuid(),
            'run_id' => 'test:'.Str::ulid(),
            'flow_id' => 'atlas_conversation',
            'outcome_status' => 'delegated',
            'evidence_quality' => 82,
            'learning_required' => true,
            'evidence_refs' => ['trace:test'],
            'outcome_hash' => 'hash:'.Str::ulid(),
        ]);

        return app(AtlasLearningDistiller::class)->distill($outcome, [
            'claim' => $claim,
            'memory_type' => 'routing_memory',
            'scope' => 'atlas-server',
            'confidence' => 78,
            'evidence_refs' => ['trace:test'],
        ]);
    }
}

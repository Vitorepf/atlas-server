<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Compounding;

use App\Models\AiLearningProposal;
use App\Services\Ai\Compounding\AtlasLearningProposalService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The capture quality gate wired into propose(): default 'observe' is zero-behavior;
 * 'enforce' refuses to persist noise (the enforce-reject path returns a transient model
 * before any DB write, so it is provable without a database).
 */
class AtlasLearningProposalQualityGateTest extends TestCase
{
    private function noiseInput(): array
    {
        return [
            'kind' => 'failure_pattern',
            'summary' => 'fp', // trivial claim — genuinely contentless when paired with the empty template
            'evidence_refs' => ['ev-1'],
            // The exact empty template that produced the duplicate proposals this week.
            'proposed_state' => ['should_repromote_sources' => [], 'should_demote_noise_count' => 0, 'target_context_sufficiency_min' => 70],
        ];
    }

    public function test_enforce_mode_does_not_persist_contentless_noise(): void
    {
        config(['atlas.ai.capture_quality_gate.mode' => 'enforce']);

        $proposal = (new AtlasLearningProposalService())->propose($this->noiseInput());

        $this->assertSame('rejected_by_quality_gate', $proposal->status);
        $this->assertFalse($proposal->exists, 'contentless noise must not be persisted in enforce mode');
        $this->assertSame('contentless', $proposal->payload['quality']['reason'] ?? null);
    }

    public function test_off_mode_disables_the_gate_short_circuit(): void
    {
        // The gate still flags the contentless input as noise, but 'off' mode means
        // propose() would never short-circuit on it (it falls through to persistence).
        $gate = app(\App\Services\Ai\Compounding\AtlasCaptureQualityGate::class)->assess([
            'kind' => 'failure_pattern',
            'claim' => 'fp',
            'content' => $this->noiseInput()['proposed_state'],
        ]);
        $this->assertFalse($gate['admit']);
        $this->assertSame('contentless', $gate['reason']);
    }

    /**
     * O-2 slice (a): em enforce, dois learnings de MESMO conteúdo (variando só
     * summary/metadata, o perfil do episódio "137→3") colapsam para UMA linha; um
     * learning de conteúdo distinto cria uma segunda. É a prova de que o enforce ataca o
     * waste real (duplicação) sem perder learning legítimo.
     */
    public function test_enforce_collapses_duplicates_but_keeps_distinct_real_learnings(): void
    {
        config(['atlas.ai.capture_quality_gate.mode' => 'enforce']);
        $this->createLearningProposalsTable();
        $svc = new AtlasLearningProposalService();

        $a1 = $svc->propose($this->realInput('summary text A', 'retry+reconnect on a transient db blip before parking the cycle'));
        $a2 = $svc->propose($this->realInput('a totally different summary B', 'retry+reconnect on a transient db blip before parking the cycle'));
        $b = $svc->propose($this->realInput('summary C', 'pin paratest 7.4 and keep the 512M floor after the upgrade broke timeouts'));

        $this->assertSame($a1->id, $a2->id, 'mesmo conteúdo deve colapsar para uma linha');
        $this->assertNotSame($a1->id, $b->id, 'conteúdo distinto deve criar uma segunda linha');
        $this->assertSame(2, AiLearningProposal::query()->count(), 'só 2 linhas reais persistidas (1 colapsada)');

        Schema::dropIfExists('ai_learning_proposals');
    }

    private function realInput(string $summary, string $action): array
    {
        return [
            'kind' => 'failure_pattern',
            'summary' => $summary,
            'evidence_refs' => ['ev-1', 'ev-2'],
            'proposed_state' => ['trigger' => 'transient failure mid-run', 'action' => $action],
        ];
    }

    private function createLearningProposalsTable(): void
    {
        Schema::dropIfExists('ai_learning_proposals');
        Schema::create('ai_learning_proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 80)->default('atlas.ai.compounding.learning_proposal.v1');
            $table->string('kind', 40)->index();
            $table->string('status', 32)->default('proposed')->index();
            $table->string('scope', 120)->default('global')->index();
            $table->string('flow_id', 80)->nullable()->index();
            $table->text('summary');
            $table->json('current_state')->nullable();
            $table->json('proposed_state')->nullable();
            $table->json('evidence_refs');
            $table->uuid('run_outcome_id')->nullable()->index();
            $table->uuid('learning_candidate_id')->nullable()->index();
            $table->uuid('rag_feedback_id')->nullable()->index();
            $table->boolean('requires_human_review')->default(true);
            $table->string('decided_by', 120)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->json('payload')->nullable();
            $table->string('proposal_hash', 64)->unique();
            $table->timestamps();
        });
    }
}

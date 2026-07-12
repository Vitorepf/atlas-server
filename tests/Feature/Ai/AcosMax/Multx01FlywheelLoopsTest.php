<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AcosMax;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BootsCompoundingSchema;
use Tests\TestCase;

final class Multx01FlywheelLoopsTest extends TestCase
{
    use BootsCompoundingSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCompoundingSchema();
        Carbon::setTestNow(Carbon::parse('2026-07-12 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->dropCompoundingSchema();
        parent::tearDown();
    }

    public function test_flywheel_loops_reports_complete_proven_real_chained_loop(): void
    {
        $chain = $this->chain(
            runId: 'task-real-1',
            provenReal: true,
            fixture: false,
            includeRecall: true,
        );

        $payload = $this->report();
        $loop = $payload['loops'][0] ?? [];

        $this->assertSame('ok', $payload['status']);
        $this->assertSame(1, $payload['loops_complete']);
        $this->assertSame(1, $payload['n_total']);
        $this->assertTrue($loop['proven_real']);
        $this->assertTrue($loop['fixture_free']);
        $this->assertSame($chain['outcome_id'], data_get($loop, 'chain.outcome_id'));
        $this->assertSame($chain['decision_id'], data_get($loop, 'chain.decision_id'));
        $this->assertSame($chain['retrieval_receipt_id'], data_get($loop, 'chain.retrieval_receipt_id'));
        $this->assertSame($chain['candidate_id'], data_get($loop, 'chain.learning_candidate_id'));
        $this->assertSame($chain['recall_feedback_id'], data_get($loop, 'chain.subsequent_recall_feedback_id'));
        $this->assertSame(3600, $loop['time_to_recall_seconds']);
    }

    public function test_non_proven_real_chain_stays_partial_and_cannot_satisfy_marco(): void
    {
        $this->chain(
            runId: 'task-claimed-1',
            provenReal: false,
            fixture: false,
            includeRecall: true,
        );

        $payload = $this->report();
        $partial = $payload['loops_partial'][0] ?? [];

        $this->assertSame('insufficient_signal', $payload['status']);
        $this->assertSame(0, $payload['loops_complete']);
        $this->assertContains('outcome_not_proven_real', $partial['blocked_by']);
        $this->assertFalse($payload['marco_esp_v1']['satisfied']);
    }

    public function test_fixture_marked_chain_is_rejected_even_when_all_ids_are_present(): void
    {
        $this->chain(
            runId: 'task-fixture-1',
            provenReal: true,
            fixture: true,
            includeRecall: true,
        );

        $payload = $this->report();
        $partial = $payload['loops_partial'][0] ?? [];

        $this->assertSame('insufficient_signal', $payload['status']);
        $this->assertSame(0, $payload['loops_complete']);
        $this->assertContains('fixture_chain', $partial['blocked_by']);
        $this->assertSame(1, $payload['fixture_rejected']);
    }

    public function test_missing_subsequent_recall_keeps_loop_partial(): void
    {
        $this->chain(
            runId: 'task-missing-recall',
            provenReal: true,
            fixture: false,
            includeRecall: false,
        );

        $payload = $this->report();
        $partial = $payload['loops_partial'][0] ?? [];

        $this->assertSame('insufficient_signal', $payload['status']);
        $this->assertSame(0, $payload['loops_complete']);
        $this->assertContains('subsequent_measured_recall_missing', $partial['blocked_by']);
    }

    /** @return array<string,mixed> */
    private function report(): array
    {
        $exit = Artisan::call('atlas:flywheel:loops', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);

        return $payload;
    }

    /** @return array<string,string> */
    private function chain(string $runId, bool $provenReal, bool $fixture, bool $includeRecall): array
    {
        $outcomeId = (string) Str::uuid();
        $candidateId = (string) Str::uuid();
        $deliveryId = (string) Str::uuid();
        $recallId = (string) Str::uuid();
        $decisionId = 'decision-'.$runId;
        $retrievalReceiptId = 'retrieval-'.$runId;

        DB::table('ai_run_outcomes')->insert([
            'id' => $outcomeId,
            'schema_version' => 'atlas.ai.compounding.outcome.v1',
            'run_id' => $runId,
            'flow_id' => 'autonomos',
            'outcome_status' => $provenReal ? 'passed' : 'claimed',
            'flow_quality' => 80,
            'retrieval_quality' => 80,
            'execution_quality' => 80,
            'evidence_quality' => 80,
            'human_override' => false,
            'learning_required' => true,
            'evidence_refs' => json_encode(['evidence:'.$runId], JSON_THROW_ON_ERROR),
            'payload' => json_encode([
                'task_id' => $runId,
                'decision_id' => $decisionId,
                'decision_receipt_id' => $decisionId,
                'proven_real' => $provenReal,
                'certified_receipt_id' => $provenReal ? 'cert-'.$runId : null,
                'verified_basis' => $provenReal ? 'server_verified' : 'claimed',
                'fixture' => $fixture,
            ], JSON_THROW_ON_ERROR),
            'outcome_hash' => hash('sha256', 'outcome-'.$runId),
            'evaluated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ai_rag_feedback_events')->insert([
            'id' => $deliveryId,
            'schema_version' => 'atlas.ai.rag.feedback.v1',
            'retrieval_receipt_id' => $retrievalReceiptId,
            'flow_id' => 'autonomos',
            'query_plan_hash' => hash('sha256', 'delivery-'.$runId),
            'included_sources' => 2,
            'used_sources' => 1,
            'noise_sources' => 0,
            'context_sufficiency' => 80,
            'post_execution_utility' => 80,
            'outcome_status' => 'passed',
            'run_outcome_id' => $outcomeId,
            'payload' => json_encode([
                'delivery_kind' => 'context_pack',
                'record_usage' => true,
                'fixture' => $fixture,
            ], JSON_THROW_ON_ERROR),
            'feedback_hash' => hash('sha256', 'delivery-feedback-'.$runId),
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(30),
        ]);

        DB::table('ai_learning_candidates')->insert([
            'id' => $candidateId,
            'schema_version' => 'atlas.ai.compounding.learning_candidate.v1',
            'run_outcome_id' => $outcomeId,
            'candidate_hash' => hash('sha256', 'candidate-'.$runId),
            'status' => 'promoted',
            'decision' => 'accepted',
            'memory_type' => 'procedural',
            'scope' => 'engineering',
            'claim' => 'Use the verified chain for the next similar task.',
            'confidence' => 80,
            'promotion_allowed' => true,
            'evidence_refs' => json_encode(['candidate-evidence:'.$runId], JSON_THROW_ON_ERROR),
            'payload' => json_encode([
                'flow_id' => 'autonomos',
                'caused_by' => ['primary_cause' => 'good_execution'],
                'fixture' => $fixture,
            ], JSON_THROW_ON_ERROR),
            'receipt_hash' => hash('sha256', 'candidate-receipt-'.$runId),
            'decided_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($includeRecall) {
            DB::table('ai_rag_feedback_events')->insert([
                'id' => $recallId,
                'schema_version' => 'atlas.ai.rag.feedback.v1',
                'retrieval_receipt_id' => 'recall-'.$runId,
                'flow_id' => 'autonomos',
                'query_plan_hash' => hash('sha256', 'recall-'.$runId),
                'included_sources' => 1,
                'used_sources' => 1,
                'noise_sources' => 0,
                'context_sufficiency' => 90,
                'post_execution_utility' => 90,
                'outcome_status' => 'passed',
                'memory_candidate_id' => $candidateId,
                'payload' => json_encode([
                    'delivery_kind' => 'shadow_recall',
                    'record_usage' => true,
                    'fixture' => $fixture,
                ], JSON_THROW_ON_ERROR),
                'feedback_hash' => hash('sha256', 'recall-feedback-'.$runId),
                'created_at' => now()->addHour(),
                'updated_at' => now()->addHour(),
            ]);
        }

        return [
            'outcome_id' => $outcomeId,
            'decision_id' => $decisionId,
            'retrieval_receipt_id' => $retrievalReceiptId,
            'candidate_id' => $candidateId,
            'recall_feedback_id' => $recallId,
        ];
    }
}

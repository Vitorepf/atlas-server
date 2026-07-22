<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition\AcosProgram;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BootsCompoundingSchema;
use Tests\TestCase;

final class Multx06LearningLatencyTest extends TestCase
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

    public function test_learning_latency_reports_delivery_and_citation_percentiles(): void
    {
        $this->chain(runId: 'latency-1', includeDelivery: true, includeCitation: true, deliverySeconds: 600, citationSeconds: 3600);

        $payload = $this->report();

        $this->assertSame('insufficient_signal', $payload['status']);
        $this->assertSame(1, $payload['n']);
        $this->assertSame(0, $payload['never_delivered']);
        $this->assertSame(0, $payload['never_cited']);
        $this->assertSame(600, data_get($payload, 'latency_seconds.delivery_p50'));
        $this->assertSame(3600, data_get($payload, 'latency_seconds.citation_p50'));
        $this->assertTrue(data_get($payload, 'claim_policy.never_delivered_in_denominator'));
    }

    public function test_promoted_lesson_never_delivered_stays_in_denominator(): void
    {
        $this->chain(runId: 'latency-delivered', includeDelivery: true, includeCitation: true, deliverySeconds: 120, citationSeconds: 900);
        $this->chain(runId: 'latency-never', includeDelivery: false, includeCitation: false, deliverySeconds: 0, citationSeconds: 0);

        $payload = $this->report();

        $this->assertSame(2, $payload['n']);
        $this->assertSame(1, $payload['never_delivered']);
        $this->assertSame(1, $payload['never_cited']);
        $this->assertSame(120, data_get($payload, 'latency_seconds.delivery_p50'));
        $this->assertSame(2, data_get($payload, 'by_lesson_class.0.n'));
        $this->assertSame(1, data_get($payload, 'by_lesson_class.0.never_delivered'));
    }

    /** @return array<string,mixed> */
    private function report(): array
    {
        $exit = Artisan::call('atlas:flywheel:learning-latency', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);

        return $payload;
    }

    private function chain(string $runId, bool $includeDelivery, bool $includeCitation, int $deliverySeconds, int $citationSeconds): void
    {
        $outcomeId = (string) Str::uuid();
        $candidateId = (string) Str::uuid();
        $outcomeAt = now();

        DB::table('ai_run_outcomes')->insert([
            'id' => $outcomeId,
            'schema_version' => 'atlas.ai.compounding.outcome.v1',
            'run_id' => $runId,
            'flow_id' => 'autonomos',
            'outcome_status' => 'passed',
            'flow_quality' => 80,
            'retrieval_quality' => 80,
            'execution_quality' => 80,
            'evidence_quality' => 80,
            'human_override' => false,
            'learning_required' => true,
            'evidence_refs' => json_encode(['evidence:'.$runId], JSON_THROW_ON_ERROR),
            'payload' => json_encode(['proven_real' => true], JSON_THROW_ON_ERROR),
            'outcome_hash' => hash('sha256', 'outcome-'.$runId),
            'evaluated_at' => $outcomeAt,
            'created_at' => $outcomeAt,
            'updated_at' => $outcomeAt,
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
            'claim' => 'Use this lesson in a later similar task.',
            'confidence' => 80,
            'promotion_allowed' => true,
            'evidence_refs' => json_encode(['candidate-evidence:'.$runId], JSON_THROW_ON_ERROR),
            'payload' => json_encode(['flow_id' => 'autonomos'], JSON_THROW_ON_ERROR),
            'receipt_hash' => hash('sha256', 'candidate-receipt-'.$runId),
            'decided_at' => $outcomeAt->copy()->addMinutes(5),
            'created_at' => $outcomeAt->copy()->addMinutes(5),
            'updated_at' => $outcomeAt->copy()->addMinutes(5),
        ]);

        if ($includeDelivery) {
            DB::table('ai_rag_feedback_events')->insert([
                'id' => (string) Str::uuid(),
                'schema_version' => 'atlas.ai.rag.feedback.v1',
                'retrieval_receipt_id' => 'delivery-'.$runId,
                'flow_id' => 'autonomos',
                'query_plan_hash' => hash('sha256', 'delivery-'.$runId),
                'included_sources' => 1,
                'used_sources' => 1,
                'noise_sources' => 0,
                'context_sufficiency' => 80,
                'post_execution_utility' => 80,
                'outcome_status' => 'passed',
                'run_outcome_id' => $outcomeId,
                'payload' => json_encode(['delivery_kind' => 'context_pack'], JSON_THROW_ON_ERROR),
                'feedback_hash' => hash('sha256', 'delivery-feedback-'.$runId),
                'created_at' => $outcomeAt->copy()->addSeconds($deliverySeconds),
                'updated_at' => $outcomeAt->copy()->addSeconds($deliverySeconds),
            ]);
        }

        if ($includeCitation) {
            DB::table('ai_rag_feedback_events')->insert([
                'id' => (string) Str::uuid(),
                'schema_version' => 'atlas.ai.rag.feedback.v1',
                'retrieval_receipt_id' => 'citation-'.$runId,
                'flow_id' => 'autonomos',
                'query_plan_hash' => hash('sha256', 'citation-'.$runId),
                'included_sources' => 1,
                'used_sources' => 1,
                'noise_sources' => 0,
                'context_sufficiency' => 90,
                'post_execution_utility' => 90,
                'outcome_status' => 'passed',
                'memory_candidate_id' => $candidateId,
                'payload' => json_encode(['delivery_kind' => 'measured_citation'], JSON_THROW_ON_ERROR),
                'feedback_hash' => hash('sha256', 'citation-feedback-'.$runId),
                'created_at' => $outcomeAt->copy()->addSeconds($citationSeconds),
                'updated_at' => $outcomeAt->copy()->addSeconds($citationSeconds),
            ]);
        }
    }
}

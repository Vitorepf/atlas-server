<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AcosMax;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BootsCompoundingSchema;
use Tests\TestCase;

final class Multj03CounterfactualLiftTest extends TestCase
{
    use BootsCompoundingSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCompoundingSchema();
    }

    protected function tearDown(): void
    {
        $this->dropCompoundingSchema();
        parent::tearDown();
    }

    public function test_counterfactual_lift_reports_measured_paired_peek_delta_by_memory_type(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->pair("procedural-$i", 'procedural', controlScore: 3, treatmentScore: 5);
        }

        $payload = $this->report();
        $procedural = collect($payload['memory_types'])->firstWhere('memory_type', 'procedural');

        $this->assertSame('ok', $payload['status']);
        $this->assertSame(8, $payload['n_pairs']);
        $this->assertEqualsWithDelta(2.0, $payload['paired_delta'], 0.0001);
        $this->assertEqualsWithDelta(0.05, $payload['rate'], 0.0001);
        $this->assertSame('measured', $procedural['status']);
        $this->assertSame(8, $procedural['n_pairs']);
        $this->assertEqualsWithDelta(2.0, $procedural['paired_delta'], 0.0001);
        $this->assertSame(0, $payload['peek_policy']['usage_rows_recorded']);
        $this->assertFalse($payload['peek_policy']['record_usage_for_peek']);
    }

    public function test_irrelevant_lesson_pair_never_fabricates_positive_lift(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->pair("irrelevant-$i", 'irrelevant', controlScore: 4, treatmentScore: 4);
        }

        $payload = $this->report();
        $irrelevant = collect($payload['memory_types'])->firstWhere('memory_type', 'irrelevant');

        $this->assertSame('ok', $payload['status']);
        $this->assertEqualsWithDelta(0.0, $payload['paired_delta'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $irrelevant['paired_delta'], 0.0001);
        $this->assertSame(0, $payload['invalid_pairs']['positive_lift_fabricated']);
    }

    public function test_non_peek_or_usage_recording_pairs_are_excluded_from_the_floor(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $this->pair("valid-$i", 'procedural', controlScore: 1, treatmentScore: 3);
        }
        $this->pair('usage-recording', 'procedural', controlScore: 1, treatmentScore: 5, recordUsage: true);
        $this->pair('not-peek', 'procedural', controlScore: 1, treatmentScore: 5, mode: 'live');

        $payload = $this->report();
        $procedural = collect($payload['memory_types'])->firstWhere('memory_type', 'procedural');

        $this->assertSame('insufficient_signal', $payload['status']);
        $this->assertSame(7, $payload['n_pairs']);
        $this->assertSame('insufficient_signal', $procedural['status']);
        $this->assertSame(2, $payload['invalid_pairs']['peek_policy_violation']);
        $this->assertSame(4, $payload['peek_policy']['usage_rows_recorded']);
    }

    /** @return array<string,mixed> */
    private function report(): array
    {
        $exit = Artisan::call('atlas:ai:counterfactual-lift', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);

        return $payload;
    }

    private function pair(
        string $pairId,
        string $memoryType,
        int $controlScore,
        int $treatmentScore,
        bool $recordUsage = false,
        string $mode = 'peek',
    ): void {
        $this->feedback($pairId, $memoryType, 'control', $controlScore, $recordUsage, $mode);
        $this->feedback($pairId, $memoryType, 'treatment', $treatmentScore, $recordUsage, $mode);
    }

    private function feedback(
        string $pairId,
        string $memoryType,
        string $arm,
        int $score,
        bool $recordUsage,
        string $mode,
    ): void {
        DB::table('ai_rag_feedback_events')->insert([
            'id' => (string) Str::uuid(),
            'schema_version' => 'atlas.ai.rag.feedback.v1',
            'retrieval_receipt_id' => 'receipt-'.$pairId.'-'.$arm,
            'flow_id' => 'multj03-flow',
            'query_plan_hash' => hash('sha256', $pairId),
            'included_sources' => $arm === 'treatment' ? 1 : 0,
            'used_sources' => $arm === 'treatment' ? 1 : 0,
            'noise_sources' => 0,
            'context_sufficiency' => $score,
            'post_execution_utility' => $score,
            'outcome_status' => 'passed',
            'payload' => json_encode([
                'counterfactual_lift_v2' => [
                    'pair_id' => $pairId,
                    'memory_type' => $memoryType,
                    'arm' => $arm,
                    'mode' => $mode,
                    'record_usage' => $recordUsage,
                    'deterministic_judge' => 'post_execution_utility',
                ],
            ], JSON_THROW_ON_ERROR),
            'feedback_hash' => hash('sha256', $pairId.$arm.$score.$mode.($recordUsage ? '1' : '0')),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

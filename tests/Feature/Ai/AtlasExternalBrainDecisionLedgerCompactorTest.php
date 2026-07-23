<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainDecisionLedgerCompactor;
use Tests\TestCase;

final class AtlasExternalBrainDecisionLedgerCompactorTest extends TestCase
{
    private function compactor(): AtlasExternalBrainDecisionLedgerCompactor
    {
        return new AtlasExternalBrainDecisionLedgerCompactor;
    }

    public function test_contradictory_high_uncertainty_stale_and_irreversible_traces_stay_standalone(): void
    {
        $result = $this->compactor()->compact([
            'traces' => [
                ['trace_id' => 'contradictory-1', 'causes' => ['a'], 'outcome' => 'x', 'scope' => 's1', 'is_contradictory' => true],
                ['trace_id' => 'uncertain-1', 'causes' => ['b'], 'outcome' => 'y', 'scope' => 's1', 'uncertainty' => 'high'],
                ['trace_id' => 'stale-1', 'causes' => ['c'], 'outcome' => 'z', 'scope' => 's1', 'is_stale' => true],
                ['trace_id' => 'irreversible-1', 'causes' => ['d'], 'outcome' => 'w', 'scope' => 's1', 'reversibility' => 'irreversible'],
            ],
        ]);

        $byTraceId = [];
        foreach ($result['lessons'] as $lesson) {
            $byTraceId[$lesson['superseded_trace_ids'][0]] = $lesson;
        }

        $this->assertSame('contradictory', $byTraceId['contradictory-1']['kept_separate_reason']);
        $this->assertSame('high_uncertainty', $byTraceId['uncertain-1']['kept_separate_reason']);
        $this->assertSame('stale', $byTraceId['stale-1']['kept_separate_reason']);
        $this->assertSame('irreversible', $byTraceId['irreversible-1']['kept_separate_reason']);
        $this->assertSame(4, $result['lesson_count']);
        $this->assertSame(4, $result['compacted_from']);
    }

    public function test_mergeable_traces_group_by_sorted_causes_outcome_and_scope_never_across_scopes(): void
    {
        $result = $this->compactor()->compact([
            'traces' => [
                ['trace_id' => 't1', 'causes' => ['b', 'a'], 'outcome' => 'same-outcome', 'scope' => 'scope-a'],
                ['trace_id' => 't2', 'causes' => ['a', 'b'], 'outcome' => 'same-outcome', 'scope' => 'scope-a'],
                ['trace_id' => 't3', 'causes' => ['a', 'b'], 'outcome' => 'same-outcome', 'scope' => 'scope-b'],
            ],
        ]);

        // Same causes/outcome, different scope -> never merged into the same lesson.
        $this->assertCount(2, $result['lessons']);

        $scopeAId = [];
        $scopeBId = [];
        foreach ($result['lessons'] as $lesson) {
            if ($lesson['scope'] === 'scope-a') {
                $scopeAId = $lesson;
            } elseif ($lesson['scope'] === 'scope-b') {
                $scopeBId = $lesson;
            }
        }

        $this->assertSame(2, $scopeAId['occurrence_count']);
        $this->assertSame(['t1', 't2'], $scopeAId['superseded_trace_ids']);
        $this->assertSame(['a', 'b'], $scopeAId['causes']);

        $this->assertSame(1, $scopeBId['occurrence_count']);
        $this->assertSame(['t3'], $scopeBId['superseded_trace_ids']);
    }

    public function test_merged_lessons_preserve_evidence_ids_conservative_expiry_reversibility_and_confidence(): void
    {
        $result = $this->compactor()->compact([
            'traces' => [
                [
                    'trace_id' => 'e1', 'causes' => ['x'], 'outcome' => 'out', 'scope' => 'scope-c',
                    'evidence_refs' => ['ref-1'], 'expires_at' => '2026-08-01T00:00:00Z',
                    'uncertainty' => 'low', 'reversibility' => 'reversible',
                ],
                [
                    'trace_id' => 'e2', 'causes' => ['x'], 'outcome' => 'out', 'scope' => 'scope-c',
                    'evidence_refs' => ['ref-2'], 'expires_at' => '2026-06-01T00:00:00Z',
                    'uncertainty' => 'medium', 'reversibility' => 'unknown',
                ],
                [
                    'trace_id' => 'e3', 'causes' => ['x'], 'outcome' => 'out', 'scope' => 'scope-c',
                    'evidence_refs' => ['ref-3'], 'expires_at' => '2026-07-15T00:00:00Z',
                    'uncertainty' => 'low', 'reversibility' => 'reversible',
                ],
            ],
        ]);

        $lesson = $result['lessons'][0];
        $this->assertSame(['ref-1', 'ref-2', 'ref-3'], $lesson['retained_evidence']);
        $this->assertSame(['e1', 'e2', 'e3'], $lesson['superseded_trace_ids']);
        $this->assertSame('2026-06-01T00:00:00Z', $lesson['expires_at']);
        $this->assertSame('unknown', $lesson['reversibility']);
        $this->assertSame(3, $lesson['occurrence_count']);
        // >=3 occurrences but one contributor is medium-uncertainty -> confidence stays medium, not high.
        $this->assertSame(AtlasExternalBrainDecisionLedgerCompactor::CONFIDENCE_MEDIUM, $lesson['confidence']);
    }

    public function test_three_low_uncertainty_occurrences_yield_high_confidence(): void
    {
        $traces = [];
        for ($i = 1; $i <= 3; $i++) {
            $traces[] = [
                'trace_id' => "h{$i}", 'causes' => ['y'], 'outcome' => 'out', 'scope' => 'scope-d',
                'uncertainty' => 'low', 'reversibility' => 'reversible',
            ];
        }

        $result = $this->compactor()->compact(['traces' => $traces]);

        $this->assertSame(AtlasExternalBrainDecisionLedgerCompactor::CONFIDENCE_HIGH, $result['lessons'][0]['confidence']);
    }
}

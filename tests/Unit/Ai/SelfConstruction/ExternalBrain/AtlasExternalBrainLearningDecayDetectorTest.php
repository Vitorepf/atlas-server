<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLearningDecayDetector;
use Tests\TestCase;

final class AtlasExternalBrainLearningDecayDetectorTest extends TestCase
{
    private function detector(): AtlasExternalBrainLearningDecayDetector
    {
        return new AtlasExternalBrainLearningDecayDetector();
    }

    private function freshLesson(string $id = 'L1'): array
    {
        return [
            'lesson_id'              => $id,
            'created_at_seconds_ago' => 100,
            'campaign_ids'           => ['camp-a', 'camp-b'],
            'contradicted_by'        => [],
        ];
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->detector()->detect([]);

        $this->assertSame(AtlasExternalBrainLearningDecayDetector::SCHEMA, $result['schema']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->detector()->detect([]);

        foreach (['schema', 'lessons', 'total', 'contradicted_count', 'decayed_count', 'overfit_count', 'fresh_count'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
    }

    public function test_each_lesson_entry_has_required_fields(): void
    {
        $result = $this->detector()->detect(['lessons' => [$this->freshLesson()]]);

        $entry = $result['lessons'][0];
        foreach (['lesson_id', 'status', 'recommendation', 'decay_reason'] as $f) {
            $this->assertArrayHasKey($f, $entry);
        }
    }

    public function test_empty_lessons_yields_zero_counts(): void
    {
        $result = $this->detector()->detect(['lessons' => []]);

        $this->assertSame(0, $result['total']);
        $this->assertSame(0, $result['contradicted_count']);
        $this->assertSame(0, $result['decayed_count']);
        $this->assertSame(0, $result['overfit_count']);
        $this->assertSame(0, $result['fresh_count']);
    }

    // ── fresh ─────────────────────────────────────────────────────────────────

    public function test_fresh_lesson_gets_keep(): void
    {
        $result = $this->detector()->detect(['lessons' => [$this->freshLesson()]]);

        $entry = $result['lessons'][0];
        $this->assertSame(AtlasExternalBrainLearningDecayDetector::STATUS_FRESH, $entry['status']);
        $this->assertSame(AtlasExternalBrainLearningDecayDetector::REC_KEEP, $entry['recommendation']);
        $this->assertNull($entry['decay_reason']);
    }

    public function test_fresh_count_increments(): void
    {
        $result = $this->detector()->detect([
            'lessons' => [$this->freshLesson('A'), $this->freshLesson('B')],
        ]);

        $this->assertSame(2, $result['fresh_count']);
    }

    // ── contradicted ──────────────────────────────────────────────────────────

    public function test_contradicted_lesson_gets_demote(): void
    {
        $result = $this->detector()->detect([
            'lessons' => [[
                'lesson_id'              => 'L2',
                'created_at_seconds_ago' => 100,
                'campaign_ids'           => ['camp-a', 'camp-b'],
                'contradicted_by'        => ['outcome-99'],
            ]],
        ]);

        $entry = $result['lessons'][0];
        $this->assertSame(AtlasExternalBrainLearningDecayDetector::STATUS_CONTRADICTED, $entry['status']);
        $this->assertSame(AtlasExternalBrainLearningDecayDetector::REC_DEMOTE, $entry['recommendation']);
        $this->assertNotEmpty($entry['decay_reason']);
    }

    public function test_contradicted_count_increments(): void
    {
        $result = $this->detector()->detect([
            'lessons' => [
                ['lesson_id' => 'A', 'created_at_seconds_ago' => 100, 'campaign_ids' => ['x','y'], 'contradicted_by' => ['o1']],
                ['lesson_id' => 'B', 'created_at_seconds_ago' => 100, 'campaign_ids' => ['x','y'], 'contradicted_by' => ['o2']],
            ],
        ]);

        $this->assertSame(2, $result['contradicted_count']);
    }

    // ── decayed ───────────────────────────────────────────────────────────────

    public function test_old_lesson_gets_decayed_and_revalidate(): void
    {
        $result = $this->detector()->detect([
            'lessons' => [[
                'lesson_id'              => 'L3',
                'created_at_seconds_ago' => 700000,    // > 604800
                'campaign_ids'           => ['camp-a', 'camp-b'],
                'contradicted_by'        => [],
            ]],
        ]);

        $entry = $result['lessons'][0];
        $this->assertSame(AtlasExternalBrainLearningDecayDetector::STATUS_DECAYED, $entry['status']);
        $this->assertSame(AtlasExternalBrainLearningDecayDetector::REC_REVALIDATE, $entry['recommendation']);
    }

    public function test_lesson_at_exact_threshold_is_not_decayed(): void
    {
        $result = $this->detector()->detect([
            'lessons' => [[
                'lesson_id'              => 'L4',
                'created_at_seconds_ago' => 604800,    // exactly at threshold, NOT over
                'campaign_ids'           => ['a', 'b'],
                'contradicted_by'        => [],
            ]],
        ]);

        $this->assertNotSame(AtlasExternalBrainLearningDecayDetector::STATUS_DECAYED, $result['lessons'][0]['status']);
    }

    public function test_custom_decay_threshold(): void
    {
        $result = $this->detector()->detect([
            'lessons' => [[
                'lesson_id'              => 'L5',
                'created_at_seconds_ago' => 1000,
                'campaign_ids'           => ['a', 'b'],
                'contradicted_by'        => [],
            ]],
            'decay_threshold_seconds' => 500,
        ]);

        $this->assertSame(AtlasExternalBrainLearningDecayDetector::STATUS_DECAYED, $result['lessons'][0]['status']);
    }

    // ── overfit ───────────────────────────────────────────────────────────────

    public function test_single_campaign_lesson_is_overfit(): void
    {
        $result = $this->detector()->detect([
            'lessons' => [[
                'lesson_id'              => 'L6',
                'created_at_seconds_ago' => 100,
                'campaign_ids'           => ['only-one'],
                'contradicted_by'        => [],
            ]],
        ]);

        $entry = $result['lessons'][0];
        $this->assertSame(AtlasExternalBrainLearningDecayDetector::STATUS_OVERFIT, $entry['status']);
        $this->assertSame(AtlasExternalBrainLearningDecayDetector::REC_REVALIDATE, $entry['recommendation']);
    }

    public function test_two_campaign_lesson_is_not_overfit_with_default_threshold(): void
    {
        $result = $this->detector()->detect([
            'lessons' => [[
                'lesson_id'              => 'L7',
                'created_at_seconds_ago' => 100,
                'campaign_ids'           => ['camp-a', 'camp-b'],
                'contradicted_by'        => [],
            ]],
        ]);

        $this->assertNotSame(AtlasExternalBrainLearningDecayDetector::STATUS_OVERFIT, $result['lessons'][0]['status']);
    }

    public function test_custom_overfit_max_campaigns(): void
    {
        $result = $this->detector()->detect([
            'lessons' => [[
                'lesson_id'              => 'L8',
                'created_at_seconds_ago' => 100,
                'campaign_ids'           => ['a', 'b'],     // 2 campaigns
                'contradicted_by'        => [],
            ]],
            'overfit_max_campaigns' => 2,                   // now 2 campaigns is still overfit
        ]);

        $this->assertSame(AtlasExternalBrainLearningDecayDetector::STATUS_OVERFIT, $result['lessons'][0]['status']);
    }

    // ── priority: contradicted > decayed > overfit > fresh ───────────────────

    public function test_contradicted_wins_over_decayed(): void
    {
        $result = $this->detector()->detect([
            'lessons' => [[
                'lesson_id'              => 'P1',
                'created_at_seconds_ago' => 999999,  // would be decayed
                'campaign_ids'           => ['a', 'b'],
                'contradicted_by'        => ['o1'],   // also contradicted
            ]],
        ]);

        $this->assertSame(AtlasExternalBrainLearningDecayDetector::STATUS_CONTRADICTED, $result['lessons'][0]['status']);
    }

    public function test_decayed_wins_over_overfit(): void
    {
        $result = $this->detector()->detect([
            'lessons' => [[
                'lesson_id'              => 'P2',
                'created_at_seconds_ago' => 999999,  // decayed
                'campaign_ids'           => ['single'], // also overfit
                'contradicted_by'        => [],
            ]],
        ]);

        $this->assertSame(AtlasExternalBrainLearningDecayDetector::STATUS_DECAYED, $result['lessons'][0]['status']);
    }

    // ── no deletion without explicit contradiction ─────────────────────────────

    public function test_decayed_lesson_gets_revalidate_not_demote(): void
    {
        $result = $this->detector()->detect([
            'lessons' => [[
                'lesson_id'              => 'ND1',
                'created_at_seconds_ago' => 900000,
                'campaign_ids'           => ['a', 'b'],
                'contradicted_by'        => [],
            ]],
        ]);

        $this->assertSame(AtlasExternalBrainLearningDecayDetector::REC_REVALIDATE, $result['lessons'][0]['recommendation']);
        $this->assertNotSame(AtlasExternalBrainLearningDecayDetector::REC_DEMOTE, $result['lessons'][0]['recommendation']);
    }

    // ── totals ────────────────────────────────────────────────────────────────

    public function test_totals_sum_correctly(): void
    {
        $result = $this->detector()->detect([
            'lessons' => [
                ['lesson_id' => 'a', 'created_at_seconds_ago' => 100, 'campaign_ids' => ['x','y'], 'contradicted_by' => ['o1']],
                ['lesson_id' => 'b', 'created_at_seconds_ago' => 900000, 'campaign_ids' => ['x','y'], 'contradicted_by' => []],
                ['lesson_id' => 'c', 'created_at_seconds_ago' => 100, 'campaign_ids' => ['only'], 'contradicted_by' => []],
                ['lesson_id' => 'd', 'created_at_seconds_ago' => 100, 'campaign_ids' => ['x','y'], 'contradicted_by' => []],
            ],
        ]);

        $this->assertSame(4, $result['total']);
        $this->assertSame(1, $result['contradicted_count']);
        $this->assertSame(1, $result['decayed_count']);
        $this->assertSame(1, $result['overfit_count']);
        $this->assertSame(1, $result['fresh_count']);
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $input = [
            'lessons' => [
                $this->freshLesson('A'),
                ['lesson_id' => 'B', 'created_at_seconds_ago' => 800000, 'campaign_ids' => ['x','y'], 'contradicted_by' => []],
            ],
        ];

        $this->assertSame($this->detector()->detect($input), $this->detector()->detect($input));
    }
}

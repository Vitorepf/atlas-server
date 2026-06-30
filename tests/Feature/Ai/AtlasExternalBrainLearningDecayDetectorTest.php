<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLearningDecayDetector;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainLearningDecayDetectorTest extends TestCase
{
    private function detector(): AtlasExternalBrainLearningDecayDetector
    {
        return new AtlasExternalBrainLearningDecayDetector;
    }

    // ── AC2: contradicted takes highest priority ───────────────────────────────

    public function test_contradicted_takes_priority_over_architecture_incompatibility(): void
    {
        $r = $this->detector()->detect([
            'current_architecture_version' => 'v2',
            'lessons' => [[
                'lesson_id'            => 'L1',
                'created_at_seconds_ago' => 0,
                'architecture_version' => 'v1',      // arch-incompatible
                'contradicted_by'      => ['outcome-99'],  // also contradicted
                'campaign_ids'         => ['c1', 'c2'],
            ]],
        ]);

        $lesson = $r['lessons'][0];
        $this->assertSame(AtlasExternalBrainLearningDecayDetector::STATUS_CONTRADICTED, $lesson['status']);
        $this->assertSame(AtlasExternalBrainLearningDecayDetector::REC_DEMOTE, $lesson['recommendation']);
        $this->assertSame(1, $r['contradicted_count']);
        $this->assertSame(0, $r['architecture_incompatible_count']);
    }

    public function test_contradicted_takes_priority_over_decay(): void
    {
        $r = $this->detector()->detect([
            'decay_threshold_seconds' => 100,
            'lessons' => [[
                'lesson_id'              => 'L2',
                'created_at_seconds_ago' => 999,   // decayed
                'contradicted_by'        => ['ev-1'],
                'campaign_ids'           => ['c1', 'c2'],
            ]],
        ]);

        $this->assertSame(AtlasExternalBrainLearningDecayDetector::STATUS_CONTRADICTED, $r['lessons'][0]['status']);
    }

    public function test_contradicted_takes_priority_over_overfit(): void
    {
        $r = $this->detector()->detect([
            'overfit_max_campaigns' => 3,
            'lessons' => [[
                'lesson_id'              => 'L3',
                'created_at_seconds_ago' => 0,
                'contradicted_by'        => ['ev-2'],
                'campaign_ids'           => ['c1'],   // overfit too
            ]],
        ]);

        $this->assertSame(AtlasExternalBrainLearningDecayDetector::STATUS_CONTRADICTED, $r['lessons'][0]['status']);
    }

    // ── AC3: architecture_version mismatch → quarantined ──────────────────────

    public function test_different_architecture_version_quarantines_lesson(): void
    {
        $r = $this->detector()->detect([
            'current_architecture_version' => 'v3',
            'lessons' => [[
                'lesson_id'              => 'L4',
                'created_at_seconds_ago' => 0,
                'architecture_version'   => 'v2',
                'campaign_ids'           => ['c1', 'c2'],
            ]],
        ]);

        $lesson = $r['lessons'][0];
        $this->assertSame(AtlasExternalBrainLearningDecayDetector::STATUS_ARCHITECTURE_INCOMPATIBLE, $lesson['status']);
        $this->assertSame(AtlasExternalBrainLearningDecayDetector::REC_QUARANTINE, $lesson['recommendation']);
        $this->assertFalse($lesson['architecture_compatible']);
        $this->assertSame(1, $r['architecture_incompatible_count']);
    }

    public function test_matching_architecture_version_does_not_quarantine(): void
    {
        $r = $this->detector()->detect([
            'current_architecture_version' => 'v3',
            'lessons' => [[
                'lesson_id'              => 'L5',
                'created_at_seconds_ago' => 0,
                'architecture_version'   => 'v3',
                'campaign_ids'           => ['c1', 'c2'],
            ]],
        ]);

        $this->assertNotSame(AtlasExternalBrainLearningDecayDetector::STATUS_ARCHITECTURE_INCOMPATIBLE, $r['lessons'][0]['status']);
    }

    // ── AC4: overfit with enough confirmations → downrank, not revalidate ────

    public function test_overfit_with_low_confirmations_triggers_revalidate(): void
    {
        $r = $this->detector()->detect([
            'overfit_max_campaigns'         => 2,
            'min_confirmations_for_reliable' => 3,
            'lessons' => [[
                'lesson_id'              => 'L6',
                'created_at_seconds_ago' => 0,
                'campaign_ids'           => ['c1'],
                'confirmation_count'     => 1,       // below min
            ]],
        ]);

        $lesson = $r['lessons'][0];
        $this->assertSame(AtlasExternalBrainLearningDecayDetector::STATUS_OVERFIT, $lesson['status']);
        $this->assertSame(AtlasExternalBrainLearningDecayDetector::REC_REVALIDATE, $lesson['recommendation']);
    }

    public function test_overfit_with_enough_confirmations_triggers_downrank(): void
    {
        $r = $this->detector()->detect([
            'overfit_max_campaigns'         => 2,
            'min_confirmations_for_reliable' => 3,
            'lessons' => [[
                'lesson_id'              => 'L7',
                'created_at_seconds_ago' => 0,
                'campaign_ids'           => ['c1'],
                'confirmation_count'     => 5,       // >= min
            ]],
        ]);

        $lesson = $r['lessons'][0];
        $this->assertSame(AtlasExternalBrainLearningDecayDetector::STATUS_OVERFIT, $lesson['status']);
        $this->assertSame(AtlasExternalBrainLearningDecayDetector::REC_DOWNRANK, $lesson['recommendation']);
    }

    // ── Output counts every status class ──────────────────────────────────────

    public function test_output_counts_all_status_classes(): void
    {
        $r = $this->detector()->detect([
            'current_architecture_version'  => 'v2',
            'decay_threshold_seconds'       => 100,
            'overfit_max_campaigns'         => 1,
            'min_confirmations_for_reliable' => 3,
            'lessons' => [
                ['lesson_id' => 'c', 'created_at_seconds_ago' => 0, 'contradicted_by' => ['ev-1'], 'campaign_ids' => ['c1', 'c2']],
                ['lesson_id' => 'a', 'created_at_seconds_ago' => 0, 'architecture_version' => 'v1', 'campaign_ids' => ['c1', 'c2']],
                ['lesson_id' => 'd', 'created_at_seconds_ago' => 999, 'campaign_ids' => ['c1', 'c2']],
                ['lesson_id' => 'o', 'created_at_seconds_ago' => 0, 'campaign_ids' => ['c1'], 'confirmation_count' => 1],
                ['lesson_id' => 'f', 'created_at_seconds_ago' => 0, 'campaign_ids' => ['c1', 'c2', 'c3']],
            ],
        ]);

        $this->assertSame(1, $r['contradicted_count']);
        $this->assertSame(1, $r['architecture_incompatible_count']);
        $this->assertSame(1, $r['decayed_count']);
        $this->assertSame(1, $r['overfit_count']);
        $this->assertSame(1, $r['fresh_count']);
        $this->assertSame(5, $r['total']);
    }

    public function test_detect_is_deterministic(): void
    {
        $input = [
            'lessons' => [
                ['lesson_id' => 'x', 'created_at_seconds_ago' => 500, 'campaign_ids' => ['c1']],
            ],
        ];

        $a = $this->detector()->detect($input);
        $b = $this->detector()->detect($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}

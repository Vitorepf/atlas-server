<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\SelfConstructionCapabilityLadderLevelClassifier;
use PHPUnit\Framework\TestCase;

final class SelfConstructionCapabilityLadderLevelClassifierTest extends TestCase
{
    private SelfConstructionCapabilityLadderLevelClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new SelfConstructionCapabilityLadderLevelClassifier();
    }

    public function testAllEightSignalsTrueReachLevelEight(): void
    {
        $result = $this->classifier->classify([
            'has_canonical_doc' => true,
            'has_spec' => true,
            'has_scaffold' => true,
            'manual_command_passes' => true,
            'agent_executable_with_receipt' => true,
            'repeated_safe_runs' => true,
            'learning_proposals_safe' => true,
            'strategic_selection_proven' => true,
        ]);

        $this->assertSame('atlas.self_construction.capability_ladder.v1', $result['schema_version']);
        $this->assertSame(8, $result['level']);
        $this->assertSame('strategic_self_construction', $result['label']);
        $this->assertNull($result['broken_at']);
        $this->assertSame([
            'has_canonical_doc',
            'has_spec',
            'has_scaffold',
            'manual_command_passes',
            'agent_executable_with_receipt',
            'repeated_safe_runs',
            'learning_proposals_safe',
            'strategic_selection_proven',
        ], $result['achieved_prerequisites']);
        $this->assertTrue($result['is_anti_confusion_safe']);
    }

    public function testMonotoneGapStopsAtFirstFalsePrerequisiteEvenWhenHigherSignalsTrue(): void
    {
        $result = $this->classifier->classify([
            'has_canonical_doc' => true,
            'has_spec' => true,
            'has_scaffold' => false,
            'manual_command_passes' => true,
            'agent_executable_with_receipt' => true,
        ]);

        $this->assertSame(2, $result['level']);
        $this->assertSame('specified', $result['label']);
        $this->assertSame(3, $result['broken_at']);
        $this->assertSame(['has_canonical_doc', 'has_spec'], $result['achieved_prerequisites']);
        $this->assertTrue($result['is_anti_confusion_safe']);
    }

    public function testEmptySignalsReturnLevelZeroNamed(): void
    {
        $result = $this->classifier->classify([]);

        $this->assertSame(0, $result['level']);
        $this->assertSame('named', $result['label']);
        $this->assertSame(1, $result['broken_at']);
        $this->assertSame([], $result['achieved_prerequisites']);
        $this->assertTrue($result['is_anti_confusion_safe']);
    }

    public function testAllFalseSignalsReturnLevelZeroNamed(): void
    {
        $result = $this->classifier->classify([
            'has_canonical_doc' => false,
            'has_spec' => false,
            'has_scaffold' => false,
            'manual_command_passes' => false,
            'agent_executable_with_receipt' => false,
            'repeated_safe_runs' => false,
            'learning_proposals_safe' => false,
            'strategic_selection_proven' => false,
        ]);

        $this->assertSame(0, $result['level']);
        $this->assertSame('named', $result['label']);
        $this->assertSame(1, $result['broken_at']);
        $this->assertSame([], $result['achieved_prerequisites']);
        $this->assertTrue($result['is_anti_confusion_safe']);
    }

    public function testOnlyCanonicalDocTrueReturnsLevelOneDocumented(): void
    {
        $result = $this->classifier->classify([
            'has_canonical_doc' => true,
        ]);

        $this->assertSame(1, $result['level']);
        $this->assertSame('documented', $result['label']);
        $this->assertSame(2, $result['broken_at']);
        $this->assertSame(['has_canonical_doc'], $result['achieved_prerequisites']);
        $this->assertTrue($result['is_anti_confusion_safe']);
    }

    public function testAntiConfusionSafeAndCorrectLabelHoldForEveryContiguousLevel(): void
    {
        $expectedLabels = [
            0 => 'named',
            1 => 'documented',
            2 => 'specified',
            3 => 'scaffolded',
            4 => 'executable_manual',
            5 => 'agent_executable',
            6 => 'autonomous_restricted',
            7 => 'self_improving_governed',
            8 => 'strategic_self_construction',
        ];

        $orderedSignals = [
            'has_canonical_doc',
            'has_spec',
            'has_scaffold',
            'manual_command_passes',
            'agent_executable_with_receipt',
            'repeated_safe_runs',
            'learning_proposals_safe',
            'strategic_selection_proven',
        ];

        foreach ($expectedLabels as $level => $expectedLabel) {
            $signals = [];
            for ($i = 0; $i < $level; $i++) {
                $signals[$orderedSignals[$i]] = true;
            }

            $result = $this->classifier->classify($signals);

            $this->assertSame($level, $result['level']);
            $this->assertSame($expectedLabel, $result['label']);
            $this->assertTrue($result['is_anti_confusion_safe']);
            $this->assertSame($level === 8 ? null : $level + 1, $result['broken_at']);
        }
    }

    public function testHigherSignalsAfterAGapAreNeverCountedTowardLevel(): void
    {
        // Contiguous through L5 (agent_executable), gap at L6, but L7+L8 true.
        $result = $this->classifier->classify([
            'has_canonical_doc' => true,
            'has_spec' => true,
            'has_scaffold' => true,
            'manual_command_passes' => true,
            'agent_executable_with_receipt' => true,
            'repeated_safe_runs' => false,
            'learning_proposals_safe' => true,
            'strategic_selection_proven' => true,
        ]);

        $this->assertSame(5, $result['level']);
        $this->assertSame('agent_executable', $result['label']);
        $this->assertSame(6, $result['broken_at']);
        $this->assertSame([
            'has_canonical_doc',
            'has_spec',
            'has_scaffold',
            'manual_command_passes',
            'agent_executable_with_receipt',
        ], $result['achieved_prerequisites']);
        $this->assertTrue($result['is_anti_confusion_safe']);
    }

    public function testContiguousLevelSevenStopsBeforeFinalStrategicSignal(): void
    {
        $result = $this->classifier->classify([
            'has_canonical_doc' => true,
            'has_spec' => true,
            'has_scaffold' => true,
            'manual_command_passes' => true,
            'agent_executable_with_receipt' => true,
            'repeated_safe_runs' => true,
            'learning_proposals_safe' => true,
            'strategic_selection_proven' => false,
        ]);

        $this->assertSame(7, $result['level']);
        $this->assertSame('self_improving_governed', $result['label']);
        $this->assertSame(8, $result['broken_at']);
        $this->assertTrue($result['is_anti_confusion_safe']);
    }

    public function testClassificationIsDeterministicForIdenticalInput(): void
    {
        $signals = [
            'has_canonical_doc' => true,
            'has_spec' => true,
            'has_scaffold' => true,
            'manual_command_passes' => false,
        ];

        $first = $this->classifier->classify($signals);
        $second = $this->classifier->classify($signals);

        $this->assertSame($first, $second);
        $this->assertSame(3, $first['level']);
        $this->assertSame('scaffolded', $first['label']);
        $this->assertSame(4, $first['broken_at']);
    }

    public function testMissingPrerequisitesAndNextLeverageSignalDerivedFromBrokenAt(): void
    {
        $result = $this->classifier->classify([
            'has_canonical_doc' => true,
            'has_spec' => true,
            'has_scaffold' => false,
        ]);

        // broken_at=3 → next_leverage_signal=has_scaffold, missing from index 2 onward
        $this->assertSame('has_scaffold', $result['next_leverage_signal']);
        $this->assertSame([
            'has_scaffold',
            'manual_command_passes',
            'agent_executable_with_receipt',
            'repeated_safe_runs',
            'learning_proposals_safe',
            'strategic_selection_proven',
        ], $result['missing_prerequisites']);
        $this->assertTrue($result['finality_gap']);
    }

    public function testFullLadderLevel8HasNoMissingPrerequisitesAndNoFinalityGap(): void
    {
        $result = $this->classifier->classify([
            'has_canonical_doc' => true,
            'has_spec' => true,
            'has_scaffold' => true,
            'manual_command_passes' => true,
            'agent_executable_with_receipt' => true,
            'repeated_safe_runs' => true,
            'learning_proposals_safe' => true,
            'strategic_selection_proven' => true,
        ]);

        $this->assertSame([], $result['missing_prerequisites']);
        $this->assertNull($result['next_leverage_signal']);
        $this->assertFalse($result['finality_gap']);
    }

    public function testFinalityGapTrueForAllLevelsBelowEight(): void
    {
        $result = $this->classifier->classify(['has_canonical_doc' => true]);
        $this->assertTrue($result['finality_gap']);

        $result = $this->classifier->classify([]);
        $this->assertTrue($result['finality_gap']);
    }
}

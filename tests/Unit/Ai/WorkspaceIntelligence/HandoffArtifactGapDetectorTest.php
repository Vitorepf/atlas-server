<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\WorkspaceIntelligence;

use App\Services\Ai\WorkspaceIntelligence\HandoffArtifactGapDetector;
use PHPUnit\Framework\TestCase;

final class HandoffArtifactGapDetectorTest extends TestCase
{
    private HandoffArtifactGapDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new HandoffArtifactGapDetector();
    }

    public function testReturnsMissingRequiredTypesInRequiredOrder(): void
    {
        $gaps = $this->detector->gaps(
            ['workspace_brief', 'task_packet'],
            ['workspace_brief', 'task_packet', 'context_pack', 'test_plan'],
        );

        $this->assertSame(['context_pack', 'test_plan'], $gaps);
    }

    public function testPresentSupersetOfRequiredYieldsNoGaps(): void
    {
        $gaps = $this->detector->gaps(
            ['workspace_brief', 'task_packet', 'context_pack', 'test_plan', 'risk_sheet'],
            ['workspace_brief', 'task_packet', 'context_pack', 'test_plan'],
        );

        $this->assertSame([], $gaps);
    }

    public function testEmptyPresentReturnsFullRequiredList(): void
    {
        $gaps = $this->detector->gaps(
            [],
            ['workspace_brief', 'task_packet', 'context_pack', 'test_plan'],
        );

        $this->assertSame(
            ['workspace_brief', 'task_packet', 'context_pack', 'test_plan'],
            $gaps,
        );
    }

    public function testDuplicateRequiredTypeCollapsesToSingleGap(): void
    {
        $gaps = $this->detector->gaps(
            [],
            ['risk_sheet', 'risk_sheet'],
        );

        $this->assertSame(['risk_sheet'], $gaps);
    }

    public function testWhitespacePaddedPresentSatisfiesRequiredType(): void
    {
        $gaps = $this->detector->gaps(
            [' task_packet '],
            ['task_packet'],
        );

        $this->assertSame([], $gaps);
    }
}

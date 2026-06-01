<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog\PostExecutionPhaseEmissionPlanBuilder;
use PHPUnit\Framework\TestCase;

final class PostExecutionPhaseEmissionPlanBuilderTest extends TestCase
{
    private PostExecutionPhaseEmissionPlanBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new PostExecutionPhaseEmissionPlanBuilder();
    }

    public function testFlagFalseReturnsEnabledFalseAndEmptyEnvelopes(): void
    {
        $plan = $this->builder->build([], [], ['ok' => true]);

        $this->assertSame('atlas.aaeos.post_execution_phase_emission_plan.v1', $plan['schema_version']);
        $this->assertFalse($plan['enabled']);
        $this->assertSame([], $plan['envelopes']);
        $this->assertSame([], $plan['failed_phase_ids']);
        $this->assertFalse($plan['dispatcher_marker_required']);
        $this->assertSame('never_throw', $plan['exception_policy']);
    }

    public function testOkResultBuildsSevenP10P16Envelopes(): void
    {
        $plan = $this->builder->build(
            ['post_execution_phase_emit' => true],
            ['duration_ms' => 420],
            ['ok' => true, 'response_hash' => 'trace-123'],
        );

        $this->assertTrue($plan['enabled']);
        $this->assertCount(7, $plan['envelopes']);
        $this->assertSame(['P10', 'P11', 'P12', 'P13', 'P14', 'P15', 'P16'], array_column($plan['envelopes'], 'phase_id'));
        $this->assertSame([10, 11, 12, 13, 14, 15, 16], array_column($plan['envelopes'], 'phase_number'));
        $this->assertSame([], $plan['failed_phase_ids']);
        $this->assertTrue($plan['dispatcher_marker_required']);
        $this->assertTrue($plan['envelopes'][0]['signals']['execution_log_watchdog_ok']);
        $this->assertSame(420, $plan['envelopes'][0]['signals']['duration_ms']);
    }

    public function testFailedResultMarksP10FailedAndDownstreamBlocked(): void
    {
        $plan = $this->builder->build(
            [],
            ['duration_ms' => 100],
            ['ok' => false],
            ['enabled' => true],
        );

        $this->assertSame('failed', $plan['envelopes'][0]['status']);
        $this->assertSame(['blocked', 'blocked', 'blocked', 'blocked', 'blocked', 'blocked'], array_column(array_slice($plan['envelopes'], 1), 'status'));
        $this->assertSame(['P10', 'P11', 'P12', 'P13', 'P14', 'P15', 'P16'], $plan['failed_phase_ids']);
        $this->assertFalse($plan['envelopes'][0]['signals']['execution_log_watchdog_ok']);
    }

    public function testNonMissionSkipsP14HumanReviewCanonically(): void
    {
        $plan = $this->builder->build(
            ['is_mission' => false],
            [],
            ['ok' => true],
            ['enabled' => true],
        );

        $p14 = $plan['envelopes'][4];

        $this->assertSame('P14', $p14['phase_id']);
        $this->assertSame('skipped', $p14['status']);
        $this->assertTrue($p14['signals']['canonical_skip_non_mission']);
    }

    public function testMissionKeepsP14PendingForHumanReview(): void
    {
        $plan = $this->builder->build(
            ['is_mission' => true],
            [],
            ['ok' => true],
            ['enabled' => true],
        );

        $p14 = $plan['envelopes'][4];

        $this->assertSame('P14', $p14['phase_id']);
        $this->assertSame('ok', $p14['status']);
        $this->assertFalse($p14['signals']['canonical_skip_non_mission']);
    }

    public function testEvidenceAndLearningUseResponseHashWithoutDispatching(): void
    {
        $plan = $this->builder->build(
            [],
            [],
            ['ok' => true, 'response_hash' => 'resp-hash'],
            ['enabled' => true],
        );

        $this->assertSame('sha256:'.hash('sha256', 'resp-hash'), $plan['envelopes'][2]['signals']['evidence_hash']);
        $this->assertSame('resp-hash', $plan['envelopes'][6]['signals']['ai_trace']);
    }

    public function testOutputIsDeterministic(): void
    {
        $job = ['post_execution_phase_emit' => true];
        $attempt = ['duration_ms' => '50'];
        $result = ['ok' => true, 'response_hash' => 'same'];

        $this->assertSame(
            $this->builder->build($job, $attempt, $result),
            $this->builder->build($job, $attempt, $result),
        );
    }
}

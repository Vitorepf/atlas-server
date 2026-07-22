<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopJudgeEffortEscalator;
use App\Services\Ai\AutonomousEvolution\AtlasLoopJudgeSelfCalibrationService;
use ReflectionClass;
use Tests\TestCase;

final class AtlasLoopJudgeEffortEscalatorTest extends TestCase
{
    private function calibration(): AtlasLoopJudgeSelfCalibrationService
    {
        return (new ReflectionClass(AtlasLoopJudgeSelfCalibrationService::class))->newInstanceWithoutConstructor();
    }

    public function test_calibration_threshold_is_deterministic_and_does_not_read_config(): void
    {
        $calib = $this->calibration();
        $this->assertSame(0.2, $calib->threshold());

        // Sanity: the threshold constant is exposed and reachable without instantiating via DI.
        $this->assertSame(0.2, AtlasLoopJudgeSelfCalibrationService::ESCALATION_THRESHOLD);

        // The calibration service file MUST NOT mention config('atlas.*')/env() for this knob.
        $src = (string) file_get_contents((new ReflectionClass(AtlasLoopJudgeSelfCalibrationService::class))->getFileName());
        $thresholdContext = substr($src, max(0, strpos($src, 'ESCALATION_THRESHOLD') ?: 0), 400);
        $this->assertStringNotContainsString("config(", $thresholdContext);
        $this->assertStringNotContainsString("env(", $thresholdContext);
    }

    public function test_disabled_escalator_returns_verdict_unchanged(): void
    {
        $invoked = 0;
        $escalator = new AtlasLoopJudgeEffortEscalator($this->calibration(), enabled: false);

        $verdict = ['decision' => 'reject', 'confidence' => 0.51, 'opposing_confidence' => 0.49, 'proposal_id' => 'p1'];
        $out = $escalator->maybeEscalate($verdict, function () use (&$invoked) {
            $invoked++;

            return [];
        });

        $this->assertSame($verdict, $out);
        $this->assertSame(0, $invoked, 'adapter must NOT be invoked when disabled');
    }

    public function test_high_margin_verdict_skips_escalation(): void
    {
        $invoked = 0;
        $escalator = new AtlasLoopJudgeEffortEscalator($this->calibration());

        $verdict = ['decision' => 'accept', 'confidence' => 0.9, 'opposing_confidence' => 0.1, 'proposal_id' => 'p2'];
        $out = $escalator->maybeEscalate($verdict, function () use (&$invoked) {
            $invoked++;

            return [];
        });

        $this->assertSame($verdict, $out, 'margin 0.8 >> 0.2 ⇒ no escalation, verdict unchanged');
        $this->assertSame(0, $invoked);
    }

    public function test_low_margin_verdict_invokes_adapter_once_and_appends_fact_only_escalation(): void
    {
        $captured = [];
        $escalator = new AtlasLoopJudgeEffortEscalator($this->calibration());

        $verdict = [
            'decision' => 'reject',
            'confidence' => 0.55,
            'opposing_confidence' => 0.45,
            'proposal_id' => 'p3',
            'effort' => 'normal',
        ];
        $retriedFromAdapter = [
            'decision' => 'accept',
            'confidence' => 0.82,
            'opposing_confidence' => 0.18,
            'proposal_id' => 'p3',
        ];

        $out = $escalator->maybeEscalate($verdict, function (array $v, string $effort) use (&$captured, $retriedFromAdapter): array {
            $captured[] = ['effort' => $effort, 'verdict' => $v];

            return $retriedFromAdapter;
        });

        $this->assertCount(1, $captured, 'adapter invoked exactly once');
        $this->assertSame(AtlasLoopJudgeEffortEscalator::EFFORT_ESCALATED, $captured[0]['effort']);
        $this->assertSame('accept', $out['decision'], 'retried verdict authoritative');
        $this->assertTrue($out['_escalation']['escalated']);
        $this->assertSame('normal', $out['_escalation']['from_effort']);
        $this->assertSame(AtlasLoopJudgeEffortEscalator::EFFORT_ESCALATED, $out['_escalation']['to_effort']);
        $this->assertSame(0.55, $out['_escalation']['original_confidence']);
        $this->assertEqualsWithDelta(0.1, $out['_escalation']['original_margin'], 1e-9);
        $this->assertSame(0.2, $out['_escalation']['threshold']);
    }

    public function test_one_retry_cap_per_proposal_id(): void
    {
        $invoked = 0;
        $escalator = new AtlasLoopJudgeEffortEscalator($this->calibration());
        $verdict = ['decision' => 'reject', 'confidence' => 0.51, 'opposing_confidence' => 0.49, 'proposal_id' => 'p4'];

        $adapter = function (array $v, string $effort) use (&$invoked, $verdict): array {
            $invoked++;

            return $verdict + ['_was_retried' => true];
        };

        $first = $escalator->maybeEscalate($verdict, $adapter);
        $second = $escalator->maybeEscalate($verdict, $adapter);

        $this->assertSame(1, $invoked, 'second call must NOT re-invoke the adapter (cap=1)');
        $this->assertTrue($first['_escalation']['escalated']);
        $this->assertFalse($second['_escalation']['escalated']);
        $this->assertSame('already_retried', $second['_escalation']['reason']);
    }

    public function test_escalator_never_widens_acceptance_or_scope(): void
    {
        // Adversarial: the adapter returns garbage. The escalator MUST NOT invent a decision or
        // mutate the original acceptance keys — it labels the returned verdict and returns it.
        $escalator = new AtlasLoopJudgeEffortEscalator($this->calibration());
        $verdict = ['decision' => 'reject', 'confidence' => 0.50, 'opposing_confidence' => 0.49, 'proposal_id' => 'p5'];

        $out = $escalator->maybeEscalate($verdict, fn (): string => 'not-an-array');
        // On non-array adapter return, escalator falls back to the original verdict + labels it.
        $this->assertSame('reject', $out['decision']);
        $this->assertTrue($out['_escalation']['escalated']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\Sdd\Compilers;

use App\Services\Ai\Programming\Sdd\Compilers\AcceptanceCriterionTestabilityClassifier;
use PHPUnit\Framework\TestCase;

final class AcceptanceCriterionTestabilityClassifierTest extends TestCase
{
    private AcceptanceCriterionTestabilityClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new AcceptanceCriterionTestabilityClassifier();
    }

    public function testPhpunitFilterCriterionIsExecutableTest(): void
    {
        $this->assertSame(
            'executable_test',
            $this->classifier->classify('run phpunit --filter SpecCriticTest'),
        );
    }

    public function testNumericMillisecondCriterionIsObservableMetric(): void
    {
        $this->assertSame(
            'observable_metric',
            $this->classifier->classify('p95 latency under 200 ms'),
        );
    }

    public function testGreenSuiteCriterionIsExecutableTest(): void
    {
        $this->assertSame(
            'executable_test',
            $this->classifier->classify('CI suite must be green'),
        );
    }

    public function testLooksPolishedCriterionIsManualInspection(): void
    {
        $this->assertSame(
            'manual_inspection',
            $this->classifier->classify('the dashboard looks polished'),
        );
    }

    public function testVagueWordCriterionIsUntestableEvenWhenLong(): void
    {
        $this->assertSame(
            'untestable',
            $this->classifier->classify('maybe handle various cases'),
        );
    }

    public function testShortCriterionIsUntestable(): void
    {
        $this->assertSame(
            'untestable',
            $this->classifier->classify('ok'),
        );
    }

    public function testDefaultCriterionIsManualInspection(): void
    {
        $this->assertSame(
            'manual_inspection',
            $this->classifier->classify('operator confirms the rollout'),
        );
    }

    public function testExecutableMarkerWinsOverVisualProvingFirstMatchPrecedence(): void
    {
        // 'visual test_runs green' matches R4 (visual), R3 (green) AND R1 (test_runs).
        // R1 is evaluated first, so the strongest verifiable label must win.
        $this->assertSame(
            'executable_test',
            $this->classifier->classify('visual test_runs green'),
        );
    }

    public function testNumericMetricWinsOverGreenWordProvingMetricPrecedesGreen(): void
    {
        // Contains both a metric ('50 mb') and the green-state word; R2 precedes R3,
        // so the numeric metric verdict must win over the executable-test verdict.
        $this->assertSame(
            'observable_metric',
            $this->classifier->classify('cache stays green under 50 mb'),
        );
    }

    public function testVisualScreenshotWordIsManualInspectionWhenNoStrongerRuleMatches(): void
    {
        $this->assertSame(
            'manual_inspection',
            $this->classifier->classify('attach a screenshot of the result'),
        );
    }

    public function testUnseenFileCountCriterionGeneralisesToObservableMetric(): void
    {
        // Input absent from the precedence cases above: proves R2 generalises
        // beyond the 'ms'/'%' fixtures to the 'files' unit.
        $this->assertSame(
            'observable_metric',
            $this->classifier->classify('the importer wrote 5 files'),
        );
    }

    public function testUnseenSyncCriterionGeneralisesToExecutableTest(): void
    {
        // 'sync' is an R3 runnable verb; this string shares no tokens with the
        // green/passes fixtures, proving the rule is not keyed to one phrase.
        $this->assertSame(
            'executable_test',
            $this->classifier->classify('the nightly sync must complete'),
        );
    }

    public function testPortugueseVagueWordIsUntestable(): void
    {
        // 'qualquer' is mirrored from SpecCritic VAGUE_WORDS; long enough to pass
        // the length gate, so only the vague-word branch can produce untestable.
        $this->assertSame(
            'untestable',
            $this->classifier->classify('o sistema aceita qualquer entrada valida'),
        );
    }

    public function testReturnIsAlwaysAClosedSetStringLabel(): void
    {
        $labels = [
            $this->classifier->classify('run phpunit --filter Foo'),
            $this->classifier->classify('latency 10 ms'),
            $this->classifier->classify('suite is green'),
            $this->classifier->classify('the page looks fine'),
            $this->classifier->classify('stuff happens here too'),
            $this->classifier->classify('no'),
            $this->classifier->classify('operator approves the release plan'),
        ];

        $allowed = ['executable_test', 'observable_metric', 'manual_inspection', 'untestable'];

        foreach ($labels as $label) {
            $this->assertIsString($label);
            $this->assertContains($label, $allowed);
        }
    }

    public function testClassificationIsDeterministic(): void
    {
        $criterion = 'visual test_runs green';

        $this->assertSame(
            $this->classifier->classify($criterion),
            $this->classifier->classify($criterion),
        );
    }
}

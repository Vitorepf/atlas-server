<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AcosMax;

use App\Services\Ai\AtlasDecide\DecisionReceiptFailurePatternMiner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * MULTK-08 — receipt failure-pattern miner acceptance (§2410).
 *
 *   - Synthetic pattern of failures ⇒ detected with raw num/den.
 *   - Pattern with n below the floor ⇒ ABSENT from the report (negative case).
 *   - Floor and ceiling are pinned; caller cannot soften them below the pin.
 *   - v1 receipts without basis ⇒ dropped (§2409 anti-mining-v1).
 *   - Verified witness rule ⇒ absent/claimed outcomes never contribute a
 *     failure (§ESP-05 wiring).
 *   - Report-only source flags ⇒ promotes_selection=false, blocker=false.
 */
final class Multk08DecisionReceiptFailurePatternMinerTest extends TestCase
{
    #[Test]
    public function schema_version_is_pinned(): void
    {
        $this->assertSame(
            'atlas.decide.receipt_failure_patterns.v1',
            DecisionReceiptFailurePatternMiner::SCHEMA_VERSION,
        );
    }

    #[Test]
    public function synthetic_pattern_with_high_failure_rate_is_detected(): void
    {
        $miner = new DecisionReceiptFailurePatternMiner;
        [$receipts, $outcomes] = $this->syntheticGroup(
            decisionCount: 12,
            failureCount: 9,
            taskCategory: 'programming',
            basis: 'proven',
            provider: 'atlas:local',
        );

        $report = $miner->mine($receipts, $outcomes);

        $this->assertSame('ok', $report['status']);
        $this->assertCount(1, $report['patterns']);
        $this->assertSame('programming', $report['patterns'][0]['task_category']);
        $this->assertSame(12, $report['patterns'][0]['n']);
        $this->assertSame(9, $report['patterns'][0]['failures']);
        $this->assertEqualsWithDelta(0.75, $report['patterns'][0]['failure_rate'], 0.001);
    }

    #[Test]
    public function pattern_below_floor_is_absent_from_report(): void
    {
        $miner = new DecisionReceiptFailurePatternMiner;
        [$receipts, $outcomes] = $this->syntheticGroup(
            decisionCount: 4,
            failureCount: 3,
            taskCategory: 'programming',
            basis: 'proven',
            provider: 'atlas:local',
        );

        $report = $miner->mine($receipts, $outcomes);

        $this->assertSame('insufficient_signal', $report['status']);
        $this->assertSame([], $report['patterns']);
        $this->assertSame(1, $report['denominators']['suppressed_by_floor']);
    }

    #[Test]
    public function caller_cannot_lower_the_pinned_floor(): void
    {
        $miner = new DecisionReceiptFailurePatternMiner;
        [$receipts, $outcomes] = $this->syntheticGroup(
            decisionCount: 4,
            failureCount: 3,
            taskCategory: 'programming',
            basis: 'proven',
            provider: 'atlas:local',
        );
        $report = $miner->mine($receipts, $outcomes, nMin: 2, failureRateCeiling: 0.0);

        $this->assertSame(
            DecisionReceiptFailurePatternMiner::N_MIN_PINNED,
            $report['thresholds']['n_min'],
        );
        $this->assertSame(
            DecisionReceiptFailurePatternMiner::FAILURE_RATE_CEILING_PINNED,
            $report['thresholds']['failure_rate_ceiling'],
        );
    }

    #[Test]
    public function receipts_without_basis_are_dropped_v1(): void
    {
        $miner = new DecisionReceiptFailurePatternMiner;
        $receipts = [
            ['decision_id' => 'd-1', 'task_category' => 'programming', 'basis' => '', 'provider' => 'atlas:local'],
            ['decision_id' => 'd-2', 'task_category' => 'programming', 'basis' => null, 'provider' => 'atlas:local'],
            ['decision_id' => 'd-3', 'task_category' => 'programming', 'basis' => 'proven', 'provider' => 'atlas:local'],
        ];
        $outcomes = [
            ['decision_id' => 'd-1', 'verified_basis' => 'gates_passed', 'proven_real' => false],
            ['decision_id' => 'd-2', 'verified_basis' => 'gates_passed', 'proven_real' => false],
            ['decision_id' => 'd-3', 'verified_basis' => 'gates_passed', 'proven_real' => false],
        ];
        $report = $miner->mine($receipts, $outcomes);
        $this->assertSame(2, $report['denominators']['dropped_v1_no_basis']);
        $this->assertSame(1, $report['denominators']['receipts_indexed']);
    }

    #[Test]
    public function unverified_witnesses_never_contribute_a_failure(): void
    {
        $miner = new DecisionReceiptFailurePatternMiner;
        $receipts = [];
        $outcomes = [];
        for ($i = 0; $i < 12; $i++) {
            $id = 'd-'.$i;
            $receipts[] = ['decision_id' => $id, 'task_category' => 'programming', 'basis' => 'proven', 'provider' => 'atlas:local'];
            $outcomes[] = ['decision_id' => $id, 'verified_basis' => 'absent', 'proven_real' => false];
        }
        $report = $miner->mine($receipts, $outcomes);
        $this->assertSame(12, $report['denominators']['unverified_outcomes']);
        $this->assertSame(0, $report['denominators']['verified_outcomes']);
        $this->assertSame([], $report['patterns']);
    }

    #[Test]
    public function report_declares_read_only_and_report_only(): void
    {
        $report = (new DecisionReceiptFailurePatternMiner)->mine([], []);
        $this->assertTrue($report['source']['read_only']);
        $this->assertFalse($report['source']['promotes_selection']);
        $this->assertFalse($report['source']['blocker']);
        $this->assertTrue($report['source']['emits_bias_carimbado']);
        $this->assertTrue($report['source']['excludes_v1_no_basis']);
        $this->assertTrue($report['source']['verified_witnesses_only']);
    }

    /**
     * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>}
     */
    private function syntheticGroup(
        int $decisionCount,
        int $failureCount,
        string $taskCategory,
        string $basis,
        string $provider,
    ): array {
        $receipts = [];
        $outcomes = [];
        for ($i = 0; $i < $decisionCount; $i++) {
            $id = 'd-'.$i;
            $receipts[] = [
                'decision_id' => $id,
                'task_category' => $taskCategory,
                'basis' => $basis,
                'provider' => $provider,
            ];
            $outcomes[] = [
                'decision_id' => $id,
                'verified_basis' => 'gates_passed',
                'proven_real' => $i >= $failureCount,
            ];
        }

        return [$receipts, $outcomes];
    }
}

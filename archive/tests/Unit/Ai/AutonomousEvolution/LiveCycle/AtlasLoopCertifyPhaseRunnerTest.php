<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\LiveCycle;

use App\Services\Ai\AutonomousEvolution\LiveCycle\AtlasLoopCertifyPhaseRunner;
use App\Services\Ai\AutonomousEvolution\LiveCycle\CertificationFailedException;
use PHPUnit\Framework\TestCase;

/**
 * Proves the certify-phase runner: a positive certifier verdict (armed+improved) yields certified=true; a
 * negative verdict (any of: not armed, not improved, regressed) NEVER yields certified=true; a regressed
 * verdict throws AND the regression receipt is still appended to the chain before the throw — so the audit
 * trail captures every certify attempt.
 */
final class AtlasLoopCertifyPhaseRunnerTest extends TestCase
{
    /** @return array{0:AtlasLoopCertifyPhaseRunner, 1:\ArrayObject<int,array<string,mixed>>} runner + chain spy */
    private function runner(callable $certifier): array
    {
        $appended = new \ArrayObject;
        $appender = static function (array $receipt) use ($appended): void {
            $appended->append($receipt);
        };
        $runner = new AtlasLoopCertifyPhaseRunner($certifier, $appender);

        return [$runner, $appended];
    }

    public function test_positive_certifier_verdict_yields_certified_true_and_appends_to_chain(): void
    {
        [$runner, $appended] = $this->runner(static fn (): array => [
            'armed' => true, 'improved' => true, 'regressed' => false,
            'frozen_judge_verdict' => 'PASS', 'net_diff_summary' => ['delta' => 0.42],
            'reason' => null,
        ]);

        $receipt = $runner->run(['task_packet_id' => 'pkt-cert-1']);

        $this->assertSame('certify', $receipt['phase']);
        $this->assertSame('held_out_delta_certifier', $receipt['delegate']);
        $this->assertTrue($receipt['certified']);
        $this->assertFalse($receipt['regressed']);
        $this->assertSame(AtlasLoopCertifyPhaseRunner::STATUS_CERTIFIED, $receipt['status']);
        $this->assertSame('PASS', $receipt['frozen_judge_verdict']);
        $this->assertCount(1, $appended, 'receipt was appended exactly once');
        $this->assertSame($receipt, $appended[0]);
    }

    public function test_certifier_not_improved_yields_inconclusive_never_certified(): void
    {
        [$runner, $appended] = $this->runner(static fn (): array => [
            'armed' => true, 'improved' => false, 'regressed' => false,
            'reason' => 'no_delta_observed',
        ]);

        $receipt = $runner->run(['task_packet_id' => 'pkt-inc-1']);

        $this->assertFalse($receipt['certified'], 'pétreo: no positive verdict ⇒ never certified');
        $this->assertSame(AtlasLoopCertifyPhaseRunner::STATUS_INCONCLUSIVE, $receipt['status']);
        $this->assertSame('no_delta_observed', $receipt['reason']);
        $this->assertCount(1, $appended);
    }

    public function test_certifier_not_armed_yields_inconclusive(): void
    {
        [$runner, $appended] = $this->runner(static fn (): array => [
            'armed' => false, 'improved' => false, 'regressed' => false,
            'reason' => 'held_out:metric_non_finite',
        ]);

        $receipt = $runner->run(['task_packet_id' => 'pkt-arm-1']);

        $this->assertFalse($receipt['certified']);
        $this->assertSame(AtlasLoopCertifyPhaseRunner::STATUS_INCONCLUSIVE, $receipt['status']);
        $this->assertCount(1, $appended);
    }

    public function test_regressed_verdict_throws_AND_appends_regression_receipt_first(): void
    {
        [$runner, $appended] = $this->runner(static fn (): array => [
            'armed' => true, 'improved' => false, 'regressed' => true,
            'reason' => 'held_out:regression',
            'net_diff_summary' => ['delta' => -0.15],
        ]);

        try {
            $runner->run(['task_packet_id' => 'pkt-reg-1']);
            $this->fail('expected CertificationFailedException');
        } catch (CertificationFailedException $e) {
            $this->assertSame(AtlasLoopCertifyPhaseRunner::STATUS_REGRESSED, $e->receipt['status']);
            $this->assertTrue($e->receipt['regressed']);
            $this->assertFalse($e->receipt['certified']);
            // Critical: the audit trail captured the regression even though we threw.
            $this->assertCount(1, $appended, 'regression receipt appended BEFORE the throw');
            $this->assertSame(AtlasLoopCertifyPhaseRunner::STATUS_REGRESSED, $appended[0]['status']);
        }
    }

    public function test_certifier_throwing_yields_inconclusive(): void
    {
        [$runner, $appended] = $this->runner(static function (): array {
            throw new \RuntimeException('cert blew up');
        });

        $receipt = $runner->run(['task_packet_id' => 'pkt-throw']);

        $this->assertSame(AtlasLoopCertifyPhaseRunner::STATUS_INCONCLUSIVE, $receipt['status']);
        $this->assertStringContainsString('certifier_threw', (string) $receipt['reason']);
        $this->assertCount(1, $appended);
    }

    public function test_certifier_returning_non_array_yields_inconclusive(): void
    {
        [$runner, $appended] = $this->runner(static fn (): mixed => 'not_an_array');

        $receipt = $runner->run(['task_packet_id' => 'pkt-shape']);

        $this->assertSame(AtlasLoopCertifyPhaseRunner::STATUS_INCONCLUSIVE, $receipt['status']);
        $this->assertCount(1, $appended);
    }

    public function test_receipt_carries_no_score_proxy_keys(): void
    {
        [$runner, ] = $this->runner(static fn (): array => ['armed' => true, 'improved' => true]);
        $receipt = $runner->run(['task_packet_id' => 'pkt-keys']);

        foreach (array_keys($receipt) as $k) {
            $this->assertDoesNotMatchRegularExpression(
                '/score|churn|loc_|lines_added/i',
                $k,
                'receipt must not carry Goodhart-prone field: '.$k,
            );
        }
    }

    public function test_chain_failure_does_not_block_runner_return(): void
    {
        $certifier = static fn (): array => ['armed' => true, 'improved' => true];
        $appender = static function (array $r): void {
            throw new \RuntimeException('chain hiccup');
        };
        $runner = new AtlasLoopCertifyPhaseRunner($certifier, $appender);

        $receipt = $runner->run(['task_packet_id' => 'pkt-chain']);
        $this->assertTrue($receipt['certified'], 'chain failure is swallowed; runner still returns the receipt');
    }
}

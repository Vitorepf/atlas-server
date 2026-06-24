<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\LiveCycle;

use App\Services\Ai\AutonomousEvolution\LiveCycle\AtlasLoopClosePhaseRunner;
use PHPUnit\Framework\TestCase;

/**
 * Proves the close-phase runner: master switch OFF ⇒ byte-identical no-op (no auto-merge invocation), a
 * non-certified prior receipt is REFUSED (auto-merge never invoked), a certified receipt with merged=true
 * propagates merged_sha, and any auto-merge refusal flows through as aborted with the delegate's reason.
 */
final class AtlasLoopClosePhaseRunnerTest extends TestCase
{
    /** @return array{0:AtlasLoopClosePhaseRunner, 1:\ArrayObject<int,array<string,mixed>>, 2:\ArrayObject<int,array<string,mixed>>} runner + chain spy + merge call spy */
    private function runner(callable $autoMerge, bool $masterOn = true): array
    {
        $appended = new \ArrayObject;
        $mergeCalls = new \ArrayObject;
        $delegate = static function (array $payload) use ($autoMerge, $mergeCalls): array {
            $mergeCalls->append($payload);

            return $autoMerge($payload);
        };
        $appender = static function (array $receipt) use ($appended): void {
            $appended->append($receipt);
        };
        $masterSwitch = static fn (): bool => $masterOn;
        $runner = new AtlasLoopClosePhaseRunner($delegate, $appender, $masterSwitch);

        return [$runner, $appended, $mergeCalls];
    }

    private function certifiedReceipt(string $packetId = 'pkt-1', string $base = 'abc123'): array
    {
        return [
            'phase' => 'certify',
            'task_packet_id' => $packetId,
            'status' => 'certified',
            'certified' => true,
            'base_sha' => $base,
        ];
    }

    public function test_master_switch_off_short_circuits_with_skipped_and_zero_merge_calls(): void
    {
        $autoMerge = static fn (): array => ['merged' => true, 'merge_result' => ['merge_sha' => 'xyz']];
        [$runner, $appended, $mergeCalls] = $this->runner($autoMerge, masterOn: false);

        $receipt = $runner->run($this->certifiedReceipt());

        $this->assertSame(AtlasLoopClosePhaseRunner::STATUS_SKIPPED, $receipt['status']);
        $this->assertSame('master_switch_off', $receipt['abort_reason']);
        $this->assertNull($receipt['merged_sha']);
        $this->assertCount(0, $mergeCalls, 'auto-merge delegate is NEVER called when master switch is off');
        $this->assertCount(1, $appended, 'skipped receipt is appended to chain');
    }

    public function test_non_certified_prior_receipt_is_refused_with_aborted_and_zero_merge_calls(): void
    {
        $autoMerge = static fn (): array => ['merged' => true];
        [$runner, , $mergeCalls] = $this->runner($autoMerge);

        $receipt = $runner->run(['task_packet_id' => 'pkt', 'status' => 'regressed']);

        $this->assertSame(AtlasLoopClosePhaseRunner::STATUS_ABORTED, $receipt['status']);
        $this->assertStringContainsString('not_certified', (string) $receipt['abort_reason']);
        $this->assertStringContainsString('regressed', (string) $receipt['abort_reason']);
        $this->assertCount(0, $mergeCalls, 'never merges a non-certified candidate');
    }

    public function test_inconclusive_prior_receipt_also_refused(): void
    {
        [$runner, , $mergeCalls] = $this->runner(static fn (): array => ['merged' => true]);

        $receipt = $runner->run(['task_packet_id' => 'p', 'status' => 'inconclusive']);

        $this->assertSame(AtlasLoopClosePhaseRunner::STATUS_ABORTED, $receipt['status']);
        $this->assertCount(0, $mergeCalls);
    }

    public function test_merge_success_propagates_merge_sha(): void
    {
        $autoMerge = static fn (): array => [
            'merged' => true,
            'merge_result' => ['merge_sha' => 'deadbeef'],
        ];
        [$runner, $appended, $mergeCalls] = $this->runner($autoMerge);

        $receipt = $runner->run($this->certifiedReceipt(base: 'base-1'));

        $this->assertSame(AtlasLoopClosePhaseRunner::STATUS_MERGED, $receipt['status']);
        $this->assertSame('deadbeef', $receipt['merged_sha']);
        $this->assertSame('base-1', $receipt['base_sha']);
        $this->assertNull($receipt['abort_reason']);
        $this->assertCount(1, $mergeCalls);
        $this->assertCount(1, $appended);
    }

    public function test_auto_merge_refusal_flows_through_as_aborted_with_reason(): void
    {
        $autoMerge = static fn (): array => ['merged' => false, 'reason' => 'conflict'];
        [$runner, , $mergeCalls] = $this->runner($autoMerge);

        $receipt = $runner->run($this->certifiedReceipt());

        $this->assertSame(AtlasLoopClosePhaseRunner::STATUS_ABORTED, $receipt['status']);
        $this->assertSame('conflict', $receipt['abort_reason']);
        $this->assertNull($receipt['merged_sha']);
        $this->assertCount(1, $mergeCalls, 'auto-merge was called and refused');
    }

    public function test_auto_merge_throwing_yields_aborted_with_reason(): void
    {
        $autoMerge = static function (): array {
            throw new \RuntimeException('git unavailable');
        };
        [$runner, ] = $this->runner($autoMerge);

        $receipt = $runner->run($this->certifiedReceipt());

        $this->assertSame(AtlasLoopClosePhaseRunner::STATUS_ABORTED, $receipt['status']);
        $this->assertStringContainsString('auto_merge_threw', (string) $receipt['abort_reason']);
    }

    public function test_receipt_carries_the_canonical_shape(): void
    {
        [$runner, ] = $this->runner(static fn (): array => ['merged' => true, 'merge_result' => ['merge_sha' => 'x']]);

        $receipt = $runner->run($this->certifiedReceipt());

        $this->assertSame('close', $receipt['phase']);
        $this->assertSame('auto_merge_service', $receipt['delegate']);
        foreach (['phase', 'delegate', 'task_packet_id', 'base_sha', 'merged_sha', 'status', 'abort_reason'] as $k) {
            $this->assertArrayHasKey($k, $receipt);
        }
    }
}

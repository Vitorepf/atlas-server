<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Core\NativeExecutionReceipt;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class NativeExecutionReceiptTest extends TestCase
{
    public function test_failed_native_receipt_requires_failure_reason(): void
    {
        $payload = $this->validReceipt();
        $payload['status'] = 'environment_failure';
        $payload['failure_reason'] = null;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('rivals_native_receipt_failure_reason_required');
        NativeExecutionReceipt::fromArray($payload);
    }

    public function test_new_native_receipt_names_log_files_and_failure(): void
    {
        $payload = $this->validReceipt();
        $payload['status'] = 'environment_failure';
        $payload['failure_reason'] = 'RewardFileEmptyError: reward file is empty';

        $receipt = NativeExecutionReceipt::fromArray($payload);

        $this->assertSame($payload['failure_reason'], $receipt->data['failure_reason']);
        $this->assertSame('native_execution_receipts/logs/ne_1.stdout.log', $receipt->data['stdout']['path']);
        $this->assertSame('native_execution_receipts/logs/ne_1.stderr.log', $receipt->data['stderr']['path']);
    }

    /** @return array<string, mixed> */
    private function validReceipt(): array
    {
        return [
            'schema_version' => NativeExecutionReceipt::SCHEMA,
            'run_id' => 'run_1',
            'execution_id' => 'ne_1',
            'manifest_hash' => str_repeat('a', 64),
            'command_hash' => str_repeat('b', 64),
            'expected_result_path' => 'external_results/units/ne_1.json',
            'result_sha256' => str_repeat('c', 64),
            'status' => 'success',
            'failure_reason' => null,
            'exit_code' => 0,
            'started_at' => '2026-07-19T10:00:00-03:00',
            'finished_at' => '2026-07-19T10:00:01-03:00',
            'wall_ms' => 1000,
            'cost_usd' => 0.0,
            'stdout' => [
                'present' => true,
                'path' => 'native_execution_receipts/logs/ne_1.stdout.log',
                'sha256' => str_repeat('d', 64),
            ],
            'stderr' => [
                'present' => true,
                'path' => 'native_execution_receipts/logs/ne_1.stderr.log',
                'sha256' => str_repeat('e', 64),
            ],
            'runner' => ['version' => 'test'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Receipt;

use App\Services\Receipt\ReceiptWriter;
use PHPUnit\Framework\TestCase;

final class ReceiptWriterTest extends TestCase
{
    public function test_writes_schema_version_and_hash(): void
    {
        $writer = new ReceiptWriter(clock: fn (string $fmt) => '2026-05-15T00:00:00Z');
        $receipt = $writer->write('ok', ['case_id' => 'demo']);

        $this->assertSame('atlas.receipt.v1', $receipt['schema_version']);
        $this->assertArrayHasKey('payload_hash', $receipt);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $receipt['payload_hash']);
    }

    public function test_same_input_same_hash(): void
    {
        $writer = new ReceiptWriter(clock: fn (string $fmt) => '2026-05-15T00:00:00Z');
        $a = $writer->write('ok', ['case_id' => 'demo', 'detail' => ['note' => 'x']]);
        $b = $writer->write('ok', ['case_id' => 'demo', 'detail' => ['note' => 'x']]);

        $this->assertSame($a['payload_hash'], $b['payload_hash'], 'mesmo payload precisa produzir mesmo hash');
    }

    public function test_different_input_different_hash(): void
    {
        $writer = new ReceiptWriter(clock: fn (string $fmt) => '2026-05-15T00:00:00Z');
        $a = $writer->write('ok', ['case_id' => 'demo-a']);
        $b = $writer->write('ok', ['case_id' => 'demo-b']);

        $this->assertNotSame($a['payload_hash'], $b['payload_hash']);
    }

    public function test_includes_replay_manifest(): void
    {
        $writer = new ReceiptWriter(clock: fn (string $fmt) => '2026-05-15T00:00:00Z');
        $receipt = $writer->write('ok', ['case_id' => 'demo']);

        $this->assertArrayHasKey('replay_manifest', $receipt);
        $this->assertSame('atlas.receipt.v1', $receipt['replay_manifest']['schema_version']);
        $this->assertSame($receipt['payload_hash'], $receipt['replay_manifest']['payload_hash']);
    }
}

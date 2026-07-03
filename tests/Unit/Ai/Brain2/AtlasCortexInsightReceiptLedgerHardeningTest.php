<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights\AtlasCortexInsightReceiptLedger;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasCortexInsightReceiptLedger does not throw when an insight receipt
 * timestamp read from the ledger file is an invalid date string.
 */
final class AtlasCortexInsightReceiptLedgerHardeningTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/atlas-insight-ledger-'.mt_rand();
        mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.'/'.$file;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function normalizeTimestamp(AtlasCortexInsightReceiptLedger $ledger, ?string $value): string
    {
        $reflection = new \ReflectionClass($ledger);
        $method = $reflection->getMethod('normalizeTimestamp');
        return $method->invoke($ledger, $value);
    }

    public function test_normalize_timestamp_with_valid_iso_string(): void
    {
        $ledger = new AtlasCortexInsightReceiptLedger($this->tmpDir);

        $result = $this->normalizeTimestamp($ledger, '2026-07-03T12:00:00+00:00');

        $this->assertStringContainsString('2026-07-03', $result);
    }

    public function test_normalize_timestamp_with_invalid_date_does_not_throw(): void
    {
        $ledger = new AtlasCortexInsightReceiptLedger($this->tmpDir);

        $result = $this->normalizeTimestamp($ledger, 'not-a-date');

        // Should not throw — returns current time as fallback
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z/', $result);
    }

    public function test_normalize_timestamp_with_null_returns_now(): void
    {
        $ledger = new AtlasCortexInsightReceiptLedger($this->tmpDir);

        $result = $this->normalizeTimestamp($ledger, null);

        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z/', $result);
    }

    public function test_normalize_timestamp_with_garbage_does_not_throw(): void
    {
        $ledger = new AtlasCortexInsightReceiptLedger($this->tmpDir);

        $result = $this->normalizeTimestamp($ledger, '???');

        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z/', $result);
    }
}

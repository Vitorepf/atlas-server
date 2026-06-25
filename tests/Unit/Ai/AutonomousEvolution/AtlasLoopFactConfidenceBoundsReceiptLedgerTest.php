<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\FactConfidence\AtlasLoopFactBoundsMissingException;
use App\Services\Ai\AutonomousEvolution\FactConfidence\AtlasLoopFactConfidenceBoundsReceiptLedger;
use App\Services\Ai\AutonomousEvolution\FactConfidence\AtlasLoopFactConfidenceBoundsValidator;
use App\Services\Ai\AutonomousEvolution\FactConfidence\AtlasLoopFactConfidenceBoundsValidatorLedgerDecorator;
use Tests\TestCase;

class AtlasLoopFactConfidenceBoundsReceiptLedgerTest extends TestCase
{
    private string $ledgerPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas-fc-ledger-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    private function inner(bool $enforce): AtlasLoopFactConfidenceBoundsValidator
    {
        return new AtlasLoopFactConfidenceBoundsValidator(
            logger: static function (string $msg, array $ctx): void {},
            configReader: fn (): array => [
                'enforce' => $enforce,
                'critical_paths' => AtlasLoopFactConfidenceBoundsValidator::DEFAULT_CRITICAL_PATHS,
            ],
        );
    }

    public function test_accepted_fact_writes_one_jsonl_line_with_documented_schema(): void
    {
        $ledger = new AtlasLoopFactConfidenceBoundsReceiptLedger($this->ledgerPath, enabled: true);
        $decorator = new AtlasLoopFactConfidenceBoundsValidatorLedgerDecorator(
            $this->inner(enforce: true),
            $ledger,
            clock: fn (): string => '2026-06-25T00:00:00Z',
            enforceFlagReader: fn (): bool => true,
        );

        $decorator->validate('loop.comprehend.snapshot_writer', [
            'key' => 'comprehend.snapshot.health',
            'value' => true,
            'sample_size' => 42,
            'source_count' => 3,
            'confidence_bounds' => ['lower' => 0.9, 'upper' => 0.97],
        ]);

        self::assertFileExists($this->ledgerPath);
        $lines = file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertCount(1, $lines);
        $row = json_decode($lines[0], true);

        foreach (['ts', 'fact_key', 'value', 'sample_size', 'source_count', 'caller_path', 'enforce_flag', 'outcome'] as $field) {
            self::assertArrayHasKey($field, $row);
        }
        self::assertSame('accepted', $row['outcome']);
        self::assertSame('loop.comprehend.snapshot_writer', $row['caller_path']);
        self::assertSame(42, $row['sample_size']);
        self::assertSame(3, $row['source_count']);
        self::assertTrue($row['enforce_flag']);
    }

    public function test_two_sequential_calls_produce_two_non_interleaved_lines(): void
    {
        $ledger = new AtlasLoopFactConfidenceBoundsReceiptLedger($this->ledgerPath, enabled: true);
        $decorator = new AtlasLoopFactConfidenceBoundsValidatorLedgerDecorator(
            $this->inner(enforce: true),
            $ledger,
            clock: fn (): string => '2026-06-25T00:00:00Z',
            enforceFlagReader: fn (): bool => true,
        );

        $fact = ['confidence_bounds' => ['lower' => 0.1, 'upper' => 0.2]];
        $decorator->validate('loop.comprehend.snapshot_writer', $fact);
        $decorator->validate('loop.next_work_decider', $fact);

        $lines = file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertCount(2, $lines);
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            self::assertIsArray($decoded);
            self::assertArrayHasKey('outcome', $decoded);
        }
    }

    public function test_ledger_disabled_creates_no_file_and_no_io(): void
    {
        $ledger = new AtlasLoopFactConfidenceBoundsReceiptLedger($this->ledgerPath, enabled: false);
        $decorator = new AtlasLoopFactConfidenceBoundsValidatorLedgerDecorator(
            $this->inner(enforce: false),
            $ledger,
            clock: fn (): string => '2026-06-25T00:00:00Z',
            enforceFlagReader: fn (): bool => false,
        );

        $decorator->validate('loop.comprehend.snapshot_writer', true);
        self::assertFalse(file_exists($this->ledgerPath));
    }

    public function test_rejected_fact_records_outcome_rejected_and_rethrows(): void
    {
        $ledger = new AtlasLoopFactConfidenceBoundsReceiptLedger($this->ledgerPath, enabled: true);
        $decorator = new AtlasLoopFactConfidenceBoundsValidatorLedgerDecorator(
            $this->inner(enforce: true),
            $ledger,
            clock: fn (): string => '2026-06-25T00:00:00Z',
            enforceFlagReader: fn (): bool => true,
        );

        try {
            $decorator->validate('loop.comprehend.snapshot_writer', true);
            self::fail('expected exception');
        } catch (AtlasLoopFactBoundsMissingException) {
            // expected
        }
        $lines = file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertCount(1, $lines);
        $row = json_decode($lines[0], true);
        self::assertSame('rejected', $row['outcome']);
    }

    public function test_legacy_fact_without_envelope_on_non_critical_path_records_legacy_outcome(): void
    {
        $ledger = new AtlasLoopFactConfidenceBoundsReceiptLedger($this->ledgerPath, enabled: true);
        $decorator = new AtlasLoopFactConfidenceBoundsValidatorLedgerDecorator(
            $this->inner(enforce: true),
            $ledger,
            clock: fn (): string => '2026-06-25T00:00:00Z',
            enforceFlagReader: fn (): bool => true,
        );

        $decorator->validate('loop.observability.minor', ['value' => 1]);
        $lines = file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertCount(1, $lines);
        $row = json_decode($lines[0], true);
        self::assertSame('legacy', $row['outcome']);
    }
}

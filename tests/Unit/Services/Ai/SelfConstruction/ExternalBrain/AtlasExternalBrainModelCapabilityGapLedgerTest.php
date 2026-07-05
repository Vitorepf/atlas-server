<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainModelCapabilityGapLedger;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainModelCapabilityGapLedgerTest extends TestCase
{
    private AtlasExternalBrainModelCapabilityGapLedger $ledger;

    protected function setUp(): void
    {
        $this->ledger = new AtlasExternalBrainModelCapabilityGapLedger;
    }

    public function test_gaps_keyed_by_capability(): void
    {
        $result = $this->ledger->ledger([
            ['capability' => 'php_refactor', 'evidence' => 'failed_test', 'task_family' => 'refactor'],
            ['capability' => 'php_refactor', 'evidence' => 'failed_test', 'task_family' => 'refactor'],
            ['capability' => 'sql_design', 'evidence' => 'schema_error', 'task_family' => 'migration'],
        ]);

        $this->assertSame(2, $result['gap_count']);
        $this->assertContains('php_refactor', $result['capabilities']);
        $this->assertContains('sql_design', $result['capabilities']);
    }

    public function test_gaps_keyed_by_evidence(): void
    {
        $result = $this->ledger->ledger([
            ['capability' => 'php_refactor', 'evidence' => 'failed_test', 'task_family' => 'refactor'],
            ['capability' => 'php_refactor', 'evidence' => 'timeout', 'task_family' => 'refactor'],
        ]);

        $this->assertSame(2, $result['gap_count']);
    }

    public function test_gaps_keyed_by_task_family(): void
    {
        $result = $this->ledger->ledger([
            ['capability' => 'php_refactor', 'evidence' => 'failed_test', 'task_family' => 'refactor'],
            ['capability' => 'php_refactor', 'evidence' => 'failed_test', 'task_family' => 'migration'],
        ]);

        $this->assertSame(2, $result['gap_count']);
    }

    public function test_gaps_include_freshness(): void
    {
        $result = $this->ledger->ledger([
            ['capability' => 'php_refactor', 'evidence' => 'failed_test', 'task_family' => 'refactor', 'freshness' => 'recent'],
        ]);

        $this->assertSame('recent', $result['gaps'][0]['freshness']);
    }

    public function test_recurring_gaps_increment_occurrence_count(): void
    {
        $result = $this->ledger->ledger([
            ['capability' => 'php_refactor', 'evidence' => 'failed_test', 'task_family' => 'refactor'],
            ['capability' => 'php_refactor', 'evidence' => 'failed_test', 'task_family' => 'refactor'],
            ['capability' => 'php_refactor', 'evidence' => 'failed_test', 'task_family' => 'refactor'],
        ]);

        $this->assertSame(1, $result['gap_count']);
        $this->assertSame(3, $result['gaps'][0]['occurrence_count']);
    }

    public function test_schema_present(): void
    {
        $result = $this->ledger->ledger([]);
        $this->assertSame(AtlasExternalBrainModelCapabilityGapLedger::SCHEMA, $result['schema']);
    }
}

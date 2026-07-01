<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionDeadOrganRetirementLedger;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionDeadOrganRetirementLedgerTest extends TestCase
{
    private function ledger(): AtlasSelfConstructionDeadOrganRetirementLedger
    {
        return new AtlasSelfConstructionDeadOrganRetirementLedger;
    }

    public function test_proxy_only_organ_is_marked_retire_or_convert(): void
    {
        $result = $this->ledger()->classify([
            'organs' => [
                ['organ_id' => 'proxy-organ', 'proxy_only' => true, 'consumer_count' => 5],
            ],
        ]);

        $row = $result['organs'][0];
        $this->assertSame(AtlasSelfConstructionDeadOrganRetirementLedger::VERDICT_RETIRE_OR_CONVERT, $row['verdict']);
        $this->assertContains('proxy_only_no_real_capability', $row['retire_reasons']);
        $this->assertSame(1, $result['retire_count']);
    }

    public function test_active_organ_with_consumers_and_proof_is_retained_with_reasons(): void
    {
        $result = $this->ledger()->classify([
            'organs' => [
                ['organ_id' => 'active-organ', 'consumer_count' => 3, 'has_proof_receipt' => true],
            ],
        ]);

        $row = $result['organs'][0];
        $this->assertSame(AtlasSelfConstructionDeadOrganRetirementLedger::VERDICT_RETAIN, $row['verdict']);
        $this->assertContains('has_consumers:3', $row['retain_reasons']);
        $this->assertContains('has_proof_receipt', $row['retain_reasons']);
        $this->assertSame(1, $result['retain_count']);
    }

    public function test_unused_organ_without_proof_is_retired(): void
    {
        $result = $this->ledger()->classify([
            'organs' => [
                ['organ_id' => 'unused-organ'],
            ],
        ]);

        $row = $result['organs'][0];
        $this->assertSame(AtlasSelfConstructionDeadOrganRetirementLedger::VERDICT_RETIRE_OR_CONVERT, $row['verdict']);
        $this->assertContains('unused_no_consumers_no_proof', $row['retire_reasons']);
    }

    public function test_organ_with_zero_consumers_but_proof_receipt_is_retained(): void
    {
        $result = $this->ledger()->classify([
            'organs' => [
                ['organ_id' => 'dormant-verified', 'consumer_count' => 0, 'has_proof_receipt' => true],
            ],
        ]);

        $row = $result['organs'][0];
        $this->assertSame(AtlasSelfConstructionDeadOrganRetirementLedger::VERDICT_RETAIN, $row['verdict']);
    }

    public function test_superseded_organ_is_retired_with_named_successor(): void
    {
        $result = $this->ledger()->classify([
            'organs' => [
                ['organ_id' => 'old-organ', 'consumer_count' => 2, 'superseded_by' => 'NewOrgan'],
            ],
        ]);

        $row = $result['organs'][0];
        $this->assertSame(AtlasSelfConstructionDeadOrganRetirementLedger::VERDICT_RETIRE_OR_CONVERT, $row['verdict']);
        $this->assertContains('superseded_by:NewOrgan', $row['retire_reasons']);
    }

    public function test_empty_organs_returns_zero_counts(): void
    {
        $result = $this->ledger()->classify(['organs' => []]);

        $this->assertSame(0, $result['retire_count']);
        $this->assertSame(0, $result['retain_count']);
        $this->assertSame([], $result['organs']);
    }
}

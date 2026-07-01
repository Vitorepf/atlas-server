<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCapabilityDebtLedger;
use Tests\TestCase;

final class AtlasExternalBrainCapabilityDebtLedgerTest extends TestCase
{
    public function test_duplicate_dimension_records_keep_highest_priority_and_list_rejected_ids(): void
    {
        $result = (new AtlasExternalBrainCapabilityDebtLedger)->assess([
            'debt_records' => [
                ['debt_id' => 'low', 'capability_dimension' => 'weak_scoring', 'leverage' => 1, 'risk' => 1],
                ['debt_id' => 'high', 'capability_dimension' => 'weak_scoring', 'leverage' => 5, 'risk' => 5],
            ],
        ]);

        $this->assertCount(1, $result['ledger_entries']);
        $this->assertSame('high', $result['ledger_entries'][0]['debt_id']);
        $this->assertContains('low', $result['duplicate_rejected']);
    }

    public function test_authored_spec_true_without_resolution_evidence_remains_unresolved(): void
    {
        $result = (new AtlasExternalBrainCapabilityDebtLedger)->assess([
            'debt_records' => [
                [
                    'debt_id' => 'd1',
                    'capability_dimension' => 'missing_evidence_intake',
                    'leverage' => 2,
                    'risk' => 2,
                    'authored_spec' => true,
                ],
            ],
        ]);

        $entry = $result['ledger_entries'][0];
        $this->assertSame('unresolved', $entry['status']);
        $this->assertTrue($entry['authored_spec_only']);
    }

    public function test_unresolved_high_priority_debt_reflects_across_all_summary_fields(): void
    {
        $result = (new AtlasExternalBrainCapabilityDebtLedger)->assess([
            'debt_records' => [
                ['debt_id' => 'd1', 'capability_dimension' => 'weak_scoring', 'leverage' => 5, 'risk' => 5],
                [
                    'debt_id' => 'd2',
                    'capability_dimension' => 'stale_task_family',
                    'leverage' => 1,
                    'risk' => 1,
                    'resolution_evidence' => 'proof',
                    'status' => 'resolved',
                ],
            ],
        ]);

        $this->assertTrue($result['maturity_blocked']);
        $this->assertSame(1, $result['unresolved_count']);
        $this->assertSame('d1', $result['next_batch_focus']['debt_id']);
        $this->assertSame('unresolved', $result['dimension_summary']['weak_scoring']);
        $this->assertSame('resolved', $result['dimension_summary']['stale_task_family']);
    }

    public function test_rank_by_unblock_value_groups_debt_by_capability_area(): void
    {
        $result = (new AtlasExternalBrainCapabilityDebtLedger)->rankByUnblockValue([
            'debt_records' => [
                ['debt_id' => 'a', 'blocked_capability' => 'wiring', 'severity' => 'critical', 'blocked_downstream_circuits' => ['x', 'y']],
                ['debt_id' => 'b', 'blocked_capability' => 'wiring', 'severity' => 'low'],
            ],
        ]);

        $this->assertSame('a', $result['top_priority_debt_id']);
        $this->assertSame(['a', 'b'], $result['debt_by_capability_area']['wiring']);
    }
}

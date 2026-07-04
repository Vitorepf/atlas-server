<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\Adapters\ReleaseDecisionReceiptLedgerAdapter;
use App\Services\Ai\EngineeringKernel\ReceiptLedger;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorAdmissionPolicy;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorReleaseDecisionLedger;
use Tests\TestCase;

final class ReleaseDecisionReceiptLedgerAdapterTest extends TestCase
{
    private string $ledgerPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas-ek-receipt-ledger-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'task_packet_id' => 'pkt-1',
            'candidate_hash' => 'cand-hash-1',
            'decision' => AtlasMergeGovernorAdmissionPolicy::DECISION_ADMITTED,
            'reasons' => [],
            'risk_level' => 'low',
            'verification_hash' => 'ver-hash-1',
            'rollback_hash' => 'rollback-hash-1',
            'changed_files_hash' => 'files-hash-1',
            'project_lane' => ['project_id' => 'proj-1'],
            'decided_at' => '2026-07-01T00:00:00+00:00',
            // required by the hardened ledger contract (codex-meta-proof-merge-governor-...-evidence)
            'evidence_refs' => ['verification:ver-hash-1'],
            'rollback_posture' => 'auto_revert_on_regression',
        ], $overrides);
    }

    public function test_adapter_implements_receipt_ledger_interface(): void
    {
        $adapter = new ReleaseDecisionReceiptLedgerAdapter(new AtlasMergeGovernorReleaseDecisionLedger($this->ledgerPath));

        $this->assertInstanceOf(ReceiptLedger::class, $adapter);
    }

    public function test_append_through_adapter_lands_the_same_record_the_underlying_ledger_writes_directly(): void
    {
        $ledger = new AtlasMergeGovernorReleaseDecisionLedger($this->ledgerPath);
        $adapter = new ReleaseDecisionReceiptLedgerAdapter($ledger);

        $adapterResult = $adapter->append($this->payload());
        $directRows = $ledger->all();

        $this->assertSame('ok', $adapterResult['status']);
        $this->assertCount(1, $directRows);
        $this->assertSame($adapterResult['row'], $directRows[0]);
    }

    public function test_replay_through_adapter_returns_what_the_underlying_ledger_replays(): void
    {
        $ledger = new AtlasMergeGovernorReleaseDecisionLedger($this->ledgerPath);
        $adapter = new ReleaseDecisionReceiptLedgerAdapter($ledger);

        $adapter->append($this->payload());

        $this->assertSame($ledger->replay(), $adapter->replay());
    }

    public function test_replay_reports_valid_true_via_adapter_after_clean_append(): void
    {
        $ledger = new AtlasMergeGovernorReleaseDecisionLedger($this->ledgerPath);
        $adapter = new ReleaseDecisionReceiptLedgerAdapter($ledger);

        $adapter->append($this->payload());
        $replay = $adapter->replay();

        $this->assertTrue($replay['valid']);
        $this->assertSame(1, $replay['entry_count']);
    }
}

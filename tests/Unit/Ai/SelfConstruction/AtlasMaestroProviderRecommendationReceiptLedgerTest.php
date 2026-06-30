<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\ProviderLearning\AtlasMaestroProviderRecommendationReceiptLedger;
use Tests\TestCase;

class AtlasMaestroProviderRecommendationReceiptLedgerTest extends TestCase
{
    private string $ledgerPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas-maestro-provider-rec-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    private function rec(array $override = []): array
    {
        return array_replace([
            'task_class' => 'refactor',
            'recommended_provider' => 'atlas_native',
            'sample_size' => 20,
            'success_rate' => 0.85,
            'give_back_rate' => 0.05,
            'tied_with' => [],
            'tie_reason' => '',
            'requested_at' => '2026-06-25T00:00:00Z',
            'requested_by' => 'maestro',
        ], $override);
    }

    public function test_recording_same_recommendation_twice_writes_only_one_line(): void
    {
        $ledger = new AtlasMaestroProviderRecommendationReceiptLedger($this->ledgerPath);

        $ledger->record($this->rec());
        $ledger->record($this->rec());

        $lines = file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertCount(1, $lines);
    }

    public function test_receipt_carries_every_required_field(): void
    {
        $ledger = new AtlasMaestroProviderRecommendationReceiptLedger($this->ledgerPath);
        $r = $ledger->record($this->rec(['tied_with' => ['p1', 'p2'], 'tie_reason' => 'within_margin']));

        foreach ([
            'receipt_id', 'task_class', 'recommended_provider', 'sample_size', 'success_rate',
            'give_back_rate', 'tied_with', 'tie_reason', 'requested_at', 'requested_by',
        ] as $field) {
            self::assertArrayHasKey($field, $r, "missing field {$field}");
        }
        self::assertSame(['p1', 'p2'], $r['tied_with']);
        self::assertSame('within_margin', $r['tie_reason']);
    }

    public function test_receipts_for_class_returns_only_that_class_ordered_by_requested_at(): void
    {
        $ledger = new AtlasMaestroProviderRecommendationReceiptLedger($this->ledgerPath);
        $ledger->record($this->rec(['task_class' => 'refactor', 'requested_at' => '2026-06-25T00:00:02Z']));
        $ledger->record($this->rec(['task_class' => 'refactor', 'requested_at' => '2026-06-25T00:00:01Z']));
        $ledger->record($this->rec(['task_class' => 'docs', 'requested_at' => '2026-06-25T00:00:03Z']));

        $rows = $ledger->receiptsForClass('refactor');
        self::assertCount(2, $rows);
        self::assertSame('2026-06-25T00:00:01Z', $rows[0]['requested_at']);
        self::assertSame('2026-06-25T00:00:02Z', $rows[1]['requested_at']);
    }

    public function test_unknown_class_returns_empty_array_without_throwing(): void
    {
        $ledger = new AtlasMaestroProviderRecommendationReceiptLedger($this->ledgerPath);
        $rows = $ledger->receiptsForClass('never-seen-class');

        self::assertSame([], $rows);
    }

    public function test_no_op_does_not_grow_the_file(): void
    {
        $ledger = new AtlasMaestroProviderRecommendationReceiptLedger($this->ledgerPath);
        $ledger->record($this->rec());
        $bytes = file_get_contents($this->ledgerPath);

        // Re-record the same receipt — file MUST be byte-identical.
        $ledger->record($this->rec());
        self::assertSame($bytes, file_get_contents($this->ledgerPath));
    }

    public function test_replay_yields_byte_identical_file(): void
    {
        // First run.
        $ledger = new AtlasMaestroProviderRecommendationReceiptLedger($this->ledgerPath);
        $ledger->record($this->rec(['task_class' => 'a', 'requested_at' => '2026-06-25T00:00:01Z']));
        $ledger->record($this->rec(['task_class' => 'b', 'requested_at' => '2026-06-25T00:00:02Z']));
        $bytes = file_get_contents($this->ledgerPath);

        // Wipe and replay in the same order — output must match.
        @unlink($this->ledgerPath);
        $ledger2 = new AtlasMaestroProviderRecommendationReceiptLedger($this->ledgerPath);
        $ledger2->record($this->rec(['task_class' => 'a', 'requested_at' => '2026-06-25T00:00:01Z']));
        $ledger2->record($this->rec(['task_class' => 'b', 'requested_at' => '2026-06-25T00:00:02Z']));

        self::assertSame($bytes, file_get_contents($this->ledgerPath));
    }

    public function test_first_receipt_prev_chain_hash_is_genesis(): void
    {
        $ledger = new AtlasMaestroProviderRecommendationReceiptLedger($this->ledgerPath);
        $r = $ledger->record($this->rec(['reason' => 'highest_success_rate']));

        self::assertSame('genesis', $r['prev_chain_hash']);
        self::assertSame('highest_success_rate', $r['reason']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $r['fact_hash']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $r['chain_hash']);
    }

    public function test_valid_chain_verifies_correctly_for_multiple_receipts(): void
    {
        $ledger = new AtlasMaestroProviderRecommendationReceiptLedger($this->ledgerPath);
        $ledger->record($this->rec(['requested_at' => '2026-06-25T00:00:01Z', 'reason' => 'highest_success_rate']));
        $ledger->record($this->rec(['task_class' => 'coverage', 'recommended_provider' => 'codex', 'requested_at' => '2026-06-25T00:00:02Z', 'reason' => 'tied_within_margin']));
        $ledger->record($this->rec(['task_class' => 'docs', 'requested_at' => '2026-06-25T00:00:03Z', 'reason' => 'minimum_sample_threshold']));

        $result = $ledger->verifyChain();

        self::assertTrue($result['valid'], implode(', ', $result['violations']));
        self::assertSame(3, $result['chain_length']);
        self::assertSame([], $result['violations']);
    }

    public function test_tampered_provider_in_stored_receipt_invalidates_chain(): void
    {
        $ledger = new AtlasMaestroProviderRecommendationReceiptLedger($this->ledgerPath);
        $ledger->record($this->rec(['reason' => 'highest_success_rate']));

        // Read the file, tamper the provider field, write back.
        $line = trim((string) file_get_contents($this->ledgerPath));
        $row = json_decode($line, true);
        $row['recommended_provider'] = 'tampered_provider'; // fact_hash and chain_hash now stale
        file_put_contents($this->ledgerPath, json_encode($row)."\n");

        $result = $ledger->verifyChain();

        self::assertFalse($result['valid']);
        self::assertContains('fact_hash_mismatch:position_0', $result['violations']);
    }

    public function test_ledger_has_no_marketing_aaeos_forge_dependency(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/Maestro/ProviderLearning/AtlasMaestroProviderRecommendationReceiptLedger.php'));
        foreach (['MarketingDomain', 'Aaeos', 'Forge\\', 'use App\\Forge'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $src);
        }
    }
}

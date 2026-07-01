<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\ProviderLearning;

use App\Services\Ai\SelfConstruction\Maestro\ProviderLearning\AtlasMaestroProviderRecommendationReceiptLedger;
use Tests\TestCase;

final class AtlasMaestroProviderRecommendationReceiptLedgerTest extends TestCase
{
    private string $ledgerPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas-maestro-provider-rec-learning-'.bin2hex(random_bytes(6)).'.jsonl';
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

    // ── AC: receiptsForClass returns only matching task class receipts ─────────

    public function test_receipts_for_class_returns_only_matching_task_class_receipts(): void
    {
        $ledger = new AtlasMaestroProviderRecommendationReceiptLedger($this->ledgerPath);
        $ledger->record($this->rec(['task_class' => 'refactor', 'requested_at' => '2026-06-25T00:00:01Z']));
        $ledger->record($this->rec(['task_class' => 'docs', 'requested_at' => '2026-06-25T00:00:02Z']));
        $ledger->record($this->rec(['task_class' => 'refactor', 'requested_at' => '2026-06-25T00:00:03Z']));

        $rows = $ledger->receiptsForClass('refactor');

        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame('refactor', $row['task_class']);
        }
    }

    // ── AC: recommendation receipts can include outcome and quality_score for learning ──

    public function test_receipt_includes_outcome_and_quality_score_when_provided(): void
    {
        $ledger = new AtlasMaestroProviderRecommendationReceiptLedger($this->ledgerPath);
        $r = $ledger->record($this->rec(['outcome' => 'success', 'quality_score' => 0.92]));

        self::assertSame('success', $r['outcome']);
        self::assertSame(0.92, $r['quality_score']);
    }

    public function test_receipt_defaults_outcome_and_quality_score_to_null_when_absent(): void
    {
        $ledger = new AtlasMaestroProviderRecommendationReceiptLedger($this->ledgerPath);
        $r = $ledger->record($this->rec());

        self::assertArrayHasKey('outcome', $r);
        self::assertArrayHasKey('quality_score', $r);
        self::assertNull($r['outcome']);
        self::assertNull($r['quality_score']);
    }

    public function test_outcome_and_quality_score_are_persisted_and_readable_via_receipts_for_class(): void
    {
        $ledger = new AtlasMaestroProviderRecommendationReceiptLedger($this->ledgerPath);
        $ledger->record($this->rec(['task_class' => 'coverage', 'outcome' => 'give_back', 'quality_score' => 0.4]));

        $rows = $ledger->receiptsForClass('coverage');

        self::assertSame('give_back', $rows[0]['outcome']);
        self::assertSame(0.4, $rows[0]['quality_score']);
    }

    public function test_learning_fields_do_not_affect_the_integrity_chain(): void
    {
        // Two receipts differing ONLY by outcome/quality_score must chain identically to the
        // hash-relevant facts (task_class|provider|sample_size|success_rate|reason) — outcome
        // is recorded once the result is known, and must never retroactively break verifyChain.
        $ledger = new AtlasMaestroProviderRecommendationReceiptLedger($this->ledgerPath);
        $r = $ledger->record($this->rec(['reason' => 'highest_success_rate', 'outcome' => 'success', 'quality_score' => 0.9]));

        $result = $ledger->verifyChain();

        self::assertTrue($result['valid'], implode(', ', $result['violations']));
    }

    // ── AC: verifyChain detects tampered recommendation records ─────────────────

    public function test_verify_chain_detects_tampered_recommendation_record(): void
    {
        $ledger = new AtlasMaestroProviderRecommendationReceiptLedger($this->ledgerPath);
        $ledger->record($this->rec(['reason' => 'highest_success_rate']));

        $line = trim((string) file_get_contents($this->ledgerPath));
        $row = json_decode($line, true);
        $row['recommended_provider'] = 'tampered_provider';
        file_put_contents($this->ledgerPath, json_encode($row)."\n");

        $result = $ledger->verifyChain();

        self::assertFalse($result['valid']);
        self::assertContains('fact_hash_mismatch:position_0', $result['violations']);
    }

    public function test_verify_chain_valid_for_untampered_multi_receipt_ledger(): void
    {
        $ledger = new AtlasMaestroProviderRecommendationReceiptLedger($this->ledgerPath);
        $ledger->record($this->rec(['requested_at' => '2026-06-25T00:00:01Z']));
        $ledger->record($this->rec(['task_class' => 'docs', 'requested_at' => '2026-06-25T00:00:02Z']));

        $result = $ledger->verifyChain();

        self::assertTrue($result['valid']);
        self::assertSame(2, $result['chain_length']);
    }
}

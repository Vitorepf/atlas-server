<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Autonomy;

use App\Services\Ai\SelfConstruction\Autonomy\AtlasSelfConstructionAutonomyDecisionReceiptLedger;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionAutonomyDecisionReceiptLedgerTest extends TestCase
{
    private function ledger(): AtlasSelfConstructionAutonomyDecisionReceiptLedger
    {
        return new AtlasSelfConstructionAutonomyDecisionReceiptLedger;
    }

    private function goFacts(array $overrides = []): array
    {
        return array_merge([
            'decision'   => 'go',
            'reasons'    => ['all_checks_passed'],
            'input_facts' => ['score' => 0.9],
            'context_id' => 'ctx-1',
            'authority'  => 'autonomous',
            'sealed_at'  => '2026-06-30T00:00:00Z',
        ], $overrides);
    }

    // ── Output shape ──────────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->ledger()->record([]);
        $this->assertSame(AtlasSelfConstructionAutonomyDecisionReceiptLedger::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('receipt_hash',  $r);
        $this->assertArrayHasKey('sealed_at',     $r);
        $this->assertArrayHasKey('decision',      $r);
        $this->assertArrayHasKey('reasons',       $r);
        $this->assertArrayHasKey('input_summary', $r);
        $this->assertArrayHasKey('provenance',    $r);
        $this->assertArrayHasKey('is_valid',      $r);
    }

    // ── AC2: receipt_hash is sha256 hex ───────────────────────────────────────

    public function test_receipt_hash_is_64_char_hex(): void
    {
        $r = $this->ledger()->record($this->goFacts());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $r['receipt_hash']);
    }

    // ── AC2: decision and reasons echoed ─────────────────────────────────────

    public function test_decision_and_reasons_echoed_in_receipt(): void
    {
        $r = $this->ledger()->record($this->goFacts());
        $this->assertSame('go', $r['decision']);
        $this->assertContains('all_checks_passed', $r['reasons']);
    }

    // ── AC2: is_valid ─────────────────────────────────────────────────────────

    public function test_go_decision_with_reasons_is_valid(): void
    {
        $r = $this->ledger()->record($this->goFacts());
        $this->assertTrue($r['is_valid']);
    }

    public function test_stop_decision_with_reasons_is_valid(): void
    {
        $r = $this->ledger()->record($this->goFacts(['decision' => 'stop', 'reasons' => ['gate_failed']]));
        $this->assertTrue($r['is_valid']);
    }

    public function test_unknown_decision_is_invalid(): void
    {
        $r = $this->ledger()->record($this->goFacts(['decision' => 'maybe']));
        $this->assertFalse($r['is_valid']);
    }

    public function test_empty_reasons_is_invalid(): void
    {
        $r = $this->ledger()->record($this->goFacts(['reasons' => []]));
        $this->assertFalse($r['is_valid']);
    }

    // ── AC2: sealed_at uses provided value ────────────────────────────────────

    public function test_sealed_at_uses_provided_value(): void
    {
        $r = $this->ledger()->record($this->goFacts(['sealed_at' => '2026-06-30T12:00:00Z']));
        $this->assertSame('2026-06-30T12:00:00Z', $r['sealed_at']);
    }

    public function test_sealed_at_derived_from_hash_when_absent(): void
    {
        $facts = $this->goFacts();
        unset($facts['sealed_at']);
        $r = $this->ledger()->record($facts);
        $this->assertSame(16, strlen($r['sealed_at']));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $r['sealed_at']);
    }

    // ── AC3: key order does not affect receipt_hash ───────────────────────────

    public function test_same_input_different_key_order_produces_same_hash(): void
    {
        $facts1 = ['input_facts' => ['b' => 2, 'a' => 1]];
        $facts2 = ['input_facts' => ['a' => 1, 'b' => 2]];

        $r1 = $this->ledger()->record(array_merge($this->goFacts(), $facts1));
        $r2 = $this->ledger()->record(array_merge($this->goFacts(), $facts2));

        $this->assertSame($r1['receipt_hash'], $r2['receipt_hash']);
    }

    // ── AC2: provenance fields ────────────────────────────────────────────────

    public function test_provenance_contains_context_and_authority(): void
    {
        $r = $this->ledger()->record($this->goFacts());
        $p = $r['provenance'];
        $this->assertSame('ctx-1',      $p['context_id']);
        $this->assertSame('autonomous', $p['authority']);
        $this->assertSame(1,            $p['input_key_count']);
    }

    // ── AC4: two records with same input produce same receipt ─────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = $this->goFacts();
        $a = $this->ledger()->record($facts);
        $b = $this->ledger()->record($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }
}

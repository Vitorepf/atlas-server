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

    // ── AC2: decision class, evidence refs, actor, autonomy level, risk, rollback path ──

    public function test_new_ac2_fields_are_echoed_in_receipt(): void
    {
        $r = $this->ledger()->record($this->goFacts([
            'decision_class' => 'autonomy_promotion',
            'evidence_refs' => ['mutop:abc', 'test:FooTest'],
            'autonomy_level' => 'assisted',
            'risk' => 'medium',
            'rollback_path' => 'atlas:self-construction:autonomy-level degrade --level=assisted',
        ]));

        $this->assertSame('autonomy_promotion', $r['decision_class']);
        $this->assertSame(['mutop:abc', 'test:FooTest'], $r['evidence_refs']);
        $this->assertSame('assisted', $r['autonomy_level']);
        $this->assertSame('medium', $r['risk']);
        $this->assertSame('atlas:self-construction:autonomy-level degrade --level=assisted', $r['rollback_path']);
        // actor is the pre-existing authority field, unchanged.
        $this->assertSame('autonomous', $r['provenance']['authority']);
    }

    public function test_ac2_fields_default_to_empty_when_absent(): void
    {
        $r = $this->ledger()->record($this->goFacts());

        $this->assertSame('', $r['decision_class']);
        $this->assertSame([], $r['evidence_refs']);
        $this->assertSame('', $r['autonomy_level']);
        $this->assertSame('', $r['risk']);
        $this->assertSame('', $r['rollback_path']);
    }

    // ── AC3: flags decisions lacking objective evidence or rollback context ─────

    public function test_missing_evidence_and_rollback_is_flagged_missing_both(): void
    {
        $r = $this->ledger()->record($this->goFacts());

        $this->assertFalse($r['is_evidence_backed']);
        $this->assertFalse($r['has_rollback_context']);
        $this->assertSame('flagged_missing_both', $r['autonomy_evidence_verdict']);
        // is_valid contract is untouched by the evidence flag -- still valid on decision+reasons alone.
        $this->assertTrue($r['is_valid']);
    }

    public function test_missing_rollback_only_is_flagged_missing_rollback(): void
    {
        $r = $this->ledger()->record($this->goFacts(['evidence_refs' => ['mutop:abc']]));

        $this->assertTrue($r['is_evidence_backed']);
        $this->assertFalse($r['has_rollback_context']);
        $this->assertSame('flagged_missing_rollback', $r['autonomy_evidence_verdict']);
    }

    public function test_missing_evidence_only_is_flagged_missing_evidence(): void
    {
        $r = $this->ledger()->record($this->goFacts(['rollback_path' => 'revert commit abc']));

        $this->assertFalse($r['is_evidence_backed']);
        $this->assertTrue($r['has_rollback_context']);
        $this->assertSame('flagged_missing_evidence', $r['autonomy_evidence_verdict']);
    }

    public function test_evidence_and_rollback_both_present_is_accepted(): void
    {
        $r = $this->ledger()->record($this->goFacts([
            'evidence_refs' => ['mutop:abc'],
            'rollback_path' => 'revert commit abc',
        ]));

        $this->assertSame('accepted', $r['autonomy_evidence_verdict']);
    }

    // ── AC4: receipt hash commits to the full decision record for replay ───────

    public function test_receipt_hash_changes_when_evidence_or_rollback_fields_differ(): void
    {
        $base = $this->ledger()->record($this->goFacts());
        $withEvidence = $this->ledger()->record($this->goFacts(['evidence_refs' => ['mutop:abc']]));
        $withRollback = $this->ledger()->record($this->goFacts(['rollback_path' => 'revert commit abc']));
        $withRisk = $this->ledger()->record($this->goFacts(['risk' => 'high']));
        $withClass = $this->ledger()->record($this->goFacts(['decision_class' => 'autonomy_promotion']));
        $withLevel = $this->ledger()->record($this->goFacts(['autonomy_level' => 'assisted']));

        $hashes = [
            $base['receipt_hash'], $withEvidence['receipt_hash'], $withRollback['receipt_hash'],
            $withRisk['receipt_hash'], $withClass['receipt_hash'], $withLevel['receipt_hash'],
        ];
        $this->assertSame($hashes, array_unique($hashes), 'every distinct decision-record field must change the receipt hash');
    }

    public function test_receipt_hash_still_deterministic_for_identical_full_decision_record(): void
    {
        $facts = $this->goFacts([
            'decision_class' => 'autonomy_promotion',
            'evidence_refs' => ['mutop:abc'],
            'autonomy_level' => 'assisted',
            'risk' => 'medium',
            'rollback_path' => 'revert commit abc',
        ]);

        $a = $this->ledger()->record($facts);
        $b = $this->ledger()->record($facts);

        $this->assertSame($a['receipt_hash'], $b['receipt_hash']);
    }
}

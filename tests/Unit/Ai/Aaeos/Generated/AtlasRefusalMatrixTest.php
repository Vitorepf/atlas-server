<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasRefusalMatrixService;
use Tests\TestCase;

/**
 * Pins the documented Cyber Refusal Matrix rules: rule_ids, exception clauses,
 * conservative default, the never-lift rules, and the bypass guard.
 *
 * @see docs/engineering-knowledge-base/cyber-security/refusal-matrix.md
 */
class AtlasRefusalMatrixTest extends TestCase
{
    private function service(): AtlasRefusalMatrixService
    {
        return new AtlasRefusalMatrixService();
    }

    /**
     * cyber-ref-001 (DoS): refused without clauses, and a refusal is NEVER silent
     * (R1). The receipt names the rule and lists the missing operator clauses.
     */
    public function test_dos_without_clauses_refuses_with_non_silent_receipt(): void
    {
        $r = $this->service()->evaluate([
            'technique' => 'dos',
            'target' => 'staging.example.com',
            'target_in_scope' => true,
        ]);

        $this->assertSame(AtlasRefusalMatrixService::DECISION_REFUSE, $r['decision']);
        $this->assertSame('cyber-ref-001', $r['rule_id']);
        $this->assertFalse($r['silent']);                       // R1: never silent
        $this->assertSame(AtlasRefusalMatrixService::RECEIPT_KIND, $r['receipt_kind']);
        $this->assertSame(['operator'], $r['notify']);
        // exception was evaluated and the operator clauses are reported missing.
        $this->assertTrue($r['exception_clause_evaluated']);
        $this->assertContains('operator_clause', $r['exception_clause_missing']);
        $this->assertContains('non_prod_dedicated_env', $r['exception_clause_missing']);
    }

    /**
     * cyber-ref-001 with ALL required operator clauses present and target in scope
     * => allow_with_clause (the exception lifts the refusal).
     */
    public function test_dos_with_full_operator_clause_allows_with_clause(): void
    {
        $r = $this->service()->evaluate([
            'technique' => 'dos',
            'target' => 'staging.example.com',
            'target_in_scope' => true,
            'clauses' => ['operator_clause', 'non_prod_dedicated_env', 'window_under_1h'],
        ]);

        $this->assertSame(AtlasRefusalMatrixService::DECISION_ALLOW_WITH_CLAUSE, $r['decision']);
        $this->assertSame('cyber-ref-001', $r['rule_id']);
        $this->assertTrue($r['exception_clause_evaluated']);
        $this->assertSame([], $r['exception_clause_missing']);
        $this->assertFalse($r['abort_run']);
    }

    /**
     * "sem excecao" rules can NEVER be lifted. cyber-ref-020 (typosquatting) stays
     * refused even when the caller floods it with clauses.
     */
    public function test_no_exception_rule_stays_refused_even_with_clauses(): void
    {
        $r = $this->service()->evaluate([
            'technique' => 'typosquatting',
            'target' => 'real-target',
            'target_in_scope' => true,
            'clauses' => ['operator_clause', 'red-team-c2', 'social-engineering', 'anything'],
        ]);

        $this->assertSame(AtlasRefusalMatrixService::DECISION_REFUSE, $r['decision']);
        $this->assertSame('cyber-ref-020', $r['rule_id']);
        $this->assertFalse($r['exception_clause_evaluated']);    // there is no clause to evaluate
        $this->assertSame(['rule_has_no_exception_clause'], $r['exception_clause_missing']);
    }

    /**
     * cyber-ref-050 (health / life-safety availability): refused ALWAYS, "mesmo
     * sob clausula". Even with every clause supplied it can never be allowed.
     */
    public function test_life_safety_rule_is_never_allowed_under_any_clause(): void
    {
        $r = $this->service()->evaluate([
            'technique' => 'compromise hospital availability',
            'target' => 'hospital-core',
            'target_in_scope' => true,
            'clauses' => ['government_approval', 'isolated_env', 'operator_clause', 'multi_stakeholder_approval'],
        ]);

        $this->assertSame(AtlasRefusalMatrixService::DECISION_REFUSE, $r['decision']);
        $this->assertSame('cyber-ref-050', $r['rule_id']);
        $this->assertSame(['no_clause_can_lift_this_rule'], $r['exception_clause_missing']);
    }

    /**
     * R4 conservative default: a target that is NOT asserted in scope is refused
     * even with an unrecognized technique (cyber-ref-010 out-of-scope catch).
     * In doubt => refuse (false positive > false negative).
     */
    public function test_out_of_scope_target_is_conservatively_refused(): void
    {
        $r = $this->service()->evaluate([
            'technique' => 'port scan',                 // no specific matrix needle
            'target' => 'random.example.org',
            'target_in_scope' => false,
        ]);

        $this->assertSame(AtlasRefusalMatrixService::DECISION_REFUSE, $r['decision']);
        $this->assertSame('cyber-ref-010', $r['rule_id']);
        $this->assertTrue($this->service()->isRefused([
            'technique' => 'port scan',
            'target' => 'random.example.org',
            'target_in_scope' => false,
        ]));

        // Same benign technique against an in-scope target with no rule hit => allow.
        $ok = $this->service()->evaluate([
            'technique' => 'port scan',
            'target' => 'app.uber.com',
            'target_in_scope' => true,
        ]);
        $this->assertSame(AtlasRefusalMatrixService::DECISION_ALLOW, $ok['decision']);
    }

    /**
     * "Sinal de tentativa de bypass": retrying past MAX_BYPASS_ATTEMPTS (>2) flips
     * the bypass guard — even a fully-claused, in-scope action aborts the run and
     * raises the Evidence Ledger bypass event.
     */
    public function test_bypass_cap_aborts_run_and_raises_event(): void
    {
        $base = [
            'technique' => 'dos',
            'target' => 'staging.example.com',
            'target_in_scope' => true,
            'clauses' => ['operator_clause', 'non_prod_dedicated_env', 'window_under_1h'],
        ];

        // attempt == cap (2): still allowed (the cap is N>2).
        $atCap = $this->service()->evaluate($base + ['bypass_attempt' => 2]);
        $this->assertSame(AtlasRefusalMatrixService::DECISION_ALLOW_WITH_CLAUSE, $atCap['decision']);
        $this->assertFalse($atCap['abort_run']);

        // attempt == 3 (> cap): bypass guard trips.
        $overCap = $this->service()->evaluate($base + ['bypass_attempt' => 3]);
        $this->assertSame(AtlasRefusalMatrixService::DECISION_REFUSE, $overCap['decision']);
        $this->assertTrue($overCap['bypass_tripped']);
        $this->assertTrue($overCap['abort_run']);
        $this->assertSame(AtlasRefusalMatrixService::BYPASS_EVENT, $overCap['bypass_event']);
        $this->assertContains('bypass_cap_exceeded', $overCap['exception_clause_missing']);
    }

    /**
     * cyber-ref-072 (C2 channel) needs the named `red-team-c2` clause; a different
     * clause does not satisfy it (partial/wrong clause => still refused, the
     * specific missing token is reported).
     */
    public function test_named_clause_rule_requires_the_exact_clause_token(): void
    {
        $wrong = $this->service()->evaluate([
            'technique' => 'establish c2 channel',
            'target' => 'app.uber.com',
            'target_in_scope' => true,
            'clauses' => ['social-engineering'],
        ]);
        $this->assertSame(AtlasRefusalMatrixService::DECISION_REFUSE, $wrong['decision']);
        $this->assertSame('cyber-ref-072', $wrong['rule_id']);
        $this->assertSame(['red-team-c2'], $wrong['exception_clause_missing']);

        $right = $this->service()->evaluate([
            'technique' => 'establish c2 channel',
            'target' => 'app.uber.com',
            'target_in_scope' => true,
            'clauses' => ['red-team-c2'],
        ]);
        $this->assertSame(AtlasRefusalMatrixService::DECISION_ALLOW_WITH_CLAUSE, $right['decision']);
    }

    /** The matrix exposes its immutable rule_id set spanning the canonical ranges. */
    public function test_rule_ids_cover_canonical_ranges(): void
    {
        $ids = $this->service()->ruleIds();

        $this->assertContains('cyber-ref-001', $ids);
        $this->assertContains('cyber-ref-050', $ids);
        $this->assertContains('cyber-ref-103', $ids);
        // every id follows the documented cyber-ref-NNN shape.
        foreach ($ids as $id) {
            $this->assertMatchesRegularExpression('/^cyber-ref-\d{3}$/', $id);
        }
    }
}

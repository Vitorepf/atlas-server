<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCompressionPolicyCompiler;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCompressionPolicyCompilerTest extends TestCase
{
    private function svc(): AtlasExternalBrainCompressionPolicyCompiler
    {
        return new AtlasExternalBrainCompressionPolicyCompiler;
    }

    private function fullyProvenAction(array $overrides = []): array
    {
        return array_merge([
            'action_id' => 'a1',
            'risk_level' => 'low',
            'proof_available' => true,
            'contract_preserved' => true,
            'rollback_evidence_available' => true,
        ], $overrides);
    }

    // ── AC: allow case ─────────────────────────────────────────────────────────

    public function test_allow_case_all_facts_proven(): void
    {
        $r = $this->svc()->compile(['actions' => [$this->fullyProvenAction()]]);

        $this->assertSame(AtlasExternalBrainCompressionPolicyCompiler::DECISION_ALLOW, $r['decisions'][0]['decision']);
        $this->assertNull($r['decisions'][0]['reason']);
        $this->assertSame(1, $r['summary']['allow_count']);
    }

    public function test_high_risk_with_proof_and_all_facts_is_allowed(): void
    {
        $r = $this->svc()->compile(['actions' => [$this->fullyProvenAction(['risk_level' => 'high'])]]);

        $this->assertSame(AtlasExternalBrainCompressionPolicyCompiler::DECISION_ALLOW, $r['decisions'][0]['decision']);
    }

    // ── AC: deny_missing_proof case — high-risk without proof is DENIED, not warned ──

    public function test_deny_missing_proof_case_high_risk_without_proof_is_denied(): void
    {
        $r = $this->svc()->compile(['actions' => [
            $this->fullyProvenAction(['risk_level' => 'high', 'proof_available' => false]),
        ]]);

        $this->assertSame(AtlasExternalBrainCompressionPolicyCompiler::DECISION_DENY, $r['decisions'][0]['decision']);
        $this->assertSame(AtlasExternalBrainCompressionPolicyCompiler::REASON_HIGH_RISK_WITHOUT_PROOF, $r['decisions'][0]['reason']);
        $this->assertSame(1, $r['summary']['deny_count']);
    }

    public function test_high_risk_without_proof_is_never_merely_a_warning(): void
    {
        $r = $this->svc()->compile(['actions' => [
            $this->fullyProvenAction(['risk_level' => 'high', 'proof_available' => false]),
        ]]);

        // Not allow, not require_prework -- must be a hard deny.
        $this->assertNotSame(AtlasExternalBrainCompressionPolicyCompiler::DECISION_ALLOW, $r['decisions'][0]['decision']);
        $this->assertNotSame(AtlasExternalBrainCompressionPolicyCompiler::DECISION_REQUIRE_PREWORK, $r['decisions'][0]['decision']);
    }

    // ── contract violation always denies regardless of risk ──────────────────

    public function test_contract_not_preserved_is_denied_even_at_low_risk(): void
    {
        $r = $this->svc()->compile(['actions' => [
            $this->fullyProvenAction(['risk_level' => 'low', 'contract_preserved' => false]),
        ]]);

        $this->assertSame(AtlasExternalBrainCompressionPolicyCompiler::DECISION_DENY, $r['decisions'][0]['decision']);
        $this->assertSame(AtlasExternalBrainCompressionPolicyCompiler::REASON_CONTRACT_VIOLATION, $r['decisions'][0]['reason']);
    }

    public function test_contract_violation_takes_priority_over_high_risk_without_proof(): void
    {
        $r = $this->svc()->compile(['actions' => [
            $this->fullyProvenAction(['risk_level' => 'high', 'proof_available' => false, 'contract_preserved' => false]),
        ]]);

        $this->assertSame(AtlasExternalBrainCompressionPolicyCompiler::REASON_CONTRACT_VIOLATION, $r['decisions'][0]['reason']);
    }

    // ── require_prework: low/medium risk missing proof or rollback evidence ──

    public function test_low_risk_missing_proof_requires_prework_not_deny(): void
    {
        $r = $this->svc()->compile(['actions' => [
            $this->fullyProvenAction(['risk_level' => 'low', 'proof_available' => false]),
        ]]);

        $this->assertSame(AtlasExternalBrainCompressionPolicyCompiler::DECISION_REQUIRE_PREWORK, $r['decisions'][0]['decision']);
        $this->assertSame(AtlasExternalBrainCompressionPolicyCompiler::REASON_MISSING_PROOF, $r['decisions'][0]['reason']);
    }

    public function test_medium_risk_missing_proof_requires_prework_not_deny(): void
    {
        $r = $this->svc()->compile(['actions' => [
            $this->fullyProvenAction(['risk_level' => 'medium', 'proof_available' => false]),
        ]]);

        $this->assertSame(AtlasExternalBrainCompressionPolicyCompiler::DECISION_REQUIRE_PREWORK, $r['decisions'][0]['decision']);
    }

    public function test_missing_rollback_evidence_alone_requires_prework(): void
    {
        $r = $this->svc()->compile(['actions' => [
            $this->fullyProvenAction(['rollback_evidence_available' => false]),
        ]]);

        $this->assertSame(AtlasExternalBrainCompressionPolicyCompiler::DECISION_REQUIRE_PREWORK, $r['decisions'][0]['decision']);
        $this->assertSame(AtlasExternalBrainCompressionPolicyCompiler::REASON_MISSING_ROLLBACK_EVIDENCE, $r['decisions'][0]['reason']);
    }

    public function test_missing_proof_takes_priority_over_missing_rollback_evidence(): void
    {
        $r = $this->svc()->compile(['actions' => [
            $this->fullyProvenAction(['proof_available' => false, 'rollback_evidence_available' => false]),
        ]]);

        $this->assertSame(AtlasExternalBrainCompressionPolicyCompiler::REASON_MISSING_PROOF, $r['decisions'][0]['reason']);
    }

    // ── invalid/unknown risk level defaults safely to medium ──────────────────

    public function test_unknown_risk_level_defaults_to_medium(): void
    {
        $r = $this->svc()->compile(['actions' => [
            $this->fullyProvenAction(['risk_level' => 'nonsense']),
        ]]);

        $this->assertSame('medium', $r['decisions'][0]['risk_level']);
    }

    // ── multiple actions + summary counts ─────────────────────────────────────

    public function test_summary_counts_match_decisions_across_multiple_actions(): void
    {
        $r = $this->svc()->compile(['actions' => [
            $this->fullyProvenAction(['action_id' => 'a1']),
            $this->fullyProvenAction(['action_id' => 'a2', 'risk_level' => 'high', 'proof_available' => false]),
            $this->fullyProvenAction(['action_id' => 'a3', 'proof_available' => false]),
        ]]);

        $this->assertSame(3, $r['summary']['total']);
        $this->assertSame(1, $r['summary']['allow_count']);
        $this->assertSame(1, $r['summary']['deny_count']);
        $this->assertSame(1, $r['summary']['require_prework_count']);
    }

    public function test_malformed_action_entry_is_skipped(): void
    {
        $r = $this->svc()->compile(['actions' => ['not-an-array', $this->fullyProvenAction()]]);

        $this->assertCount(1, $r['decisions']);
    }

    // ── schema / determinism ───────────────────────────────────────────────────

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->compile(['actions' => []]);

        $this->assertSame(AtlasExternalBrainCompressionPolicyCompiler::SCHEMA, $r['schema']);
    }

    public function test_empty_actions_yields_empty_decisions(): void
    {
        $r = $this->svc()->compile(['actions' => []]);

        $this->assertSame([], $r['decisions']);
        $this->assertSame(0, $r['summary']['total']);
    }

    public function test_compile_is_deterministic(): void
    {
        $input = ['actions' => [$this->fullyProvenAction(), $this->fullyProvenAction(['action_id' => 'a2', 'risk_level' => 'high', 'proof_available' => false])]];

        $this->assertSame(
            $this->svc()->compile($input),
            $this->svc()->compile($input),
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingGovernanceSystemService;
use Tests\TestCase;

/**
 * Pins the index doc's load-bearing, decidable contract: the canonical flow,
 * the Atlas Dev Fast Lane mapping, the non-relaxation law and scope choice.
 *
 * @see docs/engineering-knowledge-base/atlas-programming-governance-system.md
 */
class AtlasProgrammingGovernanceSystemTest extends TestCase
{
    private function service(): AtlasProgrammingGovernanceSystemService
    {
        return new AtlasProgrammingGovernanceSystemService();
    }

    /**
     * Doc "Fluxo": the governed flow is a fixed ordered pipeline. placement must
     * precede code_intelligence which must precede spec_delta; the pipeline
     * starts at intake and ends at completion_gate.
     */
    public function test_flow_is_the_canonical_ordered_pipeline(): void
    {
        $flow = $this->service()->flow();

        $this->assertSame('intake', $flow[0]);
        $this->assertSame('completion_gate', $flow[count($flow) - 1]);

        $placement = array_search('placement', $flow, true);
        $codeIntel = array_search('code_intelligence', $flow, true);
        $spec = array_search('spec_delta', $flow, true);
        $execution = array_search('execution', $flow, true);

        // placement -> code intelligence -> spec/delta -> ... -> execution
        $this->assertLessThan($codeIntel, $placement);
        $this->assertLessThan($spec, $codeIntel);
        $this->assertLessThan($execution, $spec);
    }

    /**
     * Doc "Atlas Dev Fast Lane" table: each universal gate projects to exactly
     * one Atlas Dev gate, as a projection (not a parallel system). The Code
     * Intelligence projection also carries the CodeDiscoveryManifest artifact.
     */
    public function test_fast_lane_projections_match_the_doc_table(): void
    {
        $service = $this->service();

        $this->assertSame('intake_risk_gate', $service->projectGate('placement')['dev_gate']);
        $this->assertSame('mini_spec_before_code_gate', $service->projectGate('spec_before_code')['dev_gate']);
        $this->assertSame('scope_guard_light', $service->projectGate('scope_guard')['dev_gate']);
        $this->assertSame('receipt_gate', $service->projectGate('evidence')['dev_gate']);
        $this->assertSame('completion_state_gate', $service->projectGate('completion')['dev_gate']);

        $codeIntel = $service->projectGate('code_intelligence');
        $this->assertSame('context_budget_gate', $codeIntel['dev_gate']);
        $this->assertSame('projection', $codeIntel['relationship']);
        $this->assertContains('CodeDiscoveryManifest', $codeIntel['artifacts']);
    }

    /**
     * Doc "Atlas Dev Fast Lane": the three Dev-only gates are recognised as
     * dev_only (operational additions), never as universal-gate projections.
     */
    public function test_dev_only_gates_are_classified_as_dev_only(): void
    {
        $service = $this->service();

        foreach (['light_task_contract_gate', 'verification_gate', 'forge_escalation_gate'] as $gate) {
            $r = $service->projectGate($gate);
            $this->assertSame('dev_only', $r['relationship'], "{$gate} must be dev_only");
            $this->assertNull($r['governance_gate']);
        }
    }

    /**
     * Doc non-relaxation law: "O fast path pode reduzir payload e custo por
     * R-level, mas nao pode relaxar uma lei de governanca: write sem spec ...
     * continua invalido." A cheap-lane write missing spec is INVALID even with
     * reduced_payload set.
     */
    public function test_fast_path_write_missing_spec_is_invalid_even_when_cheaper(): void
    {
        $r = $this->service()->evaluateFastPath([
            'write' => true,
            'r_level' => 'R1',
            'reduced_payload' => true,
            'has_spec' => false,            // dropped law
            'has_task_contract' => true,
            'has_scope' => true,
            'has_verification_evidence' => true,
            'has_completion_state' => true,
        ]);

        $this->assertSame(AtlasProgrammingGovernanceSystemService::FAST_PATH_INVALID, $r['fast_path']);
        $this->assertFalse($r['valid']);
        $this->assertContains('spec', $r['missing_laws']);
    }

    /**
     * Same law, positive case: a write that keeps ALL five governance laws is
     * allowed, and reducing payload/cost per R-level does not change that.
     */
    public function test_fast_path_write_with_all_laws_is_allowed(): void
    {
        $r = $this->service()->evaluateFastPath([
            'write' => true,
            'r_level' => 'R0',
            'reduced_payload' => true,
            'has_spec' => true,
            'has_task_contract' => true,
            'has_scope' => true,
            'has_verification_evidence' => true,
            'has_completion_state' => true,
        ]);

        $this->assertSame(AtlasProgrammingGovernanceSystemService::FAST_PATH_OK, $r['fast_path']);
        $this->assertTrue($r['valid']);
        $this->assertSame([], $r['missing_laws']);
    }

    /**
     * Doc "Exemplos": full governance is mandatory for multi-file / architecture
     * / schema / provider / security / self-construction / cartography work;
     * compact governance is eligible for a small local change but still requires
     * scope + evidence + completion (those are non-relaxable laws).
     */
    public function test_scope_choice_full_vs_compact(): void
    {
        $service = $this->service();

        $structural = $service->classifyScope(['schema' => true]);
        $this->assertSame(AtlasProgrammingGovernanceSystemService::SCOPE_FULL, $structural['scope']);
        $this->assertContains('schema', $structural['triggers']);
        $this->assertContains('spec_before_code', $structural['required_gates']);

        // A multi-file change is structural even without an explicit flag.
        $multiFile = $service->classifyScope(['file_count' => 4]);
        $this->assertSame(AtlasProgrammingGovernanceSystemService::SCOPE_FULL, $multiFile['scope']);
        $this->assertContains('multi_file', $multiFile['triggers']);

        $compact = $service->classifyScope(['file_count' => 1]);
        $this->assertSame(AtlasProgrammingGovernanceSystemService::SCOPE_COMPACT, $compact['scope']);
        // compact still keeps the non-relaxable proof + limit gates:
        $this->assertContains('scope_guard', $compact['required_gates']);
        $this->assertContains('evidence', $compact['required_gates']);
        $this->assertContains('completion', $compact['required_gates']);
        // but does NOT force spec-before-code:
        $this->assertNotContains('spec_before_code', $compact['required_gates']);
    }
}

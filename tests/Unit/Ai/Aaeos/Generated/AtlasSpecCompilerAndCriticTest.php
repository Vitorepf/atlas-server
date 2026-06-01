<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasSpecCompilerAndCriticService;
use Tests\TestCase;

/**
 * Pins the Spec Compiler And Critic contract: the twelve compiler-output
 * fields, the assumption-ledger "never hidden" rule, the eleven named critic
 * checks and their failure classes, and the four output states with their
 * documented precedence.
 *
 * @see docs/engineering-knowledge-base/spec-operating-system/spec-compiler-and-critic.md
 */
class AtlasSpecCompilerAndCriticTest extends TestCase
{
    private function service(): AtlasSpecCompilerAndCriticService
    {
        return new AtlasSpecCompilerAndCriticService();
    }

    /** A spec carrying all twelve minimum fields. */
    private function fullSpec(): array
    {
        return [
            'raw_user_request' => 'Add a Save button to the profile edit form.',
            'interpreted_goal' => 'Persist the active profile form on Save.',
            'non_goals' => ['Redesign the page'],
            'product_area' => 'profile',
            'business_actor_object_action' => 'user saves profile',
            'requirements' => ['Save persists ProfileForm'],
            'acceptance_criteria' => ['Save shows a success toast'],
            'design_system_constraints' => ['Use the primary button token'],
            'security_privacy_constraints' => ['Owner-only edit'],
            'assumptions' => ['A1'],
            'blocking_questions' => [],
            'test_strategy' => ['Feature test for save + error path'],
        ];
    }

    /**
     * Compiler output: a full spec is complete; dropping the documented
     * `acceptance_criteria` field reports exactly that field as missing and is
     * never silently filled.
     */
    public function test_compiler_reports_missing_fields(): void
    {
        $svc = $this->service();

        $full = $svc->compileSpec($this->fullSpec());
        $this->assertTrue($full['complete']);
        $this->assertSame([], $full['missing_fields']);
        // Blank blocking_questions is allowed: an empty list IS a present value.
        $this->assertContains('blocking_questions', $full['present_fields']);
        $this->assertSame(12, $full['required_field_count']);

        $partial = $this->fullSpec();
        unset($partial['acceptance_criteria']);
        $partial['test_strategy'] = '   '; // blank string => not present
        $result = $svc->compileSpec($partial);
        $this->assertFalse($result['complete']);
        $this->assertContains('acceptance_criteria', $result['missing_fields']);
        $this->assertContains('test_strategy', $result['missing_fields']);
    }

    /**
     * Assumption ledger: "assumptions are never hidden". A well-evidenced,
     * high-confidence, non-blocking assumption is admissible; a low-confidence
     * one becomes blocking; an entry with no evidence is malformed.
     */
    public function test_assumption_ledger_enforces_evidence_and_confidence(): void
    {
        $svc = $this->service();

        // The doc's own example: confidence 0.91, blocking:false, has evidence.
        $ok = $svc->assessAssumption([
            'id' => 'A1',
            'text' => 'The button saves the currently active profile form.',
            'confidence' => 0.91,
            'evidence' => ['active_file: ProfileForm.tsx', 'route: /profile/edit'],
            'blocking' => false,
        ]);
        $this->assertTrue($ok['well_formed']);
        $this->assertFalse($ok['blocking']);
        $this->assertFalse($ok['low_confidence']);

        // Below the clarification threshold => blocking even if not flagged.
        $low = $svc->assessAssumption([
            'id' => 'A2',
            'text' => 'Probably the right endpoint.',
            'confidence' => 0.4,
            'evidence' => ['guess'],
            'blocking' => false,
        ]);
        $this->assertTrue($low['low_confidence']);
        $this->assertTrue($low['blocking']);

        // No evidence trail => malformed (cannot be silently trusted).
        $hidden = $svc->assessAssumption([
            'id' => 'A3',
            'text' => 'It just works.',
            'confidence' => 0.95,
            'evidence' => [],
            'blocking' => false,
        ]);
        $this->assertFalse($hidden['well_formed']);
        $this->assertContains('missing_evidence', $hidden['schema_issues']);
    }

    /**
     * Spec critic: the eleven named checks are all run, each triggered finding
     * carries its failure class, and a design-system conflict is a policy-class
     * finding while overengineering is a spike-class finding.
     */
    public function test_critic_runs_eleven_checks_and_classifies_findings(): void
    {
        $svc = $this->service();

        $clean = $svc->critique([]);
        $this->assertSame(11, $clean['checks_run']);
        $this->assertTrue($clean['clean']);
        $this->assertSame([], $clean['findings']);

        $dirty = $svc->critique([
            'design_system_conflict' => true,
            'overengineering' => true,
            'missing_acceptance_criteria' => true,
        ]);
        $this->assertFalse($dirty['clean']);
        $this->assertContains('design_system_conflict', $dirty['policy_findings']);
        $this->assertContains('overengineering', $dirty['spike_findings']);
        $this->assertContains('missing_acceptance_criteria', $dirty['context_findings']);
    }

    /**
     * Output states — full precedence ladder:
     *  - any policy finding => blocked_by_policy (strongest).
     *  - overreach but no policy => spike_only.
     *  - incomplete / context ambiguity / blocking assumption => needs_clarification.
     *  - clean & complete => ready_for_plan.
     */
    public function test_output_state_precedence(): void
    {
        $svc = $this->service();
        $compilerOk = $svc->compileSpec($this->fullSpec());

        // ready_for_plan: complete spec, clean critic, no blocking assumptions.
        $ready = $svc->resolveState([
            'compiler' => $compilerOk,
            'critic' => $svc->critique([]),
            'ledger' => $svc->assessLedger([]),
        ]);
        $this->assertSame(AtlasSpecCompilerAndCriticService::STATE_READY, $ready['state']);
        $this->assertTrue($ready['implementation_allowed']);

        // needs_clarification: a context-class finding, no policy/overreach.
        $clarify = $svc->resolveState([
            'compiler' => $compilerOk,
            'critic' => $svc->critique(['api_backend_ambiguity' => true]),
            'ledger' => $svc->assessLedger([]),
        ]);
        $this->assertSame(AtlasSpecCompilerAndCriticService::STATE_CLARIFY, $clarify['state']);
        $this->assertFalse($clarify['implementation_allowed']);
        $this->assertContains('ambiguity:api_backend_ambiguity', $clarify['reasons']);

        // spike_only: overreach present, no policy finding.
        $spike = $svc->resolveState([
            'compiler' => $compilerOk,
            'critic' => $svc->critique(['scope_creep' => true, 'api_backend_ambiguity' => true]),
            'ledger' => $svc->assessLedger([]),
        ]);
        $this->assertSame(AtlasSpecCompilerAndCriticService::STATE_SPIKE, $spike['state']);
        $this->assertFalse($spike['implementation_allowed']);
        $this->assertTrue($spike['exploration_allowed']);

        // blocked_by_policy beats everything else, even with overreach + ambiguity.
        $blocked = $svc->resolveState([
            'compiler' => $compilerOk,
            'critic' => $svc->critique([
                'missing_auth_permission_rule' => true,
                'scope_creep' => true,
                'api_backend_ambiguity' => true,
            ]),
            'ledger' => $svc->assessLedger([]),
        ]);
        $this->assertSame(AtlasSpecCompilerAndCriticService::STATE_BLOCKED, $blocked['state']);
        $this->assertSame(0, $blocked['precedence_rank']);
        $this->assertFalse($blocked['exploration_allowed']);
    }

    /**
     * A blocking assumption alone (clean critic, complete spec) still forces
     * needs_clarification — assumptions cannot be hidden past planning.
     */
    public function test_blocking_assumption_forces_clarification(): void
    {
        $svc = $this->service();

        $out = $svc->resolveState([
            'compiler' => $svc->compileSpec($this->fullSpec()),
            'critic' => $svc->critique([]),
            'ledger' => $svc->assessLedger([
                ['id' => 'A9', 'text' => 'Unsure endpoint', 'confidence' => 0.3, 'evidence' => ['hunch'], 'blocking' => false],
            ]),
        ]);

        $this->assertSame(AtlasSpecCompilerAndCriticService::STATE_CLARIFY, $out['state']);
        $this->assertContains('blocking_assumption:A9', $out['reasons']);
    }

    /** The end-to-end envelope wires compiler + critic + ledger + state. */
    public function test_evaluate_envelope_is_ready_for_a_clean_full_spec(): void
    {
        $svc = $this->service();

        $env = $svc->evaluate(
            $this->fullSpec(),
            [],
            [
                ['id' => 'A1', 'text' => 'Saves active form', 'confidence' => 0.91, 'evidence' => ['ProfileForm.tsx'], 'blocking' => false],
            ],
        );

        $this->assertTrue($env['compiler']['complete']);
        $this->assertTrue($env['critic']['clean']);
        $this->assertFalse($env['ledger']['has_blocking_assumption']);
        $this->assertSame(AtlasSpecCompilerAndCriticService::STATE_READY, $env['output']['state']);
    }
}

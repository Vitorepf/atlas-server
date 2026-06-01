<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAiMasterArchitectureService;
use Tests\TestCase;

/**
 * Pins the documented Master Architecture contracts: the canonical-flow ordering
 * and the 11 non-negotiable plane-authority rules.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-master-architecture.md
 */
final class AtlasAiMasterArchitectureTest extends TestCase
{
    private AtlasAiMasterArchitectureService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasAiMasterArchitectureService;
    }

    /**
     * A clean trace that traverses the full canonical flow in order and breaches
     * no non-negotiable is admitted.
     */
    public function test_clean_canonical_trace_is_admitted(): void
    {
        $d = $this->service->evaluate([
            'stages' => $this->service->canonicalFlow(),
            'decide_actor_kind' => 'atlas',
            'vault_operational_truth' => false,
            'business_context_type' => 'context',
            'repeated_signals' => [['signal' => 'p', 'promoted_to_core' => true]],
            'important_events' => [['event' => 'e', 'has_evidence' => true]],
            'curator_proposals' => [['id' => 'c', 'critical' => true, 'auto_applied' => false, 'reviewed' => true]],
        ]);

        $this->assertSame('admitted', $d['verdict']);
        $this->assertTrue($d['admitted']);
        $this->assertSame([], $d['breaches']);
    }

    /**
     * `repair` is the only optional stage: a full flow with `repair` omitted is
     * still complete and ordered.
     */
    public function test_repair_stage_is_optional(): void
    {
        $stages = array_values(array_filter(
            $this->service->canonicalFlow(),
            static fn (string $s): bool => $s !== 'repair',
        ));

        $flow = $this->service->evaluateCanonicalFlow($stages);

        $this->assertTrue($flow['ordered']);
        $this->assertSame([], $flow['missing']);
        $this->assertSame([], $flow['breaches']);
    }

    /**
     * Rule 5 — Runtime does not execute without a Decision Receipt: a trace that
     * reaches `runtime` with no `receipt` upstream is rejected with the exact
     * documented breach code.
     */
    public function test_runtime_without_receipt_is_rejected(): void
    {
        $d = $this->service->evaluate([
            // canonical flow minus `receipt`
            'stages' => [
                'surface', 'input', 'envelope', 'intent', 'business_context',
                'domain_profile_flow', 'context', 'policy', 'decide',
                'runtime', 'gates', 'repair', 'evidence', 'learning', 'output',
            ],
            'decide_actor_kind' => 'atlas',
        ]);

        $this->assertSame('rejected', $d['verdict']);
        $this->assertContains('runtime_without_receipt', $d['breaches']);
        $this->assertContains('receipt', $d['canonical_flow']['missing']);

        $rule5 = $this->ruleByNumber($d['non_negotiables'], 5);
        $this->assertFalse($rule5['passed']);
        $this->assertSame('runtime_without_receipt', $rule5['reason']);
    }

    /**
     * Rules 1/2/3/7 — Surface/Provider/Tool/Workspace do not decide. Each
     * forbidden decider produces its own breach code while every other rule
     * keeps passing.
     */
    public function test_forbidden_deciders_are_rejected(): void
    {
        $base = [
            'stages' => $this->service->canonicalFlow(),
            'vault_operational_truth' => false,
            'business_context_type' => 'context',
        ];

        $expected = [
            'surface' => 'surface_decided',
            'provider' => 'provider_decided',
            'tool' => 'tool_decided',
            'workspace' => 'workspace_decided',
        ];

        foreach ($expected as $actor => $reason) {
            $d = $this->service->evaluate($base + ['decide_actor_kind' => $actor]);
            $this->assertSame('rejected', $d['verdict'], "actor {$actor} must be rejected");
            $this->assertContains($reason, $d['breaches'], "actor {$actor} breach code");
        }

        // Atlas as the decider is allowed.
        $ok = $this->service->evaluate($base + ['decide_actor_kind' => 'atlas']);
        $this->assertSame('admitted', $ok['verdict']);
    }

    /**
     * Rule 4 — Domain does not bypass Policy: `decide` before `policy` (or no
     * `policy` at all upstream of `decide`) is a `policy_bypassed` breach.
     */
    public function test_decide_before_policy_breaches_rule_four(): void
    {
        $d = $this->service->evaluate([
            // policy appears AFTER decide -> out of order + policy bypass
            'stages' => [
                'surface', 'input', 'envelope', 'intent', 'business_context',
                'domain_profile_flow', 'context', 'decide', 'policy', 'receipt',
                'runtime', 'gates', 'repair', 'evidence', 'learning', 'output',
            ],
            'decide_actor_kind' => 'atlas',
        ]);

        $this->assertSame('rejected', $d['verdict']);
        $this->assertContains('policy_bypassed', $d['breaches']);
        $this->assertContains('flow_out_of_order', $d['canonical_flow']['breaches']);
    }

    /**
     * Rules 6, 8, 9, 10, 11 — learning/knowledge invariants all fire together
     * and each emits its own documented breach code.
     */
    public function test_knowledge_and_learning_invariants(): void
    {
        $d = $this->service->evaluate([
            'stages' => $this->service->canonicalFlow(),
            'decide_actor_kind' => 'atlas',
            'vault_operational_truth' => true,                  // rule 6
            'business_context_type' => 'cognitive_domain',      // rule 8
            'repeated_signals' => [                              // rule 9
                ['signal' => 'recurring.fix', 'promoted_to_core' => false],
            ],
            'important_events' => [                              // rule 10
                ['event' => 'prod.change', 'has_evidence' => false],
            ],
            'curator_proposals' => [                             // rule 11
                ['id' => 'cp', 'critical' => true, 'auto_applied' => true, 'reviewed' => false],
            ],
        ]);

        $this->assertSame('rejected', $d['verdict']);
        foreach ([
            'vault_used_as_operational_truth',
            'business_context_typed_as_domain',
            'repeated_not_promoted_to_core',
            'important_without_evidence',
            'curator_auto_applied_critical_without_review',
        ] as $reason) {
            $this->assertContains($reason, $d['breaches'], "missing breach: {$reason}");
        }

        // A non-critical auto-applied curator proposal is NOT a breach.
        $safe = $this->service->evaluate([
            'stages' => $this->service->canonicalFlow(),
            'decide_actor_kind' => 'atlas',
            'curator_proposals' => [
                ['id' => 'cp', 'critical' => false, 'auto_applied' => true, 'reviewed' => false],
            ],
        ]);
        $this->assertSame('admitted', $safe['verdict']);
    }

    /**
     * @param  array<int,array{rule:int,name:string,passed:bool,reason:?string}>  $rules
     *
     * @return array{rule:int,name:string,passed:bool,reason:?string}
     */
    private function ruleByNumber(array $rules, int $n): array
    {
        foreach ($rules as $r) {
            if ($r['rule'] === $n) {
                return $r;
            }
        }
        $this->fail("rule {$n} not found");
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ScopeExpansion;

use App\Services\Ai\SelfConstruction\ScopeExpansion\AtlasSelfConstructionScopeExpansionGovernorCycle;
use Tests\TestCase;

final class AtlasSelfConstructionScopeExpansionGovernorCycleTest extends TestCase
{
    private function readyCandidate(string $id = 'scope-a'): array
    {
        return [
            'scope_id' => $id,
            'evidence_refs' => ['doc:source.md'],
            'atlas_native_owner' => true,
            'requires_operator' => false,
            'requires_human' => false,
            'requires_external_provider' => false,
            'proven_leverage_tier' => 3,
            'autonomy_readiness_tier' => 3,
            'risk' => 2,
        ];
    }

    private function readyFactsFor(string $id): array
    {
        return [
            $id => [
                'current_scope_green' => true,
                'autonomy_allows_expansion' => true,
                'rollback_gate_ready' => true,
                'release_governor_ready' => true,
                'queue_health' => ['acceptable' => true],
                'requires_operator' => false,
                'requires_human' => false,
                'requires_external_provider' => false,
                'final_runtime_owner' => 'atlas_native',
                'docs_health' => true,
                'code_intelligence_ready' => true,
            ],
        ];
    }

    private function laneFactsFor(string $id): array
    {
        return [
            $id => [
                'project_id' => 'atlas',
                'lane_type' => 'atlas_internal',
                'queue_namespace' => 'atlas.scope_a',
                'execution_topology' => 'shared_local_main_with_scope_lock',
                'allowed_roots' => ['app/Services/Ai/SelfConstruction/ScopeExpansion'],
                'forbidden_roots' => [],
                'existing_lane_roots' => [],
            ],
        ];
    }

    public function test_dry_run_invokes_no_callbacks_and_emits_schema(): void
    {
        $callbackCalled = false;
        $cycle = new AtlasSelfConstructionScopeExpansionGovernorCycle;
        $out = $cycle->run([
            'candidates' => [$this->readyCandidate()],
            'risk_budget' => ['max_risk' => 10],
            'readiness_facts' => $this->readyFactsFor('scope-a'),
            'lane_facts' => $this->laneFactsFor('scope-a'),
        ], [
            'action_callbacks' => ['admit' => function () use (&$callbackCalled) {
                $callbackCalled = true;
            }],
        ]);

        $this->assertSame(AtlasSelfConstructionScopeExpansionGovernorCycle::SCHEMA, $out['schema_version']);
        $this->assertSame('ok', $out['status']);
        $this->assertTrue($out['dry_run']);
        $this->assertSame([], $out['applied_actions']);
        $this->assertSame([], $out['blocked_actions']);
        $this->assertFalse($callbackCalled, 'dry-run must not invoke any callback');
        $this->assertNotEmpty($out['governor_cycle_hash']);
    }

    public function test_apply_mode_only_uses_injected_callbacks_and_isolates_failures(): void
    {
        $cycle = new AtlasSelfConstructionScopeExpansionGovernorCycle;

        $admissionPlan = [
            'status' => 'ready',
            'candidate_id' => 'scope-a',
            'actions' => [
                ['kind' => 'admit', 'detail' => ['lane' => 'atlas.scope_a']],
                ['kind' => 'replenish', 'detail' => ['budget' => 1]],
            ],
        ];

        $cycle = new AtlasSelfConstructionScopeExpansionGovernorCycle(
            null,
            null,
            null,
            static fn (array $c, array $r, array $l): array => $admissionPlan,
        );

        $out = $cycle->run([
            'candidates' => [$this->readyCandidate()],
            'risk_budget' => ['max_risk' => 10],
            'readiness_facts' => $this->readyFactsFor('scope-a'),
            'lane_facts' => $this->laneFactsFor('scope-a'),
        ], [
            'apply' => true,
            'action_callbacks' => [
                'admit' => static fn (array $a, array $p): array => ['ok' => true, 'lane' => $a['detail']['lane']],
                'replenish' => static function (): array {
                    throw new \RuntimeException('replenish exploded');
                },
            ],
        ]);

        $this->assertFalse($out['dry_run']);
        $this->assertCount(1, $out['applied_actions']);
        $this->assertSame('admit', $out['applied_actions'][0]['kind']);
        $this->assertCount(1, $out['blocked_actions']);
        $this->assertSame('replenish', $out['blocked_actions'][0]['kind']);
        $this->assertSame('replenish exploded', $out['blocked_actions'][0]['error']);
    }

    public function test_forbidden_action_kinds_are_withheld_even_with_callback(): void
    {
        $admissionPlan = [
            'status' => 'ready',
            'candidate_id' => 'scope-a',
            'actions' => [
                ['kind' => 'git', 'detail' => []],
                ['kind' => 'operator_action', 'detail' => []],
                ['kind' => 'external_provider_call', 'detail' => []],
                ['kind' => 'file_mutation', 'detail' => []],
                ['kind' => 'subprocess', 'detail' => []],
                ['kind' => 'human_action', 'detail' => []],
            ],
        ];
        $cycle = new AtlasSelfConstructionScopeExpansionGovernorCycle(
            null,
            null,
            null,
            static fn (array $c, array $r, array $l): array => $admissionPlan,
        );

        $callbackUsed = false;
        $out = $cycle->run([
            'candidates' => [$this->readyCandidate()],
            'risk_budget' => ['max_risk' => 10],
            'readiness_facts' => $this->readyFactsFor('scope-a'),
            'lane_facts' => $this->laneFactsFor('scope-a'),
        ], [
            'apply' => true,
            'action_callbacks' => [
                'git' => function () use (&$callbackUsed) { $callbackUsed = true; },
                'operator_action' => function () use (&$callbackUsed) { $callbackUsed = true; },
            ],
        ]);

        $this->assertFalse($callbackUsed, 'forbidden action callbacks must never fire');
        $this->assertSame([], $out['applied_actions']);
        $kinds = array_column($out['withheld_actions'], 'kind');
        foreach (AtlasSelfConstructionScopeExpansionGovernorCycle::FORBIDDEN_ACTION_KINDS as $f) {
            $this->assertContains($f, $kinds, "{$f} must be in withheld_actions");
        }
        foreach ($out['withheld_actions'] as $w) {
            $this->assertStringContainsString('forbidden_action_kind:', $w['reason']);
        }
    }

    public function test_rejected_candidate_does_not_become_admission_plan_action(): void
    {
        $bad = $this->readyCandidate('bad');
        $bad['requires_human'] = true;

        $cycle = new AtlasSelfConstructionScopeExpansionGovernorCycle;
        $out = $cycle->run([
            'candidates' => [$bad],
            'risk_budget' => ['max_risk' => 10],
        ]);

        $this->assertSame(0, $out['admitted_count']);
        $this->assertSame([], $out['applied_actions']);
        $this->assertSame([], $out['admission_plans']);
        $this->assertNotEmpty($out['ranked_candidates']['rejected_candidates']);
    }
}

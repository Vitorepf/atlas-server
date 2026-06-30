<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ScopeExpansion\AtlasSelfConstructionScopeExpansionGovernorCycle;
use Tests\TestCase;

/**
 * Feature-level gate for AtlasSelfConstructionScopeExpansionGovernorCycle.
 *
 * Verifies dry-run safety, apply-mode callback isolation, forbidden action
 * withholding, and receipt recording.
 */
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
                'knowledge_sync_current' => true,
                'test_suite_green' => true,
                'worker_capacity_available' => true,
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

    // ── AC1: Dry-run invokes no callbacks ────────────────────────────────────

    public function test_dry_run_ranks_evaluates_plans_but_invokes_no_callbacks(): void
    {
        $called = false;
        $cycle = new AtlasSelfConstructionScopeExpansionGovernorCycle;

        $out = $cycle->run([
            'candidates' => [$this->readyCandidate()],
            'risk_budget' => ['max_risk' => 10],
            'readiness_facts' => $this->readyFactsFor('scope-a'),
            'lane_facts' => $this->laneFactsFor('scope-a'),
        ], [
            'action_callbacks' => ['admit' => function () use (&$called) { $called = true; }],
        ]);

        $this->assertTrue($out['dry_run']);
        $this->assertFalse($called);
        $this->assertSame([], $out['applied_actions']);
        $this->assertNotEmpty($out['admission_plans']);
    }

    // ── AC2: Apply mode invokes injected callbacks and records receipts ─────

    public function test_apply_mode_invokes_ready_callbacks_and_records_applied_actions(): void
    {
        $admissionPlan = [
            'status' => 'ready',
            'candidate_id' => 'scope-a',
            'actions' => [
                ['kind' => 'admit', 'detail' => ['lane' => 'atlas.scope_a']],
            ],
        ];

        $cycle = new AtlasSelfConstructionScopeExpansionGovernorCycle(
            null, null, null,
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
            ],
        ]);

        $this->assertFalse($out['dry_run']);
        $this->assertCount(1, $out['applied_actions']);
        $this->assertSame('admit', $out['applied_actions'][0]['kind']);
    }

    public function test_callback_failures_isolated_into_blocked_actions(): void
    {
        $admissionPlan = [
            'status' => 'ready',
            'candidate_id' => 'scope-a',
            'actions' => [
                ['kind' => 'replenish', 'detail' => []],
            ],
        ];

        $cycle = new AtlasSelfConstructionScopeExpansionGovernorCycle(
            null, null, null,
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
                'replenish' => static function (): array {
                    throw new \RuntimeException('replenish failed');
                },
            ],
        ]);

        $this->assertCount(1, $out['blocked_actions']);
        $this->assertSame('replenish', $out['blocked_actions'][0]['kind']);
        $this->assertSame('replenish failed', $out['blocked_actions'][0]['error']);
    }

    // ── AC3: Forbidden action kinds withheld with explicit reasons ──────────

    public function test_forbidden_kinds_withheld_even_when_callback_supplied(): void
    {
        $admissionPlan = [
            'status' => 'ready',
            'candidate_id' => 'scope-a',
            'actions' => [
                ['kind' => 'git', 'detail' => []],
                ['kind' => 'subprocess', 'detail' => []],
                ['kind' => 'file_mutation', 'detail' => []],
            ],
        ];

        $cycle = new AtlasSelfConstructionScopeExpansionGovernorCycle(
            null, null, null,
            static fn (array $c, array $r, array $l): array => $admissionPlan,
        );

        $callbackFired = false;
        $out = $cycle->run([
            'candidates' => [$this->readyCandidate()],
            'risk_budget' => ['max_risk' => 10],
            'readiness_facts' => $this->readyFactsFor('scope-a'),
            'lane_facts' => $this->laneFactsFor('scope-a'),
        ], [
            'apply' => true,
            'action_callbacks' => [
                'git' => function () use (&$callbackFired) { $callbackFired = true; },
                'subprocess' => function () use (&$callbackFired) { $callbackFired = true; },
            ],
        ]);

        $this->assertFalse($callbackFired, 'forbidden callbacks must never fire');
        $this->assertSame([], $out['applied_actions']);
        $kinds = array_column($out['withheld_actions'], 'kind');
        $this->assertContains('git', $kinds);
        $this->assertContains('subprocess', $kinds);
        $this->assertContains('file_mutation', $kinds);
        foreach ($out['withheld_actions'] as $w) {
            $this->assertStringContainsString('forbidden_action_kind:', $w['reason']);
        }
    }
}

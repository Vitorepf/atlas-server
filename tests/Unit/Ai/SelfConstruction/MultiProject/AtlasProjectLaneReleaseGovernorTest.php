<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\MultiProject;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneReleaseGovernor;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasProjectLaneReleaseGovernor: all-clean facts ⇒ merge_ready; missing verification ⇒ hold;
 * verification verdict=failed ⇒ rollback_required; cross-lane refusal flags OR repeated failure streak
 * ⇒ quarantine; envelope carries project_id + lane_namespace + reasons + next_actions.
 */
final class AtlasProjectLaneReleaseGovernorTest extends TestCase
{
    private function cleanFacts(): array
    {
        return [
            'project_id' => 'demo',
            'lane_namespace' => 'lane.demo.deadbeef.main',
            'verification_court_verdict' => ['verdict' => 'passed', 'server_side_green' => true],
            'receipt_evidence' => ['envelope_hash' => 'env-h-1'],
            'rollback_gate' => ['conformant' => true],
            'knowledge_sync_plan' => ['conformant' => true, 'blockers' => []],
            'autonomy_readiness_facts' => ['status' => 'ready'],
            'cross_lane_refusal_flags' => [],
            'repeated_failure_streak' => 0,
            'queue_namespace_isolated' => true,
            'cross_lane_leak_check_passed' => true,
        ];
    }

    public function test_all_clean_facts_yield_merge_ready(): void
    {
        $r = (new AtlasProjectLaneReleaseGovernor)->decide($this->cleanFacts());
        $this->assertSame(AtlasProjectLaneReleaseGovernor::DECISION_MERGE, $r['decision']);
        $this->assertSame([], $r['reasons']);
        $this->assertContains('perform_lane_merge', $r['next_actions']);
    }

    public function test_missing_verification_yields_hold(): void
    {
        $f = $this->cleanFacts();
        $f['verification_court_verdict'] = []; // missing
        $r = (new AtlasProjectLaneReleaseGovernor)->decide($f);
        $this->assertSame(AtlasProjectLaneReleaseGovernor::DECISION_HOLD, $r['decision']);
        $this->assertContains('verification_not_passed_or_not_server_side_green', $r['reasons']);
    }

    public function test_verification_failed_yields_rollback_required(): void
    {
        $f = $this->cleanFacts();
        $f['verification_court_verdict'] = ['verdict' => 'failed', 'server_side_green' => false];
        $r = (new AtlasProjectLaneReleaseGovernor)->decide($f);
        $this->assertSame(AtlasProjectLaneReleaseGovernor::DECISION_ROLLBACK, $r['decision']);
        $this->assertContains('execute_rollback_plan', $r['next_actions']);
    }

    public function test_cross_lane_refusal_yields_quarantine(): void
    {
        $f = $this->cleanFacts();
        $f['cross_lane_refusal_flags'] = ['lane-b/secret.php'];
        $r = (new AtlasProjectLaneReleaseGovernor)->decide($f);
        $this->assertSame(AtlasProjectLaneReleaseGovernor::DECISION_QUARANTINE, $r['decision']);
        $this->assertContains('cross_lane_refusal:lane-b/secret.php', $r['reasons']);
        $this->assertContains('pause_lane_until_respec', $r['next_actions']);
    }

    public function test_repeated_failure_streak_yields_quarantine(): void
    {
        $f = $this->cleanFacts();
        $f['repeated_failure_streak'] = 5;
        $r = (new AtlasProjectLaneReleaseGovernor)->decide($f);
        $this->assertSame(AtlasProjectLaneReleaseGovernor::DECISION_QUARANTINE, $r['decision']);
        $this->assertContains('repeated_failure_streak:5', $r['reasons']);
    }

    public function test_missing_receipt_envelope_hash_yields_hold(): void
    {
        $f = $this->cleanFacts();
        $f['receipt_evidence'] = [];
        $r = (new AtlasProjectLaneReleaseGovernor)->decide($f);
        $this->assertSame(AtlasProjectLaneReleaseGovernor::DECISION_HOLD, $r['decision']);
        $this->assertContains('receipt_envelope_hash_missing', $r['reasons']);
    }

    public function test_envelope_carries_project_id_and_lane_namespace_and_canonical_schema(): void
    {
        $r = (new AtlasProjectLaneReleaseGovernor)->decide($this->cleanFacts());
        $this->assertSame(AtlasProjectLaneReleaseGovernor::SCHEMA, $r['schema']);
        $this->assertSame('demo', $r['project_id']);
        $this->assertSame('lane.demo.deadbeef.main', $r['lane_namespace']);
    }

    public function test_merge_ready_requires_autonomy_readiness_ready(): void
    {
        $f = $this->cleanFacts();
        $f['autonomy_readiness_facts'] = ['status' => 'degraded'];
        $r = (new AtlasProjectLaneReleaseGovernor)->decide($f);

        $this->assertSame(AtlasProjectLaneReleaseGovernor::DECISION_HOLD, $r['decision']);
        $this->assertContains('autonomy_readiness_not_ready', $r['reasons']);
    }

    public function test_operator_human_and_provider_approved_finality_never_satisfies_merge(): void
    {
        $forbidden = [
            'operator_approved', 'human_approved', 'claude_code_approved',
            'codex_approved', 'cursor_approved', 'provider_approved',
        ];
        $f = $this->cleanFacts();
        $f['finality_facts'] = array_fill_keys($forbidden, true);

        $r = (new AtlasProjectLaneReleaseGovernor)->decide($f);

        $this->assertSame(AtlasProjectLaneReleaseGovernor::DECISION_HOLD, $r['decision']);
        foreach ($forbidden as $source) {
            $this->assertContains('finality_provider_forbidden:'.$source, $r['reasons']);
        }
    }

    // --- queue namespace + cross-lane leak checks ---

    public function test_missing_queue_namespace_isolated_yields_hold(): void
    {
        $f = $this->cleanFacts();
        unset($f['queue_namespace_isolated']);
        $r = (new AtlasProjectLaneReleaseGovernor)->decide($f);
        $this->assertSame(AtlasProjectLaneReleaseGovernor::DECISION_HOLD, $r['decision']);
        $this->assertContains('queue_namespace_not_isolated', $r['reasons']);
    }

    public function test_queue_namespace_isolated_false_yields_hold(): void
    {
        $f = $this->cleanFacts();
        $f['queue_namespace_isolated'] = false;
        $r = (new AtlasProjectLaneReleaseGovernor)->decide($f);
        $this->assertSame(AtlasProjectLaneReleaseGovernor::DECISION_HOLD, $r['decision']);
        $this->assertContains('queue_namespace_not_isolated', $r['reasons']);
    }

    public function test_missing_cross_lane_leak_check_passed_yields_hold(): void
    {
        $f = $this->cleanFacts();
        unset($f['cross_lane_leak_check_passed']);
        $r = (new AtlasProjectLaneReleaseGovernor)->decide($f);
        $this->assertSame(AtlasProjectLaneReleaseGovernor::DECISION_HOLD, $r['decision']);
        $this->assertContains('cross_lane_leak_check_not_passed', $r['reasons']);
    }

    public function test_cross_lane_leak_facts_yield_quarantine_with_pause_lane(): void
    {
        $f = $this->cleanFacts();
        $f['cross_lane_leak_facts'] = ['lane-b/secret.php', 'lane-c/config.php'];
        $r = (new AtlasProjectLaneReleaseGovernor)->decide($f);
        $this->assertSame(AtlasProjectLaneReleaseGovernor::DECISION_QUARANTINE, $r['decision']);
        $this->assertContains('cross_lane_leak:lane-b/secret.php', $r['reasons']);
        $this->assertContains('cross_lane_leak:lane-c/config.php', $r['reasons']);
        $this->assertContains('pause_lane_until_respec', $r['next_actions']);
    }

    public function test_all_lane_scoped_finality_facts_yield_merge_ready_deterministically(): void
    {
        $svc = new AtlasProjectLaneReleaseGovernor;
        $a = $svc->decide($this->cleanFacts());
        $b = $svc->decide($this->cleanFacts());
        $this->assertSame(AtlasProjectLaneReleaseGovernor::DECISION_MERGE, $a['decision']);
        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_hold_decisions_include_deterministic_next_actions(): void
    {
        $f = $this->cleanFacts();
        $f['receipt_evidence'] = []; // missing envelope_hash → hold

        $a = (new AtlasProjectLaneReleaseGovernor)->decide($f);
        $b = (new AtlasProjectLaneReleaseGovernor)->decide($f);

        $this->assertSame(AtlasProjectLaneReleaseGovernor::DECISION_HOLD, $a['decision']);
        $this->assertNotEmpty($a['next_actions']);
        $this->assertSame($a['next_actions'], $b['next_actions'], 'next_actions must be deterministic');
    }

    // ── AC2: governance_action canonical vocabulary (release/hold/rollback/request_repair) ──

    public function test_governance_action_release_for_merge_ready(): void
    {
        $r = (new AtlasProjectLaneReleaseGovernor)->decide($this->cleanFacts());

        $this->assertSame(AtlasProjectLaneReleaseGovernor::ACTION_RELEASE, $r['governance_action']);
    }

    public function test_governance_action_hold_for_hold(): void
    {
        $f = $this->cleanFacts();
        $f['receipt_evidence'] = [];
        $r = (new AtlasProjectLaneReleaseGovernor)->decide($f);

        $this->assertSame(AtlasProjectLaneReleaseGovernor::ACTION_HOLD, $r['governance_action']);
    }

    public function test_governance_action_rollback_for_rollback_required(): void
    {
        $f = $this->cleanFacts();
        $f['verification_court_verdict'] = ['verdict' => 'failed', 'server_side_green' => false];
        $r = (new AtlasProjectLaneReleaseGovernor)->decide($f);

        $this->assertSame(AtlasProjectLaneReleaseGovernor::ACTION_ROLLBACK, $r['governance_action']);
    }

    public function test_governance_action_request_repair_for_quarantine(): void
    {
        $f = $this->cleanFacts();
        $f['repeated_failure_streak'] = 5;
        $r = (new AtlasProjectLaneReleaseGovernor)->decide($f);

        $this->assertSame(AtlasProjectLaneReleaseGovernor::ACTION_REQUEST_REPAIR, $r['governance_action']);
    }

    // ── AC3: stale lane context / verification policy mismatch block release ───

    public function test_stale_project_lane_context_yields_hold(): void
    {
        $f = $this->cleanFacts();
        $f['project_lane_context_stale'] = true;
        $r = (new AtlasProjectLaneReleaseGovernor)->decide($f);

        $this->assertSame(AtlasProjectLaneReleaseGovernor::DECISION_HOLD, $r['decision']);
        $this->assertContains('project_lane_context_stale', $r['reasons']);
    }

    public function test_verification_policy_mismatch_yields_hold(): void
    {
        $f = $this->cleanFacts();
        $f['verification_policy_mismatch'] = true;
        $r = (new AtlasProjectLaneReleaseGovernor)->decide($f);

        $this->assertSame(AtlasProjectLaneReleaseGovernor::DECISION_HOLD, $r['decision']);
        $this->assertContains('verification_policy_mismatched', $r['reasons']);
    }

    public function test_absent_new_ac3_facts_never_block_merge_ready(): void
    {
        // Backward compatibility: omitting both new facts entirely must reproduce merge_ready exactly.
        $r = (new AtlasProjectLaneReleaseGovernor)->decide($this->cleanFacts());

        $this->assertSame(AtlasProjectLaneReleaseGovernor::DECISION_MERGE, $r['decision']);
    }

    // ── AC4: smallest_missing_evidence names the cheapest fix first ─────────────

    public function test_smallest_missing_evidence_null_when_merge_ready(): void
    {
        $r = (new AtlasProjectLaneReleaseGovernor)->decide($this->cleanFacts());

        $this->assertNull($r['smallest_missing_evidence']);
    }

    public function test_smallest_missing_evidence_prefers_receipt_over_autonomy_readiness(): void
    {
        $f = $this->cleanFacts();
        $f['receipt_evidence'] = [];
        $f['autonomy_readiness_facts'] = ['status' => 'degraded'];

        $r = (new AtlasProjectLaneReleaseGovernor)->decide($f);

        $this->assertContains('receipt_envelope_hash_missing', $r['reasons']);
        $this->assertContains('autonomy_readiness_not_ready', $r['reasons']);
        $this->assertSame('receipt_envelope_hash_missing', $r['smallest_missing_evidence']);
    }

    public function test_smallest_missing_evidence_present_for_single_reason(): void
    {
        $f = $this->cleanFacts();
        $f['queue_namespace_isolated'] = false;

        $r = (new AtlasProjectLaneReleaseGovernor)->decide($f);

        $this->assertSame('queue_namespace_not_isolated', $r['smallest_missing_evidence']);
    }

    // ── laneReleaseDecision: release_decision, blocked_reasons, rollback_plan_ref, next_lane_action ──

    public function test_all_floors_met_allows_release(): void
    {
        $r = (new AtlasProjectLaneReleaseGovernor)->laneReleaseDecision([
            'lane_health' => 0.9,
            'task_quality' => 0.85,
            'proof_freshness' => 0.7,
            'rollback_readiness' => 0.9,
            'provider_safe_evidence' => true,
            'cross_project_leakage' => [],
            'rollback_plan_ref' => 'plan-123',
        ]);
        $this->assertSame('approved', $r['release_decision']);
        $this->assertSame([], $r['blocked_reasons']);
        $this->assertSame('plan-123', $r['rollback_plan_ref']);
        $this->assertSame('proceed_with_lane_release', $r['next_lane_action']);
    }

    public function test_lane_health_below_floor_blocks_release(): void
    {
        $r = (new AtlasProjectLaneReleaseGovernor)->laneReleaseDecision([
            'lane_health' => 0.3,
            'task_quality' => 0.85,
            'proof_freshness' => 0.7,
            'rollback_readiness' => 0.9,
            'provider_safe_evidence' => true,
            'cross_project_leakage' => [],
        ]);
        $this->assertSame('blocked', $r['release_decision']);
        $this->assertCount(1, $r['blocked_reasons']);
        $this->assertStringContainsString('lane_health', $r['blocked_reasons'][0]);
        $this->assertSame('resolve_blocked_reasons_and_recheck', $r['next_lane_action']);
    }

    public function test_cross_project_leakage_blocks_release(): void
    {
        $r = (new AtlasProjectLaneReleaseGovernor)->laneReleaseDecision([
            'lane_health' => 0.9,
            'task_quality' => 0.85,
            'proof_freshness' => 0.7,
            'rollback_readiness' => 0.9,
            'provider_safe_evidence' => true,
            'cross_project_leakage' => ['project-alpha'],
        ]);
        $this->assertSame('blocked', $r['release_decision']);
        $this->assertContains('cross_project_leakage:project-alpha', $r['blocked_reasons']);
    }

    public function test_missing_provider_safe_evidence_blocks_release(): void
    {
        $r = (new AtlasProjectLaneReleaseGovernor)->laneReleaseDecision([
            'lane_health' => 0.9,
            'task_quality' => 0.85,
            'proof_freshness' => 0.7,
            'rollback_readiness' => 0.9,
            'provider_safe_evidence' => false,
            'cross_project_leakage' => [],
        ]);
        $this->assertSame('blocked', $r['release_decision']);
        $this->assertContains('missing_provider_safe_evidence', $r['blocked_reasons']);
    }
}

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
}

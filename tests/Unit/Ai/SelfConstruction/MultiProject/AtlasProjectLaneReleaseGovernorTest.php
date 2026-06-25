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
}

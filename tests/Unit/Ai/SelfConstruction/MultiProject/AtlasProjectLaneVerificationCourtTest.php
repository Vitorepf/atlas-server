<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\MultiProject;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneVerificationCourt;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasProjectLaneVerificationCourt: pass when policy.allowed=true AND every evidence record
 * matches project_id AND carries a hash AND no leak facts; hold when required rerun evidence is
 * missing; blocked on project_id mismatch / missing hash / leak fact present.
 */
final class AtlasProjectLaneVerificationCourtTest extends TestCase
{
    private function passingPolicy(): array
    {
        return ['allowed' => true, 'blockers' => [], 'project_id' => 'demo-lane'];
    }

    public function test_pass_when_all_inputs_clean(): void
    {
        $v = (new AtlasProjectLaneVerificationCourt)->adjudicate([
            'project_id' => 'demo-lane',
            'verification_policy' => $this->passingPolicy(),
            'evidence_records' => [
                ['project_id' => 'demo-lane', 'gate' => 'phpunit', 'evidence_hash' => 'h-1', 'passed' => true],
            ],
            'required_rerun_evidence' => ['phpunit'],
            'leak_facts' => [],
        ]);

        $this->assertSame(AtlasProjectLaneVerificationCourt::VERDICT_PASS, $v['verdict']);
        $this->assertTrue($v['passed']);
        $this->assertContains('h-1', $v['evidence_hashes']);
    }

    public function test_hold_when_required_rerun_evidence_missing(): void
    {
        $v = (new AtlasProjectLaneVerificationCourt)->adjudicate([
            'project_id' => 'demo-lane',
            'verification_policy' => $this->passingPolicy(),
            'evidence_records' => [['project_id' => 'demo-lane', 'gate' => 'phpunit', 'evidence_hash' => 'h-1', 'server_side_green' => true]],
            'required_rerun_evidence' => ['phpunit', 'pint'],
        ]);

        $this->assertSame(AtlasProjectLaneVerificationCourt::VERDICT_HOLD, $v['verdict']);
        $this->assertFalse($v['passed']);
        $this->assertContains('pint', $v['verification_facts']['missing_rerun']);
    }

    public function test_blocked_when_evidence_project_id_mismatches(): void
    {
        $v = (new AtlasProjectLaneVerificationCourt)->adjudicate([
            'project_id' => 'demo-lane',
            'verification_policy' => $this->passingPolicy(),
            'evidence_records' => [['project_id' => 'OTHER', 'gate' => 'phpunit', 'evidence_hash' => 'h-1']],
        ]);

        $this->assertSame(AtlasProjectLaneVerificationCourt::VERDICT_BLOCKED, $v['verdict']);
        $this->assertContains('evidence_project_id_mismatch:OTHER', $v['blockers']);
    }

    public function test_blocked_when_evidence_hash_missing(): void
    {
        $v = (new AtlasProjectLaneVerificationCourt)->adjudicate([
            'project_id' => 'demo-lane',
            'verification_policy' => $this->passingPolicy(),
            'evidence_records' => [['project_id' => 'demo-lane', 'gate' => 'phpunit']],
        ]);

        $this->assertSame(AtlasProjectLaneVerificationCourt::VERDICT_BLOCKED, $v['verdict']);
        $this->assertContains('evidence_missing_hash:phpunit', $v['blockers']);
    }

    public function test_blocked_when_leak_facts_present(): void
    {
        $v = (new AtlasProjectLaneVerificationCourt)->adjudicate([
            'project_id' => 'demo-lane',
            'verification_policy' => $this->passingPolicy(),
            'evidence_records' => [['project_id' => 'demo-lane', 'gate' => 'phpunit', 'evidence_hash' => 'h-1']],
            'leak_facts' => [['kind' => 'allowed_files_escape_lane', 'fact' => ['path' => '/etc/passwd']]],
        ]);

        $this->assertSame(AtlasProjectLaneVerificationCourt::VERDICT_BLOCKED, $v['verdict']);
        $this->assertContains('leak:allowed_files_escape_lane', $v['blockers']);
    }

    public function test_blocked_when_verification_policy_disallowed(): void
    {
        $v = (new AtlasProjectLaneVerificationCourt)->adjudicate([
            'project_id' => 'demo-lane',
            'verification_policy' => ['allowed' => false, 'blockers' => ['admission:invalid_project_id']],
            'evidence_records' => [['project_id' => 'demo-lane', 'gate' => 'phpunit', 'evidence_hash' => 'h-1']],
        ]);

        $this->assertSame(AtlasProjectLaneVerificationCourt::VERDICT_BLOCKED, $v['verdict']);
        $this->assertContains('policy:admission:invalid_project_id', $v['blockers']);
    }

    public function test_blocked_when_policy_allowed_false_with_no_blockers_array(): void
    {
        // policy ships allowed=false but no 'blockers' key — the old code emitted nothing, leaving the gate open
        $v = (new AtlasProjectLaneVerificationCourt)->adjudicate([
            'project_id' => 'demo-lane',
            'verification_policy' => ['allowed' => false],
            'evidence_records' => [['project_id' => 'demo-lane', 'gate' => 'phpunit', 'evidence_hash' => 'h-ok']],
        ]);

        $this->assertSame(AtlasProjectLaneVerificationCourt::VERDICT_BLOCKED, $v['verdict']);
        $this->assertFalse($v['passed']);
        $this->assertNotEmpty($v['blockers'], 'a disallowed policy must produce at least one blocker');
    }

    public function test_envelope_carries_canonical_schema_and_no_scalar_score(): void
    {
        $v = (new AtlasProjectLaneVerificationCourt)->adjudicate([
            'project_id' => 'demo-lane',
            'verification_policy' => $this->passingPolicy(),
            'evidence_records' => [['project_id' => 'demo-lane', 'gate' => 'phpunit', 'evidence_hash' => 'h-1']],
        ]);
        $this->assertSame(AtlasProjectLaneVerificationCourt::SCHEMA, $v['schema_version']);
        $json = (string) json_encode($v);
        $this->assertDoesNotMatchRegularExpression('/"(score|grade|percent)"/i', $json);
    }

    public function test_evidence_without_server_side_green_cannot_pass(): void
    {
        $v = (new AtlasProjectLaneVerificationCourt)->adjudicate([
            'project_id' => 'demo-lane',
            'verification_policy' => $this->passingPolicy(),
            'evidence_records' => [['project_id' => 'demo-lane', 'gate' => 'phpunit', 'evidence_hash' => 'h-1']],
        ]);

        $this->assertSame(AtlasProjectLaneVerificationCourt::VERDICT_BLOCKED, $v['verdict']);
        $this->assertContains('evidence_not_server_side_green:phpunit', $v['blockers']);
    }

    public function test_stale_evidence_cannot_pass(): void
    {
        $v = (new AtlasProjectLaneVerificationCourt)->adjudicate([
            'project_id' => 'demo-lane',
            'verification_policy' => $this->passingPolicy(),
            'evidence_records' => [['project_id' => 'demo-lane', 'gate' => 'phpunit', 'evidence_hash' => 'h-1', 'server_side_green' => true, 'stale' => true]],
        ]);

        $this->assertSame(AtlasProjectLaneVerificationCourt::VERDICT_BLOCKED, $v['verdict']);
        $this->assertContains('evidence_stale:phpunit', $v['blockers']);
    }

    public function test_evidence_path_outside_lane_roots_is_blocked_as_lane_root_mismatch(): void
    {
        $v = (new AtlasProjectLaneVerificationCourt)->adjudicate([
            'project_id'          => 'demo-lane',
            'verification_policy' => $this->passingPolicy(),
            'lane_roots'          => ['/repo/demo-lane/app/'],
            'evidence_records'    => [[
                'project_id'      => 'demo-lane',
                'gate'            => 'phpunit',
                'evidence_hash'   => 'h-1',
                'server_side_green' => true,
                'path'            => '/repo/other-lane/app/secret.php',
            ]],
        ]);

        $this->assertSame(AtlasProjectLaneVerificationCourt::VERDICT_BLOCKED, $v['verdict']);
        $this->assertContains('lane_root_mismatch:/repo/other-lane/app/secret.php', $v['blockers']);
    }

    // ── evaluateVotes ─────────────────────────────────────────────────────────

    private function cleanVotesInput(): array
    {
        return [
            'project_id'               => 'demo-lane',
            'lane_roots'               => ['/repo/demo-lane'],
            'required_rerun_evidence'  => ['phpunit'],
            'evidence_records'         => [[
                'project_id'       => 'demo-lane',
                'gate'             => 'phpunit',
                'evidence_hash'    => 'h-1',
                'server_side_green' => true,
                'path'             => '/repo/demo-lane/Service.php',
            ]],
        ];
    }

    public function test_evaluate_votes_all_pass_when_clean_input(): void
    {
        $r = (new AtlasProjectLaneVerificationCourt)->evaluateVotes($this->cleanVotesInput());

        $this->assertTrue($r['lane_promotion_allowed']);
        $this->assertSame([], $r['blocked_vote_ids']);
        $this->assertSame(AtlasProjectLaneVerificationCourt::VOTES_SCHEMA, $r['schema_version']);
        $this->assertCount(count(AtlasProjectLaneVerificationCourt::VOTE_IDS), $r['votes']);
    }

    public function test_evaluate_votes_isolation_fails_when_no_lane_roots(): void
    {
        $input = $this->cleanVotesInput();
        unset($input['lane_roots']);

        $r = (new AtlasProjectLaneVerificationCourt)->evaluateVotes($input);

        $this->assertFalse($r['lane_promotion_allowed']);
        $this->assertContains('isolation', $r['blocked_vote_ids']);
        $this->assertArrayHasKey('isolation', $r['repair_hints']);
    }

    public function test_evaluate_votes_isolation_fails_when_path_outside_lane(): void
    {
        $input = $this->cleanVotesInput();
        $input['evidence_records'][0]['path'] = '/repo/other-project/foo.php';

        $r = (new AtlasProjectLaneVerificationCourt)->evaluateVotes($input);

        $this->assertFalse($r['lane_promotion_allowed']);
        $this->assertContains('isolation', $r['blocked_vote_ids']);
    }

    public function test_evaluate_votes_runnable_proof_fails_without_server_side_green(): void
    {
        $input = $this->cleanVotesInput();
        $input['evidence_records'][0]['server_side_green'] = false;

        $r = (new AtlasProjectLaneVerificationCourt)->evaluateVotes($input);

        $this->assertFalse($r['lane_promotion_allowed']);
        $this->assertContains('runnable_proof', $r['blocked_vote_ids']);
    }

    public function test_evaluate_votes_knowledge_freshness_fails_when_stale(): void
    {
        $input = $this->cleanVotesInput();
        $input['evidence_records'][0]['stale'] = true;

        $r = (new AtlasProjectLaneVerificationCourt)->evaluateVotes($input);

        $this->assertFalse($r['lane_promotion_allowed']);
        $this->assertContains('knowledge_freshness', $r['blocked_vote_ids']);
    }

    public function test_evaluate_votes_queue_namespace_safety_fails_on_project_id_mismatch(): void
    {
        $input = $this->cleanVotesInput();
        $input['evidence_records'][0]['project_id'] = 'other-project';

        $r = (new AtlasProjectLaneVerificationCourt)->evaluateVotes($input);

        $this->assertFalse($r['lane_promotion_allowed']);
        $this->assertContains('queue_namespace_safety', $r['blocked_vote_ids']);
    }

    public function test_evaluate_votes_evidence_completeness_fails_when_required_gate_missing(): void
    {
        $input = $this->cleanVotesInput();
        $input['required_rerun_evidence'] = ['phpunit', 'pint'];

        $r = (new AtlasProjectLaneVerificationCourt)->evaluateVotes($input);

        $this->assertFalse($r['lane_promotion_allowed']);
        $this->assertContains('evidence_completeness', $r['blocked_vote_ids']);
        $this->assertStringContainsString('pint', $r['repair_hints']['evidence_completeness']);
    }

    public function test_evaluate_votes_repair_hints_returned_for_each_blocked_vote(): void
    {
        // Multiple failures: no lane_roots (isolation) + stale record (knowledge_freshness).
        $input = $this->cleanVotesInput();
        unset($input['lane_roots']);
        $input['evidence_records'][0]['stale'] = true;

        $r = (new AtlasProjectLaneVerificationCourt)->evaluateVotes($input);

        $this->assertArrayHasKey('isolation', $r['repair_hints']);
        $this->assertArrayHasKey('knowledge_freshness', $r['repair_hints']);
        $this->assertNotEmpty($r['repair_hints']['isolation']);
        $this->assertNotEmpty($r['repair_hints']['knowledge_freshness']);
    }
}

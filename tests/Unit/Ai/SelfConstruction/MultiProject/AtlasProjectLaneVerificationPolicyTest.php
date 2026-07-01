<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\MultiProject;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneVerificationPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasProjectLaneVerificationPolicy: allowed=true ONLY when admission.admitted + freshness.conformant
 * + every verification_command has a passing evidence row; stale context yields freshness blockers; a
 * missing command yields evidence_missing_for:<cmd>; a missing evidence file yields the appropriate
 * blocker; identical input ⇒ byte-identical envelope.
 */
final class AtlasProjectLaneVerificationPolicyTest extends TestCase
{
    private function admittedManifest(): array
    {
        return [
            'admitted' => true,
            'project_id' => 'demo-lane',
            'blocking_reasons' => [],
            'verification_commands' => ['phpunit', 'pint'],
            'lane_local_test_command' => '/opt/homebrew/bin/php artisan test --filter=demo',
            'evidence_ledger_isolated' => true,
        ];
    }

    private function conformantFreshness(): array
    {
        return ['conformant' => true, 'blockers' => []];
    }

    private function evidenceAllPassing(): array
    {
        return [
            'rollback_proof' => true,
            'evidence' => [
                'phpunit' => ['passed' => true, 'gate' => 'phpunit'],
                'pint' => ['passed' => true, 'gate' => 'pint'],
            ],
        ];
    }

    public function test_admitted_conformant_all_evidence_passing_yields_allowed_true(): void
    {
        $v = (new AtlasProjectLaneVerificationPolicy)->decide(
            $this->admittedManifest(),
            $this->conformantFreshness(),
            $this->evidenceAllPassing(),
        );

        $this->assertTrue($v['allowed']);
        $this->assertSame([], $v['blockers']);
        $this->assertSame('demo-lane', $v['project_id']);
    }

    public function test_stale_context_freshness_blockers_propagate(): void
    {
        $v = (new AtlasProjectLaneVerificationPolicy)->decide(
            $this->admittedManifest(),
            ['conformant' => false, 'blockers' => ['context_pack_stale']],
            $this->evidenceAllPassing(),
        );

        $this->assertFalse($v['allowed']);
        $this->assertContains('freshness:context_pack_stale', $v['blockers']);
    }

    public function test_missing_evidence_for_required_command_blocks(): void
    {
        $evidence = ['evidence' => ['phpunit' => ['passed' => true]]]; // pint missing
        $v = (new AtlasProjectLaneVerificationPolicy)->decide($this->admittedManifest(), $this->conformantFreshness(), $evidence);

        $this->assertFalse($v['allowed']);
        $this->assertContains('evidence_missing_for:pint', $v['blockers']);
        $this->assertContains('pint', $v['sources']['missing_evidence_for']);
    }

    public function test_failing_evidence_for_required_command_blocks(): void
    {
        $evidence = [
            'evidence' => [
                'phpunit' => ['passed' => true],
                'pint' => ['passed' => false],
            ],
        ];
        $v = (new AtlasProjectLaneVerificationPolicy)->decide($this->admittedManifest(), $this->conformantFreshness(), $evidence);

        $this->assertFalse($v['allowed']);
        $this->assertContains('evidence_failing_for:pint', $v['blockers']);
        $this->assertContains('pint', $v['sources']['failing_evidence_for']);
    }

    public function test_non_admitted_manifest_blocks_with_admission_blockers(): void
    {
        $bad = ['admitted' => false, 'project_id' => 'bad', 'blocking_reasons' => ['invalid_project_id'], 'verification_commands' => []];
        $v = (new AtlasProjectLaneVerificationPolicy)->decide($bad, $this->conformantFreshness(), ['evidence' => []]);

        $this->assertFalse($v['allowed']);
        $this->assertContains('admission:invalid_project_id', $v['blockers']);
    }

    public function test_missing_lane_local_test_command_blocks_readiness(): void
    {
        $admission = $this->admittedManifest();
        unset($admission['lane_local_test_command']);
        $v = (new AtlasProjectLaneVerificationPolicy)->decide($admission, $this->conformantFreshness(), $this->evidenceAllPassing());
        $this->assertFalse($v['allowed']);
        $this->assertContains('lane_local_test_command_missing', $v['blockers']);
    }

    public function test_stale_code_index_freshness_blocker_blocks_readiness(): void
    {
        $v = (new AtlasProjectLaneVerificationPolicy)->decide(
            $this->admittedManifest(),
            ['conformant' => false, 'blockers' => ['code_index_stale']],
            $this->evidenceAllPassing(),
        );
        $this->assertFalse($v['allowed']);
        $this->assertContains('freshness:code_index_stale', $v['blockers']);
    }

    public function test_shared_evidence_ledger_blocks_readiness(): void
    {
        $admission = $this->admittedManifest();
        $admission['evidence_ledger_isolated'] = false;
        $v = (new AtlasProjectLaneVerificationPolicy)->decide($admission, $this->conformantFreshness(), $this->evidenceAllPassing());
        $this->assertFalse($v['allowed']);
        $this->assertContains('evidence_ledger_not_isolated', $v['blockers']);
    }

    public function test_missing_rollback_proof_blocks_readiness(): void
    {
        $evidence = $this->evidenceAllPassing();
        unset($evidence['rollback_proof']);
        $v = (new AtlasProjectLaneVerificationPolicy)->decide($this->admittedManifest(), $this->conformantFreshness(), $evidence);
        $this->assertFalse($v['allowed']);
        $this->assertContains('rollback_proof_missing', $v['blockers']);
    }

    public function test_complete_lane_local_verification_facts_yield_allowed(): void
    {
        $v = (new AtlasProjectLaneVerificationPolicy)->decide(
            $this->admittedManifest(),
            $this->conformantFreshness(),
            $this->evidenceAllPassing(),
        );
        $this->assertTrue($v['allowed']);
        $this->assertSame([], $v['blockers']);
    }

    // ── AC3: lane_decision, missing_artifacts, risk_band, required_next_checks ────

    public function test_verified_lane_returns_admit_decision_and_none_risk_band(): void
    {
        $v = (new AtlasProjectLaneVerificationPolicy)->decide(
            $this->admittedManifest(),
            $this->conformantFreshness(),
            $this->evidenceAllPassing(),
        );

        $this->assertSame('admit', $v['lane_decision']);
        $this->assertSame('none', $v['risk_band']);
        $this->assertSame([], $v['missing_artifacts']);
        $this->assertSame([], $v['required_next_checks']);
    }

    public function test_blocked_lane_returns_block_decision_with_next_checks(): void
    {
        $evidence = ['evidence' => ['phpunit' => ['passed' => true]]]; // pint missing
        $v = (new AtlasProjectLaneVerificationPolicy)->decide($this->admittedManifest(), $this->conformantFreshness(), $evidence);

        $this->assertSame('block', $v['lane_decision']);
        $this->assertContains('evidence_missing_for:pint', $v['missing_artifacts']);
        $this->assertNotEmpty($v['required_next_checks']);
    }

    public function test_failing_evidence_is_not_counted_as_a_missing_artifact(): void
    {
        $evidence = [
            'rollback_proof' => true,
            'evidence' => [
                'phpunit' => ['passed' => true],
                'pint' => ['passed' => false],
            ],
        ];
        $v = (new AtlasProjectLaneVerificationPolicy)->decide($this->admittedManifest(), $this->conformantFreshness(), $evidence);

        $this->assertNotContains('evidence_failing_for:pint', $v['missing_artifacts']);
    }

    // ── boundary risk ─────────────────────────────────────────────────────────────

    public function test_scope_path_escaping_project_root_is_a_critical_boundary_risk(): void
    {
        $admission = $this->admittedManifest();
        $admission['project_root'] = 'projects/demo-lane';
        $admission['scope_paths'] = ['projects/demo-lane/app/Foo.php', 'projects/other-lane/app/Bar.php'];

        $v = (new AtlasProjectLaneVerificationPolicy)->decide($admission, $this->conformantFreshness(), $this->evidenceAllPassing());

        $this->assertFalse($v['allowed']);
        $this->assertContains('project_boundary_violation:projects/other-lane/app/Bar.php', $v['blockers']);
        $this->assertContains('projects/other-lane/app/Bar.php', $v['sources']['boundary_violations']);
        $this->assertSame('critical', $v['risk_band']);
    }

    public function test_scope_paths_within_project_root_are_not_boundary_violations(): void
    {
        $admission = $this->admittedManifest();
        $admission['project_root'] = 'projects/demo-lane';
        $admission['scope_paths'] = ['projects/demo-lane/app/Foo.php'];

        $v = (new AtlasProjectLaneVerificationPolicy)->decide($admission, $this->conformantFreshness(), $this->evidenceAllPassing());

        $this->assertTrue($v['allowed']);
        $this->assertSame([], $v['sources']['boundary_violations']);
    }

    // ── insufficient verification ──────────────────────────────────────────────────

    public function test_empty_verification_commands_is_insufficient_verification(): void
    {
        $admission = $this->admittedManifest();
        $admission['verification_commands'] = [];

        $v = (new AtlasProjectLaneVerificationPolicy)->decide($admission, $this->conformantFreshness(), $this->evidenceAllPassing());

        $this->assertFalse($v['allowed']);
        $this->assertContains('verification_commands_missing', $v['blockers']);
        $this->assertSame('medium', $v['risk_band']);
    }

    public function test_two_decides_with_same_input_byte_identical_json(): void
    {
        $p = new AtlasProjectLaneVerificationPolicy;
        $a = json_encode($p->decide($this->admittedManifest(), $this->conformantFreshness(), $this->evidenceAllPassing()), JSON_UNESCAPED_SLASHES);
        $b = json_encode($p->decide($this->admittedManifest(), $this->conformantFreshness(), $this->evidenceAllPassing()), JSON_UNESCAPED_SLASHES);
        $this->assertSame($a, $b);
    }
}

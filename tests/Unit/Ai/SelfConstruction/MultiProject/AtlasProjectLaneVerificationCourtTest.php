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
}

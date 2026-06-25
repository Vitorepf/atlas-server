<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\VerificationCourt;

use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtEvidenceContract;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasVerificationCourtEvidenceContract: complete allegation ⇒ accepted=true AND verified===null
 * (the contract NEVER grants final verified); missing gate result yields
 * missing_tests_or_gates_result_passed; unacknowledged scope deviation yields
 * unacknowledged_scope_deviation:<path>; missing evidence_hash yields missing_evidence_hash; non-native
 * runtime owner yields non_atlas_native_runtime_owner:<owner>; blockers are deterministically sorted.
 */
final class AtlasVerificationCourtEvidenceContractTest extends TestCase
{
    private function validEvidence(): array
    {
        return [
            'task_packet_id' => 'pkt-1',
            'lease_id' => 'lease_01XYZ',
            'files_changed' => ['app/Foo.php'],
            'commands_run' => [['name' => 'phpunit', 'exit_code' => 0]],
            'tests_or_gates_result' => ['passed' => true, 'gate' => 'phpunit'],
            'evidence_hash' => 'evh-1',
            'scope_deviations' => [],
            'residual_risks' => [],
            'runtime_owner' => AtlasVerificationCourtEvidenceContract::RUNTIME_OWNER_NATIVE,
        ];
    }

    public function test_valid_allegation_yields_accepted_true_but_verified_is_always_null(): void
    {
        $r = (new AtlasVerificationCourtEvidenceContract)->evaluate($this->validEvidence());
        $this->assertTrue($r['accepted']);
        $this->assertNull($r['verified'], 'contract NEVER emits verified=true — only the court does');
        $this->assertSame([], $r['blockers']);
    }

    public function test_missing_gate_result_yields_named_blocker(): void
    {
        $e = $this->validEvidence();
        unset($e['tests_or_gates_result']);
        $r = (new AtlasVerificationCourtEvidenceContract)->evaluate($e);
        $this->assertFalse($r['accepted']);
        $this->assertContains('missing_tests_or_gates_result_passed', $r['blockers']);
    }

    public function test_unacknowledged_scope_deviation_yields_named_blocker(): void
    {
        $e = $this->validEvidence();
        $e['scope_deviations'] = [['path' => 'app/Bar.php', 'acknowledged' => false]];
        $r = (new AtlasVerificationCourtEvidenceContract)->evaluate($e);
        $this->assertContains('unacknowledged_scope_deviation:app/Bar.php', $r['blockers']);
    }

    public function test_missing_evidence_hash_yields_named_blocker(): void
    {
        $e = $this->validEvidence();
        $e['evidence_hash'] = '';
        $r = (new AtlasVerificationCourtEvidenceContract)->evaluate($e);
        $this->assertContains('missing_evidence_hash', $r['blockers']);
    }

    public function test_non_atlas_native_runtime_owner_yields_named_blocker(): void
    {
        $e = $this->validEvidence();
        $e['runtime_owner'] = 'external_provider';
        $r = (new AtlasVerificationCourtEvidenceContract)->evaluate($e);
        $this->assertContains('non_atlas_native_runtime_owner:external_provider', $r['blockers']);
    }

    public function test_empty_command_proof_yields_blocker_even_when_commands_run_present(): void
    {
        $e = $this->validEvidence();
        $e['commands_run'] = [['exit_code' => 0]]; // no 'name'
        $r = (new AtlasVerificationCourtEvidenceContract)->evaluate($e);
        $this->assertContains('empty_command_proof', $r['blockers']);
    }

    public function test_blockers_are_deterministically_sorted(): void
    {
        $r = (new AtlasVerificationCourtEvidenceContract)->evaluate([
            'task_packet_id' => '',
            'lease_id' => '',
            'files_changed' => [],
            'commands_run' => [],
            'tests_or_gates_result' => null,
            'evidence_hash' => '',
            'scope_deviations' => [],
            'residual_risks' => [],
            'runtime_owner' => '',
        ]);
        $copy = $r['blockers'];
        sort($copy, SORT_STRING);
        $this->assertSame($copy, $r['blockers']);
    }
}

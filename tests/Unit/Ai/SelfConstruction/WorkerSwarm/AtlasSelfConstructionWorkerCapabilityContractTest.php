<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\WorkerSwarm;

use App\Services\Ai\SelfConstruction\WorkerSwarm\AtlasSelfConstructionWorkerCapabilityContract;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasSelfConstructionWorkerCapabilityContract: a well-formed Atlas-native worker ⇒ accepted=true;
 * broad scope ⇒ broad_scope_root:<root>; authority-overreach action ⇒ authority_overreach:<action>; non-
 * native runtime owner ⇒ runtime_owner_not_atlas_native:<owner>; missing required evidence ⇒
 * missing_evidence:<key>.
 */
final class AtlasSelfConstructionWorkerCapabilityContractTest extends TestCase
{
    private function safeProfile(): array
    {
        return [
            'worker_id' => 'atlas-worker-1',
            'runtime_owner' => AtlasSelfConstructionWorkerCapabilityContract::RUNTIME_OWNER_NATIVE,
            'scope_roots' => ['app/Demo'],
            'declared_capabilities' => ['inspect_task_packet', 'apply_scoped_patch', 'run_gates', 'write_evidence'],
            'declared_actions' => ['inspect', 'apply_patch_in_scope', 'run_gate_in_sandbox', 'write_evidence_row'],
            'evidence_emits' => AtlasSelfConstructionWorkerCapabilityContract::REQUIRED_EVIDENCE,
        ];
    }

    public function test_safe_atlas_native_worker_is_accepted(): void
    {
        $r = (new AtlasSelfConstructionWorkerCapabilityContract)->evaluate($this->safeProfile());
        $this->assertTrue($r['accepted']);
        $this->assertSame([], $r['blockers']);
    }

    public function test_broad_scope_root_yields_blocker(): void
    {
        $p = $this->safeProfile();
        $p['scope_roots'] = ['/'];
        $r = (new AtlasSelfConstructionWorkerCapabilityContract)->evaluate($p);
        $this->assertFalse($r['accepted']);
        $this->assertContains('broad_scope_root:/', $r['blockers']);
    }

    public function test_authority_overreach_action_is_blocked(): void
    {
        $p = $this->safeProfile();
        $p['declared_actions'][] = 'grant_verification_pass';
        $r = (new AtlasSelfConstructionWorkerCapabilityContract)->evaluate($p);
        $this->assertContains('authority_overreach:grant_verification_pass', $r['blockers']);
    }

    public function test_non_atlas_native_runtime_owner_is_blocked(): void
    {
        $p = $this->safeProfile();
        $p['runtime_owner'] = 'external_provider';
        $r = (new AtlasSelfConstructionWorkerCapabilityContract)->evaluate($p);
        $this->assertContains('runtime_owner_not_atlas_native:external_provider', $r['blockers']);
    }

    public function test_missing_required_evidence_is_blocked(): void
    {
        $p = $this->safeProfile();
        $p['evidence_emits'] = ['evidence_hash']; // missing test_run_id, commit_sha_or_diff_hash
        $r = (new AtlasSelfConstructionWorkerCapabilityContract)->evaluate($p);
        $this->assertContains('missing_evidence:test_run_id', $r['blockers']);
        $this->assertContains('missing_evidence:commit_sha_or_diff_hash', $r['blockers']);
    }

    public function test_capability_outside_allowlist_is_blocked(): void
    {
        $p = $this->safeProfile();
        $p['declared_capabilities'][] = 'invent_new_constitution';
        $r = (new AtlasSelfConstructionWorkerCapabilityContract)->evaluate($p);
        $this->assertContains('capability_not_in_allowlist:invent_new_constitution', $r['blockers']);
    }

    public function test_action_outside_safe_allowlist_is_blocked(): void
    {
        $p = $this->safeProfile();
        $p['declared_actions'][] = 'reboot_universe';
        $r = (new AtlasSelfConstructionWorkerCapabilityContract)->evaluate($p);
        $this->assertContains('action_not_in_safe_allowlist:reboot_universe', $r['blockers']);
    }

    public function test_two_evaluations_with_same_profile_are_byte_identical(): void
    {
        $p = $this->safeProfile();
        $c = new AtlasSelfConstructionWorkerCapabilityContract;
        $a = json_encode($c->evaluate($p));
        $b = json_encode($c->evaluate($p));
        $this->assertSame($a, $b);
    }
}

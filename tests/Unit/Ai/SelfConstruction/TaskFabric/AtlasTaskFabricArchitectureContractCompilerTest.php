<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricArchitectureContractCompiler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves AtlasTaskFabricArchitectureContractCompiler: a valid contract produces one draft per impl file,
 * paired with its matching test file; broad directory paths are rejected; empty acceptance / evidence /
 * owner_scope throw; multi-impl contracts split into atomic drafts; same input yields byte-identical
 * spec_hash per draft.
 */
final class AtlasTaskFabricArchitectureContractCompilerTest extends TestCase
{
    private function validContract(): array
    {
        return [
            'contract_id' => 'ARCH-42',
            'owner_scope' => 'atlas-native',
            'capability_gap' => 'add a small helper for X',
            'candidate_files' => ['app/Demo/Helper.php', 'tests/Unit/Demo/HelperTest.php'],
            'acceptance_seed' => ['phpunit green'],
            'evidence_seed' => ['test_run_id', 'commit_sha'],
            'risk_class' => 'standard',
        ];
    }

    public function test_valid_contract_produces_one_paired_draft_per_impl_file(): void
    {
        $drafts = (new AtlasTaskFabricArchitectureContractCompiler)->compile($this->validContract());
        $this->assertCount(1, $drafts);
        $this->assertSame(['app/Demo/Helper.php', 'tests/Unit/Demo/HelperTest.php'], $drafts[0]['allowed_files']);
        $this->assertSame(['app/Demo/Helper.php'], $drafts[0]['scope_in']);
        $this->assertSame(['phpunit green'], $drafts[0]['acceptance_criteria']);
        $this->assertSame(['test_run_id', 'commit_sha'], $drafts[0]['required_evidence']);
        $this->assertSame('standard', $drafts[0]['risk_class']);
        $this->assertSame(64, strlen($drafts[0]['spec_hash']));
        $this->assertStringContainsString('revert_commit:ARCH-42', $drafts[0]['rollback_hint']);
    }

    public function test_broad_directory_path_is_rejected(): void
    {
        $c = $this->validContract();
        $c['candidate_files'] = ['app/Demo/']; // directory, not a file
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/broad directory rejected/');
        (new AtlasTaskFabricArchitectureContractCompiler)->compile($c);
    }

    public function test_missing_evidence_throws(): void
    {
        $c = $this->validContract();
        $c['evidence_seed'] = [];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing-field:evidence_seed/');
        (new AtlasTaskFabricArchitectureContractCompiler)->compile($c);
    }

    public function test_missing_owner_scope_throws(): void
    {
        $c = $this->validContract();
        $c['owner_scope'] = '';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing-field:owner_scope/');
        (new AtlasTaskFabricArchitectureContractCompiler)->compile($c);
    }

    public function test_empty_capability_gap_throws(): void
    {
        $c = $this->validContract();
        $c['capability_gap'] = '';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing-field:capability_gap/');
        (new AtlasTaskFabricArchitectureContractCompiler)->compile($c);
    }

    public function test_ownership_conflict_phrase_in_capability_gap_throws(): void
    {
        $c = $this->validContract();
        $c['capability_gap'] = 'add helper but external provider owns final runtime';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/conflicts with Atlas-native ownership/');
        (new AtlasTaskFabricArchitectureContractCompiler)->compile($c);
    }

    public function test_multi_impl_contract_splits_into_atomic_drafts(): void
    {
        $c = $this->validContract();
        $c['candidate_files'] = [
            'app/Demo/AaaService.php',
            'app/Demo/BbbService.php',
            'tests/Unit/Demo/AaaServiceTest.php',
            'tests/Unit/Demo/BbbServiceTest.php',
        ];
        $drafts = (new AtlasTaskFabricArchitectureContractCompiler)->compile($c);
        $this->assertCount(2, $drafts);
        $this->assertSame(['app/Demo/AaaService.php', 'tests/Unit/Demo/AaaServiceTest.php'], $drafts[0]['allowed_files']);
        $this->assertSame(['app/Demo/BbbService.php', 'tests/Unit/Demo/BbbServiceTest.php'], $drafts[1]['allowed_files']);
        $this->assertNotSame($drafts[0]['spec_hash'], $drafts[1]['spec_hash']);
    }

    public function test_spec_hash_is_byte_identical_for_same_input(): void
    {
        $c = new AtlasTaskFabricArchitectureContractCompiler;
        $a = $c->compile($this->validContract());
        $b = $c->compile($this->validContract());
        $this->assertSame($a[0]['spec_hash'], $b[0]['spec_hash']);
    }

    // ── task_constraints, evidence_floor, anti_proxy_clauses, dependency_hints ──

    public function test_draft_includes_all_new_enforced_fields(): void
    {
        $draft = (new AtlasTaskFabricArchitectureContractCompiler)->compile($this->validContract())[0];

        $this->assertArrayHasKey('task_constraints', $draft);
        $this->assertArrayHasKey('dependency_hints', $draft);
        $this->assertArrayHasKey('evidence_floor', $draft);
        $this->assertArrayHasKey('anti_proxy_clauses', $draft);
        $this->assertNotEmpty($draft['task_constraints']);
        $this->assertNotEmpty($draft['evidence_floor']);
        $this->assertNotEmpty($draft['anti_proxy_clauses']);
    }

    public function test_standard_risk_produces_min_refs_1(): void
    {
        $draft = (new AtlasTaskFabricArchitectureContractCompiler)->compile($this->validContract())[0];

        $this->assertSame(1, $draft['evidence_floor']['min_refs']);
        $this->assertContains('evidence_refs_min:1', $draft['task_constraints']);
        $this->assertNotContains('no_auto_merge', $draft['task_constraints']);
    }

    public function test_high_risk_produces_stricter_evidence_floor_and_no_auto_merge(): void
    {
        $c = $this->validContract();
        $c['risk_class'] = 'high';
        $draft = (new AtlasTaskFabricArchitectureContractCompiler)->compile($c)[0];

        $this->assertSame(2, $draft['evidence_floor']['min_refs']);
        $this->assertContains('evidence_refs_min:2', $draft['task_constraints']);
        $this->assertContains('no_auto_merge', $draft['task_constraints']);
    }

    public function test_critical_risk_produces_min_refs_3(): void
    {
        $c = $this->validContract();
        $c['risk_class'] = 'critical';
        $draft = (new AtlasTaskFabricArchitectureContractCompiler)->compile($c)[0];

        $this->assertSame(3, $draft['evidence_floor']['min_refs']);
        $this->assertContains('evidence_refs_min:3', $draft['task_constraints']);
        $this->assertContains('no_auto_merge', $draft['task_constraints']);
    }

    public function test_anti_proxy_clauses_forbid_all_proxy_kinds(): void
    {
        $draft = (new AtlasTaskFabricArchitectureContractCompiler)->compile($this->validContract())[0];

        foreach (AtlasTaskFabricArchitectureContractCompiler::ANTI_PROXY_KINDS as $kind) {
            $this->assertContains('forbidden_evidence_kind:'.$kind, $draft['anti_proxy_clauses']);
            $this->assertContains($kind, $draft['evidence_floor']['forbidden_kinds']);
        }
    }

    public function test_dependency_hints_from_contract_are_passed_through(): void
    {
        $c = $this->validContract();
        $c['dependency_hints'] = ['ARCH-10', 'ARCH-11'];
        $draft = (new AtlasTaskFabricArchitectureContractCompiler)->compile($c)[0];

        $this->assertSame(['ARCH-10', 'ARCH-11'], $draft['dependency_hints']);
    }

    public function test_dependency_hints_default_to_empty_when_not_provided(): void
    {
        $draft = (new AtlasTaskFabricArchitectureContractCompiler)->compile($this->validContract())[0];

        $this->assertSame([], $draft['dependency_hints']);
    }

    // ── AC2: architecture contract fields ──

    public function test_draft_includes_architecture_contract_fields(): void
    {
        $draft = (new AtlasTaskFabricArchitectureContractCompiler)->compile($this->validContract())[0];

        $this->assertArrayHasKey('boundary', $draft);
        $this->assertArrayHasKey('cohesion_target', $draft);
        $this->assertArrayHasKey('forbidden_coupling', $draft);
        $this->assertArrayHasKey('behavior_parity', $draft);
        $this->assertArrayHasKey('deletion_safety', $draft);
    }

    public function test_architecture_contract_fields_pass_through_from_input(): void
    {
        $c = $this->validContract();
        $c['boundary'] = 'domain/billing';
        $c['cohesion_target'] = ['BillingService'];
        $c['forbidden_coupling'] = ['PaymentGateway', 'NotificationService'];
        $c['behavior_parity'] = ['replay_tests_pass', 'output_identical'];
        $c['deletion_safety'] = ['no_downstream_calls_to_removed_interface'];

        $draft = (new AtlasTaskFabricArchitectureContractCompiler)->compile($c)[0];

        $this->assertSame('domain/billing', $draft['boundary']);
        $this->assertSame(['BillingService'], $draft['cohesion_target']);
        $this->assertContains('PaymentGateway', $draft['forbidden_coupling']);
        $this->assertContains('output_identical', $draft['behavior_parity']);
        $this->assertContains('no_downstream_calls_to_removed_interface', $draft['deletion_safety']);
    }

    // ── AC3: contract_incomplete for refactor tasks ──

    public function test_high_risk_without_behavior_parity_is_contract_incomplete(): void
    {
        $c = $this->validContract();
        $c['risk_class'] = 'high';
        $c['deletion_safety'] = ['rollback_verified'];

        $draft = (new AtlasTaskFabricArchitectureContractCompiler)->compile($c)[0];

        $this->assertTrue($draft['contract_incomplete']);
        $this->assertContains('missing_behavior_parity', $draft['contract_incomplete_reasons']);
    }

    public function test_high_risk_without_deletion_safety_is_contract_incomplete(): void
    {
        $c = $this->validContract();
        $c['risk_class'] = 'high';
        $c['behavior_parity'] = ['replay_matches'];

        $draft = (new AtlasTaskFabricArchitectureContractCompiler)->compile($c)[0];

        $this->assertTrue($draft['contract_incomplete']);
        $this->assertContains('missing_deletion_safety', $draft['contract_incomplete_reasons']);
    }

    public function test_high_risk_with_both_behavior_parity_and_deletion_safety_is_complete(): void
    {
        $c = $this->validContract();
        $c['risk_class'] = 'high';
        $c['behavior_parity'] = ['replay_matches'];
        $c['deletion_safety'] = ['rollback_verified'];

        $draft = (new AtlasTaskFabricArchitectureContractCompiler)->compile($c)[0];

        $this->assertFalse($draft['contract_incomplete']);
        $this->assertSame([], $draft['contract_incomplete_reasons']);
    }

    public function test_standard_risk_without_behavior_parity_is_not_incomplete(): void
    {
        $draft = (new AtlasTaskFabricArchitectureContractCompiler)->compile($this->validContract())[0];

        $this->assertFalse($draft['contract_incomplete']);
    }

    // ── AC4: muscle_contract_summary ──

    public function test_draft_has_muscle_contract_summary(): void
    {
        $draft = (new AtlasTaskFabricArchitectureContractCompiler)->compile($this->validContract())[0];

        $this->assertArrayHasKey('muscle_contract_summary', $draft);
        $this->assertNotEmpty($draft['muscle_contract_summary']);
        $this->assertStringContainsString('boundary=', $draft['muscle_contract_summary']);
        $this->assertStringContainsString('risk=', $draft['muscle_contract_summary']);
        $this->assertStringContainsString('rollback=', $draft['muscle_contract_summary']);
        $this->assertStringContainsString('contract_incomplete=', $draft['muscle_contract_summary']);
    }

    public function test_contract_incomplete_reflected_in_summary(): void
    {
        $c = $this->validContract();
        $c['risk_class'] = 'high';

        $draft = (new AtlasTaskFabricArchitectureContractCompiler)->compile($c)[0];

        $this->assertStringContainsString('contract_incomplete=yes', $draft['muscle_contract_summary']);
    }
}

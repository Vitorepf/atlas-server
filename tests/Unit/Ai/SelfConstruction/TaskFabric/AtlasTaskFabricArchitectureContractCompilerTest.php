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
        $this->expectExceptionMessageMatches('/empty evidence_seed/');
        (new AtlasTaskFabricArchitectureContractCompiler)->compile($c);
    }

    public function test_missing_owner_scope_throws(): void
    {
        $c = $this->validContract();
        $c['owner_scope'] = '';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing owner_scope/');
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
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricArchitectureContractCompiler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AtlasTaskFabricArchitectureContractCompilerTest extends TestCase
{
    private function validContract(array $overrides = []): array
    {
        return array_merge([
            'contract_id' => 'c1',
            'owner_scope' => 'atlas-native',
            'capability_gap' => 'compile task specs',
            'candidate_files' => ['app/Demo/Foo.php', 'tests/Unit/Demo/FooTest.php'],
            'acceptance_seed' => ['phpunit green'],
            'evidence_seed' => ['test_run_id'],
            'risk_class' => 'standard',
        ], $overrides);
    }

    // ── required field failures ──────────────────────────────────────────────────

    public function test_missing_contract_id_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing-field:contract_id');
        (new AtlasTaskFabricArchitectureContractCompiler)->compile($this->validContract(['contract_id' => '']));
    }

    public function test_missing_owner_scope_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing-field:owner_scope');
        (new AtlasTaskFabricArchitectureContractCompiler)->compile($this->validContract(['owner_scope' => '']));
    }

    public function test_missing_capability_gap_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing-field:capability_gap');
        (new AtlasTaskFabricArchitectureContractCompiler)->compile($this->validContract(['capability_gap' => '']));
    }

    public function test_missing_acceptance_seed_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing-field:acceptance_seed');
        (new AtlasTaskFabricArchitectureContractCompiler)->compile($this->validContract(['acceptance_seed' => []]));
    }

    public function test_missing_evidence_seed_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing-field:evidence_seed');
        (new AtlasTaskFabricArchitectureContractCompiler)->compile($this->validContract(['evidence_seed' => []]));
    }

    // ── broad directory rejection ────────────────────────────────────────────────

    public function test_candidate_file_ending_in_slash_is_rejected_as_broad_directory(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('broad directory rejected');
        (new AtlasTaskFabricArchitectureContractCompiler)->compile($this->validContract(['candidate_files' => ['app/Demo/']]));
    }

    public function test_candidate_file_without_extension_is_rejected_as_broad_directory(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('broad directory rejected');
        (new AtlasTaskFabricArchitectureContractCompiler)->compile($this->validContract(['candidate_files' => ['app/Demo/Foo']]));
    }

    // ── forbidden ownership phrase rejection ─────────────────────────────────────

    public function test_capability_gap_mentioning_external_provider_owns_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('conflicts with Atlas-native ownership');
        (new AtlasTaskFabricArchitectureContractCompiler)->compile(
            $this->validContract(['capability_gap' => 'external provider owns the release pipeline']),
        );
    }

    // ── impl+test pairing ─────────────────────────────────────────────────────────

    public function test_impl_file_is_paired_with_its_adjacent_test_file(): void
    {
        $drafts = (new AtlasTaskFabricArchitectureContractCompiler)->compile($this->validContract());

        self::assertCount(1, $drafts);
        self::assertSame(['app/Demo/Foo.php', 'tests/Unit/Demo/FooTest.php'], $drafts[0]['allowed_files']);
        self::assertSame(['app/Demo/Foo.php'], $drafts[0]['scope_in']);
    }

    public function test_multiple_impl_files_each_get_their_own_draft(): void
    {
        $drafts = (new AtlasTaskFabricArchitectureContractCompiler)->compile($this->validContract([
            'candidate_files' => [
                'app/Demo/Foo.php',
                'app/Demo/Bar.php',
                'tests/Unit/Demo/FooTest.php',
                'tests/Unit/Demo/BarTest.php',
            ],
        ]));

        self::assertCount(2, $drafts);
        self::assertSame('c1-p1', $drafts[0]['id']);
        self::assertSame('c1-p2', $drafts[1]['id']);
    }

    public function test_impl_file_without_matching_test_gets_a_draft_with_only_itself(): void
    {
        $drafts = (new AtlasTaskFabricArchitectureContractCompiler)->compile($this->validContract([
            'candidate_files' => ['app/Demo/Orphan.php'],
        ]));

        self::assertSame(['app/Demo/Orphan.php'], $drafts[0]['allowed_files']);
    }

    // ── tests-only fallback draft ────────────────────────────────────────────────

    public function test_tests_only_candidate_files_produce_a_single_tests_only_draft(): void
    {
        $drafts = (new AtlasTaskFabricArchitectureContractCompiler)->compile($this->validContract([
            'candidate_files' => ['tests/Unit/Demo/FooTest.php'],
        ]));

        self::assertCount(1, $drafts);
        self::assertSame('c1-tests', $drafts[0]['id']);
        self::assertSame(['tests/Unit/Demo/FooTest.php'], $drafts[0]['allowed_files']);
    }

    // ── risk_class evidence floor and no_auto_merge constraints ─────────────────

    public function test_standard_risk_requires_only_one_evidence_ref_and_no_auto_merge_constraint(): void
    {
        $draft = (new AtlasTaskFabricArchitectureContractCompiler)->compile($this->validContract())[0];

        self::assertSame(1, $draft['evidence_floor']['min_refs']);
        self::assertNotContains('no_auto_merge', $draft['task_constraints']);
    }

    public function test_high_risk_requires_two_evidence_refs_and_no_auto_merge_constraint(): void
    {
        $draft = (new AtlasTaskFabricArchitectureContractCompiler)->compile($this->validContract(['risk_class' => 'high']))[0];

        self::assertSame(2, $draft['evidence_floor']['min_refs']);
        self::assertContains('no_auto_merge', $draft['task_constraints']);
    }

    public function test_critical_risk_requires_three_evidence_refs_and_no_auto_merge_constraint(): void
    {
        $draft = (new AtlasTaskFabricArchitectureContractCompiler)->compile($this->validContract(['risk_class' => 'critical']))[0];

        self::assertSame(3, $draft['evidence_floor']['min_refs']);
        self::assertContains('no_auto_merge', $draft['task_constraints']);
    }

    // ── dependency_hints preservation ────────────────────────────────────────────

    public function test_dependency_hints_are_preserved_verbatim_into_the_draft(): void
    {
        $draft = (new AtlasTaskFabricArchitectureContractCompiler)->compile(
            $this->validContract(['dependency_hints' => ['c0-p1', 'c0-p2']]),
        )[0];

        self::assertSame(['c0-p1', 'c0-p2'], $draft['dependency_hints']);
    }

    public function test_dependency_hints_default_to_empty_when_absent(): void
    {
        $draft = (new AtlasTaskFabricArchitectureContractCompiler)->compile($this->validContract())[0];

        self::assertSame([], $draft['dependency_hints']);
    }

    // ── anti_proxy_clauses ────────────────────────────────────────────────────────

    public function test_anti_proxy_clauses_cover_every_anti_proxy_kind(): void
    {
        $draft = (new AtlasTaskFabricArchitectureContractCompiler)->compile($this->validContract())[0];

        foreach (AtlasTaskFabricArchitectureContractCompiler::ANTI_PROXY_KINDS as $kind) {
            self::assertContains('forbidden_evidence_kind:'.$kind, $draft['anti_proxy_clauses']);
        }
    }

    // ── deterministic spec_hash ───────────────────────────────────────────────────

    public function test_spec_hash_is_deterministic_for_identical_input(): void
    {
        $compiler = new AtlasTaskFabricArchitectureContractCompiler;
        $a = $compiler->compile($this->validContract())[0]['spec_hash'];
        $b = $compiler->compile($this->validContract())[0]['spec_hash'];

        self::assertSame($a, $b);
        self::assertSame(64, strlen($a));
    }

    public function test_spec_hash_changes_when_risk_class_changes(): void
    {
        $compiler = new AtlasTaskFabricArchitectureContractCompiler;
        $a = $compiler->compile($this->validContract(['risk_class' => 'standard']))[0]['spec_hash'];
        $b = $compiler->compile($this->validContract(['risk_class' => 'high']))[0]['spec_hash'];

        self::assertNotSame($a, $b);
    }
}

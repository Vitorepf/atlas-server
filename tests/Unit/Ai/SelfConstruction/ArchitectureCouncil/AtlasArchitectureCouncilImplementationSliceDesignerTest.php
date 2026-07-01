<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ArchitectureCouncil;

use App\Services\Ai\SelfConstruction\ArchitectureCouncil\AtlasArchitectureCouncilImplementationSliceDesigner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves AtlasArchitectureCouncilImplementationSliceDesigner: a valid input yields atomic slice briefs;
 * an unaccepted critique throws; empty invariants throws; any forbidden_edge throws; a capability_gap
 * with both service + cli + matching tests splits into two slices (paired with the right tests).
 */
final class AtlasArchitectureCouncilImplementationSliceDesignerTest extends TestCase
{
    private function validFacts(): array
    {
        return [
            'critique' => ['accepted' => true],
            'invariants' => ['no_scoring'],
            'boundary_map' => ['forbidden_edges' => []],
            'capability_gap' => [
                'organ' => 'TaskFabric',
                'capability' => 'compile_contract',
                'target_files' => [
                    ['kind' => 'service', 'path' => 'app/Demo/Compiler.php'],
                    ['kind' => 'test', 'path' => 'tests/Unit/Demo/CompilerTest.php'],
                ],
                'acceptance_seed' => ['phpunit green'],
                'evidence_seed' => ['test_run_id'],
            ],
        ];
    }

    public function test_valid_input_yields_one_slice_brief_paired_with_matching_test(): void
    {
        $r = (new AtlasArchitectureCouncilImplementationSliceDesigner)->design($this->validFacts());
        $this->assertCount(1, $r['slice_briefs']);
        $brief = $r['slice_briefs'][0];
        $this->assertSame('Compiler', $brief['target_class']);
        $this->assertSame('CompilerTest', $brief['test_class']);
        $this->assertSame(['app/Demo/Compiler.php', 'tests/Unit/Demo/CompilerTest.php'], $brief['allowed_files_hint']);
        $this->assertTrue($brief['claimable']);
        $this->assertSame([], $r['rejected_slices']);
        $this->assertSame(['test_run_id'], $brief['evidence_seed']);
    }

    public function test_analysis_only_acceptance_is_rejected_not_emitted_as_claimable(): void
    {
        $facts = $this->validFacts();
        $facts['capability_gap']['acceptance_seed'] = ['architecture is sound and well documented'];

        $r = (new AtlasArchitectureCouncilImplementationSliceDesigner)->design($facts);

        $this->assertSame([], $r['slice_briefs']);
        $this->assertNotEmpty($r['rejected_slices']);
        $this->assertSame(
            'analysis_only_no_runnable_acceptance',
            $r['rejected_slices'][0]['rejection_reason'],
        );
    }

    public function test_blocked_when_critique_not_accepted(): void
    {
        $f = $this->validFacts();
        $f['critique']['accepted'] = false;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/critique not accepted/');
        (new AtlasArchitectureCouncilImplementationSliceDesigner)->design($f);
    }

    public function test_blocked_when_invariants_empty(): void
    {
        $f = $this->validFacts();
        $f['invariants'] = [];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/empty invariants/');
        (new AtlasArchitectureCouncilImplementationSliceDesigner)->design($f);
    }

    public function test_blocked_when_boundary_map_has_forbidden_edge(): void
    {
        $f = $this->validFacts();
        $f['boundary_map']['forbidden_edges'] = [['edge' => ['from' => 'X', 'to' => 'Y', 'action' => 'z'], 'reason' => 'r']];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/forbidden_edges/');
        (new AtlasArchitectureCouncilImplementationSliceDesigner)->design($f);
    }

    public function test_split_into_service_plus_cli_slices_with_matching_tests(): void
    {
        $f = $this->validFacts();
        $f['capability_gap']['target_files'] = [
            ['kind' => 'service', 'path' => 'app/Demo/Compiler.php'],
            ['kind' => 'cli', 'path' => 'app/Console/Commands/CompilerCli.php'],
            ['kind' => 'test', 'path' => 'tests/Unit/Demo/CompilerTest.php'],
            ['kind' => 'test', 'path' => 'tests/Feature/CompilerCliTest.php'],
        ];
        $r = (new AtlasArchitectureCouncilImplementationSliceDesigner)->design($f);
        $this->assertCount(2, $r['slice_briefs']);
        $byTarget = [];
        foreach ($r['slice_briefs'] as $b) {
            $byTarget[$b['target_class']] = $b;
        }
        $this->assertSame('CompilerTest', $byTarget['Compiler']['test_class']);
        $this->assertSame('CompilerCliTest', $byTarget['CompilerCli']['test_class']);
    }

    public function test_missing_service_file_in_capability_gap_throws(): void
    {
        $f = $this->validFacts();
        $f['capability_gap']['target_files'] = [['kind' => 'test', 'path' => 'tests/Unit/Demo/OnlyTest.php']];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/lacks any service\/cli file/');
        (new AtlasArchitectureCouncilImplementationSliceDesigner)->design($f);
    }

    public function test_service_without_matching_test_is_surfaced_in_missing_test_pairs(): void
    {
        $f = $this->validFacts();
        $f['capability_gap']['target_files'] = [
            ['kind' => 'service', 'path' => 'app/Demo/Orphan.php'],
        ];
        $r = (new AtlasArchitectureCouncilImplementationSliceDesigner)->design($f);

        $this->assertSame([], $r['slice_briefs'], 'unpaired service must not appear in slice_briefs');
        $this->assertSame(['app/Demo/Orphan.php'], $r['missing_test_pairs']);
    }

    public function test_accepted_slice_briefs_include_only_implementation_and_test_paired_files(): void
    {
        $f = $this->validFacts();
        $f['capability_gap']['target_files'] = [
            ['kind' => 'service', 'path' => 'app/Demo/Compiler.php'],
            ['kind' => 'service', 'path' => 'app/Demo/Linker.php'],
            ['kind' => 'test', 'path' => 'tests/Unit/Demo/CompilerTest.php'],
            // Linker has no matching test → goes to missing_test_pairs only
        ];
        $r = (new AtlasArchitectureCouncilImplementationSliceDesigner)->design($f);

        $this->assertCount(1, $r['slice_briefs']);
        $brief = $r['slice_briefs'][0];
        $this->assertNotNull($brief['test_class'], 'every accepted brief must have a paired test_class');
        $this->assertCount(2, $brief['allowed_files_hint'], 'allowed_files_hint must contain exactly impl + test');
        $this->assertSame(['app/Demo/Linker.php'], $r['missing_test_pairs']);
    }

    public function test_slice_briefs_ordering_is_deterministic_by_slice_id(): void
    {
        $f = $this->validFacts();
        $f['capability_gap']['organ'] = 'Fabric';
        $f['capability_gap']['capability'] = 'build';
        $f['capability_gap']['target_files'] = [
            ['kind' => 'service', 'path' => 'app/Demo/Zeta.php'],
            ['kind' => 'service', 'path' => 'app/Demo/Alpha.php'],
            ['kind' => 'test', 'path' => 'tests/Unit/Demo/ZetaTest.php'],
            ['kind' => 'test', 'path' => 'tests/Unit/Demo/AlphaTest.php'],
        ];
        $r = (new AtlasArchitectureCouncilImplementationSliceDesigner)->design($f);

        $ids = array_column($r['slice_briefs'], 'slice_id');
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids, 'slice_briefs must be sorted by slice_id');
    }

    // ── AC: exact_files / behavior_proof / rollback_note / dependency_order / risk / minimality_reason ──

    public function test_simple_slice_includes_all_new_fields(): void
    {
        $r = (new AtlasArchitectureCouncilImplementationSliceDesigner)->design($this->validFacts());
        $brief = $r['slice_briefs'][0];

        foreach (['exact_files', 'behavior_proof', 'rollback_note', 'dependency_order', 'risk', 'minimality_reason'] as $key) {
            $this->assertArrayHasKey($key, $brief, "missing {$key}");
        }
        $this->assertSame(['app/Demo/Compiler.php', 'tests/Unit/Demo/CompilerTest.php'], $brief['exact_files']);
        $this->assertSame('phpunit green', $brief['behavior_proof']);
        $this->assertSame(1, $brief['dependency_order']);
    }

    public function test_multi_step_dependency_assigns_sequential_dependency_order(): void
    {
        $f = $this->validFacts();
        $f['capability_gap']['organ'] = 'Fabric';
        $f['capability_gap']['capability'] = 'build';
        $f['capability_gap']['target_files'] = [
            ['kind' => 'service', 'path' => 'app/Demo/Zeta.php'],
            ['kind' => 'service', 'path' => 'app/Demo/Alpha.php'],
            ['kind' => 'test', 'path' => 'tests/Unit/Demo/ZetaTest.php'],
            ['kind' => 'test', 'path' => 'tests/Unit/Demo/AlphaTest.php'],
        ];
        $r = (new AtlasArchitectureCouncilImplementationSliceDesigner)->design($f);

        $orders = array_column($r['slice_briefs'], 'dependency_order');
        $this->assertSame([1, 2], $orders);
    }

    public function test_over_broad_request_narrows_to_exactly_impl_plus_test_files(): void
    {
        $f = $this->validFacts();
        $f['capability_gap']['target_files'] = [
            ['kind' => 'service', 'path' => 'app/Demo/Compiler.php'],
            ['kind' => 'test', 'path' => 'tests/Unit/Demo/CompilerTest.php'],
            ['kind' => 'test', 'path' => 'tests/Unit/Demo/UnrelatedTest.php'],
        ];
        $r = (new AtlasArchitectureCouncilImplementationSliceDesigner)->design($f);

        $this->assertCount(2, $r['slice_briefs'][0]['exact_files'], 'over-broad target_files must narrow to exactly impl+test');
        $this->assertStringContainsString('no broader scope', $r['slice_briefs'][0]['minimality_reason']);
    }

    public function test_missing_proof_blocker_yields_empty_behavior_proof_in_rejected_slices(): void
    {
        $f = $this->validFacts();
        $f['capability_gap']['acceptance_seed'] = ['architecture is sound and well documented'];

        $r = (new AtlasArchitectureCouncilImplementationSliceDesigner)->design($f);

        $this->assertSame([], $r['slice_briefs']);
        $this->assertNotEmpty($r['rejected_slices']);
    }
}

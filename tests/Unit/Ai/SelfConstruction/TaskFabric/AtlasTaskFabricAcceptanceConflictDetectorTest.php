<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricAcceptanceConflictDetector;
use Tests\TestCase;

final class AtlasTaskFabricAcceptanceConflictDetectorTest extends TestCase
{
    private function detector(): AtlasTaskFabricAcceptanceConflictDetector
    {
        return new AtlasTaskFabricAcceptanceConflictDetector();
    }

    private function cleanSpec(array $overrides = []): array
    {
        return array_merge([
            'acceptance_criteria' => ['the gate emits accept status when conditions pass'],
            'allowed_files'       => ['app/Services/Ai/SelfConstruction/TaskFabric/AtlasGate.php'],
            'scope_in'            => [],
            'test_commands'       => ['./vendor/bin/phpunit tests/Unit/SomeTest.php --no-coverage'],
        ], $overrides);
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->detector()->detect([]);

        $this->assertSame(AtlasTaskFabricAcceptanceConflictDetector::SCHEMA, $result['schema']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->detector()->detect($this->cleanSpec());

        foreach (['schema', 'conflict_level', 'conflicts', 'reasons'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    public function test_each_conflict_has_kind_and_detail(): void
    {
        // Trigger a conflict so we can inspect its structure.
        $result = $this->detector()->detect($this->cleanSpec(['test_commands' => []]));

        $this->assertNotEmpty($result['conflicts']);
        foreach ($result['conflicts'] as $c) {
            $this->assertArrayHasKey('kind', $c);
            $this->assertArrayHasKey('detail', $c);
        }
    }

    // ── level: none ───────────────────────────────────────────────────────────

    public function test_clean_spec_yields_none(): void
    {
        $result = $this->detector()->detect($this->cleanSpec());

        $this->assertSame(AtlasTaskFabricAcceptanceConflictDetector::LEVEL_NONE, $result['conflict_level']);
        $this->assertSame([], $result['conflicts']);
    }

    // ── mutually_exclusive_behavior ───────────────────────────────────────────

    public function test_contradictory_criteria_yield_blocking(): void
    {
        $spec = $this->cleanSpec([
            'acceptance_criteria' => [
                'the gate emits accept status',
                'the gate does not emit accept status',
            ],
        ]);

        $result = $this->detector()->detect($spec);

        $this->assertSame(AtlasTaskFabricAcceptanceConflictDetector::LEVEL_BLOCKING, $result['conflict_level']);
        $kinds = array_column($result['conflicts'], 'kind');
        $this->assertContains(AtlasTaskFabricAcceptanceConflictDetector::KIND_MUTUALLY_EXCLUSIVE, $kinds);
    }

    public function test_negation_never_also_triggers_mutually_exclusive(): void
    {
        $spec = $this->cleanSpec([
            'acceptance_criteria' => [
                'the gate emits accept status',
                'the gate never emits accept status',
            ],
        ]);

        $result = $this->detector()->detect($spec);

        $this->assertSame(AtlasTaskFabricAcceptanceConflictDetector::LEVEL_BLOCKING, $result['conflict_level']);
    }

    public function test_complementary_criteria_for_different_conditions_do_not_conflict(): void
    {
        // "accepts when valid" + "rejects when invalid" — different objects, not contradictory.
        $spec = $this->cleanSpec([
            'acceptance_criteria' => [
                'the gate emits accept status when input is valid',
                'the gate emits reject status when input is invalid',
            ],
        ]);

        $result = $this->detector()->detect($spec);

        $mutuallyExclusive = array_filter(
            $result['conflicts'],
            fn (array $c): bool => $c['kind'] === AtlasTaskFabricAcceptanceConflictDetector::KIND_MUTUALLY_EXCLUSIVE,
        );
        $this->assertEmpty($mutuallyExclusive, 'complementary criteria must not be flagged as mutually exclusive');
    }

    public function test_positive_and_negative_criteria_on_different_conditions_are_fine(): void
    {
        $spec = $this->cleanSpec([
            'acceptance_criteria' => [
                'returns accept when batch has value',
                'does not return accept when batch is empty',
            ],
        ]);

        $result = $this->detector()->detect($spec);

        $mutuallyExclusive = array_filter(
            $result['conflicts'],
            fn (array $c): bool => $c['kind'] === AtlasTaskFabricAcceptanceConflictDetector::KIND_MUTUALLY_EXCLUSIVE,
        );
        $this->assertEmpty($mutuallyExclusive);
    }

    // ── out_of_scope_file ─────────────────────────────────────────────────────

    public function test_php_file_outside_allowed_files_yields_blocking(): void
    {
        $spec = $this->cleanSpec([
            'acceptance_criteria' => ['must update app/Services/OtherService.php to pass'],
            'allowed_files'       => ['app/Services/Ai/SelfConstruction/TaskFabric/AtlasGate.php'],
        ]);

        $result = $this->detector()->detect($spec);

        $this->assertSame(AtlasTaskFabricAcceptanceConflictDetector::LEVEL_BLOCKING, $result['conflict_level']);
        $kinds = array_column($result['conflicts'], 'kind');
        $this->assertContains(AtlasTaskFabricAcceptanceConflictDetector::KIND_OUT_OF_SCOPE_FILE, $kinds);
    }

    public function test_php_file_that_is_in_allowed_files_does_not_conflict(): void
    {
        $spec = $this->cleanSpec([
            'acceptance_criteria' => ['must update app/Services/Ai/SelfConstruction/TaskFabric/AtlasGate.php'],
            'allowed_files'       => ['app/Services/Ai/SelfConstruction/TaskFabric/AtlasGate.php'],
        ]);

        $result = $this->detector()->detect($spec);

        $outOfScope = array_filter(
            $result['conflicts'],
            fn (array $c): bool => $c['kind'] === AtlasTaskFabricAcceptanceConflictDetector::KIND_OUT_OF_SCOPE_FILE,
        );
        $this->assertEmpty($outOfScope);
    }

    public function test_no_allowed_files_skips_out_of_scope_check(): void
    {
        // When allowed_files is empty the check is skipped (no constraint).
        $spec = $this->cleanSpec([
            'acceptance_criteria' => ['must update app/Services/SomeService.php'],
            'allowed_files'       => [],
        ]);

        $result = $this->detector()->detect($spec);

        $outOfScope = array_filter(
            $result['conflicts'],
            fn (array $c): bool => $c['kind'] === AtlasTaskFabricAcceptanceConflictDetector::KIND_OUT_OF_SCOPE_FILE,
        );
        $this->assertEmpty($outOfScope);
    }

    // ── schema_missing ────────────────────────────────────────────────────────

    public function test_atlas_class_in_criteria_not_in_scope_in_yields_schema_missing_warning(): void
    {
        $spec = $this->cleanSpec([
            'acceptance_criteria' => ['the AtlasTaskFabricValidator must process the input'],
            'scope_in'            => ['AtlasTaskFabricGate'],  // validator not listed
        ]);

        $result = $this->detector()->detect($spec);

        $kinds = array_column($result['conflicts'], 'kind');
        $this->assertContains(AtlasTaskFabricAcceptanceConflictDetector::KIND_SCHEMA_MISSING, $kinds);
        $this->assertSame(AtlasTaskFabricAcceptanceConflictDetector::LEVEL_WARNING, $result['conflict_level']);
    }

    public function test_atlas_class_present_in_scope_in_does_not_trigger_schema_missing(): void
    {
        $spec = $this->cleanSpec([
            'acceptance_criteria' => ['the AtlasTaskFabricGate must process the input'],
            'scope_in'            => ['AtlasTaskFabricGate'],
        ]);

        $result = $this->detector()->detect($spec);

        $kinds = array_column($result['conflicts'], 'kind');
        $this->assertNotContains(AtlasTaskFabricAcceptanceConflictDetector::KIND_SCHEMA_MISSING, $kinds);
    }

    public function test_empty_scope_in_skips_schema_check(): void
    {
        $spec = $this->cleanSpec([
            'acceptance_criteria' => ['the AtlasTaskFabricBigValidator must do something'],
            'scope_in'            => [],
        ]);

        $result = $this->detector()->detect($spec);

        $kinds = array_column($result['conflicts'], 'kind');
        $this->assertNotContains(AtlasTaskFabricAcceptanceConflictDetector::KIND_SCHEMA_MISSING, $kinds);
    }

    // ── weak_runnable_proof ───────────────────────────────────────────────────

    public function test_no_test_commands_yields_weak_runnable_proof_warning(): void
    {
        $spec = $this->cleanSpec(['test_commands' => []]);

        $result = $this->detector()->detect($spec);

        $kinds = array_column($result['conflicts'], 'kind');
        $this->assertContains(AtlasTaskFabricAcceptanceConflictDetector::KIND_WEAK_RUNNABLE_PROOF, $kinds);
        $this->assertSame(AtlasTaskFabricAcceptanceConflictDetector::LEVEL_WARNING, $result['conflict_level']);
    }

    public function test_non_test_runner_command_yields_weak_runnable_proof_warning(): void
    {
        $spec = $this->cleanSpec(['test_commands' => ['echo "test passed"']]);

        $result = $this->detector()->detect($spec);

        $kinds = array_column($result['conflicts'], 'kind');
        $this->assertContains(AtlasTaskFabricAcceptanceConflictDetector::KIND_WEAK_RUNNABLE_PROOF, $kinds);
    }

    public function test_phpunit_command_does_not_trigger_weak_proof(): void
    {
        $spec = $this->cleanSpec([
            'test_commands' => ['./vendor/bin/phpunit tests/Unit/SomeTest.php --no-coverage'],
        ]);

        $result = $this->detector()->detect($spec);

        $weakProof = array_filter(
            $result['conflicts'],
            fn (array $c): bool => $c['kind'] === AtlasTaskFabricAcceptanceConflictDetector::KIND_WEAK_RUNNABLE_PROOF,
        );
        $this->assertEmpty($weakProof);
    }

    public function test_artisan_test_command_does_not_trigger_weak_proof(): void
    {
        $spec = $this->cleanSpec([
            'test_commands' => ['/opt/homebrew/bin/php artisan test --filter=SomeTest'],
        ]);

        $result = $this->detector()->detect($spec);

        $weakProof = array_filter(
            $result['conflicts'],
            fn (array $c): bool => $c['kind'] === AtlasTaskFabricAcceptanceConflictDetector::KIND_WEAK_RUNNABLE_PROOF,
        );
        $this->assertEmpty($weakProof);
    }

    // ── level precedence ──────────────────────────────────────────────────────

    public function test_blocking_trumps_warning_when_both_present(): void
    {
        // Mutually exclusive (→ blocking) + no test commands (→ warning)
        $spec = [
            'acceptance_criteria' => [
                'the gate emits accept status',
                'the gate never emits accept status',
            ],
            'allowed_files'  => [],
            'scope_in'       => [],
            'test_commands'  => [],  // weak proof warning
        ];

        $result = $this->detector()->detect($spec);

        $this->assertSame(AtlasTaskFabricAcceptanceConflictDetector::LEVEL_BLOCKING, $result['conflict_level']);
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $spec = $this->cleanSpec([
            'acceptance_criteria' => [
                'the gate emits accept status',
                'the gate does not emit accept status',
            ],
        ]);

        $this->assertSame(
            $this->detector()->detect($spec),
            $this->detector()->detect($spec),
        );
    }
}

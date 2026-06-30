<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainHighValueBatchComposer;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainHighValueBatchComposerTest extends TestCase
{
    private function composer(): AtlasExternalBrainHighValueBatchComposer
    {
        return new AtlasExternalBrainHighValueBatchComposer;
    }

    /** Standard (non-thin) opportunity — 2 acceptance criteria so isThin() returns false. */
    private function valid(string $label, array $overrides = []): array
    {
        return array_merge([
            'label'               => $label,
            'objective'           => 'Implement '.$label,
            'category'            => 'bug_fix',
            'allowed_files'       => ['app/Services/'.$label.'.php', 'tests/Unit/'.$label.'Test.php'],
            'acceptance_criteria' => [
                'test passes: php artisan test --filter='.$label,
                'implementation notes included in evidence',
            ],
            'required_evidence'   => ['tests_or_gates_result'],
            'value_mechanism'     => 'fixes_recurring_bug:'.$label,
            'final_score'         => 0.6,
        ], $overrides);
    }

    /** Thin opportunity — exactly 1 acceptance criterion so isThin() returns true. */
    private function thin(string $label, array $overrides = []): array
    {
        return array_merge([
            'label'               => $label,
            'objective'           => 'Thin task '.$label,
            'category'            => 'test_gate',
            'allowed_files'       => ['app/Services/'.$label.'.php', 'tests/Unit/'.$label.'Test.php'],
            'acceptance_criteria' => ['test passes: php artisan test --filter='.$label],
            'required_evidence'   => ['tests_or_gates_result'],
            'value_mechanism'     => 'adds_gate:'.$label,
            'final_score'         => 0.4,
        ], $overrides);
    }

    public function test_schema_constant(): void
    {
        $this->assertSame(
            'atlas.external_brain.high_value_batch_composer.v1',
            AtlasExternalBrainHighValueBatchComposer::SCHEMA,
        );
    }

    public function test_multi_file_capability_task_is_emitted_with_dependency_wave(): void
    {
        $composer = $this->composer();
        $opp = $this->valid('capability-task', [
            'category'      => 'architecture_unlock',
            'allowed_files' => ['app/Services/Foo.php', 'app/Services/Bar.php', 'tests/Unit/FooTest.php'],
        ]);

        $result = $composer->compose([$opp]);

        $this->assertCount(1, $result['emitted']);
        $this->assertCount(0, $result['rejected']);

        $packet = $result['emitted'][0];
        $this->assertSame('capability-task', $packet['task_packet_id']);
        $this->assertSame(1, $packet['dependency_wave']);  // architecture_unlock → wave 1
        $this->assertCount(3, $packet['allowed_files']);
        $this->assertNotEmpty($packet['acceptance_criteria']);
        $this->assertNotEmpty($packet['required_evidence']);
        $this->assertNotEmpty($packet['value_mechanism']);
    }

    public function test_isolated_thin_microtask_is_rejected(): void
    {
        $composer = $this->composer();
        $result   = $composer->compose([$this->thin('lone-thin-1')]);

        $this->assertCount(0, $result['emitted']);
        $this->assertCount(1, $result['rejected']);
        $this->assertSame('thin_microtask_no_grouping_partner', $result['rejected'][0]['reason']);
        $this->assertSame('lone-thin-1', $result['rejected'][0]['label']);
    }

    public function test_two_thin_microtasks_same_category_are_grouped(): void
    {
        $composer = $this->composer();
        $result   = $composer->compose([
            $this->thin('thin-a', ['category' => 'test_gate']),
            $this->thin('thin-b', ['category' => 'test_gate']),
        ]);

        $this->assertCount(1, $result['emitted']);
        $this->assertCount(0, $result['rejected']);
        $this->assertCount(1, $result['grouped']);

        $packet = $result['emitted'][0];
        $this->assertCount(2, $packet['grouped_from']);
        $this->assertContains('thin-a', $packet['grouped_from']);
        $this->assertContains('thin-b', $packet['grouped_from']);
        $this->assertCount(4, $packet['allowed_files']);  // 2 impl + 2 test (unique)
        $this->assertCount(2, $packet['acceptance_criteria']);
    }

    public function test_thin_tasks_in_different_categories_are_not_merged(): void
    {
        $composer = $this->composer();
        $result   = $composer->compose([
            $this->thin('thin-gate-1', ['category' => 'test_gate']),
            $this->thin('thin-bug-1',  ['category' => 'bug_fix']),
        ]);

        // Each is alone in its category → both rejected.
        $this->assertCount(0, $result['emitted']);
        $this->assertCount(2, $result['rejected']);
        $reasons = array_column($result['rejected'], 'reason');
        $this->assertContains('thin_microtask_no_grouping_partner', $reasons);
    }

    public function test_opportunity_missing_objective_is_rejected(): void
    {
        $composer = $this->composer();
        $opp = $this->valid('no-objective');
        unset($opp['objective']);

        $result = $composer->compose([$opp]);

        $this->assertCount(0, $result['emitted']);
        $this->assertCount(1, $result['rejected']);
        $this->assertSame('missing_required_fields', $result['rejected'][0]['reason']);
        $this->assertStringContainsString('objective', $result['rejected'][0]['detail']);
    }

    public function test_opportunity_missing_allowed_files_is_rejected(): void
    {
        $composer = $this->composer();
        $opp = $this->valid('no-files', ['allowed_files' => []]);

        $result = $composer->compose([$opp]);

        $this->assertCount(0, $result['emitted']);
        $this->assertSame('missing_required_fields', $result['rejected'][0]['reason']);
        $this->assertStringContainsString('allowed_files', $result['rejected'][0]['detail']);
    }

    public function test_opportunity_missing_value_mechanism_is_rejected(): void
    {
        $composer = $this->composer();
        $opp = $this->valid('no-value', ['value_mechanism' => '']);

        $result = $composer->compose([$opp]);

        $this->assertCount(0, $result['emitted']);
        $this->assertStringContainsString('value_mechanism', $result['rejected'][0]['detail']);
    }

    public function test_allowed_files_collision_is_rejected(): void
    {
        $composer = $this->composer();
        $sharedFile = 'app/Services/Shared.php';

        $first  = $this->valid('first',  ['allowed_files' => [$sharedFile, 'tests/FirstTest.php']]);
        $second = $this->valid('second', ['allowed_files' => [$sharedFile, 'tests/SecondTest.php']]);

        $result = $composer->compose([$first, $second]);

        $this->assertCount(1, $result['emitted']);
        $this->assertSame('first', $result['emitted'][0]['task_packet_id']);

        $this->assertCount(1, $result['rejected']);
        $this->assertSame('second', $result['rejected'][0]['label']);
        $this->assertSame('allowed_files_collision', $result['rejected'][0]['reason']);
        $this->assertStringContainsString($sharedFile, $result['rejected'][0]['detail']);
    }

    public function test_dependency_wave_assignment_by_category(): void
    {
        $composer = $this->composer();
        $result   = $composer->compose([
            $this->valid('arch',    ['category' => 'architecture_unlock']),
            $this->valid('bug',     ['category' => 'bug_fix']),
            $this->valid('docs',    ['category' => 'docs_sync']),
        ]);

        $waves = array_column($result['emitted'], 'dependency_wave', 'task_packet_id');
        $this->assertSame(1, $waves['arch']);
        $this->assertSame(2, $waves['bug']);
        $this->assertSame(3, $waves['docs']);
    }

    public function test_batch_cap_is_respected(): void
    {
        $composer = $this->composer();
        $opps = array_map(
            fn (int $i): array => $this->valid("task-{$i}", ['allowed_files' => ["app/Services/Task{$i}.php", "tests/Task{$i}Test.php"]]),
            range(1, 5),
        );

        $result = $composer->compose($opps, ['max_batch' => 3]);

        $this->assertCount(3, $result['emitted']);
        $this->assertCount(2, array_filter($result['rejected'], static fn (array $r): bool => $r['reason'] === 'batch_cap_reached'));
    }

    public function test_output_has_canonical_keys(): void
    {
        $result = $this->composer()->compose([$this->valid('canonical-test')]);

        foreach (['schema', 'emitted', 'grouped', 'rejected', 'stats'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        $this->assertSame(AtlasExternalBrainHighValueBatchComposer::SCHEMA, $result['schema']);
        $this->assertArrayHasKey('emitted_count', $result['stats']);
        $this->assertArrayHasKey('rejected_count', $result['stats']);
    }

    public function test_emitted_packet_has_all_required_fields(): void
    {
        $result = $this->composer()->compose([$this->valid('packet-fields')]);
        $packet = $result['emitted'][0];

        foreach (['task_packet_id', 'objective', 'allowed_files', 'acceptance_criteria', 'required_evidence', 'value_mechanism', 'dependency_wave'] as $field) {
            $this->assertArrayHasKey($field, $packet, "packet missing: {$field}");
        }
        $this->assertNotEmpty($packet['task_packet_id']);
        $this->assertNotEmpty($packet['allowed_files']);
        $this->assertNotEmpty($packet['acceptance_criteria']);
        $this->assertNotEmpty($packet['required_evidence']);
        $this->assertNotEmpty($packet['value_mechanism']);
        $this->assertIsInt($packet['dependency_wave']);
    }

    public function test_empty_input_returns_empty_batch(): void
    {
        $result = $this->composer()->compose([]);

        $this->assertCount(0, $result['emitted']);
        $this->assertCount(0, $result['rejected']);
        $this->assertSame(0, $result['stats']['opportunities_in']);
    }

    // ── AC1: allowed_files must include impl (app/) AND test (tests/) paths ───

    public function test_opportunity_without_app_impl_path_is_rejected(): void
    {
        $result = $this->composer()->compose([
            $this->valid('no-impl', ['allowed_files' => ['tests/Unit/SomeTest.php']]),
        ]);

        $this->assertCount(0, $result['emitted']);
        $this->assertSame('missing_required_fields', $result['rejected'][0]['reason']);
        $this->assertStringContainsString('allowed_files_impl_path', $result['rejected'][0]['detail']);
    }

    public function test_opportunity_without_tests_path_is_rejected(): void
    {
        $result = $this->composer()->compose([
            $this->valid('no-test', ['allowed_files' => ['app/Services/Foo.php']]),
        ]);

        $this->assertCount(0, $result['emitted']);
        $this->assertSame('missing_required_fields', $result['rejected'][0]['reason']);
        $this->assertStringContainsString('allowed_files_test_path', $result['rejected'][0]['detail']);
    }

    public function test_opportunity_with_both_impl_and_test_paths_is_emitted(): void
    {
        $result = $this->composer()->compose([
            $this->valid('both-paths', [
                'allowed_files' => ['app/Services/Foo.php', 'tests/Unit/FooTest.php'],
            ]),
        ]);

        $this->assertCount(1, $result['emitted']);
        $this->assertCount(0, $result['rejected']);
    }

    public function test_rejection_detail_is_deterministic_for_missing_paths(): void
    {
        $result = $this->composer()->compose([
            $this->valid('det', ['allowed_files' => ['tests/Unit/DetTest.php']]),
        ]);

        $a = $result['rejected'][0]['detail'];

        $result2 = $this->composer()->compose([
            $this->valid('det', ['allowed_files' => ['tests/Unit/DetTest.php']]),
        ]);

        $this->assertSame($a, $result2['rejected'][0]['detail']);
    }

    // ── AC2: generic value_mechanism rejection ────────────────────────────────

    public function test_generic_value_mechanism_general_is_rejected(): void
    {
        $result = $this->composer()->compose([
            $this->valid('generic', ['value_mechanism' => 'general']),
        ]);

        $this->assertCount(0, $result['emitted']);
        $this->assertSame('generic_value_mechanism', $result['rejected'][0]['reason']);
    }

    public function test_generic_value_mechanism_misc_is_rejected(): void
    {
        $result = $this->composer()->compose([
            $this->valid('misc-task', ['value_mechanism' => 'misc']),
        ]);

        $this->assertCount(0, $result['emitted']);
        $this->assertSame('generic_value_mechanism', $result['rejected'][0]['reason']);
    }

    public function test_generic_value_mechanism_wrapper_is_rejected(): void
    {
        $result = $this->composer()->compose([
            $this->valid('wrapper-task', ['value_mechanism' => 'wrapper']),
        ]);

        $this->assertCount(0, $result['emitted']);
        $this->assertSame('generic_value_mechanism', $result['rejected'][0]['reason']);
    }

    public function test_generic_value_mechanism_observability_only_is_rejected(): void
    {
        $result = $this->composer()->compose([
            $this->valid('obs-task', ['value_mechanism' => 'observability-only']),
        ]);

        $this->assertCount(0, $result['emitted']);
        $this->assertSame('generic_value_mechanism', $result['rejected'][0]['reason']);
    }

    public function test_generic_value_mechanism_with_concrete_evidence_is_emitted(): void
    {
        $result = $this->composer()->compose([
            $this->valid('with-evidence', [
                'value_mechanism'   => 'general',
                'concrete_evidence' => 'Measured 30% reduction in p99 latency via load test results.',
            ]),
        ]);

        $this->assertCount(1, $result['emitted']);
        $this->assertCount(0, $result['rejected']);
    }

    public function test_specific_value_mechanism_is_not_rejected(): void
    {
        $result = $this->composer()->compose([
            $this->valid('specific', ['value_mechanism' => 'fixes_recurring_null_pointer_in_parser:FooService']),
        ]);

        $this->assertCount(1, $result['emitted']);
    }

    public function test_generic_value_mechanism_detail_contains_the_value(): void
    {
        $result = $this->composer()->compose([
            $this->valid('detail-check', ['value_mechanism' => 'misc']),
        ]);

        $this->assertStringContainsString('misc', $result['rejected'][0]['detail']);
    }

    // ── New fields: strategic_diversity, dependency_chain_summary, batch_thesis, rejected_template_farm_reasons ──

    public function test_output_has_new_canonical_keys(): void
    {
        $result = $this->composer()->compose([$this->valid('key-test')]);

        foreach (['strategic_diversity', 'dependency_chain_summary', 'batch_thesis', 'rejected_template_farm_reasons'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    public function test_strategic_diversity_has_canonical_subkeys(): void
    {
        $result = $this->composer()->compose([
            $this->valid('a', ['category' => 'architecture_unlock']),
            $this->valid('b', ['category' => 'bug_fix', 'allowed_files' => ['app/B.php', 'tests/BTest.php']]),
        ]);

        $div = $result['strategic_diversity'];
        $this->assertArrayHasKey('distinct_categories',   $div);
        $this->assertArrayHasKey('category_distribution', $div);
        $this->assertArrayHasKey('is_diverse',             $div);
        $this->assertSame(2, $div['distinct_categories']);
        $this->assertTrue($div['is_diverse']);
    }

    public function test_single_category_batch_is_not_diverse(): void
    {
        $result = $this->composer()->compose([
            $this->valid('t1', ['category' => 'bug_fix', 'allowed_files' => ['app/A.php', 'tests/ATest.php']]),
            $this->valid('t2', ['category' => 'bug_fix', 'allowed_files' => ['app/B.php', 'tests/BTest.php']]),
            $this->valid('t3', ['category' => 'bug_fix', 'allowed_files' => ['app/C.php', 'tests/CTest.php']]),
        ]);

        $this->assertFalse($result['strategic_diversity']['is_diverse']);
        $this->assertSame(1, $result['strategic_diversity']['distinct_categories']);
    }

    public function test_dependency_chain_summary_has_canonical_subkeys(): void
    {
        $result = $this->composer()->compose([
            $this->valid('arch', ['category' => 'architecture_unlock']),
            $this->valid('bug',  ['category' => 'bug_fix', 'allowed_files' => ['app/Bug.php', 'tests/BugTest.php']]),
        ]);

        $chain = $result['dependency_chain_summary'];
        $this->assertArrayHasKey('waves_present',     $chain);
        $this->assertArrayHasKey('chain_description', $chain);
        $this->assertArrayHasKey('prerequisite_count', $chain);
        $this->assertIsArray($chain['waves_present']);
        $this->assertIsString($chain['chain_description']);
        $this->assertStringContainsString('wave 1', $chain['chain_description']);
        $this->assertStringContainsString('wave 2', $chain['chain_description']);
    }

    public function test_batch_thesis_is_non_empty_deterministic_string(): void
    {
        $result = $this->composer()->compose([
            $this->valid('arch', ['category' => 'architecture_unlock']),
        ]);

        $this->assertIsString($result['batch_thesis']);
        $this->assertNotEmpty($result['batch_thesis']);
        $this->assertStringContainsString('wave 1', $result['batch_thesis']);
    }

    public function test_empty_batch_thesis_says_empty(): void
    {
        $result = $this->composer()->compose([]);

        $this->assertStringContainsString('Empty', $result['batch_thesis']);
    }

    public function test_template_farm_fixture_rejected_with_explicit_reasons(): void
    {
        // 5 tasks in the same deep directory (≥ 3 components) and same category → template farm
        $opps = array_map(
            fn (int $i): array => $this->valid("deep-task-{$i}", [
                'category'     => 'architecture_unlock',
                'allowed_files' => [
                    "app/Services/Ai/SelfConstruction/Organ{$i}.php",
                    "tests/Unit/Ai/SelfConstruction/Organ{$i}Test.php",
                ],
            ]),
            range(1, 5),
        );

        $result = $this->composer()->compose($opps);

        // Template farm detected: overflow should be in rejected_template_farm_reasons
        $this->assertNotEmpty($result['rejected_template_farm_reasons']);
        $farmReasons = array_column($result['rejected_template_farm_reasons'], 'reason');
        $this->assertContains('template_farm', $farmReasons);

        // Should emit fewer than all 5
        $this->assertLessThan(5, count($result['emitted']));
    }

    public function test_no_template_farm_on_diverse_dirs_leaves_rejected_template_farm_empty(): void
    {
        $opps = [
            $this->valid('a', ['category' => 'bug_fix', 'allowed_files' => ['app/Core/Auth/A.php', 'tests/Core/Auth/ATest.php']]),
            $this->valid('b', ['category' => 'bug_fix', 'allowed_files' => ['app/Queue/Jobs/B.php', 'tests/Queue/Jobs/BTest.php']]),
            $this->valid('c', ['category' => 'bug_fix', 'allowed_files' => ['app/Runtime/Events/C.php', 'tests/Runtime/Events/CTest.php']]),
            $this->valid('d', ['category' => 'bug_fix', 'allowed_files' => ['app/Domain/Finance/D.php', 'tests/Domain/Finance/DTest.php']]),
        ];

        $result = $this->composer()->compose($opps);

        $this->assertSame([], $result['rejected_template_farm_reasons']);
        $this->assertCount(4, $result['emitted']);
    }
}

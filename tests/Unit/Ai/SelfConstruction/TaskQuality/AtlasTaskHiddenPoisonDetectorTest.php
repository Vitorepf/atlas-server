<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskHiddenPoisonDetector;
use Tests\TestCase;

final class AtlasTaskHiddenPoisonDetectorTest extends TestCase
{
    public function test_clean_packet_finds_no_patterns(): void
    {
        $verdict = (new AtlasTaskHiddenPoisonDetector)->detect([
            'objective' => 'Build a service that does X with bounded evidence.',
            'acceptance_criteria' => ['unit test passes'],
            'required_evidence_kinds' => ['phpunit'],
            'allowed_files' => ['app/Foo.php', 'tests/FooTest.php'],
            'forbidden_files' => [],
            'quality_facts' => [],
        ]);

        $this->assertTrue($verdict['clean']);
        $this->assertSame([], $verdict['found_patterns']);
    }

    public function test_scope_repair_leftover_target_in_objective_is_flagged(): void
    {
        $verdict = (new AtlasTaskHiddenPoisonDetector)->detect([
            'objective' => 'Build the service and also update config/atlas.php with the new config key.',
            'allowed_files' => ['app/Foo.php'],
            'quality_facts' => ['removed_targets' => ['config/atlas.php']],
        ]);

        $found = array_column($verdict['found_patterns'], 'pattern_id');
        $this->assertContains(AtlasTaskHiddenPoisonDetector::PATTERN_REMOVED_TARGET, $found);
    }

    public function test_contradictory_acceptance_is_flagged(): void
    {
        $verdict = (new AtlasTaskHiddenPoisonDetector)->detect([
            'objective' => 'Do the thing.',
            'quality_facts' => [
                'contradiction_pairs' => [['a' => 'must_have_X', 'b' => 'must_not_have_X']],
            ],
        ]);

        $found = array_column($verdict['found_patterns'], 'pattern_id');
        $this->assertContains(AtlasTaskHiddenPoisonDetector::PATTERN_CONTRADICTORY_ACCEPTANCE, $found);
    }

    public function test_unavailable_dependency_is_flagged(): void
    {
        $verdict = (new AtlasTaskHiddenPoisonDetector)->detect([
            'objective' => 'Do it.',
            'required_evidence_kinds' => ['phpunit', 'extractor_x'],
            'quality_facts' => ['unavailable_deps' => ['extractor_x']],
        ]);

        $found = array_column($verdict['found_patterns'], 'pattern_id');
        $this->assertContains(AtlasTaskHiddenPoisonDetector::PATTERN_UNAVAILABLE_DEPENDENCY, $found);
    }

    public function test_duplicate_canonical_symbol_hint_is_flagged(): void
    {
        $verdict = (new AtlasTaskHiddenPoisonDetector)->detect([
            'objective' => 'Refactor things.',
            'allowed_files' => ['app/A/Foo.php', 'app/B/Foo.php'],
        ]);

        $found = array_column($verdict['found_patterns'], 'pattern_id');
        $this->assertContains(AtlasTaskHiddenPoisonDetector::PATTERN_DUPLICATE_CANONICAL_SYMBOL, $found);
    }

    public function test_ambiguous_objective_with_TBD_is_flagged(): void
    {
        $verdict = (new AtlasTaskHiddenPoisonDetector)->detect([
            'objective' => 'Implement TBD per the spec.',
        ]);

        $found = array_column($verdict['found_patterns'], 'pattern_id');
        $this->assertContains(AtlasTaskHiddenPoisonDetector::PATTERN_AMBIGUOUS_INSTRUCTION, $found);
    }

    public function test_permanent_autonomy_dependency_wording_is_flagged(): void
    {
        $verdict = (new AtlasTaskHiddenPoisonDetector)->detect([
            'objective' => 'Run the pipeline; operator approval is required before merge.',
        ]);

        $found = array_column($verdict['found_patterns'], 'pattern_id');
        $this->assertContains(AtlasTaskHiddenPoisonDetector::PATTERN_PERMANENT_AUTONOMY_DEP, $found);
    }

    public function test_contradictory_acceptance_detected_from_criteria_text_without_quality_facts(): void
    {
        $verdict = (new AtlasTaskHiddenPoisonDetector)->detect([
            'objective' => 'Do the thing.',
            'acceptance_criteria' => [
                'The service must produce audit logs on every request.',
                'The service must not produce audit logs to avoid disk bloat.',
            ],
            'quality_facts' => [], // no precomputed contradiction_pairs
        ]);

        $found = array_column($verdict['found_patterns'], 'pattern_id');
        $this->assertContains(AtlasTaskHiddenPoisonDetector::PATTERN_CONTRADICTORY_ACCEPTANCE, $found);
        $this->assertFalse($verdict['clean']);
    }

    public function test_objective_referencing_non_allowed_file_is_flagged_without_removed_targets(): void
    {
        $verdict = (new AtlasTaskHiddenPoisonDetector)->detect([
            'objective' => 'Implement the service and also update config/database.php with the new DSN.',
            'allowed_files' => ['app/Service.php', 'tests/ServiceTest.php'],
            'quality_facts' => [], // no removed_targets
        ]);

        $found = array_column($verdict['found_patterns'], 'pattern_id');
        $this->assertContains(AtlasTaskHiddenPoisonDetector::PATTERN_REMOVED_TARGET, $found);
        $this->assertFalse($verdict['clean']);
    }

    public function test_indirect_human_dependency_phrasing_is_flagged(): void
    {
        $verdict = (new AtlasTaskHiddenPoisonDetector)->detect([
            'objective' => 'Run the pipeline; a human review is required before the release.',
        ]);

        $found = array_column($verdict['found_patterns'], 'pattern_id');
        $this->assertContains(AtlasTaskHiddenPoisonDetector::PATTERN_PERMANENT_AUTONOMY_DEP, $found);
    }

    public function test_allowed_files_test_only_trap_is_flagged(): void
    {
        $verdict = (new AtlasTaskHiddenPoisonDetector)->detect([
            'objective' => 'Harden the thing.',
            'allowed_files' => ['tests/FooTest.php', 'tests/BarTest.php'],
        ]);

        $found = array_column($verdict['found_patterns'], 'pattern_id');
        $this->assertContains(AtlasTaskHiddenPoisonDetector::PATTERN_TEST_ONLY_ALLOWED_FILES, $found);
        $this->assertFalse($verdict['clean']);
    }

    public function test_mixed_allowed_files_with_impl_target_not_flagged_as_test_only_trap(): void
    {
        $verdict = (new AtlasTaskHiddenPoisonDetector)->detect([
            'objective' => 'Harden the thing.',
            'allowed_files' => ['app/Foo.php', 'tests/FooTest.php'],
        ]);

        $found = array_column($verdict['found_patterns'], 'pattern_id');
        $this->assertNotContains(AtlasTaskHiddenPoisonDetector::PATTERN_TEST_ONLY_ALLOWED_FILES, $found);
    }

    public function test_schema_only_acceptance_is_flagged(): void
    {
        $verdict = (new AtlasTaskHiddenPoisonDetector)->detect([
            'objective' => 'Harden the thing.',
            'acceptance_criteria' => ['Running php artisan test --filter=Foo exits 0.'],
        ]);

        $found = array_column($verdict['found_patterns'], 'pattern_id');
        $this->assertContains(AtlasTaskHiddenPoisonDetector::PATTERN_SCHEMA_ONLY_ACCEPTANCE, $found);
        $this->assertFalse($verdict['clean']);
    }

    public function test_acceptance_with_behavior_assertion_not_flagged_as_schema_only(): void
    {
        $verdict = (new AtlasTaskHiddenPoisonDetector)->detect([
            'objective' => 'Harden the thing.',
            'acceptance_criteria' => [
                'Running php artisan test --filter=Foo exits 0.',
                'The computed total matches the expected business value for each input.',
            ],
        ]);

        $found = array_column($verdict['found_patterns'], 'pattern_id');
        $this->assertNotContains(AtlasTaskHiddenPoisonDetector::PATTERN_SCHEMA_ONLY_ACCEPTANCE, $found);
    }

    public function test_detection_does_not_mutate_input_packet(): void
    {
        $packet = [
            'objective' => 'Build a service.',
            'allowed_files' => ['app/A/Foo.php', 'app/B/Foo.php'],
            'quality_facts' => ['unavailable_deps' => ['extractor_x']],
        ];
        $before = json_encode($packet);

        (new AtlasTaskHiddenPoisonDetector)->detect($packet);

        $this->assertSame($before, json_encode($packet), 'detector MUST NOT mutate the packet (queue invariant)');
    }
}

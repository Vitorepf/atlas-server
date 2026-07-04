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

    public function test_repeated_failed_respec_blocked_family_is_flagged_for_retirement(): void
    {
        $verdict = (new AtlasTaskHiddenPoisonDetector)->detect([
            'objective' => 'Build a service that does X with bounded evidence.',
            'acceptance_criteria' => ['unit test passes'],
            'allowed_files' => ['app/Foo.php'],
            'quality_facts' => [
                'family_status' => 'blocked',
                'failed_respec_count' => 4,
                'give_back_count' => 0,
                'field_recovery_confidence' => 0.2,
                'has_runnable_acceptance' => false,
            ],
        ]);

        $found = array_column($verdict['found_patterns'], 'pattern_id');
        $this->assertContains(AtlasTaskHiddenPoisonDetector::PATTERN_REPEATED_FAILED_RESPEC_FAMILY, $found);
        $entry = $verdict['found_patterns'][array_search(AtlasTaskHiddenPoisonDetector::PATTERN_REPEATED_FAILED_RESPEC_FAMILY, $found, true)];
        $this->assertTrue($entry['evidence']['retirement_recommended']);
    }

    public function test_recoverable_family_with_field_recovery_confidence_and_runnable_acceptance_is_not_flagged(): void
    {
        $verdict = (new AtlasTaskHiddenPoisonDetector)->detect([
            'objective' => 'Build a service that does X with bounded evidence.',
            'acceptance_criteria' => ['unit test passes'],
            'allowed_files' => ['app/Foo.php'],
            'quality_facts' => [
                'family_status' => 'blocked',
                'failed_respec_count' => 4,
                'give_back_count' => 0,
                'field_recovery_confidence' => 0.9,
                'has_runnable_acceptance' => true,
            ],
        ]);

        $found = array_column($verdict['found_patterns'], 'pattern_id');
        $this->assertNotContains(AtlasTaskHiddenPoisonDetector::PATTERN_REPEATED_FAILED_RESPEC_FAMILY, $found);
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

    // ── AC2: severity, confidence, recommended_action ──────────────────────────

    public function test_clean_packet_recommends_serve_with_no_severity(): void
    {
        $verdict = (new AtlasTaskHiddenPoisonDetector)->detect([
            'objective' => 'Build a service that does X with bounded evidence.',
            'acceptance_criteria' => ['unit test passes'],
            'allowed_files' => ['app/Foo.php', 'tests/FooTest.php'],
            'quality_facts' => [],
        ]);

        $this->assertSame(AtlasTaskHiddenPoisonDetector::SEVERITY_NONE, $verdict['severity']);
        $this->assertSame(AtlasTaskHiddenPoisonDetector::ACTION_SERVE, $verdict['recommended_action']);
        $this->assertIsFloat($verdict['confidence']);
        $this->assertGreaterThan(0.0, $verdict['confidence']);
    }

    public function test_repeated_failed_respec_family_recommends_retire_with_critical_severity(): void
    {
        $verdict = (new AtlasTaskHiddenPoisonDetector)->detect([
            'objective' => 'Build a service that does X with bounded evidence.',
            'acceptance_criteria' => ['unit test passes'],
            'allowed_files' => ['app/Foo.php'],
            'quality_facts' => [
                'family_status' => 'blocked',
                'failed_respec_count' => 4,
                'give_back_count' => 0,
                'field_recovery_confidence' => 0.2,
                'has_runnable_acceptance' => false,
            ],
        ]);

        $this->assertSame(AtlasTaskHiddenPoisonDetector::SEVERITY_CRITICAL, $verdict['severity']);
        $this->assertSame(AtlasTaskHiddenPoisonDetector::ACTION_RETIRE, $verdict['recommended_action']);
    }

    public function test_contradictory_acceptance_recommends_quarantine(): void
    {
        $verdict = (new AtlasTaskHiddenPoisonDetector)->detect([
            'objective' => 'Build a service.',
            'acceptance_criteria' => ['the endpoint must return JSON', 'the endpoint must not return JSON'],
            'allowed_files' => ['app/Foo.php'],
            'quality_facts' => [],
        ]);

        $this->assertSame(AtlasTaskHiddenPoisonDetector::ACTION_QUARANTINE, $verdict['recommended_action']);
        $this->assertNotSame(AtlasTaskHiddenPoisonDetector::SEVERITY_NONE, $verdict['severity']);
    }

    public function test_allowed_files_test_only_trap_recommends_reshape(): void
    {
        $verdict = (new AtlasTaskHiddenPoisonDetector)->detect([
            'objective' => 'Add coverage for a feature.',
            'acceptance_criteria' => ['unit test passes'],
            'allowed_files' => ['tests/Unit/FooTest.php'],
            'quality_facts' => [],
        ]);

        $this->assertSame(AtlasTaskHiddenPoisonDetector::ACTION_RESHAPE, $verdict['recommended_action']);
    }

    public function test_most_severe_pattern_wins_when_multiple_patterns_found(): void
    {
        // Ambiguous instruction (low/reshape) AND contradictory acceptance (high/quarantine)
        // present at once — the more severe verdict must win, never be softened.
        $verdict = (new AtlasTaskHiddenPoisonDetector)->detect([
            'objective' => 'Build a service. TBD if this applies.',
            'acceptance_criteria' => ['the endpoint must return JSON', 'the endpoint must not return JSON'],
            'allowed_files' => ['app/Foo.php'],
            'quality_facts' => [],
        ]);

        $this->assertSame(AtlasTaskHiddenPoisonDetector::ACTION_QUARANTINE, $verdict['recommended_action']);
        $this->assertSame(AtlasTaskHiddenPoisonDetector::SEVERITY_HIGH, $verdict['severity']);
    }

    // ── AC4: safe_explanation is present only when clean ────────────────────────

    public function test_safe_explanation_present_when_clean(): void
    {
        $verdict = (new AtlasTaskHiddenPoisonDetector)->detect([
            'objective' => 'Build a service that does X with bounded evidence.',
            'acceptance_criteria' => ['unit test passes'],
            'allowed_files' => ['app/Foo.php', 'tests/FooTest.php'],
            'quality_facts' => [],
        ]);

        $this->assertNotNull($verdict['safe_explanation']);
        $this->assertNotEmpty($verdict['safe_explanation']);
    }

    public function test_safe_explanation_null_when_poison_found(): void
    {
        $verdict = (new AtlasTaskHiddenPoisonDetector)->detect([
            'objective' => 'Build a service. TBD if this applies.',
            'acceptance_criteria' => ['unit test passes'],
            'allowed_files' => ['app/Foo.php'],
            'quality_facts' => [],
        ]);

        $this->assertNull($verdict['safe_explanation']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC2: weak_runnable_acceptance_no_behavior
    // ═══════════════════════════════════════════════════════════════════════

    public function test_acceptance_with_only_runnable_command_and_exits_zero_is_weak_runnable(): void
    {
        $verdict = (new AtlasTaskHiddenPoisonDetector)->detect([
            'objective' => 'Implement the foo service according to spec.',
            'acceptance_criteria' => ['Running php artisan test --filter=Foo exits 0.'],
            'allowed_files' => ['app/Foo.php'],
        ]);

        $found = array_column($verdict['found_patterns'], 'pattern_id');
        $this->assertContains(
            AtlasTaskHiddenPoisonDetector::PATTERN_WEAK_RUNNABLE_ACCEPTANCE,
            $found,
            'acceptance with only runnable command + exits 0 must be flagged as weak_runnable',
        );
    }

    public function test_acceptance_with_runnable_command_and_behavior_assertion_not_weak_runnable(): void
    {
        // AC3: runnable command + behavior assertion → not flagged.
        $verdict = (new AtlasTaskHiddenPoisonDetector)->detect([
            'objective' => 'Implement the foo service according to spec.',
            'acceptance_criteria' => [
                'Running php artisan test --filter=Foo exits 0.',
                'The output must contain the correct computed value for each input.',
            ],
            'allowed_files' => ['app/Foo.php'],
        ]);

        $found = array_column($verdict['found_patterns'], 'pattern_id');
        $this->assertNotContains(
            AtlasTaskHiddenPoisonDetector::PATTERN_WEAK_RUNNABLE_ACCEPTANCE,
            $found,
            'acceptance with runnable command + behavior assertion must NOT be flagged',
        );
    }

    public function test_acceptance_with_runnable_command_and_failure_mode_not_weak_runnable(): void
    {
        $verdict = (new AtlasTaskHiddenPoisonDetector)->detect([
            'objective' => 'Implement the foo service according to spec.',
            'acceptance_criteria' => [
                'Running ./vendor/bin/phpunit tests/FooTest.php exits 0.',
                'The primary failure mode is a non-zero exit from the gate binary.',
            ],
            'allowed_files' => ['app/Foo.php'],
        ]);

        $found = array_column($verdict['found_patterns'], 'pattern_id');
        $this->assertNotContains(
            AtlasTaskHiddenPoisonDetector::PATTERN_WEAK_RUNNABLE_ACCEPTANCE,
            $found,
            'acceptance with runnable command + failure mode must NOT be flagged',
        );
    }

    public function test_acceptance_with_runnable_command_and_value_proof_not_weak_runnable(): void
    {
        $verdict = (new AtlasTaskHiddenPoisonDetector)->detect([
            'objective' => 'Implement the foo service.',
            'acceptance_criteria' => [
                'Running php artisan test --filter=Foo exits 0.',
                'The value proof must match the expected ROI from the design brief.',
            ],
            'allowed_files' => ['app/Foo.php'],
        ]);

        $found = array_column($verdict['found_patterns'], 'pattern_id');
        $this->assertNotContains(
            AtlasTaskHiddenPoisonDetector::PATTERN_WEAK_RUNNABLE_ACCEPTANCE,
            $found,
            'acceptance with runnable command + value proof must NOT be flagged',
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC4: weak_runnable coexists with schema_only and test_only
    // ═══════════════════════════════════════════════════════════════════════

    public function test_weak_runnable_coexists_with_schema_only(): void
    {
        // Both patterns can fire on the same packet without hiding each other.
        // The acceptance "Running php artisan test --filter=Foo exits 0."
        // matches BOTH schema_only (has "exits 0") and weak_runnable (has runnable command, no behavior).
        $verdict = (new AtlasTaskHiddenPoisonDetector)->detect([
            'objective' => 'Harden the thing.',
            'acceptance_criteria' => ['Running php artisan test --filter=Foo exits 0.'],
        ]);

        $found = array_column($verdict['found_patterns'], 'pattern_id');
        $this->assertContains(AtlasTaskHiddenPoisonDetector::PATTERN_SCHEMA_ONLY_ACCEPTANCE, $found,
            'schema_only pattern must still fire');
        $this->assertContains(AtlasTaskHiddenPoisonDetector::PATTERN_WEAK_RUNNABLE_ACCEPTANCE, $found,
            'weak_runnable pattern must still fire alongside schema_only');
    }

    public function test_weak_runnable_coexists_with_test_only(): void
    {
        // A test-only packet with only runnable acceptance triggers BOTH patterns.
        $verdict = (new AtlasTaskHiddenPoisonDetector)->detect([
            'objective' => 'Harden the thing.',
            'acceptance_criteria' => ['Running php artisan test --filter=Foo exits 0.'],
            'allowed_files' => ['tests/Unit/FooTest.php'],
        ]);

        $found = array_column($verdict['found_patterns'], 'pattern_id');
        $this->assertContains(AtlasTaskHiddenPoisonDetector::PATTERN_TEST_ONLY_ALLOWED_FILES, $found,
            'test_only must be flagged alongside weak_runnable');
        $this->assertContains(AtlasTaskHiddenPoisonDetector::PATTERN_WEAK_RUNNABLE_ACCEPTANCE, $found,
            'weak_runnable must be flagged alongside test_only');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Extra: weak_runnable does not fire for non-runnable acceptance
    // ═══════════════════════════════════════════════════════════════════════

    public function test_acceptance_without_runnable_command_not_weak(): void
    {
        $verdict = (new AtlasTaskHiddenPoisonDetector)->detect([
            'objective' => 'Build a service that does X.',
            'acceptance_criteria' => ['The service must output a JSON response for valid input.'],
            'allowed_files' => ['app/Foo.php'],
        ]);

        $found = array_column($verdict['found_patterns'], 'pattern_id');
        $this->assertNotContains(
            AtlasTaskHiddenPoisonDetector::PATTERN_WEAK_RUNNABLE_ACCEPTANCE,
            $found,
            'acceptance without runnable command must not be flagged',
        );
    }

    public function test_empty_acceptance_not_weak_runnable(): void
    {
        $verdict = (new AtlasTaskHiddenPoisonDetector)->detect([
            'objective' => 'Build a service.',
            'acceptance_criteria' => [],
            'allowed_files' => ['app/Foo.php'],
        ]);

        $found = array_column($verdict['found_patterns'], 'pattern_id');
        $this->assertNotContains(
            AtlasTaskHiddenPoisonDetector::PATTERN_WEAK_RUNNABLE_ACCEPTANCE,
            $found,
            'empty acceptance must not be flagged',
        );
    }
}

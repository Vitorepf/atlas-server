<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskGraph;

use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;
use App\Services\Ai\SelfConstruction\TaskGraph\AtlasSelfConstructionMissingOrganTaskPlanner;
use Tests\TestCase;

class AtlasSelfConstructionMissingOrganTaskPlannerTest extends TestCase
{
    private function organs(): array
    {
        return [
            [
                'organ_id' => 'cortex',
                'purpose' => 'Read-only context provider.',
                'safe_targets' => [
                    'implementation' => 'app/Services/Ai/SelfConstruction/Cortex/CortexService.php',
                    'test' => 'tests/Unit/Ai/SelfConstruction/Cortex/CortexServiceTest.php',
                ],
            ],
            [
                'organ_id' => 'verification_court',
                'purpose' => 'Server-side verification.',
                'safe_targets' => [
                    'implementation' => 'app/Services/Ai/SelfConstruction/VerificationCourt/VerificationCourtService.php',
                    'test' => 'tests/Unit/Ai/SelfConstruction/VerificationCourt/VerificationCourtServiceTest.php',
                ],
            ],
            [
                'organ_id' => 'unsafe_no_targets',
                'purpose' => 'Has no safe_targets — must withhold.',
                // no safe_targets => withhold
            ],
        ];
    }

    public function test_missing_organ_emits_self_sufficient_draft(): void
    {
        $verdict = (new AtlasSelfConstructionMissingOrganTaskPlanner)->plan(
            ['missing_organs' => ['cortex']],
            $this->organs(),
        );

        self::assertSame(1, $verdict['draft_count']);
        $draft = $verdict['drafts'][0];
        self::assertSame('coverage-cortex-missing-v1', $draft['task_packet_id']);
        self::assertContains('app/Services/Ai/SelfConstruction/Cortex/CortexService.php', $draft['allowed_files']);
        self::assertNotEmpty($draft['acceptance_criteria']);
        self::assertContains('tests_or_gates_result', $draft['required_evidence']);
        self::assertContains('self_construction', $draft['tags']);
        self::assertContains('cortex', $draft['tags']);
    }

    public function test_thin_organ_draft_carries_missing_evidence_classes_in_rationale(): void
    {
        $verdict = (new AtlasSelfConstructionMissingOrganTaskPlanner)->plan(
            ['thin_organs' => [['organ_id' => 'verification_court', 'missing_evidence_classes' => ['gate', 'receipt']]]],
            $this->organs(),
        );

        self::assertSame(1, $verdict['draft_count']);
        $draft = $verdict['drafts'][0];
        self::assertSame('coverage-verification_court-thin-v1', $draft['task_packet_id']);
        self::assertStringContainsString('gate', $draft['rationale']);
        self::assertStringContainsString('receipt', $draft['rationale']);
    }

    public function test_unsafe_organ_is_withheld_with_reason(): void
    {
        $verdict = (new AtlasSelfConstructionMissingOrganTaskPlanner)->plan(
            ['missing_organs' => ['unsafe_no_targets']],
            $this->organs(),
        );

        self::assertSame(0, $verdict['draft_count']);
        self::assertCount(1, $verdict['withheld_gaps']);
        self::assertSame('unsafe_no_targets', $verdict['withheld_gaps'][0]['organ_id']);
        self::assertSame('safe_targets_unavailable', $verdict['withheld_gaps'][0]['reason']);
    }

    public function test_dependency_ordering_in_draft_carries_depends_on(): void
    {
        $organs = $this->organs();
        $organs[0]['depends_on'] = ['organ_metadata', 'final_organ_map'];

        $verdict = (new AtlasSelfConstructionMissingOrganTaskPlanner)->plan(
            ['missing_organs' => ['cortex']],
            $organs,
        );

        $draft = $verdict['drafts'][0];
        self::assertSame(['organ_metadata', 'final_organ_map'], $draft['depends_on']);
    }

    public function test_draft_is_quality_inspector_self_sufficient(): void
    {
        $verdict = (new AtlasSelfConstructionMissingOrganTaskPlanner)->plan(
            ['missing_organs' => ['cortex']],
            $this->organs(),
        );

        $draft = $verdict['drafts'][0];
        $inspector = new AtlasTaskPacketQualityInspector();
        $report = $inspector->inspect($draft);

        self::assertTrue((bool) $report['self_sufficient'], 'planner draft must pass quality inspector. Deficiencies: '.implode(',', (array) $report['deficiencies']));
    }

    public function test_draft_acceptance_criteria_include_runnable_artisan_test_command(): void
    {
        $verdict = (new AtlasSelfConstructionMissingOrganTaskPlanner)->plan(
            ['missing_organs' => ['cortex']],
            $this->organs(),
        );

        $criteria = $verdict['drafts'][0]['acceptance_criteria'];
        $hasTestCommand = false;
        foreach ($criteria as $c) {
            if (str_starts_with($c, '/opt/homebrew/bin/php artisan test ')) {
                $hasTestCommand = true;
                self::assertStringContainsString('CortexServiceTest.php', $c);
            }
        }
        self::assertTrue($hasTestCommand, 'acceptance_criteria must contain a runnable artisan test command');
    }

    public function test_draft_required_evidence_includes_implementation_notes(): void
    {
        $verdict = (new AtlasSelfConstructionMissingOrganTaskPlanner)->plan(
            ['missing_organs' => ['cortex']],
            $this->organs(),
        );

        self::assertContains('implementation_notes', $verdict['drafts'][0]['required_evidence']);
    }

    public function test_impl_only_or_test_only_safe_targets_are_withheld(): void
    {
        $organs = [
            ['organ_id' => 'impl_only', 'purpose' => 'impl but no test', 'safe_targets' => ['implementation' => 'app/Foo.php']],
            ['organ_id' => 'test_only', 'purpose' => 'test but no impl', 'safe_targets' => ['test' => 'tests/FooTest.php']],
        ];

        $verdict = (new AtlasSelfConstructionMissingOrganTaskPlanner)->plan(
            ['missing_organs' => ['impl_only', 'test_only']],
            $organs,
        );

        self::assertSame(0, $verdict['draft_count']);
        self::assertCount(2, $verdict['withheld_gaps']);
        $reasons = array_column($verdict['withheld_gaps'], 'reason');
        foreach ($reasons as $r) {
            self::assertSame('safe_targets_unavailable', $r);
        }
    }

    public function test_draft_ids_are_deterministic_for_identical_input(): void
    {
        $planner = new AtlasSelfConstructionMissingOrganTaskPlanner();
        $a = $planner->plan(['missing_organs' => ['cortex', 'verification_court']], $this->organs());
        $b = $planner->plan(['missing_organs' => ['cortex', 'verification_court']], $this->organs());

        self::assertSame(array_column($a['drafts'], 'task_packet_id'), array_column($b['drafts'], 'task_packet_id'));
        // Stable sort order
        self::assertSame('coverage-cortex-missing-v1', $a['drafts'][0]['task_packet_id']);
        self::assertSame('coverage-verification_court-missing-v1', $a['drafts'][1]['task_packet_id']);
    }

    // ── new fields: prerequisite_ids, required_proof, priority ────────────────

    public function test_draft_has_prerequisite_ids_field(): void
    {
        $organs = $this->organs();
        $organs[0]['depends_on'] = ['organ_map', 'cortex_seed'];

        $verdict = (new AtlasSelfConstructionMissingOrganTaskPlanner)->plan(
            ['missing_organs' => ['cortex']],
            $organs,
        );

        $draft = $verdict['drafts'][0];
        self::assertArrayHasKey('prerequisite_ids', $draft);
        self::assertSame(['organ_map', 'cortex_seed'], $draft['prerequisite_ids']);
        self::assertSame($draft['depends_on'], $draft['prerequisite_ids']);
    }

    public function test_draft_has_required_proof_with_test_file_path(): void
    {
        $verdict = (new AtlasSelfConstructionMissingOrganTaskPlanner)->plan(
            ['missing_organs' => ['cortex']],
            $this->organs(),
        );

        $proof = $verdict['drafts'][0]['required_proof'];
        self::assertIsArray($proof);
        self::assertNotEmpty($proof);
        $hasTestEntry = false;
        foreach ($proof as $p) {
            if (str_contains($p, 'CortexServiceTest.php')) {
                $hasTestEntry = true;
            }
        }
        self::assertTrue($hasTestEntry, 'required_proof must include test file path');
    }

    public function test_draft_has_priority_with_value_and_reason(): void
    {
        $verdict = (new AtlasSelfConstructionMissingOrganTaskPlanner)->plan(
            ['missing_organs' => ['cortex']],
            $this->organs(),
        );

        $priority = $verdict['drafts'][0]['priority'];
        self::assertArrayHasKey('value', $priority);
        self::assertArrayHasKey('reason', $priority);
        self::assertIsInt($priority['value']);
        self::assertIsString($priority['reason']);
        self::assertNotEmpty($priority['reason']);
    }

    public function test_missing_organ_priority_higher_than_thin_organ(): void
    {
        $organs = $this->organs();
        $verdict = (new AtlasSelfConstructionMissingOrganTaskPlanner)->plan(
            [
                'missing_organs' => ['cortex'],
                'thin_organs' => [['organ_id' => 'verification_court', 'missing_evidence_classes' => ['gate']]],
            ],
            $organs,
        );

        $missingPriority = $verdict['drafts'][0]['priority']['value'] ?? 0;
        $thinPriority = $verdict['drafts'][1]['priority']['value'] ?? 0;
        // After sort by task_packet_id: cortex < verification_court alphabetically
        $byId = array_column($verdict['drafts'], null, 'task_packet_id');
        $mp = $byId['coverage-cortex-missing-v1']['priority']['value'];
        $tp = $byId['coverage-verification_court-thin-v1']['priority']['value'];
        self::assertGreaterThan($tp, $mp, 'missing organ should have higher priority value than thin organ');
    }

    // ── live_target_exists + already_drafted ──────────────────────────────────

    public function test_organ_with_live_target_is_withheld_with_live_target_exists_reason(): void
    {
        $liveTargets = ['app/Services/Ai/SelfConstruction/Cortex/CortexService.php'];

        $verdict = (new AtlasSelfConstructionMissingOrganTaskPlanner)->plan(
            ['missing_organs' => ['cortex']],
            $this->organs(),
            'self_construction_coverage',
            $liveTargets,
        );

        self::assertSame(0, $verdict['draft_count']);
        $reasons = array_column($verdict['withheld_gaps'], 'reason');
        self::assertContains('live_target_exists', $reasons);
    }

    public function test_organ_in_both_missing_and_thin_produces_one_draft_and_already_drafted_withheld(): void
    {
        $verdict = (new AtlasSelfConstructionMissingOrganTaskPlanner)->plan(
            [
                'missing_organs' => ['cortex'],
                'thin_organs' => [['organ_id' => 'cortex', 'missing_evidence_classes' => ['gate']]],
            ],
            $this->organs(),
        );

        // Only one draft for cortex, plus one withheld with already_drafted.
        $cortexDrafts = array_filter($verdict['drafts'], static fn (array $d): bool => str_contains($d['task_packet_id'], 'cortex'));
        self::assertCount(1, $cortexDrafts, 'organ in missing+thin must produce only one draft');

        $withheldReasons = array_column($verdict['withheld_gaps'], 'reason');
        self::assertContains('already_drafted', $withheldReasons);
    }

    // ── dependency-aware waves ──────────────────────────────────────────────────

    public function test_organ_with_no_in_batch_dependency_gets_dependency_wave_one(): void
    {
        $verdict = (new AtlasSelfConstructionMissingOrganTaskPlanner)->plan(
            ['missing_organs' => ['cortex']],
            $this->organs(),
        );

        self::assertSame(1, $verdict['drafts'][0]['dependency_wave']);
    }

    public function test_organ_depending_on_another_drafted_organ_gets_a_later_wave(): void
    {
        $organs = $this->organs();
        // verification_court depends on cortex — both are in this batch.
        $organs[1]['depends_on'] = ['cortex'];

        $verdict = (new AtlasSelfConstructionMissingOrganTaskPlanner)->plan(
            ['missing_organs' => ['cortex', 'verification_court']],
            $organs,
        );

        $byId = array_column($verdict['drafts'], null, 'task_packet_id');
        $cortexWave = $byId['coverage-cortex-missing-v1']['dependency_wave'];
        $vcWave = $byId['coverage-verification_court-missing-v1']['dependency_wave'];

        self::assertSame(1, $cortexWave);
        self::assertGreaterThan($cortexWave, $vcWave, 'a downstream organ must land in a later wave than its in-batch prerequisite');
    }

    public function test_depends_on_an_organ_outside_the_batch_does_not_bump_wave(): void
    {
        $organs = $this->organs();
        // 'organ_metadata' is not in this batch — purely informational, must not affect wave.
        $organs[0]['depends_on'] = ['organ_metadata'];

        $verdict = (new AtlasSelfConstructionMissingOrganTaskPlanner)->plan(
            ['missing_organs' => ['cortex']],
            $organs,
        );

        self::assertSame(1, $verdict['drafts'][0]['dependency_wave']);
    }

    public function test_three_level_dependency_chain_produces_three_distinct_waves(): void
    {
        $organs = $this->organs();
        // cortex (wave1) <- verification_court (wave2) <- unsafe_no_targets has no safe_targets, so
        // build a 3rd organ instead with safe_targets depending on verification_court.
        $organs[] = [
            'organ_id' => 'chain_tail',
            'purpose' => 'Depends on verification_court, which depends on cortex.',
            'safe_targets' => [
                'implementation' => 'app/Services/Ai/SelfConstruction/ChainTail/ChainTailService.php',
                'test' => 'tests/Unit/Ai/SelfConstruction/ChainTail/ChainTailServiceTest.php',
            ],
            'depends_on' => ['verification_court'],
        ];
        $organs[1]['depends_on'] = ['cortex'];

        $verdict = (new AtlasSelfConstructionMissingOrganTaskPlanner)->plan(
            ['missing_organs' => ['cortex', 'verification_court', 'chain_tail']],
            $organs,
        );

        $byId = array_column($verdict['drafts'], null, 'task_packet_id');
        $w1 = $byId['coverage-cortex-missing-v1']['dependency_wave'];
        $w2 = $byId['coverage-verification_court-missing-v1']['dependency_wave'];
        $w3 = $byId['coverage-chain_tail-missing-v1']['dependency_wave'];

        self::assertSame(1, $w1);
        self::assertSame(2, $w2);
        self::assertSame(3, $w3);
    }

    public function test_dependency_wave_is_deterministic(): void
    {
        $organs = $this->organs();
        $organs[1]['depends_on'] = ['cortex'];

        $planner = new AtlasSelfConstructionMissingOrganTaskPlanner();
        $a = $planner->plan(['missing_organs' => ['cortex', 'verification_court']], $organs);
        $b = $planner->plan(['missing_organs' => ['cortex', 'verification_court']], $organs);

        self::assertSame(
            array_column($a['drafts'], 'dependency_wave'),
            array_column($b['drafts'], 'dependency_wave'),
        );
    }
}

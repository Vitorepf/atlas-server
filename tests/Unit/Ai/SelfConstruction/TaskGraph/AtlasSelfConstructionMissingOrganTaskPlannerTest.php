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
}

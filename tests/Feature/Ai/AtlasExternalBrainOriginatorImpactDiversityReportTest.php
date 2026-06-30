<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOriginatorImpactDiversityReport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainOriginatorImpactDiversityReportTest extends TestCase
{
    private function report(): AtlasExternalBrainOriginatorImpactDiversityReport
    {
        return new AtlasExternalBrainOriginatorImpactDiversityReport;
    }

    private function task(string $impactClass, array $overrides = []): array
    {
        return array_merge([
            'task_packet_id' => 'task-'.$impactClass,
            'impact_class' => $impactClass,
        ], $overrides);
    }

    // ── output shape ───────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $result = $this->report()->report(['tasks' => []]);

        foreach ([
            'schema', 'classified_tasks', 'class_counts', 'impact_diversity_score',
            'missing_high_priority_classes', 'dominant_class',
            'advances_more_than_one_structural_capability', 'recommendation', 'recommended_actions',
        ] as $key) {
            $this->assertArrayHasKey($key, $result, "missing key: {$key}");
        }
        $this->assertSame(AtlasExternalBrainOriginatorImpactDiversityReport::SCHEMA, $result['schema']);
    }

    // ── classification: explicit impact_class trusted verbatim ──────────────

    public function test_explicit_impact_class_is_trusted_verbatim(): void
    {
        $result = $this->report()->report(['tasks' => [
            $this->task(AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_SIMPLIFICATION),
        ]]);

        $this->assertSame(
            AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_SIMPLIFICATION,
            $result['classified_tasks'][0]['impact_class'],
        );
        $this->assertSame('explicit', $result['classified_tasks'][0]['classification_source']);
    }

    // ── classification: all 8 impact classes covered by heuristic keywords ──

    public static function impactClassKeywordProvider(): array
    {
        return [
            'task_fabric' => ['Upgrade task fabric acceptance criteria contract', AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_TASK_FABRIC],
            'outcome_learning' => ['Calibrate impact forecast against give_back churn', AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_OUTCOME_LEARNING],
            'queue_self_healing' => ['Replenish backlog and reap stale leases', AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_QUEUE_SELF_HEALING],
            'model_amplifier' => ['Strengthen the amplifier model tier scoring', AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_MODEL_AMPLIFIER],
            'autonomy_governor' => ['Tune the ambition budget governor decision', AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_AUTONOMY_GOVERNOR],
            'simplification' => ['Consolidate duplicate simplification candidates', AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_SIMPLIFICATION],
            'evidence_integrity' => ['Add a runtime evidence receipt journal integrity audit', AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_EVIDENCE_INTEGRITY],
            'provider_optional_acceleration' => ['Adapt a frontier research grounding breakthrough', AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_PROVIDER_OPTIONAL_ACCELERATION],
        ];
    }

    #[DataProvider('impactClassKeywordProvider')]
    public function test_heuristic_classifies_task_into_expected_class(string $objective, string $expectedClass): void
    {
        $result = $this->report()->report(['tasks' => [
            ['task_packet_id' => 't1', 'objective' => $objective],
        ]]);

        $this->assertSame($expectedClass, $result['classified_tasks'][0]['impact_class']);
        $this->assertSame('heuristic', $result['classified_tasks'][0]['classification_source']);
    }

    public function test_unmatched_objective_is_unclassified_not_forced(): void
    {
        $result = $this->report()->report(['tasks' => [
            ['task_packet_id' => 't1', 'objective' => 'completely unrelated gibberish xyz123'],
        ]]);

        $this->assertSame(AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_UNCLASSIFIED, $result['classified_tasks'][0]['impact_class']);
    }

    // ── impact_diversity_score ────────────────────────────────────────────

    public function test_diversity_score_is_zero_for_empty_batch(): void
    {
        $result = $this->report()->report(['tasks' => []]);
        $this->assertSame(0.0, $result['impact_diversity_score']);
    }

    public function test_diversity_score_is_one_eighth_for_single_class_batch(): void
    {
        $result = $this->report()->report(['tasks' => [
            $this->task(AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_TASK_FABRIC),
            $this->task(AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_TASK_FABRIC),
        ]]);

        $this->assertSame(0.125, $result['impact_diversity_score']);
    }

    public function test_diversity_score_is_one_when_all_eight_classes_present(): void
    {
        $tasks = array_map(
            fn (string $class) => $this->task($class),
            AtlasExternalBrainOriginatorImpactDiversityReport::IMPACT_CLASSES,
        );

        $result = $this->report()->report(['tasks' => $tasks]);
        $this->assertSame(1.0, $result['impact_diversity_score']);
    }

    // ── missing_high_priority_classes / dominant_class ───────────────────

    public function test_missing_high_priority_classes_lists_absent_classes(): void
    {
        $result = $this->report()->report([
            'tasks' => [$this->task(AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_TASK_FABRIC)],
            'high_priority_classes' => [
                AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_TASK_FABRIC,
                AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_SIMPLIFICATION,
            ],
        ]);

        $this->assertSame(
            [AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_SIMPLIFICATION],
            $result['missing_high_priority_classes'],
        );
    }

    public function test_dominant_class_is_the_most_frequent_present_class(): void
    {
        $result = $this->report()->report(['tasks' => [
            $this->task(AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_TASK_FABRIC),
            $this->task(AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_TASK_FABRIC),
            $this->task(AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_SIMPLIFICATION),
        ]]);

        $this->assertSame(AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_TASK_FABRIC, $result['dominant_class']);
    }

    // ── advances_more_than_one_structural_capability ─────────────────────

    public function test_single_class_batch_does_not_advance_more_than_one_capability(): void
    {
        $result = $this->report()->report(['tasks' => [
            $this->task(AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_TASK_FABRIC),
            $this->task(AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_TASK_FABRIC),
        ]]);

        $this->assertFalse($result['advances_more_than_one_structural_capability']);
    }

    public function test_two_class_batch_advances_more_than_one_capability(): void
    {
        $result = $this->report()->report(['tasks' => [
            $this->task(AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_TASK_FABRIC),
            $this->task(AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_SIMPLIFICATION),
        ]]);

        $this->assertTrue($result['advances_more_than_one_structural_capability']);
    }

    // ── recommendation: narrow batch recommends replace ──────────────────

    public function test_narrow_single_class_batch_recommends_replace(): void
    {
        $result = $this->report()->report(['tasks' => [
            $this->task(AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_TASK_FABRIC),
            $this->task(AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_TASK_FABRIC),
            $this->task(AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_TASK_FABRIC),
        ]]);

        $this->assertSame('replace', $result['recommendation']['action']);
        $this->assertNotEmpty($result['recommendation']['reasons']);
    }

    // ── recommendation: missing high-priority classes recommends add ─────

    public function test_diverse_batch_with_missing_high_priority_class_recommends_add(): void
    {
        $result = $this->report()->report([
            'tasks' => [
                $this->task(AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_TASK_FABRIC),
                $this->task(AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_OUTCOME_LEARNING),
            ],
            'high_priority_classes' => [
                AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_TASK_FABRIC,
                AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_OUTCOME_LEARNING,
                AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_EVIDENCE_INTEGRITY,
            ],
        ]);

        $this->assertSame('add', $result['recommendation']['action']);
        $addActions = array_filter($result['recommended_actions'], fn ($a) => $a['action'] === 'add');
        $this->assertNotEmpty($addActions);
        $this->assertSame(
            AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_EVIDENCE_INTEGRITY,
            array_values($addActions)[0]['target_class'],
        );
    }

    // ── recommendation: fully diverse, complete batch recommends none ────

    public function test_fully_covered_batch_recommends_none(): void
    {
        $tasks = array_map(
            fn (string $class) => $this->task($class),
            AtlasExternalBrainOriginatorImpactDiversityReport::IMPACT_CLASSES,
        );

        $result = $this->report()->report(['tasks' => $tasks]);

        $this->assertSame('none', $result['recommendation']['action']);
        $this->assertSame([], $result['recommended_actions']);
    }

    // ── determinism ────────────────────────────────────────────────────────

    public function test_report_is_deterministic(): void
    {
        $tasks = [
            $this->task(AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_TASK_FABRIC),
            $this->task(AtlasExternalBrainOriginatorImpactDiversityReport::CLASS_SIMPLIFICATION),
        ];

        $a = $this->report()->report(['tasks' => $tasks]);
        $b = $this->report()->report(['tasks' => $tasks]);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}

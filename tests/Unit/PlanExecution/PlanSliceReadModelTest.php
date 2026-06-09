<?php

declare(strict_types=1);

namespace Tests\Unit\PlanExecution;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanSliceReadModel;
use PHPUnit\Framework\TestCase;

final class PlanSliceReadModelTest extends TestCase
{
    public function test_ordered_slices_filters_empty_ids_and_sorts_by_sequence(): void
    {
        $ordered = PlanSliceReadModel::orderedSlices([
            'slices' => [
                ['slice_id' => 'S3', 'sequence' => 3],
                ['slice_id' => '', 'sequence' => 1],
                ['slice_id' => 'S1', 'sequence' => 1],
                'not-a-slice',
                ['slice_id' => 'S2', 'sequence' => 2],
            ],
        ]);

        $this->assertSame(['S1', 'S2', 'S3'], array_column($ordered, 'slice_id'));
    }

    public function test_plan_slices_preserve_source_order_and_tracker_row_shape(): void
    {
        $rows = PlanSliceReadModel::planSlices([
            'slices' => [
                ['slice_id' => 'S2', 'depends_on' => ['S1'], 'finding' => ['finding_id' => 'F2'], 'allowed_files' => [' app/B.php ', 'app/B.php'], 'objective' => 'obj', 'delivery' => 'del', 'acceptance_criteria' => ['a', '']],
                ['slice_id' => '', 'finding' => ['finding_id' => 'skip']],
                'not-a-slice',
                ['slice_id' => 'S1', 'finding' => ['finding_id' => 'F1']],
            ],
        ]);

        $this->assertSame(['S2', 'S1'], array_column($rows, 'slice_id'));
        $this->assertSame(['S1'], $rows[0]['depends_on']);
        $this->assertSame('F2', $rows[0]['finding_id']);
        $this->assertSame([' app/B.php ', 'app/B.php'], $rows[0]['allowed_files']);
        $this->assertSame(['a'], $rows[0]['acceptance_criteria']);
        $this->assertSame([], $rows[1]['depends_on']);
    }

    public function test_normalize_skip_accepts_list_and_boolean_map_without_empty_values(): void
    {
        $this->assertSame(
            ['S1' => true, 'S2' => true, 'S4' => true],
            PlanSliceReadModel::normalizeSkip([
                'S1',
                '',
                'S2' => true,
                'S3' => false,
                'S4' => 1,
            ])
        );
    }

    public function test_dependencies_delivered_uses_delivered_state_only_and_ignores_empty_dep_ids(): void
    {
        $slice = ['depends_on' => ['S1', '', 'S2']];
        $states = [
            'S1' => ['state' => PlanSliceReadModel::STATE_DELIVERED],
            'S2' => ['state' => 'planned'],
        ];

        $this->assertFalse(PlanSliceReadModel::dependenciesDelivered($slice, $states));

        $states['S2']['state'] = PlanSliceReadModel::STATE_DELIVERED;

        $this->assertTrue(PlanSliceReadModel::dependenciesDelivered($slice, $states));
    }

    public function test_dependencies_satisfied_uses_delivered_set(): void
    {
        $this->assertTrue(PlanSliceReadModel::dependenciesSatisfied(
            ['S1', 'S2'],
            ['S1' => true, 'S2' => true]
        ));

        $this->assertFalse(PlanSliceReadModel::dependenciesSatisfied(
            ['S1', 'S2'],
            ['S1' => true]
        ));
    }

    public function test_dependency_order_preserved_blocks_delivered_slice_with_undelivered_parent(): void
    {
        $slices = [
            ['slice_id' => 'S1', 'depends_on' => []],
            ['slice_id' => 'S2', 'depends_on' => ['S1']],
        ];

        $this->assertFalse(PlanSliceReadModel::dependencyOrderPreserved($slices, [
            'S1' => ['state' => 'planned'],
            'S2' => ['state' => PlanSliceReadModel::STATE_DELIVERED],
        ]));

        $this->assertTrue(PlanSliceReadModel::dependencyOrderPreserved($slices, [
            'S1' => ['state' => PlanSliceReadModel::STATE_DELIVERED],
            'S2' => ['state' => PlanSliceReadModel::STATE_DELIVERED],
        ]));
    }

    public function test_allowed_files_are_trimmed_deduplicated_and_empty_values_removed(): void
    {
        $this->assertSame(
            ['app/A.php', 'tests/A.php'],
            PlanSliceReadModel::allowedFiles([
                'allowed_files' => [' app/A.php ', '', 'tests/A.php', 'app/A.php'],
            ])
        );
    }

    public function test_integration_files_add_changed_files_to_declared_scope(): void
    {
        $this->assertSame(
            ['app/A.php', 'tests/A.php', 'app/B.php'],
            PlanSliceReadModel::integrationFiles(
                ['allowed_files' => ['app/A.php', 'tests/A.php']],
                ['changed_files' => [' app/B.php ', 'app/A.php', '']]
            )
        );
    }

    public function test_intersects_reports_shared_claimed_file(): void
    {
        $this->assertTrue(PlanSliceReadModel::intersects(
            ['app/A.php', 'app/B.php'],
            ['app/B.php' => true]
        ));

        $this->assertFalse(PlanSliceReadModel::intersects(
            ['app/A.php'],
            ['app/B.php' => true]
        ));
    }
}

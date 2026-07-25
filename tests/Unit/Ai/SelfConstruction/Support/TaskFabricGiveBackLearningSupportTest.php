<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Support;

use App\Services\Ai\SelfConstruction\Support\TaskFabricGiveBackLearningSupport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pure-unit lock for {@see TaskFabricGiveBackLearningSupport}
 * (string/array-only; no FS / DI / I/O).
 */
final class TaskFabricGiveBackLearningSupportTest extends TestCase
{
    #[Test]
    public function classify_cli_clobber_and_petreo(): void
    {
        $this->assertSame(
            'cli_clobber_or_petreo',
            TaskFabricGiveBackLearningSupport::classify('cli_clobber', []),
        );
        $this->assertSame(
            'cli_clobber_or_petreo',
            TaskFabricGiveBackLearningSupport::classify('blocked', ['forbidden_core']),
        );
        $this->assertSame(
            'cli_clobber_or_petreo',
            TaskFabricGiveBackLearningSupport::classify('pétreo boundary', []),
        );
    }

    #[Test]
    public function classify_contradictory_acceptance(): void
    {
        $this->assertSame(
            'contradictory_acceptance',
            TaskFabricGiveBackLearningSupport::classify('acceptance_contradiction', ['scalar_score_required']),
        );
        $this->assertSame(
            'contradictory_acceptance',
            TaskFabricGiveBackLearningSupport::classify('', ['goodhart_proxy']),
        );
    }

    #[Test]
    public function classify_scope_repair_missing_impl(): void
    {
        $this->assertSame(
            'scope_repair_missing_impl',
            TaskFabricGiveBackLearningSupport::classify('scope_repair_attempted', []),
        );
        $this->assertSame(
            'scope_repair_missing_impl',
            TaskFabricGiveBackLearningSupport::classify('blocked', ['missing_impl_file: app/X.php']),
        );
        $this->assertSame(
            'scope_repair_missing_impl',
            TaskFabricGiveBackLearningSupport::classify('', ['allowed_files_missing']),
        );
    }

    #[Test]
    public function classify_generic_when_no_signal(): void
    {
        $this->assertSame(
            'generic',
            TaskFabricGiveBackLearningSupport::classify('worker_timeout', ['unknown_failure']),
        );
    }

    #[Test]
    public function classify_prefers_cli_clobber_over_other_matchers_in_same_haystack(): void
    {
        // cli_clobber matcher runs first in the haystack.
        $this->assertSame(
            'cli_clobber_or_petreo',
            TaskFabricGiveBackLearningSupport::classify('scope_repair', ['cli_clobber', 'missing_impl']),
        );
    }

    #[Test]
    public function guess_missing_impl_extracts_first_named_path(): void
    {
        $path = TaskFabricGiveBackLearningSupport::guessMissingImpl([
            'blocking_deficiencies' => [
                'other_signal',
                'missing_impl_file: app/Demo/Foo.php',
                'missing_impl_file: app/Demo/Bar.php',
            ],
        ]);

        $this->assertSame('app/Demo/Foo.php', $path);
    }

    #[Test]
    public function guess_missing_impl_accepts_missing_impl_without_file_suffix(): void
    {
        $path = TaskFabricGiveBackLearningSupport::guessMissingImpl([
            'blocking_deficiencies' => ['missing_impl: app/Services/X.php'],
        ]);

        $this->assertSame('app/Services/X.php', $path);
    }

    #[Test]
    public function guess_missing_impl_returns_null_when_unnamed_or_absent(): void
    {
        $this->assertNull(TaskFabricGiveBackLearningSupport::guessMissingImpl([
            'blocking_deficiencies' => ['missing_impl'],
        ]));
        $this->assertNull(TaskFabricGiveBackLearningSupport::guessMissingImpl([]));
        $this->assertNull(TaskFabricGiveBackLearningSupport::guessMissingImpl([
            'blocking_deficiencies' => 'not-an-array',
        ]));
    }

    #[Test]
    public function build_worker_shape_learning_groups_and_counts_outcomes(): void
    {
        $rows = TaskFabricGiveBackLearningSupport::buildWorkerShapeLearning([
            ['task_shape' => 'scope_repair', 'worker_client_id' => 'w1', 'outcome' => 'give_back', 'give_back_count' => 2],
            ['task_shape' => 'scope_repair', 'worker_client_id' => 'w1', 'outcome' => 'success'],
            ['task_shape' => 'scope_repair', 'worker_client_id' => 'w1', 'outcome' => 'give_back', 'give_back_count' => 8],
            ['task_shape' => 'cli', 'worker_client_id' => 'w2', 'outcome' => 'give_back'],
            // skipped: no shape and no worker
            ['reason' => 'noise'],
            // skipped: not an array
            'bad',
        ], 8);

        $this->assertCount(2, $rows);

        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row['task_shape'].'||'.$row['worker_client_id']] = $row;
        }

        $this->assertSame(2, $byKey['scope_repair||w1']['give_back_count']);
        $this->assertSame(1, $byKey['scope_repair||w1']['success_count']);
        $this->assertSame(1, $byKey['scope_repair||w1']['quarantine_count']);
        $this->assertSame(1, $byKey['cli||w2']['give_back_count']);
        $this->assertSame(0, $byKey['cli||w2']['quarantine_count']);
    }

    #[Test]
    public function build_worker_shape_learning_is_sorted_by_key(): void
    {
        $rows = TaskFabricGiveBackLearningSupport::buildWorkerShapeLearning([
            ['task_shape' => 'z', 'worker_client_id' => 'w', 'outcome' => 'give_back'],
            ['task_shape' => 'a', 'worker_client_id' => 'w', 'outcome' => 'give_back'],
        ], 8);

        $this->assertSame(['a', 'z'], array_column($rows, 'task_shape'));
    }

    #[Test]
    public function build_worker_shape_learning_uses_quarantine_threshold_argument(): void
    {
        $rows = TaskFabricGiveBackLearningSupport::buildWorkerShapeLearning([
            ['task_shape' => 's', 'worker_client_id' => 'w', 'outcome' => 'give_back', 'give_back_count' => 3],
        ], 3);

        $this->assertSame(1, $rows[0]['quarantine_count']);

        $rowsStrict = TaskFabricGiveBackLearningSupport::buildWorkerShapeLearning([
            ['task_shape' => 's', 'worker_client_id' => 'w', 'outcome' => 'give_back', 'give_back_count' => 3],
        ], 8);

        $this->assertSame(0, $rowsStrict[0]['quarantine_count']);
    }
}

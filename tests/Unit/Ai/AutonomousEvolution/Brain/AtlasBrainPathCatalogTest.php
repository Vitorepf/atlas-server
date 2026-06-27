<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathCatalog;
use Tests\TestCase;

/**
 * Frozen contract for the path catalog — proves the single-source lookups + fail-safe nulls + pétreo.
 */
final class AtlasBrainPathCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas.brain.paths', [
            ['id' => 'comprehension-deepening', 'executor_organ' => 'App\\Some\\Builder', 'lens' => 'deepen the model'],
            ['id' => 'pattern-design', 'executor_organ' => 'App\\Some\\Registry'],
            ['id' => 'no-executor'], // entry without executor_organ ⇒ lookup returns null.
            'garbage-non-array', // defensive filter.
        ]);
    }

    public function test_all_returns_only_array_entries(): void
    {
        $all = (new AtlasBrainPathCatalog)->all();
        self::assertCount(3, $all);
    }

    public function test_find_resolves_by_id(): void
    {
        $entry = (new AtlasBrainPathCatalog)->find('pattern-design');
        self::assertIsArray($entry);
        self::assertSame('App\\Some\\Registry', $entry['executor_organ']);

        self::assertNull((new AtlasBrainPathCatalog)->find('nonexistent'));
    }

    public function test_executor_and_lens_lookups_or_null_when_missing(): void
    {
        $catalog = new AtlasBrainPathCatalog;
        self::assertSame('App\\Some\\Builder', $catalog->executorOrganFor('comprehension-deepening'));
        self::assertSame('deepen the model', $catalog->lensFor('comprehension-deepening'));
        self::assertNull($catalog->executorOrganFor('no-executor'));
        self::assertNull($catalog->lensFor('pattern-design'));
        self::assertNull($catalog->executorOrganFor('nonexistent'));
    }

    public function test_by_objective_kind_filters_matching_entries(): void
    {
        config()->set('atlas.brain.paths', [
            ['id' => 'frontier-harvest', 'objective_kind' => 'research'],
            ['id' => 'metrics-optimization', 'objective_kind' => 'optimization'],
            ['id' => 'simulation-twin', 'objective_kind' => 'optimization'],
            ['id' => 'pattern-design', 'objective_kind' => 'refactor'],
        ]);
        $catalog = new AtlasBrainPathCatalog;

        self::assertCount(2, $catalog->byObjectiveKind('optimization'));
        self::assertCount(1, $catalog->byObjectiveKind('research'));
        self::assertSame([], $catalog->byObjectiveKind('nonexistent'));
        self::assertSame([], $catalog->byObjectiveKind('   '));
    }

    public function test_catalog_organ_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit('app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainPathCatalog.php', true);
        self::assertSame('forbidden', $verdict);
    }
}

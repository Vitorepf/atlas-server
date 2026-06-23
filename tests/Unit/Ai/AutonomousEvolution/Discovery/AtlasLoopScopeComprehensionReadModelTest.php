<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModelBuilder;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionQuery;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionReadModel;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * PART 2 · B1 (P1-B) — the persistent read-model: a single JSON blob per snapshotId that rehydrates the
 * model BYTE-IDENTICALLY (no facts re-derived), so a cross-refill / time-series consumer reads proven facts
 * without rebuilding.
 */
final class AtlasLoopScopeComprehensionReadModelTest extends TestCase
{
    /** @var list<string> */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $d) {
            (new Process(['rm', '-rf', $d]))->run();
        }
        parent::tearDown();
    }

    private function store(): AtlasLoopScopeComprehensionReadModel
    {
        $dir = sys_get_temp_dir().'/atlas-rm-'.bin2hex(random_bytes(5));
        $this->tmp[] = $dir;
        $rm = new AtlasLoopScopeComprehensionReadModel;
        $rm->setRootForTesting($dir);

        return $rm;
    }

    private function model(): AtlasLoopScopeComprehensionModel
    {
        return (new AtlasLoopScopeComprehensionModelBuilder)
            ->build(base_path('tests/Fixtures/loop-comprehension-scope'), 'app/Scope', ['docs_roots' => ['docs']]);
    }

    public function test_round_trip_is_byte_identical(): void
    {
        $model = $this->model();
        $rm = $this->store();

        $put = $rm->put($model, 'app/Scope');
        $this->assertTrue($put['persisted']);

        $got = $rm->get($model->snapshotId);
        $this->assertNotNull($got);
        $this->assertSame($model->toArray(), $got->toArray(), 'the read-model rehydrates byte-identically');
        $this->assertSame($model->structuralProjection(), $got->structuralProjection());
    }

    public function test_latest_for_scope_returns_the_persisted_model(): void
    {
        $model = $this->model();
        $rm = $this->store();
        $rm->put($model, 'app/Scope');

        $latest = $rm->latestFor('app/Scope');
        $this->assertNotNull($latest);
        $this->assertSame($model->snapshotId, $latest->snapshotId);

        $this->assertNull($rm->latestFor('app/Nonexistent'), 'no persisted model for an unknown scope');
    }

    public function test_query_write_through_persists_on_build(): void
    {
        $rm = $this->store();
        $query = new AtlasLoopScopeComprehensionQuery(
            new AtlasLoopScopeComprehensionModelBuilder,
            base_path('tests/Fixtures/loop-comprehension-scope'),
            ['docs_roots' => ['docs']],
            null,
            $rm,
        );

        $model = $query->model('app/Scope');

        $got = $rm->get($model->snapshotId);
        $this->assertNotNull($got, 'building through the query write-through-persists the model');
        $this->assertSame($model->toArray(), $got->toArray());
    }
}

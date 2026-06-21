<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Models\AtlasLoopDecompositionOutcome;
use App\Services\Ai\AutonomousEvolution\AtlasLoopDecompositionOutcomeRecorder;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AtlasLoopDecompositionOutcomeRecorderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('atlas_loop_decomposition_outcomes')) {
            (require base_path('database/migrations/2026_06_16_000100_create_atlas_loop_decomposition_outcomes_table.php'))->up();
        }

        AtlasLoopDecompositionOutcome::query()->delete();
    }

    public function test_history_returns_empty_corpus_when_the_feature_flag_is_off_even_if_rows_exist(): void
    {
        config()->set('atlas.loop.decomposition_corpus_enabled', false);
        $this->seedOutcome('shape-a', certified: true);
        $this->seedOutcome('shape-a', certified: false);

        $history = (new AtlasLoopDecompositionOutcomeRecorder)->history('shape-a');

        $this->assertSame(['certified' => 0, 'total' => 0], $history);
    }

    public function test_history_reads_the_persisted_counts_when_the_feature_flag_is_on(): void
    {
        config()->set('atlas.loop.decomposition_corpus_enabled', true);
        $this->seedOutcome('shape-a', certified: true);
        $this->seedOutcome('shape-a', certified: false);
        $this->seedOutcome('shape-b', certified: true);

        $history = (new AtlasLoopDecompositionOutcomeRecorder)->history('shape-a');

        $this->assertSame(['certified' => 1, 'total' => 2], $history);
    }

    public function test_record_and_history_degrade_to_no_op_when_the_feature_flag_is_on_but_no_db_is_bound(): void
    {
        config()->set('atlas.loop.decomposition_corpus_enabled', true);
        $this->seedOutcome('shape-a', certified: true);

        $this->withContainerWithoutDatabase(function (): void {
            $recorder = new AtlasLoopDecompositionOutcomeRecorder;

            $recorder->record([
                'nodes' => [
                    ['id' => 'node-1', 'target_area' => 'app/Support/Helper.php'],
                ],
            ], 'refactor_extract_class', false, 'obra_not_certified:partial', 2);

            $this->assertSame(['certified' => 0, 'total' => 0], $recorder->history('shape-a'));
        });

        $this->assertSame(1, AtlasLoopDecompositionOutcome::query()->count(), 'without a db binding the recorder must not write or consult the corpus');
    }

    private function seedOutcome(string $fingerprintHash, bool $certified): void
    {
        AtlasLoopDecompositionOutcome::query()->create([
            'fingerprint_hash' => $fingerprintHash,
            'objective_kind' => 'refactor_extract_class',
            'node_count' => 2,
            'certified' => $certified,
            'thrashed' => ! $certified,
            'terminal_reason' => $certified ? 'certified' : 'obra_not_certified:partial',
            'rounds' => 1,
        ]);
    }

    private function withContainerWithoutDatabase(callable $callback): void
    {
        $originalContainer = Container::getInstance();
        $originalFacadeApplication = Facade::getFacadeApplication();

        $container = new Container;
        $container->instance('config', new Repository([
            'atlas.loop.decomposition_corpus_enabled' => true,
        ]));

        Container::setInstance($container);
        Facade::setFacadeApplication($container);

        try {
            $callback();
        } finally {
            Container::setInstance($originalContainer);
            Facade::setFacadeApplication($originalFacadeApplication);
        }
    }
}

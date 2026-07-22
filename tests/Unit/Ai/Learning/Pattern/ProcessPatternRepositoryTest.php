<?php

namespace Tests\Unit\Ai\Cognitive\Pattern;

use App\Services\Ai\Cognitive\Pattern\ProcessPatternRepository;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProcessPatternRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_05_07_150000_create_process_patterns_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('process_pattern_applications');
        Schema::dropIfExists('process_patterns');

        parent::tearDown();
    }

    public function test_repository_seeds_canonical_patterns_and_authors_pattern(): void
    {
        $repository = app(ProcessPatternRepository::class);

        $this->assertGreaterThanOrEqual(5, count($repository->catalog()));
        $this->assertNotNull($repository->findByName('validate-then-scale'));

        $pattern = $repository->upsert([
            'name' => 'measure-before-refactor',
            'category' => 'architecture',
            'intent' => 'Measure system behavior before changing structure.',
            'problem_context' => 'Refactor may improve aesthetics while harming runtime.',
            'forces' => [['name' => 'quality']],
            'solution' => ['abstract' => 'Measure first.', 'steps' => ['baseline', 'change', 'compare']],
            'consequences' => ['pros' => ['evidence'], 'cons' => [], 'trade_offs' => []],
        ]);

        $this->assertSame('measure-before-refactor', $pattern['name']);
        $this->assertSame('architecture', $pattern['category']);
    }
}

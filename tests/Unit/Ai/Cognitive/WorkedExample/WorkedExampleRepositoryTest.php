<?php

namespace Tests\Unit\Ai\Cognitive\WorkedExample;

use App\Services\Ai\Cognitive\WorkedExample\WorkedExampleRepository;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WorkedExampleRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_05_07_140000_create_worked_examples_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('worked_examples');

        parent::tearDown();
    }

    public function test_repository_creates_lists_and_finds_worked_example(): void
    {
        $repository = app(WorkedExampleRepository::class);
        $example = $repository->create(
            topic: 'pull-request-review',
            domain: 'programming',
            title: 'PR review estrutural',
            problemContext: 'Revisar PR com migration sensivel.',
            solutionFull: [
                ['step' => 1, 'action' => 'Leia o diff completo.'],
                ['step' => 2, 'action' => 'Rode migration e rollback.'],
            ],
            source: 'canonical_library',
        );

        $this->assertSame('atlas.cognitive.worked_example.v1', $example['schema_version']);
        $this->assertSame('PR review estrutural', $example['title']);
        $this->assertSame($example['id'], $repository->find($example['id'])['id']);
        $this->assertCount(1, $repository->list('programming'));
        $this->assertCount(1, $repository->candidates($example['knowledge_node_id'], 'programming'));
    }
}

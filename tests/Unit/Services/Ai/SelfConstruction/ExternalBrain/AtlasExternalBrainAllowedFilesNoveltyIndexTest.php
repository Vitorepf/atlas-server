<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAllowedFilesNoveltyIndex;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainAllowedFilesNoveltyIndexTest extends TestCase
{
    private AtlasExternalBrainAllowedFilesNoveltyIndex $index;

    protected function setUp(): void
    {
        $this->index = new AtlasExternalBrainAllowedFilesNoveltyIndex;
    }

    public function test_exact_duplicate_flagged(): void
    {
        $result = $this->index->evaluate(
            ['app/Foo.php', 'tests/FooTest.php'],
            [['task_id' => 't1', 'allowed_files' => ['tests/FooTest.php', 'app/Foo.php']]]
        );

        $this->assertFalse($result['novel']);
        $this->assertContains(AtlasExternalBrainAllowedFilesNoveltyIndex::FLAG_EXACT_DUPLICATE, $result['flags']);
    }

    public function test_same_impl_different_test_flagged(): void
    {
        $result = $this->index->evaluate(
            ['app/Foo.php', 'tests/FooTest.php'],
            [['task_id' => 't1', 'allowed_files' => ['app/Foo.php', 'tests/FooOtherTest.php']]]
        );

        $this->assertFalse($result['novel']);
        $this->assertContains(AtlasExternalBrainAllowedFilesNoveltyIndex::FLAG_SAME_IMPL_DIFFERENT_TEST, $result['flags']);
    }

    public function test_same_test_different_wrapper_flagged(): void
    {
        $result = $this->index->evaluate(
            ['app/Wrappers/Foo.php', 'tests/FooTest.php'],
            [['task_id' => 't1', 'allowed_files' => ['app/Wrappers/Bar.php', 'tests/FooTest.php']]]
        );

        $this->assertFalse($result['novel']);
        $this->assertContains(AtlasExternalBrainAllowedFilesNoveltyIndex::FLAG_SAME_TEST_DIFFERENT_WRAPPER, $result['flags']);
    }

    public function test_disjoint_implementation_organs_pass(): void
    {
        $result = $this->index->evaluate(
            ['app/Services/A.php', 'tests/ATest.php'],
            [['task_id' => 't1', 'allowed_files' => ['app/Services/B.php', 'tests/BTest.php']]]
        );

        $this->assertTrue($result['novel']);
        $this->assertEmpty($result['flags']);
    }

    public function test_empty_live_tasks_is_novel(): void
    {
        $result = $this->index->evaluate(['app/Foo.php', 'tests/FooTest.php'], []);

        $this->assertTrue($result['novel']);
    }

    public function test_schema_present(): void
    {
        $result = $this->index->evaluate([], []);
        $this->assertSame(AtlasExternalBrainAllowedFilesNoveltyIndex::SCHEMA, $result['schema']);
    }
}

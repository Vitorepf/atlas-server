<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionNativeTestFeedbackRepairLoop;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasSelfConstructionNativeTestFeedbackRepairLoop emits a
 * create_test_file_skeleton proposal for a test_file_not_found failure.
 */
final class AtlasSelfConstructionNativeTestFeedbackRepairLoopFileNotFoundTest extends TestCase
{
    public function test_test_file_not_found_yields_create_test_file_skeleton_template(): void
    {
        $loop = new AtlasSelfConstructionNativeTestFeedbackRepairLoop;
        $verdict = $loop->repair([
            [
                'failure_kind' => 'test_file_not_found',
                'target_path' => 'tests/Unit/Ai/Foo/BarTest.php',
                'class_name' => 'AtlasFooBar',
            ],
        ], ['allowed_files' => ['tests/Unit/Ai/Foo/BarTest.php']]);

        $this->assertCount(1, $verdict['proposals']);
        $this->assertSame(
            AtlasSelfConstructionNativeTestFeedbackRepairLoop::TEMPLATE_CREATE_TEST_FILE,
            $verdict['proposals'][0]['template_id'],
        );
        $this->assertSame(
            'tests/Unit/Ai/Foo/BarTest.php',
            $verdict['proposals'][0]['target_path'],
        );
        $this->assertStringContainsString('BarTest.php', $verdict['proposals'][0]['hint']);
        $this->assertStringContainsString('AtlasFooBar', $verdict['proposals'][0]['hint']);
        $this->assertSame('partial_or_complete', $verdict['response']);
        $this->assertSame([], $verdict['unknown_failures']);
    }

    public function test_test_file_not_found_outside_allowed_files_is_unknown(): void
    {
        $loop = new AtlasSelfConstructionNativeTestFeedbackRepairLoop;
        $verdict = $loop->repair([
            [
                'failure_kind' => 'test_file_not_found',
                'target_path' => 'tests/ForeignTest.php',
                'class_name' => 'Foreign',
            ],
        ], ['allowed_files' => ['app/Foo.php']]);

        $this->assertCount(0, $verdict['proposals']);
        $this->assertCount(1, $verdict['unknown_failures']);
        $this->assertSame('needs_external_capability', $verdict['response']);
    }

    public function test_existing_templates_still_operate_alongside_new_one(): void
    {
        $loop = new AtlasSelfConstructionNativeTestFeedbackRepairLoop;
        $verdict = $loop->repair([
            [
                'failure_kind' => 'missing_class',
                'target_path' => 'app/Foo.php',
                'class_name' => 'AtlasFoo',
            ],
            [
                'failure_kind' => 'test_file_not_found',
                'target_path' => 'tests/FooTest.php',
                'class_name' => 'AtlasFooTest',
            ],
        ], ['allowed_files' => ['app/Foo.php', 'tests/FooTest.php']]);

        $this->assertCount(2, $verdict['proposals']);
        $this->assertSame(
            AtlasSelfConstructionNativeTestFeedbackRepairLoop::TEMPLATE_STUB_CLASS,
            $verdict['proposals'][0]['template_id'],
        );
        $this->assertSame(
            AtlasSelfConstructionNativeTestFeedbackRepairLoop::TEMPLATE_CREATE_TEST_FILE,
            $verdict['proposals'][1]['template_id'],
        );
        $this->assertSame('partial_or_complete', $verdict['response']);
    }
}

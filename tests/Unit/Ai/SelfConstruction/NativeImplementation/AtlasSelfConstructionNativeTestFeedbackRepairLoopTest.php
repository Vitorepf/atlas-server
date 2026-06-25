<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\NativeImplementation;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionNativeTestFeedbackRepairLoop;
use Tests\TestCase;

final class AtlasSelfConstructionNativeTestFeedbackRepairLoopTest extends TestCase
{
    private function plan(): array
    {
        return ['allowed_files' => ['app/Foo.php', 'tests/FooTest.php']];
    }

    public function test_missing_class_yields_stub_class_template(): void
    {
        $verdict = (new AtlasSelfConstructionNativeTestFeedbackRepairLoop)->repair([
            ['failure_kind' => 'missing_class', 'target_path' => 'app/Foo.php', 'class_name' => 'AtlasFoo'],
        ], $this->plan());

        $this->assertSame(AtlasSelfConstructionNativeTestFeedbackRepairLoop::TEMPLATE_STUB_CLASS, $verdict['proposals'][0]['template_id']);
        $this->assertStringContainsString('AtlasFoo', $verdict['proposals'][0]['hint']);
    }

    public function test_namespace_mismatch_yields_fix_namespace_template(): void
    {
        $verdict = (new AtlasSelfConstructionNativeTestFeedbackRepairLoop)->repair([
            ['failure_kind' => 'namespace_mismatch', 'target_path' => 'app/Foo.php', 'expected_namespace' => 'App\\Services\\X'],
        ], $this->plan());

        $this->assertSame(AtlasSelfConstructionNativeTestFeedbackRepairLoop::TEMPLATE_FIX_NAMESPACE, $verdict['proposals'][0]['template_id']);
        $this->assertStringContainsString('App\\Services\\X', $verdict['proposals'][0]['hint']);
    }

    public function test_assertion_mismatch_yields_align_assertion_template(): void
    {
        $verdict = (new AtlasSelfConstructionNativeTestFeedbackRepairLoop)->repair([
            ['failure_kind' => 'assertion_mismatch', 'target_path' => 'tests/FooTest.php', 'expected' => '42', 'actual' => '41'],
        ], $this->plan());

        $this->assertSame(AtlasSelfConstructionNativeTestFeedbackRepairLoop::TEMPLATE_ALIGN_ASSERTION, $verdict['proposals'][0]['template_id']);
        $this->assertStringContainsString('expected 42', $verdict['proposals'][0]['hint']);
        $this->assertStringContainsString('got 41', $verdict['proposals'][0]['hint']);
    }

    public function test_import_error_yields_add_use_template(): void
    {
        $verdict = (new AtlasSelfConstructionNativeTestFeedbackRepairLoop)->repair([
            ['failure_kind' => 'import_error', 'target_path' => 'app/Foo.php', 'missing_symbol' => 'App\\Services\\Bar'],
        ], $this->plan());

        $this->assertSame(AtlasSelfConstructionNativeTestFeedbackRepairLoop::TEMPLATE_ADD_USE, $verdict['proposals'][0]['template_id']);
        $this->assertStringContainsString('add use App\\Services\\Bar', $verdict['proposals'][0]['hint']);
    }

    public function test_unknown_failure_kind_returns_needs_external_capability(): void
    {
        $verdict = (new AtlasSelfConstructionNativeTestFeedbackRepairLoop)->repair([
            ['failure_kind' => 'cosmic_ray_bit_flip', 'target_path' => 'app/Foo.php'],
        ], $this->plan());

        $this->assertSame(AtlasSelfConstructionNativeTestFeedbackRepairLoop::RESPONSE_NEEDS_EXTERNAL, $verdict['response']);
        $this->assertSame([], $verdict['proposals']);
        $this->assertSame('cosmic_ray_bit_flip', $verdict['unknown_failures'][0]['failure_kind']);
    }

    public function test_target_path_outside_allowed_files_is_unknown(): void
    {
        $verdict = (new AtlasSelfConstructionNativeTestFeedbackRepairLoop)->repair([
            ['failure_kind' => 'missing_class', 'target_path' => 'config/atlas.php'],
        ], $this->plan());

        $this->assertSame([], $verdict['proposals']);
        $this->assertSame('target_path_not_in_allowed_files', $verdict['unknown_failures'][0]['reason']);
    }

    public function test_mixed_known_and_unknown_failures_return_partial_response(): void
    {
        $verdict = (new AtlasSelfConstructionNativeTestFeedbackRepairLoop)->repair([
            ['failure_kind' => 'missing_class', 'target_path' => 'app/Foo.php'],
            ['failure_kind' => 'cosmic_ray', 'target_path' => 'app/Foo.php'],
        ], $this->plan());

        $this->assertSame('partial_or_complete', $verdict['response']);
        $this->assertCount(1, $verdict['proposals']);
        $this->assertCount(1, $verdict['unknown_failures']);
    }

    public function test_repair_loop_does_not_call_providers_or_edit_files(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/NativeImplementation/AtlasSelfConstructionNativeTestFeedbackRepairLoop.php'));
        foreach (['file_put_contents', 'shell_exec', 'exec(', 'system(', 'proc_open', 'curl_', 'Http::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "repair loop source must NOT contain {$forbidden}");
        }
    }
}

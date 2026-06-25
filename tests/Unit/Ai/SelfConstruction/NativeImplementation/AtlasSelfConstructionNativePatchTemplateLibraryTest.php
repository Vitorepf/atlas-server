<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\NativeImplementation;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionNativePatchTemplateLibrary;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves AtlasSelfConstructionNativePatchTemplateLibrary: templates() lists every registered template;
 * describe(id) returns its support contract + required vars; unknown id throws; supports() returns
 * matching template_ids for a task shape; supports() returns empty list for unsupported shape;
 * renderSkeleton substitutes variables byte-stably and throws on missing var.
 */
final class AtlasSelfConstructionNativePatchTemplateLibraryTest extends TestCase
{
    public function test_templates_lists_every_registered_template_sorted(): void
    {
        $r = (new AtlasSelfConstructionNativePatchTemplateLibrary)->templates();
        $ids = array_column($r, 'template_id');
        $copy = $ids;
        sort($copy, SORT_STRING);
        $this->assertSame($copy, $ids);
        $this->assertContains('pure_service', $ids);
        $this->assertContains('value_object', $ids);
        $this->assertContains('facts_only_gate', $ids);
        $this->assertContains('cli_wrapper', $ids);
        $this->assertContains('unit_test_scaffold', $ids);
    }

    public function test_describe_returns_support_contract_and_required_variables(): void
    {
        $d = (new AtlasSelfConstructionNativePatchTemplateLibrary)->describe('pure_service');
        $this->assertSame('service', $d['supports']['kind']);
        $this->assertContains('class_name', $d['required_variables']);
    }

    public function test_describe_throws_on_unknown_id(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/unknown_template_id/');
        (new AtlasSelfConstructionNativePatchTemplateLibrary)->describe('nope');
    }

    public function test_supports_returns_matching_template_ids(): void
    {
        $r = (new AtlasSelfConstructionNativePatchTemplateLibrary)->supports(['kind' => 'service', 'side_effects' => 'none']);
        $this->assertContains('pure_service', $r['matches']);
    }

    public function test_supports_returns_empty_for_unsupported_shape(): void
    {
        $r = (new AtlasSelfConstructionNativePatchTemplateLibrary)->supports(['kind' => 'witchcraft']);
        $this->assertSame([], $r['matches']);
    }

    public function test_render_skeleton_substitutes_variables_byte_stably(): void
    {
        $lib = new AtlasSelfConstructionNativePatchTemplateLibrary;
        $a = $lib->renderSkeleton('pure_service', ['namespace' => 'App\\Demo', 'class_name' => 'Foo', 'method_name' => 'bar']);
        $b = $lib->renderSkeleton('pure_service', ['namespace' => 'App\\Demo', 'class_name' => 'Foo', 'method_name' => 'bar']);
        $this->assertSame($a, $b);
        $this->assertStringContainsString('namespace App\\Demo;', $a);
        $this->assertStringContainsString('final class Foo', $a);
        $this->assertStringContainsString('public function bar(', $a);
    }

    public function test_render_skeleton_throws_when_required_variable_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing_variable:method_name/');
        (new AtlasSelfConstructionNativePatchTemplateLibrary)->renderSkeleton('pure_service', ['namespace' => 'X', 'class_name' => 'Y']);
    }
}

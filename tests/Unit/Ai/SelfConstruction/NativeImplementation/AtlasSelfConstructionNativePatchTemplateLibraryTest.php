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

    public function test_templates_exposes_proof_strength_for_every_entry(): void
    {
        $lib = new AtlasSelfConstructionNativePatchTemplateLibrary;
        foreach ($lib->templates() as $t) {
            $this->assertArrayHasKey('proof_strength', $t, "template {$t['template_id']} must expose proof_strength");
            $this->assertNotEmpty($t['proof_strength']);
        }
    }

    public function test_describe_exposes_proof_strength(): void
    {
        $d = (new AtlasSelfConstructionNativePatchTemplateLibrary)->describe('pure_service');
        $this->assertSame(AtlasSelfConstructionNativePatchTemplateLibrary::PROOF_STRENGTH_PRODUCTION, $d['proof_strength']);
    }

    public function test_production_templates_have_production_proof_strength(): void
    {
        $lib = new AtlasSelfConstructionNativePatchTemplateLibrary;
        foreach (['pure_service', 'value_object', 'facts_only_gate'] as $id) {
            $this->assertSame(
                AtlasSelfConstructionNativePatchTemplateLibrary::PROOF_STRENGTH_PRODUCTION,
                $lib->describe($id)['proof_strength'],
                "$id should be production"
            );
        }
    }

    public function test_unit_test_scaffold_is_behavioral_test_not_production(): void
    {
        $d = (new AtlasSelfConstructionNativePatchTemplateLibrary)->describe('unit_test_scaffold');
        $this->assertSame(AtlasSelfConstructionNativePatchTemplateLibrary::PROOF_STRENGTH_BEHAVIORAL_TEST, $d['proof_strength']);
        $this->assertNotSame(AtlasSelfConstructionNativePatchTemplateLibrary::PROOF_STRENGTH_PRODUCTION, $d['proof_strength']);
    }

    public function test_cli_wrapper_skeleton_does_not_contain_print_ok_only(): void
    {
        $lib = new AtlasSelfConstructionNativePatchTemplateLibrary;
        $rendered = $lib->renderSkeleton('cli_wrapper', [
            'namespace'          => 'App\\Console\\Commands',
            'class_name'         => 'FooCommand',
            'signature'          => 'atlas:foo',
            'description'        => 'Run foo',
            'service_fqn'        => 'App\\Services\\FooService',
            'service_class_short' => 'FooService',
        ]);
        $this->assertStringNotContainsString("\$this->line('ok')", $rendered);
        $this->assertStringContainsString('FooService', $rendered);
    }

    public function test_unit_test_scaffold_skeleton_does_not_contain_assert_true_true(): void
    {
        $lib = new AtlasSelfConstructionNativePatchTemplateLibrary;
        $rendered = $lib->renderSkeleton('unit_test_scaffold', [
            'namespace'         => 'Tests\\Unit',
            'class_name'        => 'FooTest',
            'target_fqn'        => 'App\\Services\\Foo',
            'target_class_short' => 'Foo',
        ]);
        $this->assertStringNotContainsString('assertTrue(true)', $rendered);
        $this->assertStringContainsString('assertInstanceOf', $rendered);
    }

    public function test_supports_exposes_match_proof_strengths(): void
    {
        $r = (new AtlasSelfConstructionNativePatchTemplateLibrary)->supports(['kind' => 'service', 'side_effects' => 'none']);
        $this->assertArrayHasKey('match_proof_strengths', $r);
        $this->assertArrayHasKey('pure_service', $r['match_proof_strengths']);
        $this->assertSame(AtlasSelfConstructionNativePatchTemplateLibrary::PROOF_STRENGTH_PRODUCTION, $r['match_proof_strengths']['pure_service']);
    }

    public function test_supports_distinguishes_production_from_scaffold_without_string_hacks(): void
    {
        $lib = new AtlasSelfConstructionNativePatchTemplateLibrary;
        $prodResult  = $lib->supports(['kind' => 'service', 'side_effects' => 'none']);
        $testResult  = $lib->supports(['kind' => 'unit_test']);
        $cliResult   = $lib->supports(['kind' => 'cli_wrapper']);

        $this->assertSame(
            AtlasSelfConstructionNativePatchTemplateLibrary::PROOF_STRENGTH_PRODUCTION,
            $prodResult['match_proof_strengths']['pure_service']
        );
        $this->assertSame(
            AtlasSelfConstructionNativePatchTemplateLibrary::PROOF_STRENGTH_BEHAVIORAL_TEST,
            $testResult['match_proof_strengths']['unit_test_scaffold']
        );
        $this->assertSame(
            AtlasSelfConstructionNativePatchTemplateLibrary::PROOF_STRENGTH_THIN_DELEGATION,
            $cliResult['match_proof_strengths']['cli_wrapper']
        );
    }
}

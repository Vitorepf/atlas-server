<?php

namespace Tests\Unit\Engineering\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphEdgeResolver;
use PHPUnit\Framework\TestCase;

class CodeGraphEdgeResolverTest extends TestCase
{
    private function nodeResolver(): callable
    {
        $known = [
            'ai-router' => 'node:app/Services/Ai/Router',
            'ai-compounding' => 'node:app/Services/Ai/Compounding',
            'ai-dev' => 'node:app/Services/Ai/Dev',
            'engineering' => 'node:app/Services/Engineering',
            'tests-ai-dev' => 'node:tests/Unit/Ai/Dev',
        ];

        return static fn (string $slug): ?string => $known[$slug] ?? null;
    }

    private function file(string $modulePath, string $moduleSlug, array $relations): array
    {
        return [
            'file_path' => $modulePath,
            'module_slug' => $moduleSlug,
            'relations' => array_merge(
                ['dependencies' => [], 'symbol_references' => [], 'test_targets' => []],
                $relations,
            ),
        ];
    }

    public function test_dependency_becomes_extracted_depends_on_edge(): void
    {
        $result = (new CodeGraphEdgeResolver)->resolve([
            $this->file('app/Services/Ai/Dev/Foo.php', 'ai-dev', [
                'dependencies' => [[
                    'kind' => 'php_use_ast',
                    'from_module' => 'ai-dev',
                    'to_module' => 'ai-router',
                    'symbol' => 'App\\Services\\Ai\\Router\\RouterService',
                    'file_path' => 'app/Services/Ai/Dev/Foo.php',
                    'line' => 7,
                ]],
            ]),
        ], $this->nodeResolver());

        $this->assertSame(CodeGraphEdgeResolver::SCHEMA, $result['schema_version']);
        $this->assertCount(1, $result['edges']);

        $edge = $result['edges'][0];
        $this->assertSame('node:app/Services/Ai/Dev', $edge['from_node_id']);
        $this->assertSame('node:app/Services/Ai/Router', $edge['to_node_id']);
        $this->assertSame('depends_on', $edge['edge_type']);
        $this->assertSame('EXTRACTED', $edge['confidence']);
        $this->assertSame(1.0, $edge['confidence_score']);
        $this->assertSame('app/Services/Ai/Dev/Foo.php:7', $edge['metadata']['source_ref']);
        $this->assertSame(1, $result['stats']['extracted']);
    }

    public function test_reference_without_import_evidence_is_inferred(): void
    {
        $result = (new CodeGraphEdgeResolver)->resolve([
            $this->file('app/Services/Ai/Dev/Bar.php', 'ai-dev', [
                'symbol_references' => [[
                    'kind' => 'class_constant',
                    'symbol' => 'App\\Services\\Ai\\Compounding\\Engine',
                    'target_module' => 'ai-compounding',
                    'file_path' => 'app/Services/Ai/Dev/Bar.php',
                    'line' => 12,
                ]],
            ]),
        ], $this->nodeResolver());

        $this->assertCount(1, $result['edges']);
        $edge = $result['edges'][0];
        $this->assertSame('node:app/Services/Ai/Compounding', $edge['to_node_id']);
        $this->assertSame('INFERRED', $edge['confidence']);
        $this->assertSame(0.85, $edge['confidence_score']);
        $this->assertTrue($edge['metadata']['inferred']);
        $this->assertSame(1, $result['stats']['inferred']);
    }

    public function test_reference_with_import_evidence_is_promoted_to_extracted(): void
    {
        // The file imports symbol Engine (to ai-router) AND references the same
        // symbol toward ai-compounding -> the reference edge is promoted.
        $result = (new CodeGraphEdgeResolver)->resolve([
            $this->file('app/Services/Ai/Dev/Baz.php', 'ai-dev', [
                'dependencies' => [[
                    'kind' => 'php_use_ast',
                    'from_module' => 'ai-dev',
                    'to_module' => 'engineering',
                    'symbol' => 'App\\Services\\Ai\\Compounding\\Engine',
                    'file_path' => 'app/Services/Ai/Dev/Baz.php',
                    'line' => 5,
                ]],
                'symbol_references' => [[
                    'kind' => 'class_constant',
                    'symbol' => 'App\\Services\\Ai\\Compounding\\Engine',
                    'target_module' => 'ai-compounding',
                    'file_path' => 'app/Services/Ai/Dev/Baz.php',
                    'line' => 20,
                ]],
            ]),
        ], $this->nodeResolver());

        $promoted = collect($result['edges'])->firstWhere('to_node_id', 'node:app/Services/Ai/Compounding');
        $this->assertNotNull($promoted);
        $this->assertSame('EXTRACTED', $promoted['confidence']);
        $this->assertSame(1.0, $promoted['confidence_score']);
    }

    public function test_test_target_becomes_inferred_tests_edge(): void
    {
        $result = (new CodeGraphEdgeResolver)->resolve([
            $this->file('tests/Unit/Ai/Dev/FooTest.php', 'tests-ai-dev', [
                'test_targets' => [[
                    'kind' => 'test_symbol_reference',
                    'symbol' => 'App\\Services\\Ai\\Router\\RouterService',
                    'target_module' => 'ai-router',
                    'test_path' => 'tests/Unit/Ai/Dev/FooTest.php',
                    'line' => 30,
                ]],
            ]),
        ], $this->nodeResolver());

        $this->assertCount(1, $result['edges']);
        $edge = $result['edges'][0];
        $this->assertSame('node:tests/Unit/Ai/Dev', $edge['from_node_id']);
        $this->assertSame('node:app/Services/Ai/Router', $edge['to_node_id']);
        $this->assertSame('tests', $edge['edge_type']);
        $this->assertSame('INFERRED', $edge['confidence']);
        $this->assertSame(0.8, $edge['confidence_score']);
    }

    public function test_unknown_target_module_is_skipped_single_candidate(): void
    {
        $result = (new CodeGraphEdgeResolver)->resolve([
            $this->file('app/Services/Ai/Dev/Q.php', 'ai-dev', [
                'dependencies' => [[
                    'kind' => 'php_use_ast',
                    'from_module' => 'ai-dev',
                    'to_module' => 'vendor-unknown-thing',
                    'symbol' => 'Vendor\\Unknown\\Thing',
                    'file_path' => 'app/Services/Ai/Dev/Q.php',
                    'line' => 9,
                ]],
            ]),
        ], $this->nodeResolver());

        $this->assertCount(0, $result['edges']);
        $this->assertSame(1, $result['stats']['skipped_unresolved']);
    }

    public function test_self_loop_is_skipped(): void
    {
        $result = (new CodeGraphEdgeResolver)->resolve([
            $this->file('app/Services/Ai/Dev/Self.php', 'ai-dev', [
                'dependencies' => [[
                    'kind' => 'php_use_ast',
                    'from_module' => 'ai-dev',
                    'to_module' => 'ai-dev',
                    'symbol' => 'App\\Services\\Ai\\Dev\\Other',
                    'file_path' => 'app/Services/Ai/Dev/Self.php',
                    'line' => 3,
                ]],
            ]),
        ], $this->nodeResolver());

        $this->assertCount(0, $result['edges']);
        $this->assertSame(1, $result['stats']['skipped_self']);
    }

    public function test_duplicate_edges_dedupe_and_upgrade_confidence(): void
    {
        // File 1 references ai-router with no import evidence (INFERRED);
        // File 2 imports ai-router (EXTRACTED) -> dedupe must upgrade to EXTRACTED.
        $result = (new CodeGraphEdgeResolver)->resolve([
            $this->file('app/Services/Ai/Dev/A.php', 'ai-dev', [
                'symbol_references' => [[
                    'kind' => 'class_constant',
                    'symbol' => 'App\\Services\\Ai\\Router\\RouterService',
                    'target_module' => 'ai-router',
                    'file_path' => 'app/Services/Ai/Dev/A.php',
                    'line' => 11,
                ]],
            ]),
            $this->file('app/Services/Ai/Dev/B.php', 'ai-dev', [
                'dependencies' => [[
                    'kind' => 'php_use_ast',
                    'from_module' => 'ai-dev',
                    'to_module' => 'ai-router',
                    'symbol' => 'App\\Services\\Ai\\Router\\RouterService',
                    'file_path' => 'app/Services/Ai/Dev/B.php',
                    'line' => 6,
                ]],
            ]),
        ], $this->nodeResolver());

        $this->assertCount(1, $result['edges']);
        $edge = $result['edges'][0];
        $this->assertSame('EXTRACTED', $edge['confidence']);
        $this->assertSame(1.0, $edge['confidence_score']);
        $this->assertSame(2, $edge['metadata']['occurrences']);
        $this->assertSame(1, $result['stats']['deduped']);
    }
}

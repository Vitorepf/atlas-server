<?php

declare(strict_types=1);

namespace Tests\Feature\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphTreeSitterExtractor;
use Tests\TestCase;

/**
 * AP-815 A1 — the tree-sitter extractor wrapper. Proves it is governed (flag off →
 * empty, caller falls back) and that it captures the real AST symbols the regex
 * indexer missed — including the React `const App = () =>` arrow component.
 */
final class CodeGraphTreeSitterExtractorTest extends TestCase
{
    public function test_returns_empty_when_flag_off_so_caller_falls_back(): void
    {
        config()->set('atlas.code_graph.real_edges', false);

        $out = app(CodeGraphTreeSitterExtractor::class)->extract([
            ['path' => 'App.tsx', 'language' => 'typescript', 'content' => 'export const App = () => null;'],
        ]);

        $this->assertSame([], $out, 'flag off → empty so the indexer keeps its regex path');
    }

    public function test_empty_input_returns_empty(): void
    {
        config()->set('atlas.code_graph.real_edges', true);

        $this->assertSame([], app(CodeGraphTreeSitterExtractor::class)->extract([]));
    }

    public function test_extracts_real_symbols_including_react_arrow_component(): void
    {
        config()->set('atlas.code_graph.real_edges', true);

        $content = "import React from 'react';\n"
            ."export const App = () => { return null; };\n"
            ."const helper = function () {};\n"
            ."function regular() {}\n"
            ."class Widget {}\n"
            ."export interface Props { x: number; }\n";

        $out = app(CodeGraphTreeSitterExtractor::class)->extract([
            ['path' => 'App.tsx', 'language' => 'typescript', 'content' => $content],
        ]);

        if ($out === []) {
            $this->markTestSkipped('tree-sitter runtime (venv) unavailable in this environment.');
        }

        $names = array_map(static fn (array $n): string => (string) ($n['label'] ?? ''), $out['App.tsx']['symbols'] ?? []);

        // The whole point of A1 for React: the arrow component the regex half-missed.
        $this->assertContains('App', $names, 'const App = () => must be captured');
        $this->assertContains('helper', $names, 'const = function expression must be captured');
        $this->assertContains('regular', $names);
        $this->assertContains('Widget', $names);
        $this->assertContains('Props', $names);
    }
}

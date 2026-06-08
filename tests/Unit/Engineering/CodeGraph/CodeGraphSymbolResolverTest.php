<?php

namespace Tests\Unit\Engineering\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphSymbolResolver;
use PHPUnit\Framework\TestCase;

class CodeGraphSymbolResolverTest extends TestCase
{
    private function sym(string $name, string $file, string $type = 'class'): array
    {
        return ['name' => $name, 'type' => $type, 'file_path' => $file];
    }

    private function rel(string $file, string $symbol, string $kind): array
    {
        return ['file_path' => $file, 'symbol' => $symbol, 'kind' => $kind];
    }

    public function test_dependency_creates_extracted_symbol_edge(): void
    {
        $r = (new CodeGraphSymbolResolver)->resolve(
            [
                $this->sym('App\\Services\\Ai\\Dev\\Foo', 'app/Services/Ai/Dev/Foo.php'),
                $this->sym('App\\Services\\Ai\\Router\\RouterService', 'app/Services/Ai/Router/RouterService.php'),
            ],
            [$this->rel('app/Services/Ai/Dev/Foo.php', 'App\\Services\\Ai\\Router\\RouterService', 'php_use_ast')],
        );

        $this->assertSame(CodeGraphSymbolResolver::SCHEMA, $r['schema_version']);
        $this->assertCount(1, $r['edges']);
        $edge = $r['edges'][0];
        $this->assertSame('sym:App\\Services\\Ai\\Dev\\Foo', $edge['from_node_id']);
        $this->assertSame('sym:App\\Services\\Ai\\Router\\RouterService', $edge['to_node_id']);
        $this->assertSame('depends_on', $edge['edge_type']);
        $this->assertSame('EXTRACTED', $edge['confidence']);
        $this->assertContains('sym:App\\Services\\Ai\\Router\\RouterService', $r['symbol_node_ids']);
    }

    public function test_reference_without_import_is_inferred(): void
    {
        $r = (new CodeGraphSymbolResolver)->resolve(
            [
                $this->sym('App\\A\\Bar', 'app/A/Bar.php'),
                $this->sym('App\\B\\Engine', 'app/B/Engine.php'),
            ],
            [$this->rel('app/A/Bar.php', 'App\\B\\Engine', 'class_constant')],
        );

        $this->assertCount(1, $r['edges']);
        $this->assertSame('INFERRED', $r['edges'][0]['confidence']);
        $this->assertSame(0.85, $r['edges'][0]['confidence_score']);
    }

    public function test_reference_with_import_evidence_is_promoted(): void
    {
        $r = (new CodeGraphSymbolResolver)->resolve(
            [
                $this->sym('App\\A\\Baz', 'app/A/Baz.php'),
                $this->sym('App\\B\\Engine', 'app/B/Engine.php'),
            ],
            [
                $this->rel('app/A/Baz.php', 'App\\B\\Engine', 'php_use_ast'),     // import evidence
                $this->rel('app/A/Baz.php', 'App\\B\\Engine', 'class_constant'),  // reference -> promoted (dedup keeps EXTRACTED)
            ],
        );

        $this->assertCount(1, $r['edges']);
        $this->assertSame('EXTRACTED', $r['edges'][0]['confidence']);
        $this->assertSame(2, $r['edges'][0]['metadata']['occurrences']);
    }

    public function test_ambiguous_target_is_skipped_single_candidate(): void
    {
        $r = (new CodeGraphSymbolResolver)->resolve(
            [
                $this->sym('App\\A\\Caller', 'app/A/Caller.php'),
                $this->sym('App\\Dup\\Thing', 'app/X/Thing.php'),
                $this->sym('App\\Dup\\Thing', 'app/Y/Thing.php'), // same FQN, two files => collision
            ],
            [$this->rel('app/A/Caller.php', 'App\\Dup\\Thing', 'php_use_ast')],
        );

        $this->assertCount(0, $r['edges']);
        $this->assertSame(1, $r['stats']['ambiguous_target']);
    }

    public function test_unknown_target_skipped_and_self_loop_skipped(): void
    {
        $r = (new CodeGraphSymbolResolver)->resolve(
            [$this->sym('App\\A\\Solo', 'app/A/Solo.php')],
            [
                $this->rel('app/A/Solo.php', 'Vendor\\Unknown\\Thing', 'php_use_ast'), // unknown target
                $this->rel('app/A/Solo.php', 'App\\A\\Solo', 'class_constant'),         // self
            ],
        );

        $this->assertCount(0, $r['edges']);
        $this->assertSame(1, $r['stats']['skipped_unresolved']);
        $this->assertSame(1, $r['stats']['skipped_self']);
    }
}

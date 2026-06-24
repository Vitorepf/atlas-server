<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Semantic;

use App\Services\Ai\SelfConstruction\Maestro\Semantic\AtlasMaestroSemanticSymbolResolver;
use Tests\TestCase;

/**
 * Proves the semantic symbol resolver against the LIVE repo: a real class resolves to its concrete file:line at
 * the class declaration, a method resolves to its function line, and a fabricated symbol returns exists=false
 * with fuzzy candidates and never a fabricated path.
 */
final class AtlasMaestroSemanticSymbolResolverTest extends TestCase
{
    private function resolver(): AtlasMaestroSemanticSymbolResolver
    {
        return new AtlasMaestroSemanticSymbolResolver(base_path());
    }

    private function lineContains(string $relFile, int $line, string $needle): bool
    {
        $lines = file(base_path().'/'.$relFile, FILE_IGNORE_NEW_LINES) ?: [];

        return isset($lines[$line - 1]) && str_contains($lines[$line - 1], $needle);
    }

    public function test_resolves_a_real_class_to_its_declaration_line(): void
    {
        $out = $this->resolver()->resolve('App\\Services\\Ai\\SelfConstruction\\AtlasTaskPacketQualityInspector');

        $this->assertTrue($out['exists']);
        $this->assertSame('app/Services/Ai/SelfConstruction/AtlasTaskPacketQualityInspector.php', $out['file']);
        $this->assertSame('class', $out['kind']);
        $this->assertTrue(
            $this->lineContains($out['file'], $out['line'], 'class AtlasTaskPacketQualityInspector'),
            'resolved line must be the class declaration',
        );
    }

    public function test_resolves_a_short_name_too(): void
    {
        $out = $this->resolver()->resolve('AtlasTaskPacketQualityInspector');

        $this->assertTrue($out['exists']);
        $this->assertSame('app/Services/Ai/SelfConstruction/AtlasTaskPacketQualityInspector.php', $out['file']);
    }

    public function test_resolves_a_method_to_its_function_line(): void
    {
        $out = $this->resolver()->resolve('App\\Services\\Ai\\SelfConstruction\\AtlasTaskPacketQualityInspector::inspect');

        $this->assertTrue($out['exists']);
        $this->assertSame('method', $out['kind']);
        $this->assertTrue($this->lineContains($out['file'], $out['line'], 'function inspect'));
    }

    public function test_fabricated_symbol_is_absent_with_candidates_and_no_invented_path(): void
    {
        $out = $this->resolver()->resolve('App\\Services\\Ai\\NonExistentClassXyz123');

        $this->assertFalse($out['exists']);
        $this->assertArrayNotHasKey('file', $out, 'never invents a path for an absent symbol');
        $this->assertNotEmpty($out['candidates']);
    }
}

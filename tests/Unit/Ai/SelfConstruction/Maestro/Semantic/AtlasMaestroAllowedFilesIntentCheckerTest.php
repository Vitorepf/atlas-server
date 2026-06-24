<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Semantic;

use App\Services\Ai\SelfConstruction\Maestro\Semantic\AtlasMaestroAllowedFilesIntentChecker;
use App\Services\Ai\SelfConstruction\Maestro\Semantic\AtlasMaestroSemanticSymbolResolver;
use Tests\TestCase;

final class AtlasMaestroAllowedFilesIntentCheckerTest extends TestCase
{
    private function checker(): AtlasMaestroAllowedFilesIntentChecker
    {
        return new AtlasMaestroAllowedFilesIntentChecker(new AtlasMaestroSemanticSymbolResolver(base_path()));
    }

    public function test_ok_when_cited_symbol_defining_file_is_allowed(): void
    {
        $result = $this->checker()->check([
            'objective' => 'Modify AtlasTaskPacketQualityInspector safely.',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasTaskPacketQualityInspector.php'],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame([
            [
                'symbol' => 'AtlasTaskPacketQualityInspector',
                'defining_file' => 'app/Services/Ai/SelfConstruction/AtlasTaskPacketQualityInspector.php',
            ],
        ], $result['matched_symbols']);
    }

    public function test_fails_when_cited_symbol_defining_file_is_outside_allowed_files(): void
    {
        $result = $this->checker()->check([
            'objective' => 'Modify AtlasTaskPacketQualityInspector safely.',
            'allowed_files' => ['docs/loop-foo.md'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('symbol_outside_allowed_files', $result['reason']);
        $this->assertSame('AtlasTaskPacketQualityInspector', $result['symbol']);
        $this->assertSame('app/Services/Ai/SelfConstruction/AtlasTaskPacketQualityInspector.php', $result['defining_file']);
    }

    public function test_prose_only_and_unknown_tokens_are_silently_skipped(): void
    {
        $result = $this->checker()->check([
            'objective' => 'Improve the worker flow with careful prose and Laravel primitives.',
            'allowed_files' => ['docs/loop-foo.md'],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['matched_symbols']);
    }
}

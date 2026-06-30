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

    public function test_sibling_file_not_allowed_by_file_prefix_smuggle(): void
    {
        // app/FooBar.php must NOT be allowed when allowed_files = ['app/Foo.php']
        $checker = new AtlasMaestroAllowedFilesIntentChecker(
            resolver: null,
            pathExistsCallback: static fn (string $p): bool => $p === 'app/FooBar.php',
        );
        $result = $checker->check([
            'objective' => 'Fix app/FooBar.php now.',
            'allowed_files' => ['app/Foo.php'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('symbol_outside_allowed_files', $result['reason']);
        $this->assertSame('app/FooBar.php', $result['defining_file']);
    }

    public function test_sibling_file_not_allowed_by_path_without_extension(): void
    {
        // app/FooBar.php must NOT be allowed when allowed_files = ['app/Foo'] (no extension)
        $checker = new AtlasMaestroAllowedFilesIntentChecker(
            resolver: null,
            pathExistsCallback: static fn (string $p): bool => $p === 'app/FooBar.php',
        );
        $result = $checker->check([
            'objective' => 'Fix app/FooBar.php now.',
            'allowed_files' => ['app/Foo'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('symbol_outside_allowed_files', $result['reason']);
    }

    public function test_missing_test_companion_surfaces_named_warning(): void
    {
        $checker = new AtlasMaestroAllowedFilesIntentChecker(
            resolver: null,
            pathExistsCallback: static fn (): bool => false,
        );
        $result = $checker->check([
            'objective' => 'Upgrade FooService.',
            'allowed_files' => ['app/Services/Foo/FooService.php'],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertNotEmpty($result['warnings']);
        $warning = $result['warnings'][0];
        $this->assertSame('missing_test_companion', $warning['kind']);
        $this->assertSame('app/Services/Foo/FooService.php', $warning['impl_file']);
        $this->assertSame('FooServiceTest.php', $warning['expected_test_basename']);
    }

    public function test_no_warning_when_test_companion_is_in_allowed_files(): void
    {
        $checker = new AtlasMaestroAllowedFilesIntentChecker(
            resolver: null,
            pathExistsCallback: static fn (): bool => false,
        );
        $result = $checker->check([
            'objective' => 'Upgrade FooService.',
            'allowed_files' => [
                'app/Services/Foo/FooService.php',
                'tests/Unit/Foo/FooServiceTest.php',
            ],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['warnings']);
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

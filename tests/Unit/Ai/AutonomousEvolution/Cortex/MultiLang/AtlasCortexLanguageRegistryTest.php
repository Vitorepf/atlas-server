<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex\MultiLang;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\MultiLang\AtlasCortexLanguageRegistry;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\MultiLang\UnknownLanguageException;
use Tests\TestCase;

class AtlasCortexLanguageRegistryTest extends TestCase
{
    private function seeded(): AtlasCortexLanguageRegistry
    {
        $registry = new AtlasCortexLanguageRegistry();
        $registry->register('php', ['parser_class' => 'A\\B\\PhpParser', 'extractor_class' => 'A\\B\\PhpExtractor', 'extensions' => ['php']]);
        $registry->register('typescript', ['parser_class' => 'A\\B\\TsParser', 'extractor_class' => 'A\\B\\TsExtractor', 'extensions' => ['ts', 'tsx']]);
        $registry->register('yaml', ['parser_class' => 'A\\B\\YamlParser', 'extractor_class' => 'A\\B\\YamlExtractor', 'extensions' => ['yml', 'yaml']]);

        return $registry;
    }

    public function test_supported_returns_three_languages_after_registration(): void
    {
        $registry = $this->seeded();

        self::assertSame(['php', 'typescript', 'yaml'], $registry->supported());
    }

    public function test_resolve_for_path_maps_extensions_deterministically(): void
    {
        $registry = $this->seeded();

        self::assertSame('php', $registry->resolveForPath('/some/path/X.php'));
        self::assertSame('typescript', $registry->resolveForPath('/x/y.ts'));
        self::assertSame('typescript', $registry->resolveForPath('/x/Y.tsx'));
        self::assertSame('yaml', $registry->resolveForPath('/x/config.yml'));
        self::assertSame('yaml', $registry->resolveForPath('/x/config.yaml'));
        self::assertNull($registry->resolveForPath('/x/file.unknown'));
    }

    public function test_get_returns_binding_without_autoloading_parser_class(): void
    {
        $registry = new AtlasCortexLanguageRegistry();
        $registry->register('php', ['parser_class' => 'Doesnt\\Exist\\NeverLoaded', 'extractor_class' => 'X', 'extensions' => ['php']]);

        $b = $registry->get('php');
        self::assertSame('Doesnt\\Exist\\NeverLoaded', $b['parser_class']);
        // Confirm we did NOT autoload (class still doesn't exist).
        self::assertFalse(class_exists('Doesnt\\Exist\\NeverLoaded', false));
    }

    public function test_get_unknown_language_throws_typed_exception(): void
    {
        $this->expectException(UnknownLanguageException::class);
        (new AtlasCortexLanguageRegistry)->get('cobol');
    }

    public function test_resolve_returns_byte_identical_output_across_two_runs(): void
    {
        $registry = $this->seeded();

        $a = [
            $registry->resolveForPath('/a.php'),
            $registry->resolveForPath('/b.ts'),
            $registry->resolveForPath('/c.yml'),
            $registry->resolveForPath('/d.unknown'),
        ];
        $b = [
            $registry->resolveForPath('/a.php'),
            $registry->resolveForPath('/b.ts'),
            $registry->resolveForPath('/c.yml'),
            $registry->resolveForPath('/d.unknown'),
        ];
        self::assertSame($a, $b);
    }
}

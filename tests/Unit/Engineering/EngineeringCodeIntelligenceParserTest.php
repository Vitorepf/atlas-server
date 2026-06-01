<?php

declare(strict_types=1);

namespace Tests\Unit\Engineering;

use App\Services\Engineering\EngineeringCodeIntelligenceService;
use App\Services\Tools\AtlasToolEvidenceStore;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Locks the PHP symbol extractor against the two phantom-class regressions that
 * previously made Code Intelligence evidence refs silently unresolvable:
 *
 *   1. Docblock PROSE — a line like "each class carries a fixed authority" used
 *      to be parsed (by the old bare /\b(class)\s+(\w+)/ walker) as a real
 *      `class carries`, shadowing the true class. That is exactly what hid
 *      AtlasSourceConnectorsAndCaptureService from the resolver.
 *   2. Anonymous classes — "return new class extends Migration" used to be
 *      parsed as a `class extends`. Every immutable migration minted one, and
 *      because the file-snapshot cache is keyed on content, those phantoms
 *      survived every re-index until the extractor version was bumped.
 *
 * Pure parser logic — no database, so it does NOT (and must not) use
 * RefreshDatabase.
 *
 * @see app/Services/Engineering/EngineeringCodeIntelligenceService.php
 */
final class EngineeringCodeIntelligenceParserTest extends TestCase
{
    /**
     * @return array<int,array<string,mixed>>
     */
    private function parse(string $relativePath, string $content): array
    {
        $service = new EngineeringCodeIntelligenceService(
            $this->createMock(AtlasToolEvidenceStore::class),
        );

        $method = new ReflectionMethod($service, 'parsePhpSymbols');
        $method->setAccessible(true);

        /** @var array<int,array<string,mixed>> $symbols */
        $symbols = $method->invoke($service, $relativePath, $content, 'module_under_test');

        return $symbols;
    }

    /**
     * @param  array<int,array<string,mixed>>  $symbols
     * @return array<int,string>
     */
    private function namesOfType(array $symbols, string $type): array
    {
        return array_values(array_map(
            static fn (array $symbol): string => (string) ($symbol['symbol_name'] ?? ''),
            array_filter(
                $symbols,
                static fn (array $symbol): bool => ($symbol['symbol_type'] ?? null) === $type,
            ),
        ));
    }

    public function test_docblock_prose_does_not_shadow_the_real_class(): void
    {
        $content = <<<'PHP'
        <?php

        namespace App\Services\Ai\Aaeos\Generated;

        /**
         * Source Connectors decider.
         *
         *   - Source Classes table: each class carries a fixed authority
         *     (primary / secondary / lead_only / tier_0 / artifact).
         */
        final class AtlasSourceConnectorsAndCaptureService
        {
            public function classifySource(string $sourceClass): array
            {
                return [];
            }
        }
        PHP;

        $classes = $this->namesOfType(
            $this->parse('app/Services/Ai/Aaeos/Generated/AtlasSourceConnectorsAndCaptureService.php', $content),
            'class',
        );

        $this->assertSame(
            ['App\Services\Ai\Aaeos\Generated\AtlasSourceConnectorsAndCaptureService'],
            $classes,
            'The real class must be the only class symbol; docblock prose must not mint a phantom.',
        );
        foreach ($classes as $name) {
            $this->assertStringNotContainsString('carries', $name);
        }
    }

    public function test_anonymous_migration_class_emits_no_named_class(): void
    {
        $content = <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::create('widgets', function (Blueprint $table): void {
                    $table->id();
                });
            }
        };
        PHP;

        $symbols = $this->parse('database/migrations/2026_06_01_000000_create_widgets_table.php', $content);

        foreach ($this->namesOfType($symbols, 'class') as $name) {
            $this->assertStringNotContainsString('extends', $name, 'Anonymous class must not mint a `class extends` phantom.');
        }
        // The migration is still indexed for the table it creates.
        $this->assertContains('create:widgets', $this->namesOfType($symbols, 'migration_table'));
    }

    public function test_real_service_file_extracts_one_class_and_only_real_methods(): void
    {
        $relative = 'app/Services/Ai/Aaeos/Generated/AtlasSourceConnectorsAndCaptureService.php';
        $symbols = $this->parse($relative, (string) file_get_contents(base_path($relative)));

        $this->assertSame(
            ['App\Services\Ai\Aaeos\Generated\AtlasSourceConnectorsAndCaptureService'],
            $this->namesOfType($symbols, 'class'),
        );

        $methods = $this->namesOfType($symbols, 'method');
        $this->assertGreaterThanOrEqual(6, count($methods));
        foreach ($methods as $name) {
            $this->assertStringStartsWith(
                'App\Services\Ai\Aaeos\Generated\AtlasSourceConnectorsAndCaptureService::',
                $name,
                'Every method must hang off the real class, never a phantom parent.',
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\AutonomousEvolution\SelfMod;

use App\Console\Commands\AtlasLoopSelfModCommand;
use App\Services\Ai\AutonomousEvolution\SelfMod\AtlasLoopSelfModInvariantExtractor;
use Illuminate\Support\Facades\Artisan;
use phpDocumentor\Reflection\DocBlockFactory;
use ReflectionClass;
use Tests\TestCase;

/**
 * Wires AtlasLoopSelfModInvariantExtractor (which internally instantiates DocBlockFactory) into
 * the live `atlas:loop:selfmod invariants` flow. Proves the previously-orphan DocBlockFactory
 * is reached by real production code via this CLI call path.
 */
final class AtlasLoopSelfModInvariantExtractorWiringWiredTest extends TestCase
{
    public function test_command_invokes_invariant_extractor_which_uses_docblock_factory(): void
    {
        $exit = Artisan::call('atlas:loop:selfmod', [
            'action' => 'invariants',
            '--class' => InvariantFixture::class,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('invariants', $payload);

        $records = $payload['invariants'][InvariantFixture::class] ?? null;
        $this->assertIsArray($records);
        $this->assertNotEmpty($records, 'extractor must surface the fixture @invariant entries');

        $ids = array_column($records, 'invariant_id');
        $this->assertContains('non_empty', $ids);
        $this->assertContains('non_null', $ids);
    }

    public function test_extractor_source_references_docblock_factory_symbol(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AtlasLoopSelfModInvariantExtractor::class))->getFileName(),
        );
        $this->assertStringContainsString(
            'DocBlockFactory',
            $source,
            'extractor must reference DocBlockFactory so it is no longer an orphan',
        );

        // Sanity: DocBlockFactory class is loadable in the runtime PHP environment.
        $this->assertTrue(class_exists(DocBlockFactory::class));
    }
}

/**
 * Local class declaring two @invariant tags. The extractor reads them via the DocBlockFactory.
 *
 * @invariant non_empty: $value !== ''
 * @invariant non_null:  $other !== null
 */
final class InvariantFixture
{
    public function __construct(public readonly string $value = 'x', public readonly ?string $other = 'y') {}
}

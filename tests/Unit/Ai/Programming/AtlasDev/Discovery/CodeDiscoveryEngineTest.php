<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Discovery;

use App\Services\Ai\Programming\AtlasDev\Discovery\CodeDiscoveryEngine;
use App\Services\Ai\Programming\AtlasDev\Discovery\RipgrepRunner;
use App\Services\Ai\Programming\AtlasDev\Discovery\SymbolLookup;
use App\Services\Ai\Programming\AtlasDev\Schemas\CodeDiscoveryManifest;
use PHPUnit\Framework\TestCase;

final class CodeDiscoveryEngineTest extends TestCase
{
    public function test_intent_with_clear_symbol_confirms_workspace_file(): void
    {
        $engine = new CodeDiscoveryEngine(
            rg: $this->ripgrepStub(),
            symbols: $this->symbolStub([
                'AtlasCliDevWorkflowService' => [
                    ['path' => 'app/Services/Ai/Cli/AtlasCliDevWorkflowService.php'],
                ],
                'AtlasCliDevWorkflowServiceTest' => [
                    ['path' => 'app/Services/Ai/Cli/AtlasCliDevWorkflowService.php'],
                    ['path' => 'tests/Unit/AtlasCliDevWorkflowServiceTest.php'],
                ],
            ]),
        );

        $manifest = $engine->discover(
            DiscoveryFixtureFactory::envelope(),
            DiscoveryFixtureFactory::compactSdd(),
        );

        $this->assertContains($manifest->confidence, [
            CodeDiscoveryManifest::CONFIDENCE_STRONG_INFERENCE,
            CodeDiscoveryManifest::CONFIDENCE_CONFIRMED_FACT,
        ]);
        $this->assertNotEmpty($manifest->likelyFiles, 'AtlasCliDevWorkflowService.php must surface as a likely_file');
        $paths = array_map(static fn ($candidate): string => $candidate->path, $manifest->likelyFiles);
        $this->assertTrue(
            (bool) array_filter($paths, static fn (string $path): bool => str_ends_with($path, 'AtlasCliDevWorkflowService.php')),
            'expected AtlasCliDevWorkflowService.php in likely_files',
        );

        $tests = array_map(static fn ($ref): string => $ref->ref, $manifest->relatedTests);
        $this->assertTrue(
            (bool) array_filter($tests, static fn (string $ref): bool => str_contains($ref, 'AtlasCliDevWorkflowServiceTest.php')),
            'conventional test path should be auto-attached',
        );
    }

    public function test_vague_intent_yields_blocking_ambiguity_with_missing_refs(): void
    {
        $engine = new CodeDiscoveryEngine(rg: $this->ripgrepStub(), symbols: null);

        $manifest = $engine->discover(
            DiscoveryFixtureFactory::envelope([
                'normalized_intent' => 'olha o codigo ai',
                'raw_intent' => 'olha o codigo ai',
            ]),
            DiscoveryFixtureFactory::compactSdd([
                'intent_normalized' => 'olha o codigo ai',
                'intent_raw' => 'olha o codigo ai',
            ]),
        );

        $this->assertSame(
            CodeDiscoveryManifest::CONFIDENCE_BLOCKING_AMBIGUITY,
            $manifest->confidence,
        );
        $this->assertNotEmpty($manifest->missingRefs, 'blocking_ambiguity must surface at least one missing_ref');
        $this->assertSame([], $manifest->likelyFiles, 'no path may be invented');
        $this->assertTrue($manifest->isBlocking());
    }

    public function test_intent_referencing_nonexistent_path_goes_to_missing_refs(): void
    {
        $engine = new CodeDiscoveryEngine(rg: $this->ripgrepStub(), symbols: null);

        $manifest = $engine->discover(
            DiscoveryFixtureFactory::envelope([
                'normalized_intent' => 'corrigir bug em app/Services/DoesNotExist.php',
                'raw_intent' => 'corrigir bug em app/Services/DoesNotExist.php',
            ]),
            DiscoveryFixtureFactory::compactSdd([
                'intent_normalized' => 'corrigir bug em app/Services/DoesNotExist.php',
                'intent_raw' => 'corrigir bug em app/Services/DoesNotExist.php',
            ]),
        );

        $this->assertSame([], $manifest->likelyFiles);
        $missing = array_map(static fn ($ref): string => $ref->what, $manifest->missingRefs);
        $this->assertContains('app/Services/DoesNotExist.php', $missing);
        $this->assertContains($manifest->confidence, [
            CodeDiscoveryManifest::CONFIDENCE_HYPOTHESIS,
            CodeDiscoveryManifest::CONFIDENCE_BLOCKING_AMBIGUITY,
        ]);
    }

    public function test_discovery_is_deterministic_byte_identical_payload(): void
    {
        $engine = new CodeDiscoveryEngine(
            rg: $this->ripgrepStub(),
            symbols: $this->symbolStub([
                'AtlasCliDevWorkflowService' => [
                    ['path' => 'app/Services/Ai/Cli/AtlasCliDevWorkflowService.php'],
                ],
                'AtlasCliDevWorkflowServiceTest' => [
                    ['path' => 'app/Services/Ai/Cli/AtlasCliDevWorkflowService.php'],
                ],
            ]),
        );

        $a = $engine->discover(
            DiscoveryFixtureFactory::envelope(),
            DiscoveryFixtureFactory::compactSdd(),
        );
        $b = $engine->discover(
            DiscoveryFixtureFactory::envelope(),
            DiscoveryFixtureFactory::compactSdd(),
        );

        $this->assertSame($a->toJson(), $b->toJson());
        $this->assertSame($a->manifestHash, $b->manifestHash);
    }

    public function test_no_rivals_or_benchmark_leakage_in_canonical_payload(): void
    {
        $engine = new CodeDiscoveryEngine(
            rg: $this->ripgrepStub(),
            symbols: $this->symbolStub([
                'AtlasCliDevWorkflowService' => [
                    ['path' => 'app/Services/Ai/Cli/AtlasCliDevWorkflowService.php'],
                ],
                'AtlasCliDevWorkflowServiceTest' => [
                    ['path' => 'app/Services/Ai/Cli/AtlasCliDevWorkflowService.php'],
                ],
            ]),
        );

        $serialized = $engine->discover(
            DiscoveryFixtureFactory::envelope(),
            DiscoveryFixtureFactory::compactSdd(),
        )->toJson();

        foreach (['rivals', 'benchmark', 'opus', 'messy_human_local', 'cost_normalized_score'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $serialized);
        }
    }

    private function ripgrepStub(): RipgrepRunner
    {
        return new class extends RipgrepRunner
        {
            public function __construct()
            {
                parent::__construct(binaryPath: '');
            }

            public function isAvailable(): bool
            {
                return false;
            }

            public function search(string $workspace, string $needle, array $includeGlobs = []): array
            {
                return [];
            }
        };
    }

    /**
     * @param  array<string,list<array<string,mixed>>>  $map
     */
    private function symbolStub(array $map): SymbolLookup
    {
        return new class($map) implements SymbolLookup
        {
            public function __construct(private readonly array $map) {}

            public function find(string $workspace, string $symbol): array
            {
                return $this->map[$symbol] ?? [];
            }
        };
    }
}

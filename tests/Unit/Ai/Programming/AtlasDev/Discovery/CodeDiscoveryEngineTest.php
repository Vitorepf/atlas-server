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

    public function test_frontend_html_path_reference_confirms_workspace_file(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-dev-discovery-html-'.bin2hex(random_bytes(4));
        mkdir($workspace.'/public', 0o755, true);
        file_put_contents($workspace.'/public/button.html', '<button>Save</button>');

        try {
            $engine = new CodeDiscoveryEngine(rg: $this->ripgrepStub(), symbols: null);

            $manifest = $engine->discover(
                DiscoveryFixtureFactory::envelope([
                    'workspace' => $workspace,
                    'normalized_intent' => 'ajuste a UI em public/button.html',
                    'raw_intent' => 'ajuste a UI em public/button.html',
                ]),
                DiscoveryFixtureFactory::compactSdd([
                    'intent_normalized' => 'ajuste a UI em public/button.html',
                    'intent_raw' => 'ajuste a UI em public/button.html',
                    'task_kind' => 'frontend',
                    'mode' => 'frontend_visual',
                ]),
            );
        } finally {
            @unlink($workspace.'/public/button.html');
            @rmdir($workspace.'/public');
            @rmdir($workspace);
        }

        $this->assertContains($manifest->confidence, [
            CodeDiscoveryManifest::CONFIDENCE_STRONG_INFERENCE,
            CodeDiscoveryManifest::CONFIDENCE_CONFIRMED_FACT,
        ]);
        $paths = array_map(static fn ($candidate): string => $candidate->path, $manifest->likelyFiles);
        $this->assertTrue(
            (bool) array_filter($paths, static fn (string $path): bool => str_ends_with($path, 'public/button.html')),
            'expected public/button.html in likely_files',
        );
    }

    public function test_explicit_path_reference_ranks_before_broader_symbol_hit(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-dev-discovery-explicit-path-'.bin2hex(random_bytes(4));
        $explicitPath = 'app/Services/Ai/Programming/AtlasDev/Support/AtlasDevStringListNormalizer.php';
        $broadHitPath = 'app/Console/Commands/AtlasCliDevPlanCommand.php';

        mkdir($workspace.'/'.dirname($explicitPath), 0o755, true);
        mkdir($workspace.'/'.dirname($broadHitPath), 0o755, true);
        file_put_contents($workspace.'/'.$explicitPath, "<?php\nfinal class AtlasDevStringListNormalizer {}\n");
        file_put_contents($workspace.'/'.$broadHitPath, "<?php\nfinal class AtlasCliDevPlanCommand {}\n");

        try {
            $engine = new CodeDiscoveryEngine(
                rg: $this->ripgrepStub(),
                symbols: $this->symbolStub([
                    'AtlasDevStringListNormalizer' => [
                        ['path' => $broadHitPath],
                    ],
                ]),
            );

            $intent = 'Refactor AtlasDevStringListNormalizer in '.$explicitPath.' and keep the public behavior unchanged.';

            $manifest = $engine->discover(
                DiscoveryFixtureFactory::envelope([
                    'workspace' => $workspace,
                    'normalized_intent' => $intent,
                    'raw_intent' => $intent,
                ]),
                DiscoveryFixtureFactory::compactSdd([
                    'intent_normalized' => $intent,
                    'intent_raw' => $intent,
                ]),
            );
        } finally {
            $this->removeDirectory($workspace);
        }

        $this->assertNotEmpty($manifest->likelyFiles);
        $this->assertSame($workspace.'/'.$explicitPath, $manifest->likelyFiles[0]->path);
        $this->assertSame('path mentioned in intent', $manifest->likelyFiles[0]->reason);

        $paths = array_map(static fn ($candidate): string => $candidate->path, $manifest->likelyFiles);
        $this->assertContains($workspace.'/'.$broadHitPath, $paths);
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

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }

        @rmdir($path);
    }
}

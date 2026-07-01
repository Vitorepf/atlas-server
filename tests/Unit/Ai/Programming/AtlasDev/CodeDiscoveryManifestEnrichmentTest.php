<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev;

use App\Services\Ai\AtlasOpenBrainService;
use App\Services\Ai\Programming\AtlasDev\Discovery\CodeDiscoveryEngine;
use App\Services\Ai\Programming\AtlasDev\Discovery\DocContextTierSelector;
use App\Services\Ai\Programming\AtlasDev\Discovery\OpenBrainProjectionAdapter;
use App\Services\Ai\Programming\AtlasDev\Discovery\SymbolLookup;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Pipeline\AtlasDevFastPathOrchestrator;
use App\Services\Ai\Programming\AtlasDev\Pipeline\IntakeNormalizer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RiskLevelScorer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RoutingDecisionEngine;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RunIdGenerator;
use App\Services\Ai\Programming\AtlasDev\Pipeline\SpecComposer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\TaskClassifier;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptQualityChecker;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptRenderer;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptSectionsMapper;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\ProviderPromptBuilder;
use App\Services\Ai\Programming\AtlasDev\Schemas\CodeDiscoveryManifest;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CodeCandidate;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ContextRef;
use RuntimeException;
use Tests\TestCase;

/**
 * Proves the additive CodeDiscoveryManifest enrichment: likely_callers and recent_outcome_facts
 * round-trip, default empty when unavailable, and never break a Dev run even when the enrichment
 * source throws (fail-open).
 */
final class CodeDiscoveryManifestEnrichmentTest extends TestCase
{
    private string $tmpStorage;

    private string $tmpWorkspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-enrich-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-enrich-ws-'.bin2hex(random_bytes(4));
        mkdir($this->tmpWorkspace.'/app/Services/Foo', 0o755, true);
        file_put_contents($this->tmpWorkspace.'/app/Services/Foo/FooService.php', "<?php\nclass FooService {}\n");
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpStorage);
        $this->rmrf($this->tmpWorkspace);
        parent::tearDown();
    }

    private function rmrf(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $items = scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path.'/'.$item;
            is_dir($full) ? $this->rmrf($full) : unlink($full);
        }
        rmdir($path);
    }

    private function baseManifest(array $overrides = []): CodeDiscoveryManifest
    {
        $defaults = [
            'runId' => 'run-1',
            'likelyFiles' => [new CodeCandidate(path: '/tmp/Foo.php', reason: 'test', confidence: 0.9)],
            'relatedSymbols' => [],
            'relatedTests' => [],
            'relatedCommands' => [],
            'confidence' => CodeDiscoveryManifest::CONFIDENCE_STRONG_INFERENCE,
            'missingRefs' => [],
            'forbiddenFiles' => [],
            'providerSafe' => true,
            'manifestHash' => 'abc',
        ];
        $args = array_merge($defaults, $overrides);

        return new CodeDiscoveryManifest(...$args);
    }

    // ── (a) round-trips likely_callers and recent_outcome_facts ──────────────

    public function test_manifest_round_trips_likely_callers_and_recent_outcome_facts(): void
    {
        $manifest = $this->baseManifest([
            'likelyCallers' => [new ContextRef(kind: ContextRef::KIND_FILE, ref: 'file:///tmp/Caller.php', reason: 'likely caller of Foo')],
            'recentOutcomeFacts' => ['outcome:succeeded:episode-1'],
        ]);

        $canonical = $manifest->toCanonicalArray();
        $this->assertArrayHasKey('likely_callers', $canonical);
        $this->assertArrayHasKey('recent_outcome_facts', $canonical);
        $this->assertSame(['outcome:succeeded:episode-1'], $canonical['recent_outcome_facts']);
        $this->assertSame('file:///tmp/Caller.php', $canonical['likely_callers'][0]['ref']);

        $rebuilt = CodeDiscoveryManifest::fromArray($canonical);
        $this->assertCount(1, $rebuilt->likelyCallers);
        $this->assertSame('file:///tmp/Caller.php', $rebuilt->likelyCallers[0]->ref);
        $this->assertSame(['outcome:succeeded:episode-1'], $rebuilt->recentOutcomeFacts);
        $this->assertSame($manifest->toCanonicalArray(), $rebuilt->toCanonicalArray());
    }

    public function test_schema_version_and_existing_fields_are_unchanged(): void
    {
        $manifest = $this->baseManifest();

        $this->assertSame('atlas.dev.code_discovery_manifest.v1', $manifest->schemaVersion());
        $canonical = $manifest->toCanonicalArray();
        foreach (['confidence', 'forbidden_files', 'likely_files', 'manifest_hash', 'missing_refs', 'provider_safe', 'related_commands', 'related_symbols', 'related_tests', 'run_id', 'schema_version'] as $key) {
            $this->assertArrayHasKey($key, $canonical, "existing field '{$key}' must remain present");
        }
    }

    // ── (b) both default to empty arrays when enrichment sources are unavailable ──

    public function test_new_fields_default_to_empty_arrays(): void
    {
        $manifest = $this->baseManifest();

        $this->assertSame([], $manifest->likelyCallers);
        $this->assertSame([], $manifest->recentOutcomeFacts);
        $canonical = $manifest->toCanonicalArray();
        $this->assertSame([], $canonical['likely_callers']);
        $this->assertSame([], $canonical['recent_outcome_facts']);
    }

    public function test_from_array_without_new_fields_defaults_to_empty(): void
    {
        $canonical = $this->baseManifest()->toCanonicalArray();
        unset($canonical['likely_callers'], $canonical['recent_outcome_facts']);

        $rebuilt = CodeDiscoveryManifest::fromArray($canonical);

        $this->assertSame([], $rebuilt->likelyCallers);
        $this->assertSame([], $rebuilt->recentOutcomeFacts);
    }

    // ── orchestrator wiring ────────────────────────────────────────────────

    private function orchestrator(?SymbolLookup $callerLookup = null): AtlasDevFastPathOrchestrator
    {
        return new AtlasDevFastPathOrchestrator(
            intake: new IntakeNormalizer(new RunIdGenerator),
            classifier: new TaskClassifier,
            riskScorer: new RiskLevelScorer,
            specComposer: new SpecComposer,
            tierSelector: new DocContextTierSelector,
            codeDiscovery: new CodeDiscoveryEngine,
            openBrainAdapter: new OpenBrainProjectionAdapter(new class extends AtlasOpenBrainService
            {
                public function __construct() {}

                public function contextPack(array $data, string $surface = 'api'): array
                {
                    return ['ok' => true, 'context_refs' => []];
                }
            }),
            promptBuilder: new ProviderPromptBuilder(
                sectionsMapper: new PromptSectionsMapper,
                renderer: new PromptRenderer,
                qualityChecker: new PromptQualityChecker,
            ),
            routingEngine: new RoutingDecisionEngine,
            receiptStorage: new ReceiptStorage($this->tmpStorage),
            callerLookup: $callerLookup,
        );
    }

    public function test_orchestrator_populates_likely_callers_via_symbol_lookup(): void
    {
        $fakeLookup = new class implements SymbolLookup
        {
            public function find(string $workspace, string $symbol): array
            {
                if ($symbol === 'FooService') {
                    return [['path' => $workspace.'/app/Services/Foo/FooServiceCaller.php', 'reason' => 'imports FooService']];
                }

                return [];
            }
        };

        $result = $this->orchestrator($fakeLookup)->planOnly(
            surfaceId: 'atlas_cli_dev',
            workspace: $this->tmpWorkspace,
            rawIntent: 'corrija o arquivo em app/Services/Foo/FooService.php',
        );

        $this->assertNotEmpty($result->discovery->likelyCallers, 'likely_callers must be populated when a lookup hit exists');
    }

    public function test_orchestrator_without_caller_lookup_yields_empty_likely_callers_and_outcome_facts(): void
    {
        $result = $this->orchestrator()->planOnly(
            surfaceId: 'atlas_cli_dev',
            workspace: $this->tmpWorkspace,
            rawIntent: 'corrija o arquivo em app/Services/Foo/FooService.php',
        );

        $this->assertSame([], $result->discovery->likelyCallers);
        $this->assertSame([], $result->discovery->recentOutcomeFacts);
    }

    // ── (c) an enrichment exception still yields a complete manifest (fail-open) ──

    public function test_throwing_symbol_lookup_still_yields_a_complete_manifest(): void
    {
        $throwingLookup = new class implements SymbolLookup
        {
            public function find(string $workspace, string $symbol): array
            {
                throw new RuntimeException('simulated code-intelligence failure');
            }
        };

        $result = $this->orchestrator($throwingLookup)->planOnly(
            surfaceId: 'atlas_cli_dev',
            workspace: $this->tmpWorkspace,
            rawIntent: 'corrija o arquivo em app/Services/Foo/FooService.php',
        );

        // Fail-open: enrichment threw, but the run completed with a fully intact manifest.
        $this->assertSame([], $result->discovery->likelyCallers);
        $this->assertNotEmpty($result->discovery->likelyFiles, 'the pre-existing manifest fields must survive enrichment failure');
        $this->assertSame(CodeDiscoveryManifest::SCHEMA_VERSION, $result->discovery->schemaVersion());
    }
}

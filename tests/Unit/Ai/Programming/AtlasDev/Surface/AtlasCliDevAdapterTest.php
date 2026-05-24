<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Surface;

use App\Services\Ai\Programming\AtlasDev\Discovery\CodeDiscoveryEngine;
use App\Services\Ai\Programming\AtlasDev\Discovery\DocContextTierSelector;
use App\Services\Ai\Programming\AtlasDev\Discovery\OpenBrainProjectionAdapter;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Pipeline\AtlasDevFastPathOrchestrator;
use App\Services\Ai\Programming\AtlasDev\Pipeline\IntakeNormalizer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\PlanOnlyResult;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RiskLevelScorer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RoutingDecisionEngine;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RunIdGenerator;
use App\Services\Ai\Programming\AtlasDev\Pipeline\SpecComposer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\TaskClassifier;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptQualityChecker;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptRenderer;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptSectionsMapper;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\ProviderPromptBuilder;
use App\Services\Ai\Programming\AtlasDev\Surface\AtlasCliDevAdapter;
use App\Services\Ai\Programming\AtlasDev\Surface\SurfaceResponseFormatter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Feature\Ai\Programming\AtlasDev\Http\FakeAtlasOpenBrainService;

/**
 * Unit-level parity coverage for the CLI surface adapter.
 *
 * The feature suite at tests/Feature/Ai/Programming/AtlasDev/Cli/ already
 * exercises the `atlas:cli:dev --efficient` command end-to-end. This unit
 * test guards the adapter in isolation:
 *   - surface_id constant is `atlas_cli_dev`;
 *   - build_envelope rejects unsupported overrides;
 *   - build_envelope accepts the legacy alias `atlas_cli_dev_efficient`;
 *   - format_plan_only emits the canonical projection without ui_hints and
 *     without absolute paths;
 *   - constructor depends on the intake + formatter only — never a provider.
 */
final class AtlasCliDevAdapterTest extends TestCase
{
    private string $tmpStorage;

    private string $tmpWorkspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-cli-adapter-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-cli-adapter-ws-'.bin2hex(random_bytes(4));
        mkdir($this->tmpWorkspace.'/app/Services/Foo', 0o755, true);
        mkdir($this->tmpWorkspace.'/tests/Unit/Services/Foo', 0o755, true);
        mkdir($this->tmpWorkspace.'/.git/refs/heads', 0o755, true);
        file_put_contents($this->tmpWorkspace.'/.git/HEAD', 'ref: refs/heads/main');
        file_put_contents(
            $this->tmpWorkspace.'/.git/refs/heads/main',
            '0123456789abcdef0123456789abcdef01234567',
        );
        file_put_contents(
            $this->tmpWorkspace.'/app/Services/Foo/FooService.php',
            "<?php\nclass FooService {}\n",
        );
        file_put_contents(
            $this->tmpWorkspace.'/tests/Unit/Services/Foo/FooServiceTest.php',
            "<?php\nclass FooServiceTest {}\n",
        );
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpStorage);
        $this->rmrf($this->tmpWorkspace);
        parent::tearDown();
    }

    public function test_surface_id_is_canonical_atlas_cli_dev(): void
    {
        $this->assertSame('atlas_cli_dev', $this->adapter()->surfaceId());
    }

    public function test_build_envelope_produces_atlas_cli_dev_with_atlas_dev_flow_id(): void
    {
        $envelope = $this->adapter()->buildEnvelope([
            'workspace' => $this->tmpWorkspace,
            'raw_intent' => 'corrija o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php',
            'user_constraints' => ['nao tocar vendor/'],
            'thread_id' => 'cli_thread_1',
            'flow_origin' => 'direct',
            'command_intent' => 'dev',
        ]);

        $this->assertSame('atlas_cli_dev', $envelope->surfaceId);
        $this->assertSame('atlas_dev', $envelope->flowId);
        $this->assertSame('direct', $envelope->flowOrigin);
        $this->assertSame('dev', $envelope->commandIntent);
        $this->assertSame('cli_thread_1', $envelope->surfaceContext->threadId);
        $this->assertSame(['nao tocar vendor/'], $envelope->userConstraints);
    }

    public function test_build_envelope_accepts_legacy_atlas_cli_dev_efficient_alias(): void
    {
        $envelope = $this->adapter()->buildEnvelope([
            'surface_id' => 'atlas_cli_dev_efficient',
            'workspace' => $this->tmpWorkspace,
            'raw_intent' => 'corrija o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php',
        ]);

        $this->assertSame('atlas_cli_dev', $envelope->surfaceId, 'legacy alias must normalise');
    }

    public function test_build_envelope_rejects_unsupported_surface_id_override(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->adapter()->buildEnvelope([
            'surface_id' => 'atlas_desktop_ai',
            'workspace' => $this->tmpWorkspace,
            'raw_intent' => 'corrija o teste',
        ]);
    }

    public function test_build_envelope_rejects_empty_raw_intent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->adapter()->buildEnvelope([
            'workspace' => $this->tmpWorkspace,
            'raw_intent' => '   ',
        ]);
    }

    public function test_build_envelope_rejects_empty_workspace(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->adapter()->buildEnvelope([
            'workspace' => '',
            'raw_intent' => 'corrija o teste',
        ]);
    }

    public function test_format_plan_only_returns_canonical_projection_without_ui_hints(): void
    {
        $result = $this->runPlanOnly('corrija o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php');

        $response = $this->adapter()->formatPlanOnly($result);

        $this->assertSame('plan_only', $response['kind']);
        $this->assertSame('atlas_cli_dev', $response['surface_id']);
        $this->assertArrayNotHasKey('ui_hints', $response, 'CLI must not surface Desktop ui_hints');
        $this->assertArrayHasKey('hashes', $response);
        $this->assertArrayHasKey('persisted_artifact_refs', $response);
        $this->assertArrayHasKey('workspace_label', $response);
        $this->assertArrayNotHasKey('workspace', $response, 'absolute workspace must not appear');
    }

    public function test_format_plan_only_has_no_absolute_paths(): void
    {
        $result = $this->runPlanOnly('corrija o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php');
        $response = $this->adapter()->formatPlanOnly($result);

        $json = json_encode($response, JSON_UNESCAPED_SLASHES);
        $this->assertIsString($json);
        $this->assertStringNotContainsString($this->tmpWorkspace, $json, 'workspace abs path leaked');
        $this->assertStringNotContainsString($this->tmpStorage, $json, 'storage abs path leaked');
        $this->assertStringNotContainsString('/Users/', $json, '/Users/ prefix leaked');
        $this->assertStringNotContainsString('/private/var/', $json, '/private/var/ prefix leaked');

        foreach ($response['persisted_artifact_refs'] as $ref) {
            $this->assertStringStartsWith(
                'receipts/',
                $ref,
                "artifact refs must be relative (receipts/<run_id>/<file>): {$ref}",
            );
        }
    }

    public function test_adapter_does_not_import_desktop_namespace(): void
    {
        $contents = (string) file_get_contents(
            __DIR__.'/../../../../../../app/Services/Ai/Programming/AtlasDev/Surface/AtlasCliDevAdapter.php',
        );

        $useStatements = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $trim = ltrim($line);
            if (str_starts_with($trim, 'use ')) {
                $useStatements[] = $trim;
            }
        }
        $imports = implode("\n", $useStatements);

        $this->assertStringNotContainsString('Desktop', $imports, 'CLI adapter must not import Desktop classes');
        $this->assertStringNotContainsString('DesktopUiHintsBuilder', $contents);

        // Strip docblocks + single-line comments before checking for ui_hints
        // symbols. The class docblock legitimately documents that the CLI does
        // NOT emit ui_hints; that prose must not be confused with a leak.
        $codeOnly = (string) preg_replace('@/\*[\s\S]*?\*/|//[^\r\n]*@', '', $contents);
        $this->assertStringNotContainsString(
            'ui_hints',
            $codeOnly,
            'CLI must not use the ui_hints vocabulary in code paths',
        );
    }

    public function test_adapter_does_not_depend_on_a_provider_class(): void
    {
        $reflection = new ReflectionClass(AtlasCliDevAdapter::class);
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType) {
                $name = $type->getName();
                $this->assertStringNotContainsString('Provider', $name, "adapter must not depend on a Provider (got {$name})");
                $this->assertStringNotContainsString('ClaudeCli', $name, "adapter must not depend on a Claude CLI gateway (got {$name})");
                $this->assertStringNotContainsString('RunExecutor', $name, "adapter must not depend on the RunExecutor (got {$name})");
            }
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function adapter(): AtlasCliDevAdapter
    {
        return new AtlasCliDevAdapter(
            intake: new IntakeNormalizer(new RunIdGenerator),
            formatter: new SurfaceResponseFormatter,
        );
    }

    private function runPlanOnly(string $rawIntent): PlanOnlyResult
    {
        $orchestrator = new AtlasDevFastPathOrchestrator(
            intake: new IntakeNormalizer(new RunIdGenerator),
            classifier: new TaskClassifier,
            riskScorer: new RiskLevelScorer,
            specComposer: new SpecComposer,
            tierSelector: new DocContextTierSelector,
            codeDiscovery: new CodeDiscoveryEngine,
            openBrainAdapter: new OpenBrainProjectionAdapter(new FakeAtlasOpenBrainService),
            promptBuilder: new ProviderPromptBuilder(
                sectionsMapper: new PromptSectionsMapper,
                renderer: new PromptRenderer,
                qualityChecker: new PromptQualityChecker,
            ),
            routingEngine: new RoutingDecisionEngine,
            receiptStorage: new ReceiptStorage($this->tmpStorage),
        );

        return $orchestrator->planOnly(
            surfaceId: 'atlas_cli_dev',
            workspace: $this->tmpWorkspace,
            rawIntent: $rawIntent,
            surfaceHints: ['flow_origin' => 'direct'],
        );
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            if (is_dir($path) && ! is_link($path)) {
                $this->rmrf($path);
            } else {
                @chmod($path, 0o644);
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

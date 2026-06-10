<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Obra;

use App\Services\Ai\AiProviderManager;
use App\Services\Ai\Obra\DeterministicObraDecomposer;
use App\Services\Ai\Obra\ProviderObraDecomposer;
use Tests\TestCase;

/**
 * AOBG N3.F1 — the REAL (provider-backed) decomposer, proven COST-FREE.
 *
 * The actual provider call is isolated behind the protected {@see ProviderObraDecomposer::invokeProvider()}
 * seam; here a subclass overrides it to return crafted provider text, so the full
 * decode/parse path is exercised with ZERO provider spend (the same test-seam
 * philosophy as AtlasLiveCodeDeliveryService::setSandboxFactoryForTesting). The
 * provider manager is never asked for a real driver.
 */
final class ProviderObraDecomposerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas.obra.decompose_provider', 'codex'); // a key is configured
        config()->set('atlas.obra.max_nodes', 12);
    }

    public function test_parses_strict_json_step_list_into_drafts(): void
    {
        $json = json_encode([
            ['key' => 'a', 'title' => 'create', 'request' => 'create the service', 'target_area' => 'app/Services/A.php', 'depends_on' => []],
            ['key' => 'b', 'title' => 'wire', 'request' => 'wire it in', 'depends_on' => ['a']],
        ]);

        $drafts = $this->decomposer($json)->decompose('build a thing', []);

        $this->assertCount(2, $drafts);
        $this->assertSame('a', $drafts[0]->key);
        $this->assertSame('create the service', $drafts[0]->request);
        $this->assertSame('app/Services/A.php', $drafts[0]->targetArea);
        $this->assertSame(['a'], $drafts[1]->dependsOn);
    }

    public function test_strips_a_markdown_fence_around_the_json(): void
    {
        $fenced = "Here is the plan:\n```json\n".json_encode([
            ['key' => 'x', 'title' => 'only', 'request' => 'do x'],
        ])."\n```\nthanks";

        $drafts = $this->decomposer($fenced)->decompose('x', []);

        $this->assertCount(1, $drafts);
        $this->assertSame('do x', $drafts[0]->request);
    }

    public function test_unparseable_output_degrades_honestly_to_deterministic(): void
    {
        $decomposer = $this->decomposer('this is not json at all');

        $drafts = $decomposer->decompose('alpha; beta', []);

        // It fell back to the deterministic decomposer (2 chained steps).
        $this->assertCount(2, $drafts);
        $this->assertSame('alpha', $drafts[0]->request);
        $this->assertSame('beta', $drafts[1]->request);
        // The label honestly reports the degrade.
        $this->assertSame('provider_fallback:deterministic', $decomposer->label());
    }

    public function test_provider_error_degrades_honestly_to_deterministic(): void
    {
        $decomposer = $this->throwingDecomposer();

        $drafts = $decomposer->decompose('one; two', []);

        $this->assertCount(2, $drafts);
        $this->assertSame('provider_fallback:deterministic', $decomposer->label());
    }

    public function test_no_provider_configured_uses_deterministic_without_spending(): void
    {
        config()->set('atlas.obra.decompose_provider', '');

        // Even though this is the provider decomposer, an empty config never calls a provider.
        $decomposer = new ProviderObraDecomposer(app(AiProviderManager::class));

        $drafts = $decomposer->decompose('only; step', []);

        $this->assertCount(2, $drafts);
        $this->assertSame('provider_fallback:deterministic', $decomposer->label());
    }

    /**
     * A ProviderObraDecomposer whose provider call returns crafted text (zero spend).
     */
    private function decomposer(string $providerOutput): ProviderObraDecomposer
    {
        return new class(app(AiProviderManager::class), $providerOutput) extends ProviderObraDecomposer
        {
            public function __construct(AiProviderManager $providers, private string $out)
            {
                parent::__construct($providers, new DeterministicObraDecomposer);
            }

            protected function invokeProvider(string $providerKey, string $prompt, array $opts): string
            {
                return $this->out;
            }
        };
    }

    private function throwingDecomposer(): ProviderObraDecomposer
    {
        return new class(app(AiProviderManager::class)) extends ProviderObraDecomposer
        {
            public function __construct(AiProviderManager $providers)
            {
                parent::__construct($providers, new DeterministicObraDecomposer);
            }

            protected function invokeProvider(string $providerKey, string $prompt, array $opts): string
            {
                throw new \RuntimeException('provider down');
            }
        };
    }
}

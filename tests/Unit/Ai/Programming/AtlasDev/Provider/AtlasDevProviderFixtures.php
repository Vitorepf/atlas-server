<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Provider;

use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptQualityChecker;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptRenderer;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptSectionsMapper;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\ProviderPromptBuilder;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliResponse;
use App\Services\Ai\Programming\AtlasDev\Provider\SonnetClaudeCliAdapter;
use App\Services\Ai\Programming\AtlasDev\Schemas\CodeDiscoveryManifest;
use App\Services\Ai\Programming\AtlasDev\Schemas\CompactSdd;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\MiniProgrammingSpec;
use App\Services\Ai\Programming\AtlasDev\Schemas\OpenBrainProgrammingProjection;
use App\Services\Ai\Programming\AtlasDev\Schemas\OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use RuntimeException;

/**
 * Shared fixture loader for Provider / Gate tests.
 *
 * Loads the canonical "repair R2" upstream artifacts and builds a sendable
 * ProviderPromptProjection so the adapter / gates can be exercised against
 * a deterministic, realistic input.
 */
trait AtlasDevProviderFixtures
{
    private function fixturesRoot(): string
    {
        return realpath(__DIR__.'/../../../../../Fixtures/AtlasDev')
            ?: throw new RuntimeException('Atlas Dev fixtures directory missing');
    }

    private function loadJsonFixture(string $relative, array $overrides = []): array
    {
        $raw = file_get_contents($this->fixturesRoot().'/'.$relative);
        if ($raw === false) {
            throw new RuntimeException("Unable to load fixture {$relative}");
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Fixture {$relative} is not valid JSON");
        }

        return array_replace($decoded, $overrides);
    }

    private function envelopeFixture(array $overrides = []): OperationEnvelope
    {
        return OperationEnvelope::fromArray(
            $this->loadJsonFixture('envelopes/valid_desktop_dev_r2.json', $overrides),
        );
    }

    private function compactSddFixture(array $overrides = []): CompactSdd
    {
        return CompactSdd::fromArray(
            $this->loadJsonFixture('compact_sdd/valid_repair_r2.json', $overrides),
        );
    }

    private function miniSpecFixture(array $overrides = []): MiniProgrammingSpec
    {
        return MiniProgrammingSpec::fromArray(
            $this->loadJsonFixture('mini_spec/valid_repair_r2.json', $overrides),
        );
    }

    private function taskContractFixture(array $overrides = []): LightTaskContract
    {
        return LightTaskContract::fromArray(
            $this->loadJsonFixture('task_contract/valid_repair_r2.json', $overrides),
        );
    }

    private function codeDiscoveryFixture(array $overrides = []): CodeDiscoveryManifest
    {
        return CodeDiscoveryManifest::fromArray(
            $this->loadJsonFixture('context/valid_code_discovery_strong_inference.json', $overrides),
        );
    }

    private function openBrainProjectionFixture(array $overrides = []): OpenBrainProgrammingProjection
    {
        return OpenBrainProgrammingProjection::fromArray(
            $this->loadJsonFixture('context/valid_open_brain_projection_truncated.json', $overrides),
        );
    }

    private function buildSendableProjection(
        ?OperationEnvelope $envelope = null,
        ?MiniProgrammingSpec $miniSpec = null,
        ?LightTaskContract $taskContract = null,
    ): ProviderPromptProjection {
        $builder = new ProviderPromptBuilder(
            new PromptSectionsMapper,
            new PromptRenderer,
            new PromptQualityChecker,
        );

        return $builder->build(
            envelope: $envelope ?? $this->envelopeFixture(),
            compactSdd: $this->compactSddFixture(),
            miniSpec: $miniSpec ?? $this->miniSpecFixture(),
            taskContract: $taskContract ?? $this->taskContractFixture(),
            discovery: $this->codeDiscoveryFixture(),
            projection: $this->openBrainProjectionFixture(),
        );
    }

    private function gatewayResponse(
        string $stdout,
        int $exitCode = 0,
        string $stderr = '',
        int $durationMs = 1200,
        ?int $tokensIn = 1200,
        ?int $tokensOut = 240,
        ?float $costEstimateUsd = 0.0042,
        ?string $actualProvider = null,
        ?string $actualModelFamily = null,
    ): ClaudeCliResponse {
        return new ClaudeCliResponse(
            actualProvider: $actualProvider ?? SonnetClaudeCliAdapter::PROVIDER,
            actualModelFamily: $actualModelFamily ?? SonnetClaudeCliAdapter::MODEL_FAMILY,
            exitCode: $exitCode,
            stdout: $stdout,
            stderr: $stderr,
            durationMs: $durationMs,
            tokensIn: $tokensIn,
            tokensOut: $tokensOut,
            costEstimateUsd: $costEstimateUsd,
        );
    }
}

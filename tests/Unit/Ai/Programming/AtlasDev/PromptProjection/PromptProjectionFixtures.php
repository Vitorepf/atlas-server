<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\PromptProjection;

use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\CodeDiscoveryManifest;
use App\Services\Ai\Programming\AtlasDev\Schemas\CompactSdd;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\MiniProgrammingSpec;
use App\Services\Ai\Programming\AtlasDev\Schemas\OpenBrainProgrammingProjection;
use RuntimeException;

/**
 * Loads the canonical "repair R2" inputs and exposes typed DTOs so each test
 * can override a single field without re-loading every fixture file.
 */
trait PromptProjectionFixtures
{
    private function fixturesRoot(): string
    {
        return realpath(__DIR__.'/../../../../../Fixtures/AtlasDev')
            ?: throw new RuntimeException('Atlas Dev fixtures directory missing');
    }

    private function manifest(): array
    {
        $path = $this->fixturesRoot().'/prompt-projection/valid_repair_r2_inputs.json';
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Unable to load prompt projection manifest');
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Prompt projection manifest is not valid JSON');
        }

        return $decoded;
    }

    private function loadFixture(string $relative, array $overrides = []): array
    {
        $path = $this->fixturesRoot().'/'.$relative;
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Unable to load fixture {$relative}");
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Fixture {$relative} is not valid JSON");
        }

        return array_replace($decoded, $overrides);
    }

    private function envelope(array $overrides = []): OperationEnvelope
    {
        return OperationEnvelope::fromArray(
            $this->loadFixture($this->manifest()['envelope_fixture'], $overrides),
        );
    }

    private function compactSdd(array $overrides = []): CompactSdd
    {
        return CompactSdd::fromArray(
            $this->loadFixture($this->manifest()['compact_sdd_fixture'], $overrides),
        );
    }

    private function miniSpec(array $overrides = []): MiniProgrammingSpec
    {
        return MiniProgrammingSpec::fromArray(
            $this->loadFixture($this->manifest()['mini_spec_fixture'], $overrides),
        );
    }

    private function taskContract(array $overrides = []): LightTaskContract
    {
        return LightTaskContract::fromArray(
            $this->loadFixture($this->manifest()['task_contract_fixture'], $overrides),
        );
    }

    private function codeDiscovery(array $overrides = []): CodeDiscoveryManifest
    {
        return CodeDiscoveryManifest::fromArray(
            $this->loadFixture($this->manifest()['code_discovery_fixture'], $overrides),
        );
    }

    private function openBrainProjection(array $overrides = []): OpenBrainProgrammingProjection
    {
        return OpenBrainProgrammingProjection::fromArray(
            $this->loadFixture($this->manifest()['open_brain_projection_fixture'], $overrides),
        );
    }
}

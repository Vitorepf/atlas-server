<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Provider;

use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptQualityChecker;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptRenderer;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptSectionsMapper;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\ProviderPromptBuilder;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliResponse;
use App\Services\Ai\Programming\AtlasDev\Provider\SonnetClaudeCliAdapter;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\CodeDiscoveryManifest;
use App\Services\Ai\Programming\AtlasDev\Schemas\CompactSdd;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Schemas\MiniProgrammingSpec;
use App\Services\Ai\Programming\AtlasDev\Schemas\OpenBrainProgrammingProjection;
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
    private function bindAllowedAwisGate(): void
    {
        app()->instance(
            \App\Services\Ai\ExecutionAuthority\AwisExecutionGatePort::class,
            new class implements \App\Services\Ai\ExecutionAuthority\AwisExecutionGatePort
            {
                public function gate(?string $workspace = null, string $mode = 'conversation', string $task = '', array $conversationTexts = []): array
                {
                    return [
                        'allowed' => true,
                        'status' => 'passed',
                        'mode' => $mode,
                        'workspace' => $workspace,
                        'blockers' => [],
                    ];
                }
            },
        );
    }

    /**
     * The M3 senior critic reviews every green run; its 'critic_reviewed'
     * flag downgrades PASSED->needs_review, polluting axis-isolated feature
     * suites written before it landed. Bind a no-concerns critic so the
     * completion state reflects only the axis under test.
     */
    private function bindNoConcernsCritic(\Illuminate\Container\Container $container): void
    {
        $container->instance(\App\Services\Ai\Programming\AtlasDev\Intelligence\ReviewIntelligenceService::class, new class
        {
            public function analyse(array $input, array $options = []): \App\Services\Ai\Programming\AtlasDev\Schemas\ReviewReceipt
            {
                return \App\Services\Ai\Programming\AtlasDev\Schemas\ReviewReceipt::issue(
                    receiptId: 'rr-fixture-'.bin2hex(random_bytes(4)),
                    runId: (string) ($input['run_id'] ?? 'run-fixture'),
                    status: \App\Services\Ai\Programming\AtlasDev\Schemas\ReviewReceipt::STATUS_NO_CONCERNS,
                    reviewedFiles: [],
                    findings: [],
                    missingTestsCount: 0,
                    confidence: 1.0,
                    evidenceRefs: [],
                    blockerReasons: [],
                    createdAt: '2026-01-01T00:00:00Z',
                );
            }
        });
    }

    /**
     * The honesty flags a run persisted, read back from the verification
     * receipt artifact (completion.honesty_flags).
     *
     * @return list<string>
     */
    private function readHonestyFlags(\App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage $storage, string $runId): array
    {
        $receipt = $storage->read($runId, \App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames::VERIFICATION_RECEIPT);

        return is_array($receipt)
            ? array_values(array_map('strval', (array) data_get($receipt, 'completion.honesty_flags', [])))
            : [];
    }

    /**
     * Same flags, read via the run result's persisted receipt path (for runs
     * whose ReceiptStorage is scenario-local).
     *
     * @return list<string>
     */
    private function readHonestyFlagsFromResult(mixed $result): array
    {
        $path = $result->persistedReceiptPaths[\App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames::VERIFICATION_RECEIPT] ?? '';
        $receipt = is_string($path) && is_file($path)
            ? json_decode((string) file_get_contents($path), true)
            : null;

        return is_array($receipt)
            ? array_values(array_map('strval', (array) data_get($receipt, 'completion.honesty_flags', [])))
            : [];
    }

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

    // Obra #12 cauda da S-15: seedRun byte-idêntico consolidado (16 arquivos).
    private function seedRun(ReceiptStorage $storage, string $runId, string $taskKind, string $riskLevel): void
    {
        $compactSdd = $this->compactSddFixture(['task_kind' => $taskKind, 'risk_level' => $riskLevel]);
        $compactPayload = $compactSdd->toCanonicalArray();
        $compactPayload['compact_sdd_hash'] = $compactSdd->hash();
        $storage->writeAtomic($runId, ArtifactNames::COMPACT_SDD, $compactPayload);

        $miniSpec = $this->miniSpecFixture(['compact_sdd_hash' => $compactPayload['compact_sdd_hash']]);
        $storage->writeAtomic($runId, ArtifactNames::MINI_PROGRAMMING_SPEC, $miniSpec->toCanonicalArray());

        $storage->writeAtomic($runId, ArtifactNames::OPEN_BRAIN_PROJECTION, [
            'context_pack_hash' => 'atlas-dev:context_pack:'.bin2hex(random_bytes(4)),
        ]);
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

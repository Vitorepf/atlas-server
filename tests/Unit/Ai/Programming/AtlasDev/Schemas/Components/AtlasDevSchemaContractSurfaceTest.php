<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\CodeDiscoveryManifest;
use App\Services\Ai\Programming\AtlasDev\Schemas\CompactSdd;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Budget;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CodeCandidate;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ContextBudget;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ContextRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GitState;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\MissingRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ObservedSignals;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Preflight;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\PromptSections;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ProviderLock;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\QualityChecks;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\RepairPolicy;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeBaseline;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Truncation;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\VerificationPlan;
use App\Services\Ai\Programming\AtlasDev\Schemas\ContextRetrievalPlan;
use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\MiniProgrammingSpec;
use App\Services\Ai\Programming\AtlasDev\Schemas\OpenBrainProgrammingProjection;
use App\Services\Ai\Programming\AtlasDev\Schemas\OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use Tests\TestCase;

/**
 * Schema-contract surface test: every AtlasDevSchemaContract implementation
 * must expose a canonical array (sorted), a deterministic sha256 hash, a
 * round-trippable JSON, and a provider-safe projection that is honest
 * (canonical == projection when isProviderSafe(); redaction never silently
 * drops fields otherwise).
 */
final class AtlasDevSchemaContractSurfaceTest extends TestCase
{
    use SchemaContractAssertions;

    /**
     * @return array<string, AtlasDevSchemaContract>
     */
    private function components(): array
    {
        return [
            'Budget' => new Budget(charsRequested: 1000, charsUsed: 500),
            'ContextBudget' => new ContextBudget(
                maxChars: 12000,
                maxDocs: 4,
                maxCandidateFiles: 6,
                maxPlanSteps: 4,
                maxProviderCalls: 1,
                maxRepairAttempts: 1,
            ),
            'GitState' => new GitState(headSha: 'abc', dirty: false, untrackedCount: 0, pendingChangesCount: 0),
            'Preflight' => new Preflight(workspaceResolved: true, permissionMode: 'write', writeAllowed: true, operatorExplicit: false),
            'ProviderLock' => new ProviderLock(provider: 'claude_cli', modelFamily: 'sonnet'),
            'RepairPolicy' => new RepairPolicy(maxAttempts: 1, sameProvider: true, requiresFailedGateOutput: true, abortOnSameSignatureTwice: true),
            'ScopeBaseline' => new ScopeBaseline(gitStatusBefore: 'clean', gitDiffBeforeHash: null),
            'SurfaceContext' => new SurfaceContext(productSurface: 'atlas_ai_desktop_mac'),
            'Truncation' => new Truncation(truncated: false, reasons: []),
            'VerificationPlan' => new VerificationPlan(profile: 'php_laravel', commands: ['composer test']),
            'ObservedSignals' => new ObservedSignals(fileCount: 1, layersTouched: 1, riskKeywords: []),
            'CodeCandidate' => new CodeCandidate(path: '/abs/x.php', reason: 'r', confidence: 0.9, symbols: ['A']),
            'ContextRef' => new ContextRef(kind: 'symbol', ref: 'sym://X', reason: 'alvo'),
            'MissingRef' => new MissingRef(what: 'symbol Y', whyMissing: 'no index'),
            'QualityChecks' => QualityChecks::allPassing(),
            'PromptSections' => self::makePromptSections(),
        ];
    }

    public function test_every_component_satisfies_contract_surface(): void
    {
        foreach ($this->components() as $name => $component) {
            $this->assertInstanceOf(
                AtlasDevSchemaContract::class,
                $component,
                "{$name} must implement AtlasDevSchemaContract",
            );
            $this->assertContractSurface($component);
        }
    }

    public function test_operation_envelope_contract_surface(): void
    {
        $this->assertContractSurface(OperationEnvelope::fromArray(
            $this->loadEnvelopeFixture('valid_desktop_dev_r2.json')
        ));
    }

    public function test_compact_sdd_contract_surface(): void
    {
        $this->assertContractSurface(CompactSdd::fromArray(
            $this->loadFixture('compact_sdd/valid_repair_r2.json')
        ));
    }

    public function test_mini_programming_spec_contract_surface(): void
    {
        $this->assertContractSurface(MiniProgrammingSpec::fromArray(
            $this->loadFixture('mini_spec/valid_repair_r2.json')
        ));
    }

    public function test_light_task_contract_contract_surface(): void
    {
        $this->assertContractSurface(LightTaskContract::fromArray(
            $this->loadFixture('task_contract/valid_repair_r2.json')
        ));
    }

    public function test_context_retrieval_plan_contract_surface(): void
    {
        $this->assertContractSurface(ContextRetrievalPlan::fromArray(
            $this->loadFixture('context/valid_context_plan_desktop_r2.json')
        ));
    }

    public function test_code_discovery_manifest_contract_surface(): void
    {
        $this->assertContractSurface(CodeDiscoveryManifest::fromArray(
            $this->loadFixture('context/valid_code_discovery_strong_inference.json')
        ));
    }

    public function test_open_brain_projection_contract_surface(): void
    {
        $this->assertContractSurface(OpenBrainProgrammingProjection::fromArray(
            $this->loadFixture('context/valid_open_brain_projection_truncated.json')
        ));
    }

    public function test_provider_prompt_projection_contract_surface(): void
    {
        $this->assertContractSurface(ProviderPromptProjection::fromArray(
            $this->loadFixture('context/valid_provider_prompt_projection.json')
        ));
    }

    public function test_provider_prompt_projection_redaction_when_unsafe(): void
    {
        $payload = $this->loadFixture('context/valid_provider_prompt_projection.json');
        $payload['provider_safe'] = false;
        $p = ProviderPromptProjection::fromArray($payload);

        $canonical = $p->toCanonicalArray();
        $safe = $p->toProviderSafeArray();

        $this->assertSame(array_keys($canonical), array_keys($safe));
        $this->assertNotSame($canonical['rendered_prompt_text'], $safe['rendered_prompt_text']);
        $this->assertStringStartsWith('[redacted:', $safe['rendered_prompt_text']);
    }

    private function loadFixture(string $relative): array
    {
        $path = __DIR__.'/../../../../../../Fixtures/AtlasDev/'.$relative;
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded, "fixture missing: {$path}");

        return $decoded;
    }

    private function loadEnvelopeFixture(string $name): array
    {
        return $this->loadFixture('envelopes/'.$name);
    }

    private static function makePromptSections(): PromptSections
    {
        return new PromptSections(
            objective: 'corrigir teste',
            operatingRules: ['no broad refactor'],
            miniSpecRef: 'spec://x',
            taskContractRef: 'tc://x',
            contextRefs: ['ctx://x'],
            codeDiscoveryRef: 'cd://x',
            allowedFiles: ['a.php'],
            forbiddenFiles: ['vendor/*'],
            expectedTests: ['t.php'],
            acceptanceCriteria: ['ac'],
            stopConditions: ['tests_green'],
            escalationConditions: ['scope_guard_violation'],
            outputContract: ['diff em formato unified'],
        );
    }
}

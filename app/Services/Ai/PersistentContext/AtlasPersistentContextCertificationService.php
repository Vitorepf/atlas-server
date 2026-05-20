<?php

declare(strict_types=1);

namespace App\Services\Ai\PersistentContext;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Throwable;

final class AtlasPersistentContextCertificationService
{
    public const SCHEMA_VERSION = 'atlas.persistent_context.certification.v1';

    public const STATUS_PASSED = 'passed';

    public const STATUS_BLOCKED = 'blocked';

    public function __construct(
        private readonly AtlasPersistentContextRuntimeService $runtime,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function certify(): array
    {
        $now = CarbonImmutable::now();
        $checks = [
            $this->canonicalDoc(),
            $this->runtimeSmoke(),
            $this->persistenceSurface(),
            $this->runtimeCommand(),
            $this->hyperflowWiring(),
            $this->gatewayWiring(),
            $this->promptBuilderWiring(),
            $this->programmingWiring(),
            $this->forgeWiring(),
            $this->controlPlaneWiring(),
            $this->postExecutionMemoryGuard(),
            $this->testsPresent(),
            $this->noProviderPolicy(),
            $this->claimPolicy(),
        ];

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $this->status($checks),
            'generated_at' => $now->toJSON(),
            'summary' => $this->summary($checks),
            'checks' => $checks,
            'blockers' => array_values(array_filter($checks, static fn (array $check): bool => ($check['status'] ?? null) === 'fail')),
            'claim_policy' => [
                'benchmark_not_run' => true,
                'rivals_compared' => false,
                'provider_calls_made' => false,
                'allows_external_superiority_claim' => false,
                'certifies_local_wiring_only' => true,
            ],
            'scope' => [
                'covers' => 'APCR default context bootstrap, persistence, sufficiency gate, provider handoff, provider prompt projection, Hyperflow, Atlas Dev, Atlas Forge, Control Plane and memory-update guard.',
                'does_not_cover' => 'external provider execution, benchmark/rivals, UI rendering and live production traffic.',
            ],
            'writes' => false,
        ];
        $payload['certification_hash'] = $this->hash($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function canonicalDoc(): array
    {
        $path = base_path('docs/engineering-knowledge-base/atlas-persistent-context-runtime.md');
        $source = $this->read($path);
        $ok = $source !== null
            && str_contains($source, 'doc_schema: atlas_canonical_module_doc.v1')
            && str_contains($source, 'Atlas Persistent Context Runtime')
            && str_contains($source, self::SCHEMA_VERSION);

        return $this->check('canonical_doc', $ok, 'APCR canonical doc present.', ['path' => $this->relative($path)], 'Create or repair APCR canonical doc.');
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimeSmoke(): array
    {
        try {
            $payload = $this->runtime->build([
                'prompt' => 'retome contexto do Atlas antes de executar',
                'workspace' => base_path(),
                'surface_id' => 'certification',
                'domain' => 'programming',
                'flow_id' => 'atlas_dev',
                'evidence_refs' => ['certification:apcr'],
            ]);
        } catch (Throwable $exception) {
            return $this->check('runtime_smoke', false, 'APCR runtime smoke threw: '.$exception->getMessage(), [], 'Fix AtlasPersistentContextRuntimeService::build.');
        }

        $ok = ($payload['schema_version'] ?? null) === AtlasPersistentContextRuntimeService::SCHEMA_VERSION
            && isset($payload['persistent_context_hash'])
            && isset($payload['sufficiency'])
            && isset($payload['must_know_ledger'])
            && isset($payload['provider_handoff'])
            && ($payload['claim_policy']['provider_calls_made'] ?? true) === false;

        return $this->check(
            'runtime_smoke',
            $ok,
            'APCR runtime emits context hash, sufficiency, must-know ledger and provider handoff.',
            ['status' => $payload['status'] ?? null, 'persistent_context_hash' => $payload['persistent_context_hash'] ?? null],
            'Make APCR emit stable runtime contract without provider calls.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function persistenceSurface(): array
    {
        $model = base_path('app/Models/AtlasPersistentContextPack.php');
        $migration = base_path('database/migrations/2026_05_20_120000_create_atlas_persistent_context_packs_table.php');
        $ok = $this->contains($model, 'class AtlasPersistentContextPack')
            && $this->contains($migration, 'atlas_persistent_context_packs')
            && $this->contains($migration, 'must_know_ledger_hash')
            && $this->contains($migration, 'post_execution_update');

        return $this->check('persistence_surface', $ok, 'APCR persistence model and migration present.', ['paths' => [$this->relative($model), $this->relative($migration)]], 'Restore APCR model/migration.');
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimeCommand(): array
    {
        $path = base_path('app/Console/Commands/AtlasPersistentContextRuntimeCommand.php');
        $ok = $this->contains($path, 'atlas:persistent-context')
            && $this->contains($path, 'build|outcome')
            && $this->contains($path, 'recordOutcome');

        return $this->check('runtime_command', $ok, 'APCR build/outcome command present.', ['path' => $this->relative($path)], 'Restore atlas:persistent-context command.');
    }

    /**
     * @return array<string,mixed>
     */
    private function hyperflowWiring(): array
    {
        $path = base_path('app/Services/Ai/RouterRuntime/AtlasHyperflowEntryService.php');
        $source = $this->read($path);
        $ok = $source !== null
            && str_contains($source, 'AtlasPersistentContextRuntimeService')
            && str_contains($source, 'buildPersistentContext')
            && str_contains($source, "'persistent_context'")
            && strpos($source, 'buildPersistentContext') < strpos($source, 'maybeActivateMissionMode');

        return $this->check('hyperflow_wiring', $ok, 'Hyperflow builds APCR before mission/intent routing.', ['path' => $this->relative($path)], 'Wire APCR before Hyperflow routing.');
    }

    /**
     * @return array<string,mixed>
     */
    private function gatewayWiring(): array
    {
        $path = base_path('app/Services/Ai/AiGatewayService.php');
        $ok = $this->contains($path, 'AtlasPersistentContextRuntimeService')
            && $this->contains($path, 'optionsWithPersistentContext')
            && $this->contains($path, "'persistent_context'")
            && $this->contains($path, '$this->prompts->build($input, $options)')
            && strpos($this->read($path) ?? '', 'optionsWithPersistentContext') < strpos($this->read($path) ?? '', '$this->prompts->build($input, $options)');

        return $this->check('gateway_wiring', $ok, 'AiGateway creates APCR before building provider prompt, covering CLI/direct gateway entries.', ['path' => $this->relative($path)], 'Wire APCR into AiGatewayService before AiPromptBuilder.');
    }

    /**
     * @return array<string,mixed>
     */
    private function promptBuilderWiring(): array
    {
        $path = base_path('app/Services/Ai/AiPromptBuilder.php');
        $test = base_path('tests/Unit/Ai/PersistentContext/PersistentContextPromptBuilderTest.php');
        $ok = $this->contains($path, 'persistentContextPromptSection')
            && $this->contains($path, '# Atlas Persistent Context Runtime')
            && $this->contains($path, 'payload.persistent_context')
            && $this->contains($path, 'required_before_execution')
            && $this->contains($test, 'test_persistent_context_runtime_projects_provider_handoff_into_prompt');

        return $this->check('prompt_builder_wiring', $ok, 'Provider prompt receives APCR provider handoff and must-know ledger.', ['paths' => [$this->relative($path), $this->relative($test)]], 'Project APCR into AiPromptBuilder before provider execution.');
    }

    /**
     * @return array<string,mixed>
     */
    private function programmingWiring(): array
    {
        $path = base_path('app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php');
        $ok = $this->contains($path, 'AtlasPersistentContextRuntimeService')
            && $this->contains($path, 'persistentContextContract')
            && $this->contains($path, "'persistent_context'");

        return $this->check('programming_wiring', $ok, 'Atlas Dev/Programming plan includes APCR.', ['path' => $this->relative($path)], 'Attach persistent_context to programming plans and dispatch contract.');
    }

    /**
     * @return array<string,mixed>
     */
    private function forgeWiring(): array
    {
        $service = base_path('app/Services/Ai/Programming/Forge/ForgeIntakeService.php');
        $model = base_path('app/Models/AiForgeIntake.php');
        $migration = base_path('database/migrations/2026_05_20_121000_add_persistent_context_to_ai_forge_intakes.php');
        $ok = $this->contains($service, 'persistentContextForIntake')
            && $this->contains($service, "'atlas_forge'")
            && $this->contains($model, "'persistent_context'")
            && $this->contains($migration, 'persistent_context_hash');

        return $this->check('forge_wiring', $ok, 'Forge intake persists APCR context.', ['paths' => [$this->relative($service), $this->relative($model), $this->relative($migration)]], 'Wire APCR into Forge intake persistence.');
    }

    /**
     * @return array<string,mixed>
     */
    private function controlPlaneWiring(): array
    {
        $path = base_path('app/Services/Ai/ControlPlane/AtlasAiControlPlaneService.php');
        $ok = $this->contains($path, 'persistentContext(')
            && $this->contains($path, "'persistent_context'")
            && $this->contains($path, 'persistent_context_runtime');

        return $this->check('control_plane_wiring', $ok, 'Control Plane exposes APCR health.', ['path' => $this->relative($path)], 'Add APCR section and readiness ref to Control Plane.');
    }

    /**
     * @return array<string,mixed>
     */
    private function postExecutionMemoryGuard(): array
    {
        $path = base_path('app/Services/Ai/PersistentContext/AtlasPersistentContextRuntimeService.php');
        $ok = $this->contains($path, 'recordOutcome')
            && $this->contains($path, 'AiMemoryDelta')
            && $this->contains($path, "'requires_confirmation' => true")
            && $this->contains($path, "'promotion_allowed' => false")
            && $this->contains($path, 'missing_evidence_refs');

        return $this->check('post_execution_memory_guard', $ok, 'APCR outcome creates pending memory candidates only with evidence.', ['path' => $this->relative($path)], 'Restore APCR memory-update guard.');
    }

    /**
     * @return array<string,mixed>
     */
    private function testsPresent(): array
    {
        $runtimeTest = base_path('tests/Feature/Ai/PersistentContext/AtlasPersistentContextRuntimeServiceTest.php');
        $certTest = base_path('tests/Feature/Ai/PersistentContext/AtlasPersistentContextCertificationServiceTest.php');
        $ok = $this->contains($runtimeTest, 'test_build_persists_sufficient_context_pack_with_provider_handoff')
            && $this->contains($runtimeTest, 'test_record_outcome_without_evidence_blocks_memory_update')
            && $this->contains($certTest, 'test_certification_passes_when_apcr_wiring_is_present');

        return $this->check('tests_present', $ok, 'APCR runtime and certification tests present.', ['paths' => [$this->relative($runtimeTest), $this->relative($certTest)]], 'Add APCR tests.');
    }

    /**
     * @return array<string,mixed>
     */
    private function noProviderPolicy(): array
    {
        $path = base_path('app/Services/Ai/PersistentContext/AtlasPersistentContextRuntimeService.php');
        $source = $this->read($path) ?? '';
        $forbidden = ['AiProviderManager', 'ClaudeCli', 'CodexCli', 'Gemini', 'OpenAI'];
        $ok = ! collect($forbidden)->contains(fn (string $needle): bool => str_contains($source, $needle))
            && str_contains($source, "'provider_calls_made' => false");

        return $this->check('no_provider_policy', $ok, 'APCR does not invoke providers.', ['path' => $this->relative($path)], 'Remove provider execution from APCR.');
    }

    /**
     * @return array<string,mixed>
     */
    private function claimPolicy(): array
    {
        $path = base_path('app/Services/Ai/PersistentContext/AtlasPersistentContextRuntimeService.php');
        $ok = $this->contains($path, "'benchmark_not_run' => true")
            && $this->contains($path, "'rivals_compared' => false")
            && $this->contains($path, "'provider_is_context_consumer_only' => true");

        return $this->check('claim_policy', $ok, 'APCR claim policy blocks benchmark/rival/superiority claims.', ['path' => $this->relative($path)], 'Restore APCR claim policy.');
    }

    /**
     * @return array<string,mixed>
     */
    private function check(string $id, bool $ok, string $message, array $evidence, string $remediation): array
    {
        return [
            'id' => $id,
            'status' => $ok ? 'pass' : 'fail',
            'severity' => 'critical',
            'message' => $message,
            'evidence' => $evidence,
            'remediation' => $ok ? null : $remediation,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     */
    private function status(array $checks): string
    {
        return collect($checks)->contains(fn (array $check): bool => ($check['status'] ?? null) === 'fail')
            ? self::STATUS_BLOCKED
            : self::STATUS_PASSED;
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     * @return array<string,int>
     */
    private function summary(array $checks): array
    {
        return [
            'total' => count($checks),
            'pass' => collect($checks)->where('status', 'pass')->count(),
            'fail' => collect($checks)->where('status', 'fail')->count(),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hash(array $payload): string
    {
        $canonical = $payload;
        unset($canonical['generated_at'], $canonical['certification_hash']);

        return MissionCanonicalHash::sha256($canonical);
    }

    private function contains(string $path, string $needle): bool
    {
        return str_contains($this->read($path) ?? '', $needle);
    }

    private function read(string $path): ?string
    {
        return File::exists($path) ? File::get($path) : null;
    }

    private function relative(string $path): string
    {
        return str_replace(base_path().'/', '', $path);
    }
}

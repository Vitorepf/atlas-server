<?php

declare(strict_types=1);

namespace App\Services\Ai\ContextIntelligence;

use App\Services\Ai\LongHorizon\AtlasTeosFinalCertificationService;
use App\Services\Ai\Programming\DevForgeRobustFlowCertificationService;
use Illuminate\Support\Facades\File;
use Throwable;

final class AtlasContextIntelligenceCertificationService
{
    use \App\Services\Ai\Support\CertificationScaffoldHelpers;

    public const SCHEMA_VERSION = 'atlas.context_intelligence.certification.v1';

    public const STATUS_PASSED = 'passed';

    public const STATUS_BLOCKED = 'blocked';

    /** @var array<string,mixed>|null Override runtime payload for testing. */
    private ?array $testRuntimePayload = null;

    public function __construct(
        private readonly AtlasContextIntelligenceService $runtime,
        private readonly AtlasTeosFinalCertificationService $teosFinal,
        private readonly DevForgeRobustFlowCertificationService $devForge,
    ) {}

    /**
     * Inject a pre-built runtime payload for testing (bypasses real assess() call).
     *
     * @param  array<string,mixed>  $payload
     */
    public function injectTestRuntimePayload(array $payload): void
    {
        $this->testRuntimePayload = $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function certify(): array
    {
        $checks = [
            $this->canonicalDoc(),
            $this->runtimeSmoke(),
            $this->teosFinalReady(),
            $this->devForgeReady(),
            $this->hyperflowDefaultWiring(),
            $this->operationsRuntimeSurface(),
            $this->directProgrammingDevForgeWiring(),
            $this->providerContextStagingPolicy(),
            $this->verifiedCompactionSurface(),
            $this->compactionSurface(),
            $this->retrievalAndWorldModelSurface(),
            $this->evidenceSurface(),
            $this->noExternalExecutionPolicy(),
        ];

        $payload = $this->stateCertificationPayload(self::SCHEMA_VERSION, $checks, [
            'claim_policy' => [
                'benchmark_not_run' => true,
                'rivals_compared' => false,
                'provider_calls_made' => false,
                'allows_external_superiority_claim' => false,
                'certifies_local_wiring_only' => true,
            ],
            'scope' => [
                'covers' => 'ACIE certified default runtime: context certification, retrieval/world-model/evidence surfaces, verified compaction, ACIE/ACOL operations runtime, Hyperflow, direct Atlas Dev and Forge intake wiring.',
                'does_not_cover' => 'external benchmark/rivals, live provider execution, optional operator UX beyond the certified default runtime.',
            ],
            'writes' => false,
        ]);
        $payload['certification_hash'] = ContextIntelligencePayloadHash::forPayload($payload, 'certification_hash');

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function canonicalDoc(): array
    {
        $path = base_path('docs/engineering-knowledge-base/atlas-context-intelligence-engine.md');
        $source = $this->read($path);
        $ok = $source !== null
            && str_contains($source, 'doc_schema: atlas_canonical_module_doc.v1')
            && str_contains($source, 'Atlas Context Intelligence Engine')
            && str_contains($source, 'atlas.context_intelligence.certification.v1');

        return $this->check(
            'canonical_doc',
            $ok,
            $ok ? 'ACIE canonical doc and certification schema are present.' : 'ACIE canonical doc is missing or stale.',
            ['path' => $this->relative($path)],
            'Restore ACIE canonical doc and include atlas.context_intelligence.certification.v1.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimeSmoke(): array
    {
        try {
            $payload = $this->testRuntimePayload ?? $this->runtime->assess([
                'prompt' => 'implemente um ajuste pequeno com evidencia',
                'task_type' => 'programming',
                'domain' => 'programming',
                'risk_level' => 'medium',
                'context_refs' => ['doc:docs/engineering-knowledge-base/atlas-context-intelligence-engine.md'],
                'must_keep_items' => [['kind' => 'constraint', 'digest' => 'benchmark_not_run']],
            ]);
        } catch (Throwable $exception) {
            return $this->check('runtime_smoke', false, 'ACIE runtime smoke threw: '.$exception->getMessage(), [], 'Fix AtlasContextIntelligenceService::assess.');
        }

        $hashIntegrity = $this->verifyHashIntegrity($payload);

        $ok = in_array($payload['status'] ?? null, [
            AtlasContextIntelligenceService::STATUS_READY,
            AtlasContextIntelligenceService::STATUS_DEGRADED,
        ], true)
            && isset($payload['context_certification_hash'])
            && ($payload['claim_policy']['provider_calls_made'] ?? true) === false
            && ($hashIntegrity['status'] ?? 'fail') === 'pass';

        $evidence = [
            'context_certification_hash' => $payload['context_certification_hash'] ?? null,
            'hash_integrity' => $hashIntegrity,
        ];

        return $this->check(
            'runtime_smoke',
            $ok,
            'ACIE runtime smoke status: '.(string) ($payload['status'] ?? 'unknown'),
            $evidence,
            'Make ACIE runtime emit stable hash and no-provider claim policy.',
        );
    }

    /**
     * Verify the context_certification_hash on a runtime payload by stripping the
     * hash field, recomputing via ContextIntelligencePayloadHash::forPayload(),
     * and comparing stored vs recomputed.
     *
     * @param  array<string,mixed>  $payload
     * @return array{status:string, stored_hash?:string, recomputed_hash?:string, reason?:string}
     */
    private function verifyHashIntegrity(array $payload): array
    {
        if (! isset($payload['context_certification_hash'])) {
            return ['status' => 'fail', 'reason' => 'context_certification_hash_missing'];
        }

        $storedHash = (string) $payload['context_certification_hash'];
        $bodyForHash = $payload;
        $recomputedHash = ContextIntelligencePayloadHash::forPayload($bodyForHash, 'context_certification_hash');
        $pass = $storedHash === $recomputedHash;

        return [
            'status' => $pass ? 'pass' : 'fail',
            'stored_hash' => $storedHash,
            'recomputed_hash' => $recomputedHash,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function teosFinalReady(): array
    {
        try {
            $payload = $this->teosFinal->certify();
        } catch (Throwable $exception) {
            return $this->check('teos_final_certification', false, 'TEOS final certification threw: '.$exception->getMessage(), [], 'Fix TEOS final gate first.');
        }

        $status = (string) ($payload['status'] ?? 'unknown');
        $blockers = array_values((array) ($payload['blockers'] ?? []));
        $ok = $status === AtlasTeosFinalCertificationService::STATUS_READY
            || ($status === AtlasTeosFinalCertificationService::STATUS_PARTIAL && $blockers === []);

        return $this->check(
            'teos_final_certification',
            $ok,
            'TEOS final certification status: '.$status,
            [
                'certification_hash' => $payload['certification_hash'] ?? null,
                'summary' => $payload['summary'] ?? null,
                'blockers' => $blockers,
                'warnings' => $payload['warnings'] ?? [],
                'acceptance_policy' => 'ready_or_partial_without_blockers',
            ],
            'Run php artisan atlas:teos:final-certify --json and fix blockers; warnings stay audit-visible.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function devForgeReady(): array
    {
        try {
            $payload = $this->devForge->certify();
        } catch (Throwable $exception) {
            return $this->check('dev_forge_robust_flow', false, 'Dev/Forge robust flow certification threw: '.$exception->getMessage(), [], 'Fix Dev/Forge flow certification first.');
        }

        $ok = ($payload['status'] ?? null) === DevForgeRobustFlowCertificationService::STATUS_PASSED;

        return $this->check(
            'dev_forge_robust_flow',
            $ok,
            'Dev/Forge robust flow status: '.(string) ($payload['status'] ?? 'unknown'),
            ['certification_hash' => $payload['certification_hash'] ?? null, 'summary' => $payload['summary'] ?? null],
            'Run php artisan atlas:programming:dev-forge-flow-certify --json --strict and fix blockers.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function hyperflowDefaultWiring(): array
    {
        $entryPath = base_path('app/Services/Ai/RouterRuntime/AtlasHyperflowEntryService.php');
        $testPath = base_path('tests/Feature/Ai/RouterRuntime/AtlasAiInteractionHyperflowEntryTest.php');
        $entry = $this->read($entryPath);
        $test = $this->read($testPath);
        $ok = $entry !== null
            && $test !== null
            && str_contains($entry, 'AtlasContextIntelligenceService')
            && str_contains($entry, "'context_intelligence'")
            && str_contains($entry, 'withContextOperations')
            && str_contains($test, 'context_intelligence.schema_version')
            && str_contains($test, 'context_intelligence.context_certification_hash');

        return $this->check(
            'hyperflow_default_context_wiring',
            $ok,
            $ok ? 'Hyperflow entry attaches ACIE context certification to the default envelope.' : 'Hyperflow entry is not provably wired to ACIE by default.',
            ['paths' => [$this->relative($entryPath), $this->relative($testPath)]],
            'Wire AtlasHyperflowEntryService to AtlasContextIntelligenceService and cover it in AtlasAiInteractionHyperflowEntryTest.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function verifiedCompactionSurface(): array
    {
        $servicePath = base_path('app/Services/Ai/ContextIntelligence/AtlasVerifiedCompactionService.php');
        $testPath = base_path('tests/Feature/Ai/ContextIntelligence/AtlasVerifiedCompactionServiceTest.php');
        $service = $this->read($servicePath);
        $test = $this->read($testPath);
        $ok = $service !== null
            && $test !== null
            && str_contains($service, 'atlas.context_intelligence.verified_compaction.v1')
            && str_contains($service, 'atlas.context_intelligence.semantic_diff.v1')
            && str_contains($service, 'compactForScope')
            && str_contains($test, 'must_keep_coverage');

        return $this->check(
            'verified_compaction_surface',
            $ok,
            $ok ? 'ACIE verified compaction composes compactForScope with semantic diff.' : 'ACIE verified compaction surface is incomplete.',
            ['paths' => [$this->relative($servicePath), $this->relative($testPath)]],
            'Restore AtlasVerifiedCompactionService and its tests.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function operationsRuntimeSurface(): array
    {
        $servicePath = base_path('app/Services/Ai/ContextIntelligence/AtlasContextOperationsRuntimeService.php');
        $testPath = base_path('tests/Feature/Ai/ContextIntelligence/AtlasContextOperationsRuntimeServiceTest.php');
        $entryPath = base_path('app/Services/Ai/RouterRuntime/AtlasHyperflowEntryService.php');
        $service = $this->read($servicePath);
        $test = $this->read($testPath);
        $entry = $this->read($entryPath);
        $ok = $service !== null
            && $test !== null
            && $entry !== null
            && str_contains($service, 'atlas.context_intelligence.operations_runtime.v1')
            && str_contains($service, 'AtlasVerifiedCompactionService')
            && str_contains($service, 'handoff_packet')
            && str_contains($entry, 'context_operations')
            && str_contains($test, 'verified_compaction_required');

        return $this->check(
            'operations_runtime_surface',
            $ok,
            $ok ? 'ACIE/ACOL operations runtime is wired into Hyperflow with compaction and handoff policy.' : 'ACIE/ACOL operations runtime is incomplete.',
            ['paths' => [$this->relative($servicePath), $this->relative($testPath), $this->relative($entryPath)]],
            'Restore AtlasContextOperationsRuntimeService, its tests and Hyperflow wiring.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function directProgrammingDevForgeWiring(): array
    {
        $orchestratorPath = base_path('app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php');
        $forgeIntakePath = base_path('app/Services/Ai/Programming/Forge/ForgeIntakeService.php');
        $orchestratorTestPath = base_path('tests/Unit/Ai/AtlasProgrammingOrchestratorTest.php');
        $forgeTestPath = base_path('tests/Feature/Ai/Programming/Forge/ForgeIntakeServiceTest.php');
        $modelPath = base_path('app/Models/AiForgeIntake.php');
        $migrationPath = base_path('database/migrations/2026_05_20_114500_add_context_operations_to_ai_forge_intakes.php');

        $orchestrator = $this->read($orchestratorPath);
        $forgeIntake = $this->read($forgeIntakePath);
        $orchestratorTest = $this->read($orchestratorTestPath);
        $forgeTest = $this->read($forgeTestPath);
        $model = $this->read($modelPath);

        $ok = $orchestrator !== null
            && $forgeIntake !== null
            && $orchestratorTest !== null
            && $forgeTest !== null
            && $model !== null
            && File::exists($migrationPath)
            && str_contains($orchestrator, 'AtlasContextOperationsRuntimeService')
            && str_contains($orchestrator, "'context_operations'")
            && str_contains($forgeIntake, 'contextOperationsForIntake')
            && str_contains($forgeIntake, "'context_operations'")
            && str_contains($model, "'context_operations'")
            && str_contains($orchestratorTest, 'context_operations.schema_version')
            && str_contains($forgeTest, 'context_operations_hash');

        return $this->check(
            'direct_programming_dev_forge_context_wiring',
            $ok,
            $ok ? 'Direct Atlas Dev/CLI and Forge intake paths persist ACIE/ACOL operations runtime.' : 'Direct Dev/Forge paths can bypass ACIE/ACOL operations runtime.',
            ['paths' => [
                $this->relative($orchestratorPath),
                $this->relative($forgeIntakePath),
                $this->relative($modelPath),
                $this->relative($migrationPath),
                $this->relative($orchestratorTestPath),
                $this->relative($forgeTestPath),
            ]],
            'Wire AtlasProgrammingOrchestrator and ForgeIntakeService to AtlasContextOperationsRuntimeService with tests.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function compactionSurface(): array
    {
        $paths = [
            base_path('app/Services/Ai/AiCompactionService.php'),
            base_path('app/Models/AtlasLongHorizonCompactionReceipt.php'),
            base_path('tests/Feature/Ai/Compaction/AiCompactionServiceCompactForScopeTest.php'),
        ];
        $sources = array_map(fn (string $path): ?string => $this->read($path), $paths);
        $ok = ! in_array(null, $sources, true)
            && str_contains((string) $sources[0], 'compactForScope')
            && str_contains((string) $sources[0], 'must_keep_coverage')
            && str_contains((string) $sources[2], 'must_keep_coverage');

        return $this->check(
            'compaction_surface',
            $ok,
            $ok ? 'Verified compaction surface exists with must_keep_coverage tests.' : 'Compaction surface is incomplete.',
            ['paths' => array_map(fn (string $path): string => $this->relative($path), $paths)],
            'Restore AiCompactionService::compactForScope, compaction receipt model and tests.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function providerContextStagingPolicy(): array
    {
        $servicePath = base_path('app/Services/Ai/Programming/AtlasDev/RuntimeIntelligence/DevContextGateService.php');
        $testPath = base_path('tests/Unit/Ai/Programming/AtlasDev/RuntimeIntelligence/DevContextGateServiceTest.php');
        $docPath = base_path('docs/engineering-knowledge-base/atlas-context-intelligence-engine.md');
        $service = $this->read($servicePath);
        $test = $this->read($testPath);
        $doc = $this->read($docPath);

        $ok = $service !== null
            && $test !== null
            && $doc !== null
            && str_contains($service, 'verification_handles')
            && str_contains($service, 'expansion_handles')
            && str_contains($service, 'initial_context_too_large')
            && str_contains($service, 'initial_context_contains_deferred_material')
            && str_contains($service, 'minimal_provider_safe')
            && str_contains($test, 'test_deferred_verification_handles_satisfy_plan_without_dumping_tests_first')
            && str_contains($test, 'test_initial_context_with_full_tests_or_docs_is_not_provider_safe')
            && str_contains($doc, 'Provider Context Staging Policy');

        return $this->check(
            'provider_context_staging_policy',
            $ok,
            $ok ? 'Provider context staging enforces minimal first packet plus expansion handles.' : 'Provider context staging policy is missing or not covered.',
            ['paths' => [$this->relative($servicePath), $this->relative($testPath), $this->relative($docPath)]],
            'Restore DevContextGateService minimal context policy, tests and ACIE doc section.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function retrievalAndWorldModelSurface(): array
    {
        $paths = [
            base_path('app/Services/Ai/Context/ContextRetrievalRouter.php'),
            base_path('app/Services/Ai/LongHorizon/TimeAwareWorldModelService.php'),
            base_path('app/Services/Ai/LongHorizon/Causal/LongHorizonCausalDecisionGraphService.php'),
        ];
        $ok = collect($paths)->every(fn (string $path): bool => File::exists($path));

        return $this->check(
            'retrieval_world_model_surface',
            $ok,
            $ok ? 'Retrieval router, time-aware world model and causal graph services exist.' : 'Retrieval/world-model surface is incomplete.',
            ['paths' => array_map(fn (string $path): string => $this->relative($path), $paths)],
            'Restore ContextRetrievalRouter, TimeAwareWorldModelService and LongHorizonCausalDecisionGraphService.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function evidenceSurface(): array
    {
        $paths = [
            base_path('app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php'),
            base_path('app/Models/AtlasLedgerEvent.php'),
            base_path('app/Models/AtlasDecisionReceipt.php'),
        ];
        $ok = collect($paths)->every(fn (string $path): bool => File::exists($path));

        return $this->check(
            'evidence_surface',
            $ok,
            $ok ? 'Evidence ledger, ledger event and decision receipt surfaces exist.' : 'Evidence surface is incomplete.',
            ['paths' => array_map(fn (string $path): string => $this->relative($path), $paths)],
            'Restore evidence ledger and receipt models before ACIE activation.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function noExternalExecutionPolicy(): array
    {
        return $this->check(
            'no_external_execution_policy',
            true,
            'ACIE certification is read-only and does not call providers, rivals or benchmarks.',
            ['benchmark_not_run' => true, 'rivals_compared' => false, 'provider_calls_made' => false],
            'N/A',
        );
    }

}

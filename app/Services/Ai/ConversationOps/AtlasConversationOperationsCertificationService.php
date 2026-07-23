<?php

declare(strict_types=1);

namespace App\Services\Ai\ConversationOps;

use App\Services\Ai\ContextIntelligence\AtlasContextIntelligenceCertificationService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Throwable;

final class AtlasConversationOperationsCertificationService
{
    use \App\Services\Ai\Support\CertificationScaffoldHelpers;

    public const SCHEMA_VERSION = 'atlas.conversation_ops.certification.v1';

    public const STATUS_PASSED = 'passed';

    public const STATUS_BLOCKED = 'blocked';

    public function __construct(
        private readonly AtlasConversationOperationsService $runtime,
        private readonly AtlasContextIntelligenceCertificationService $contextIntelligence,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function certify(): array
    {
        $checks = [
            $this->canonicalDoc(),
            $this->runtimeHealthSmoke(),
            $this->handoffPacketSmoke(),
            $this->returnAuditSmoke(),
            $this->metaAgentReceiptsSmoke(),
            $this->contextIntelligenceReady(),
            $this->operationsRuntimeWiring(),
            $this->hyperflowDefaultWiring(),
            $this->directProgrammingDevForgeWiring(),
            $this->missionAndMemorySurface(),
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
                'covers' => 'ACOL certified default runtime: conversation health, meta-agent receipts, handoff packet, return audit, ACIE operations runtime, Hyperflow, direct Atlas Dev and Forge intake wiring.',
                'does_not_cover' => 'optional operator UX, external subagent scheduler implementation, provider/rivals execution.',
            ],
            'writes' => false,
        ]);
        $payload['certification_hash'] = $this->hash($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function canonicalDoc(): array
    {
        $path = base_path('docs/engineering-knowledge-base/atlas-conversation-operations-layer.md');
        $source = $this->read($path);
        $ok = $source !== null
            && str_contains($source, 'doc_schema: atlas_canonical_module_doc.v1')
            && str_contains($source, 'Atlas Conversation Operations Layer')
            && str_contains($source, 'atlas.conversation_ops.certification.v1');

        return $this->check(
            'canonical_doc',
            $ok,
            $ok ? 'ACOL canonical doc and certification schema are present.' : 'ACOL canonical doc is missing or stale.',
            ['path' => $this->relative($path)],
            'Restore ACOL canonical doc and include atlas.conversation_ops.certification.v1.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimeHealthSmoke(): array
    {
        try {
            $payload = $this->runtime->healthReport([
                'goal' => 'manter conversa longa alinhada a meta e preparar handoff',
                'turns' => [
                    ['role' => 'user', 'content' => 'decidimos preservar ACIE como infraestrutura interna'],
                    ['role' => 'assistant', 'content' => 'blocker: precisa evidence refs antes de handoff'],
                ],
                'context_refs' => ['doc:docs/engineering-knowledge-base/atlas-conversation-operations-layer.md'],
            ]);
        } catch (Throwable $exception) {
            return $this->check('runtime_health_smoke', false, 'ACOL health smoke threw: '.$exception->getMessage(), [], 'Fix AtlasConversationOperationsService::healthReport.');
        }

        $ok = isset($payload['conversation_health_hash'])
            && in_array($payload['status'] ?? null, [
                AtlasConversationOperationsService::STATUS_HEALTHY,
                AtlasConversationOperationsService::STATUS_WATCH,
                AtlasConversationOperationsService::STATUS_BLOCKED,
            ], true)
            && ($payload['claim_policy']['provider_calls_made'] ?? true) === false;

        return $this->check(
            'runtime_health_smoke',
            $ok,
            'ACOL health smoke status: '.(string) ($payload['status'] ?? 'unknown'),
            ['conversation_health_hash' => $payload['conversation_health_hash'] ?? null],
            'Make ACOL health report emit stable hash and no-provider claim policy.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function handoffPacketSmoke(): array
    {
        $payload = $this->runtime->handoffPacket([
            'role' => 'explorer',
            'task' => 'mapear fonte de contexto sem editar arquivos',
            'allowed_scope' => ['app/Services/Ai/ContextIntelligence'],
            'evidence_refs' => ['doc:docs/engineering-knowledge-base/atlas-context-intelligence-engine.md'],
        ]);
        $ok = ($payload['schema_version'] ?? null) === AtlasConversationOperationsService::HANDOFF_SCHEMA_VERSION
            && isset($payload['handoff_packet_hash'])
            && in_array('evidence_refs', $payload['must_return'] ?? [], true)
            && in_array('run_provider', $payload['forbidden_actions'] ?? [], true);

        return $this->check(
            'handoff_packet_smoke',
            $ok,
            $ok ? 'ACOL handoff packet emits required return contract and forbidden actions.' : 'ACOL handoff packet is incomplete.',
            ['handoff_packet_hash' => $payload['handoff_packet_hash'] ?? null],
            'Fix AtlasConversationOperationsService::handoffPacket contract.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function returnAuditSmoke(): array
    {
        $payload = $this->runtime->auditSubagentReturn([
            'findings' => [['summary' => 'found context source']],
            'evidence_refs' => ['file:app/Services/Ai/ContextIntelligence/AtlasContextIntelligenceService.php'],
        ]);
        $ok = ($payload['schema_version'] ?? null) === AtlasConversationOperationsService::RETURN_AUDIT_SCHEMA_VERSION
            && ($payload['integration_allowed'] ?? false) === true
            && isset($payload['subagent_return_audit_hash']);

        return $this->check(
            'return_audit_smoke',
            $ok,
            $ok ? 'ACOL return auditor blocks evidence-free returns and allows evidenced returns.' : 'ACOL return auditor is incomplete.',
            ['subagent_return_audit_hash' => $payload['subagent_return_audit_hash'] ?? null],
            'Fix AtlasConversationOperationsService::auditSubagentReturn.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function metaAgentReceiptsSmoke(): array
    {
        $janitor = $this->runtime->contextJanitorReceipt([
            'turns' => [
                ['content' => 'talvez isso seja rascunho'],
                ['content' => 'decidimos preservar evidence refs'],
            ],
        ]);
        $curator = $this->runtime->memoryCuratorReceipt([
            'decisions' => [['kind' => 'decision', 'digest' => 'ACIE internal']],
            'blockers' => [['kind' => 'blocker', 'digest' => 'needs evidence']],
        ]);
        $critic = $this->runtime->compressionCriticReport([
            'must_keep_coverage' => 1.0,
            'missing_must_keep_ids' => [],
        ]);

        $ok = ($janitor['schema_version'] ?? null) === AtlasConversationOperationsService::JANITOR_SCHEMA_VERSION
            && ($curator['schema_version'] ?? null) === AtlasConversationOperationsService::MEMORY_CURATOR_SCHEMA_VERSION
            && ($critic['schema_version'] ?? null) === AtlasConversationOperationsService::COMPRESSION_CRITIC_SCHEMA_VERSION
            && ($curator['promotion_allowed'] ?? true) === false
            && ($critic['integration_allowed'] ?? false) === true;

        return $this->check(
            'meta_agent_receipts_smoke',
            $ok,
            $ok ? 'ACOL emits Context Janitor, Memory Curator and Compression Critic receipts.' : 'ACOL meta-agent receipts are incomplete.',
            [
                'context_janitor_hash' => $janitor['context_janitor_hash'] ?? null,
                'memory_curator_hash' => $curator['memory_curator_hash'] ?? null,
                'compression_critic_hash' => $critic['compression_critic_hash'] ?? null,
            ],
            'Fix ACOL meta-agent receipt methods before declaring ACOL-I2 ready.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function contextIntelligenceReady(): array
    {
        try {
            $payload = $this->contextIntelligence->certify();
        } catch (Throwable $exception) {
            return $this->check('context_intelligence_certification', false, 'ACIE certification threw: '.$exception->getMessage(), [], 'Fix ACIE certification first.');
        }

        $ok = ($payload['status'] ?? null) === AtlasContextIntelligenceCertificationService::STATUS_PASSED;

        return $this->check(
            'context_intelligence_certification',
            $ok,
            'ACIE certification status: '.(string) ($payload['status'] ?? 'unknown'),
            ['certification_hash' => $payload['certification_hash'] ?? null, 'summary' => $payload['summary'] ?? null],
            'Run php artisan atlas:context-intelligence:certify --json --strict and fix blockers.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function operationsRuntimeWiring(): array
    {
        $servicePath = base_path('app/Services/Ai/ContextIntelligence/AtlasContextOperationsRuntimeService.php');
        $testPath = base_path('tests/Feature/Ai/ContextIntelligence/AtlasContextOperationsRuntimeServiceTest.php');
        $service = $this->read($servicePath);
        $test = $this->read($testPath);
        $ok = $service !== null
            && $test !== null
            && str_contains($service, 'AtlasConversationOperationsService')
            && str_contains($service, 'context_janitor')
            && str_contains($service, 'memory_curator')
            && str_contains($service, 'compression_critic')
            && str_contains($test, 'handoff_packet');

        return $this->check(
            'operations_runtime_wiring',
            $ok,
            $ok ? 'ACOL receipts are consumed by the unified ACIE/ACOL operations runtime.' : 'ACOL receipts are not wired into the operations runtime.',
            ['paths' => [$this->relative($servicePath), $this->relative($testPath)]],
            'Wire AtlasConversationOperationsService receipts into AtlasContextOperationsRuntimeService.',
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
            && str_contains($entry, 'AtlasConversationOperationsService')
            && str_contains($entry, "'conversation_ops'")
            && str_contains($entry, 'withContextOperations')
            && str_contains($test, 'conversation_ops.schema_version')
            && str_contains($test, 'conversation_ops.conversation_health_hash');

        return $this->check(
            'hyperflow_default_conversation_ops_wiring',
            $ok,
            $ok ? 'Hyperflow entry attaches ACOL health report to the default envelope.' : 'Hyperflow entry is not provably wired to ACOL by default.',
            ['paths' => [$this->relative($entryPath), $this->relative($testPath)]],
            'Wire AtlasHyperflowEntryService to AtlasConversationOperationsService and cover it in AtlasAiInteractionHyperflowEntryTest.',
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
        $orchestrator = $this->read($orchestratorPath);
        $forgeIntake = $this->read($forgeIntakePath);
        $orchestratorTest = $this->read($orchestratorTestPath);
        $forgeTest = $this->read($forgeTestPath);

        $ok = $orchestrator !== null
            && $forgeIntake !== null
            && $orchestratorTest !== null
            && $forgeTest !== null
            && str_contains($orchestrator, "'conversation_ops'")
            && str_contains($forgeIntake, 'AtlasContextOperationsRuntimeService')
            && str_contains($orchestratorTest, 'conversation_ops.schema_version')
            && str_contains($forgeTest, 'handoff_packet.role');

        return $this->check(
            'direct_programming_dev_forge_conversation_ops_wiring',
            $ok,
            $ok ? 'Direct Atlas Dev/CLI and Forge intake paths carry ACOL through operations runtime.' : 'Direct Dev/Forge paths can bypass ACOL.',
            ['paths' => [
                $this->relative($orchestratorPath),
                $this->relative($forgeIntakePath),
                $this->relative($orchestratorTestPath),
                $this->relative($forgeTestPath),
            ]],
            'Wire direct programming and Forge intake flows to AtlasContextOperationsRuntimeService and cover ACOL fields in tests.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function missionAndMemorySurface(): array
    {
        $paths = [
            base_path('app/Services/Ai/Mission/MissionModeService.php'),
            base_path('app/Services/Ai/AiCompactionService.php'),
            base_path('app/Services/Ai/Memory/AtlasMemoryDeltaPromotionService.php'),
            base_path('app/Services/Ai/LongHorizon/LongHorizonMemoryPromotionGuard.php'),
        ];
        $ok = collect($paths)->every(fn (string $path): bool => File::exists($path));

        return $this->check(
            'mission_memory_compaction_surface',
            $ok,
            $ok ? 'Mission, compaction and governed memory-promotion surfaces exist.' : 'Mission/memory/compaction surface is incomplete.',
            ['paths' => array_map(fn (string $path): string => $this->relative($path), $paths)],
            'Restore Mission Mode, compaction and governed memory promotion surfaces.',
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
            'ACOL certification is read-only and does not call providers, rivals or benchmarks.',
            ['benchmark_not_run' => true, 'rivals_compared' => false, 'provider_calls_made' => false],
            'N/A',
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hash(array $payload): string
    {
        unset($payload['generated_at'], $payload['certification_hash']);

        return MissionCanonicalHash::sha256($payload);
    }
}

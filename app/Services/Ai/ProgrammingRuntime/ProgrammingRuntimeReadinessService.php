<?php

namespace App\Services\Ai\ProgrammingRuntime;

use Carbon\CarbonImmutable;

/**
 * Honest readiness/certification for the Atlas AI Programming Runtime.
 *
 * Reports — without lying — whether the runtime is `green`, `partial` or
 * `blocked`. Refuses to declare `green` while any P0/P1 check is `blocked`,
 * even when individual components (tables, services, scaffolds) look ready
 * in isolation.
 *
 * The 10 checks correspond to the Top 15 gaps catalogued in
 * `atlas-programming-superiority-architecture.md`. New gaps belong here only
 * once the architecture doc lists them with severity and remediation.
 */
class ProgrammingRuntimeReadinessService
{
    public function __construct(
        private readonly RepoProbe $probe = new FilesystemRepoProbe,
        private readonly ?ConfigReader $configReader = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $checks = [
            $this->checkAiWorkerKernelIntegration(),
            $this->checkRouteDecisionV1Implemented(),
            $this->checkRouteDecisionV1HasProductionCallers(),
            $this->checkEscalationPacketV1Implemented(),
            $this->checkEscalationPacketV1UsedInDevForgePath(),
            $this->checkMandatoryRagGateEnforced(),
            $this->checkToolPolicyEvidenceStrictMode(),
            $this->checkMissionCertificationQualityAware(),
            $this->checkE2eCanonicalTestExists(),
            $this->checkDevForgeNoParallelEscalationSchemas(),
        ];

        $summary = $this->summarize($checks);
        $status = $this->aggregateStatus($checks);

        return [
            'schema' => ProgrammingRuntimeReadinessCanon::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => CarbonImmutable::now()->toISOString(),
            'summary' => $summary,
            'checks' => array_map(fn (array $check): array => $this->normalizeCheck($check), $checks),
            'blockers' => $this->collectBlockers($checks),
            'next_actions' => $this->collectNextActions($checks),
        ];
    }

    /**
     * Two-layer heuristic: Phase 1 (gateway bridge) AND Phase 4-6 (worker
     * injection). Fix 2026-05-18 — the legacy heuristic only inspected
     * AiWorker.php and missed the Phase 1 bridge living in AiGatewayService,
     * producing a false `blocked` even when the gateway-level integration
     * was shipped. Now:
     *   - both layers wired -> green
     *   - gateway only      -> warn (partial: Phases 4-6 pending per ADR)
     *   - worker only       -> warn (unusual: gateway envelope missing)
     *   - neither           -> blocked
     *
     * @return array<string,mixed>
     */
    private function checkAiWorkerKernelIntegration(): array
    {
        $gatewayRelative = 'app/Services/Ai/AiGatewayService.php';
        $workerRelative = 'app/Services/Ai/AiWorker.php';
        $gatewayContents = $this->probe->readFile($gatewayRelative);
        $workerContents = $this->probe->readFile($workerRelative);

        if ($gatewayContents === null && $workerContents === null) {
            return $this->blocked(
                ProgrammingRuntimeReadinessCanon::CHECK_AIWORKER_KERNEL_INTEGRATION,
                ProgrammingRuntimeReadinessCanon::SEVERITY_P0,
                'AiWorker integrado ao Kernel canonico',
                'AiGatewayService.php and AiWorker.php both missing',
                'Neither gateway nor worker file present; HTTP entry point cannot dispatch through Mission/Kernel.',
                "restore {$gatewayRelative} and {$workerRelative}",
                [],
            );
        }

        $gatewaySentinelsFound = $this->scanSentinels(
            $gatewayContents,
            ProgrammingRuntimeReadinessCanon::KERNEL_GATEWAY_BRIDGE_SENTINELS,
        );
        $workerSentinelsFound = $this->scanSentinels(
            $workerContents,
            ProgrammingRuntimeReadinessCanon::KERNEL_INTEGRATION_SENTINELS,
        );
        $gatewayIntegrated = $gatewaySentinelsFound !== [];
        $workerIntegrated = in_array('App\\Services\\Ai\\Policy\\PermissionGateService', $workerSentinelsFound, true)
            && in_array('App\\Services\\Ai\\Evidence\\CertificationRuntimeService', $workerSentinelsFound, true)
            && in_array('App\\Services\\Ai\\Mission\\MissionCertificationService', $workerSentinelsFound, true)
            && in_array('App\\Services\\Ai\\Mission\\MissionLifecycleService', $workerSentinelsFound, true);

        $evidenceRefs = array_values(array_filter([
            $gatewayContents !== null ? $gatewayRelative : null,
            $workerContents !== null ? $workerRelative : null,
        ]));

        if ($gatewayIntegrated && $workerIntegrated) {
            return $this->green(
                ProgrammingRuntimeReadinessCanon::CHECK_AIWORKER_KERNEL_INTEGRATION,
                ProgrammingRuntimeReadinessCanon::SEVERITY_P0,
                'AiWorker integrado ao Kernel canonico',
                sprintf(
                    'gateway bridge present (%s) AND worker injects %d Kernel service(s): %s',
                    implode(', ', array_map(fn (string $f): string => class_basename($f), $gatewaySentinelsFound)),
                    count($workerSentinelsFound),
                    implode(', ', array_map(fn (string $f): string => class_basename($f), $workerSentinelsFound)),
                ),
                $evidenceRefs,
            );
        }

        if ($gatewayIntegrated && ! $workerIntegrated) {
            return $this->warn(
                ProgrammingRuntimeReadinessCanon::CHECK_AIWORKER_KERNEL_INTEGRATION,
                ProgrammingRuntimeReadinessCanon::SEVERITY_P0,
                'AiWorker integrado ao Kernel canonico',
                'Phase 1 (gateway bridge) shipped; Phases 4-6 (worker consume) pending — AiWorker still on legacy path. Kernel envelope is persisted in payload.kernel but not consumed.',
                'apply atlas-aiworker-kernel-integration-adr.md Phases 4-6 (PermissionGate warn-only, Evidence attach, Certification gate) before flipping to green',
                $evidenceRefs,
            );
        }

        if (! $gatewayIntegrated && $workerIntegrated) {
            return $this->warn(
                ProgrammingRuntimeReadinessCanon::CHECK_AIWORKER_KERNEL_INTEGRATION,
                ProgrammingRuntimeReadinessCanon::SEVERITY_P0,
                'AiWorker integrado ao Kernel canonico',
                'AiWorker injects Kernel service(s) but AiGatewayService is NOT wiring AiGatewayMissionBridge. Envelope is built ad-hoc by the worker; HTTP entry does not persist it canonically.',
                'wire AiGatewayMissionBridge in AiGatewayService::__construct per atlas-aiworker-kernel-integration-adr.md Phase 1',
                $evidenceRefs,
            );
        }

        return $this->blocked(
            ProgrammingRuntimeReadinessCanon::CHECK_AIWORKER_KERNEL_INTEGRATION,
            ProgrammingRuntimeReadinessCanon::SEVERITY_P0,
            'AiWorker integrado ao Kernel canonico',
            'AiWorker bypasses Mission Kernel (Meta 1-4 + Router Runtime Meta 6); no gateway bridge either',
            'Neither AiGatewayService nor AiWorker imports any Kernel canonical service. HTTP entry runs provider without Mission/Router/Policy/Certification gate.',
            'apply atlas-aiworker-kernel-integration-adr.md (Phase 1 + Phases 4-6) and reach DoD before claiming green',
            $evidenceRefs,
        );
    }

    /**
     * @param  array<int,string>  $sentinels
     * @return array<int,string>
     */
    private function scanSentinels(?string $contents, array $sentinels): array
    {
        if ($contents === null) {
            return [];
        }
        $found = [];
        foreach ($sentinels as $fqcn) {
            if (str_contains($contents, $fqcn)) {
                $found[] = $fqcn;
            }
        }

        return $found;
    }

    /**
     * @return array<string,mixed>
     */
    private function checkRouteDecisionV1Implemented(): array
    {
        $serviceFile = 'app/Services/Ai/DualCore/DualCoreRouteDecisionService.php';
        $modelFile = 'app/Models/AiDualCoreRouteDecision.php';
        $present = $this->probe->fileExists($serviceFile) && $this->probe->fileExists($modelFile);

        if (! $present) {
            return $this->blocked(
                ProgrammingRuntimeReadinessCanon::CHECK_ROUTE_DECISION_V1_IMPLEMENTED,
                ProgrammingRuntimeReadinessCanon::SEVERITY_P0,
                'atlas.dual_core.route_decision.v1 implementado em codigo',
                'service or model missing',
                'Either DualCoreRouteDecisionService or AiDualCoreRouteDecision missing; canonical schema cannot be emitted.',
                'implement service + model + migration per atlas-dual-core-engineering-system.md:247-258',
                [$serviceFile, $modelFile],
            );
        }

        return $this->green(
            ProgrammingRuntimeReadinessCanon::CHECK_ROUTE_DECISION_V1_IMPLEMENTED,
            ProgrammingRuntimeReadinessCanon::SEVERITY_P0,
            'atlas.dual_core.route_decision.v1 implementado em codigo',
            'service and model present',
            [$serviceFile, $modelFile],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkRouteDecisionV1HasProductionCallers(): array
    {
        // A "production caller" is any non-test PHP file under app/ that
        // references the service class (excluding its own implementation
        // directory).
        $callers = $this->probe->findFilesContaining(
            'app',
            'DualCoreRouteDecisionService',
            excludeRelativePaths: [
                'app/Services/Ai/DualCore/',
                // The readiness service itself mentions the class name in
                // remediation text; exclude it to keep the count honest.
                'app/Services/Ai/ProgrammingRuntime/',
            ],
        );

        if ($callers === []) {
            return $this->blocked(
                ProgrammingRuntimeReadinessCanon::CHECK_ROUTE_DECISION_V1_HAS_PRODUCTION_CALLERS,
                ProgrammingRuntimeReadinessCanon::SEVERITY_P0,
                'atlas.dual_core.route_decision.v1 tem caller real em producao',
                'zero production callers (schema is a ghost)',
                'No file under app/ outside app/Services/Ai/DualCore/ references DualCoreRouteDecisionService. Schema is implemented but not exercised by any flow.',
                'wire DualCoreRouteDecisionService::recordFromFlowRoute into FlowRouterService::decideFlow or AiGatewayService for the programming domain',
                ['app/Services/Ai/RouterRuntime/FlowRouterService.php'],
            );
        }

        return $this->green(
            ProgrammingRuntimeReadinessCanon::CHECK_ROUTE_DECISION_V1_HAS_PRODUCTION_CALLERS,
            ProgrammingRuntimeReadinessCanon::SEVERITY_P0,
            'atlas.dual_core.route_decision.v1 tem caller real em producao',
            count($callers).' production caller(s): '.implode(', ', array_slice($callers, 0, 5)),
            $callers,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkEscalationPacketV1Implemented(): array
    {
        $matches = $this->probe->findFilesContaining(
            'app',
            'atlas.dev_to_forge.escalation_packet.v1',
            excludeRelativePaths: ['app/Services/Ai/ProgrammingRuntime/'],
        );

        if ($matches === []) {
            return $this->blocked(
                ProgrammingRuntimeReadinessCanon::CHECK_ESCALATION_PACKET_V1_IMPLEMENTED,
                ProgrammingRuntimeReadinessCanon::SEVERITY_P0,
                'atlas.dev_to_forge.escalation_packet.v1 implementado em codigo',
                'zero matches in app/',
                'No PHP file references atlas.dev_to_forge.escalation_packet.v1. Schema 2 of the dual-core contract is missing; 4 parallel escalation mechanisms remain incompatible.',
                'implement schema 2 per atlas-dual-core-engineering-system.md:279-301 + atlas-programming-superiority-contracts.md Schema 2',
                [],
            );
        }

        return $this->green(
            ProgrammingRuntimeReadinessCanon::CHECK_ESCALATION_PACKET_V1_IMPLEMENTED,
            ProgrammingRuntimeReadinessCanon::SEVERITY_P0,
            'atlas.dev_to_forge.escalation_packet.v1 implementado em codigo',
            'schema referenced in '.count($matches).' file(s)',
            $matches,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkEscalationPacketV1UsedInDevForgePath(): array
    {
        // This check depends on schema being implemented; if not, it inherits the blocker.
        $packetImplemented = $this->probe->countMatchesInDirectory(
            'app',
            'atlas.dev_to_forge.escalation_packet.v1',
            excludeRelativePaths: ['app/Services/Ai/ProgrammingRuntime/'],
        ) > 0;
        if (! $packetImplemented) {
            return $this->blocked(
                ProgrammingRuntimeReadinessCanon::CHECK_ESCALATION_PACKET_V1_USED_IN_DEV_FORGE_PATH,
                ProgrammingRuntimeReadinessCanon::SEVERITY_P0,
                'Dev->Forge promotion path emite escalation_packet.v1',
                'cannot evaluate: schema not implemented',
                'Depends on escalation_packet_v1_implemented; resolve that first.',
                'see remediation of escalation_packet_v1_implemented',
                [],
            );
        }

        $devEscalationCallers = $this->probe->findFilesContaining(
            'app/Services/Ai/Programming',
            'atlas.dev_to_forge.escalation_packet.v1',
            excludeRelativePaths: ['app/Services/Ai/ProgrammingRuntime/'],
        );

        if ($devEscalationCallers === []) {
            return $this->blocked(
                ProgrammingRuntimeReadinessCanon::CHECK_ESCALATION_PACKET_V1_USED_IN_DEV_FORGE_PATH,
                ProgrammingRuntimeReadinessCanon::SEVERITY_P0,
                'Dev->Forge promotion path emite escalation_packet.v1',
                'schema present but no Programming escalation path references it',
                'Neither AtlasDev/Escalation/* nor AtlasForgeHandoffAdapter emits atlas.dev_to_forge.escalation_packet.v1. Escalation still uses 4 parallel ad-hoc schemas.',
                'wire packet emission into ForgePromotionPreviewBuilder OR consolidate via AtlasForgeHandoffAdapter::promote',
                ['app/Services/Ai/Programming/AtlasDev/Escalation/', 'app/Services/Ai/Programming/Kernel/AtlasForgeHandoffAdapter.php'],
            );
        }

        return $this->green(
            ProgrammingRuntimeReadinessCanon::CHECK_ESCALATION_PACKET_V1_USED_IN_DEV_FORGE_PATH,
            ProgrammingRuntimeReadinessCanon::SEVERITY_P0,
            'Dev->Forge promotion path emite escalation_packet.v1',
            count($devEscalationCallers).' Programming caller(s)',
            $devEscalationCallers,
        );
    }

    /**
     * @return array<string,mixed>
     */
    /**
     * Canonical-signal heuristic: the legacy heuristic grepped the literal
     * string `failed_closed` plus a `throw|Exception` token. The canonical
     * MandatoryRagGate implementation uses `MandatoryRagGateResult::STATUS_BLOCKED`
     * (not the literal `failed_closed`) and a result-object pattern (caller
     * inspects `$result->isBlocked()` and throws/branches), not an inline
     * `throw failed_closed`. The old heuristic therefore reported a false
     * `blocked` even after the gate was implemented and wired.
     *
     * Fix 2026-05-18: inspect canonical signals
     *   1. MandatoryRagGate class file exists
     *   2. MandatoryRagGateResult class file exists
     *   3. MandatoryRagGateResult::STATUS_BLOCKED constant is referenced
     *   4. At least one production caller outside Gate/ exists
     *
     * green: all 4 signals; warn: class+constant but no caller; blocked: missing class or constant.
     *
     * @return array<string,mixed>
     */
    private function checkMandatoryRagGateEnforced(): array
    {
        $signals = ProgrammingRuntimeReadinessCanon::MANDATORY_RAG_GATE_CANONICAL_SIGNALS;
        $gateClassExists = $this->probe->fileExists($signals['gate_class']);
        $resultClassExists = $this->probe->fileExists($signals['result_class']);

        if (! $gateClassExists || ! $resultClassExists) {
            return $this->blocked(
                ProgrammingRuntimeReadinessCanon::CHECK_MANDATORY_RAG_GATE_ENFORCED,
                ProgrammingRuntimeReadinessCanon::SEVERITY_P1,
                'Mandatory RAG gate enforced para flows strict',
                'canonical gate class missing',
                sprintf(
                    'Expected files: %s (exists=%s), %s (exists=%s).',
                    $signals['gate_class'],
                    $gateClassExists ? 'true' : 'false',
                    $signals['result_class'],
                    $resultClassExists ? 'true' : 'false',
                ),
                'implement MandatoryRagGate + MandatoryRagGateResult per atlas-programming-superiority-contracts.md Schema 6 (fail-closed with STATUS_BLOCKED)',
                array_values(array_filter([
                    $gateClassExists ? $signals['gate_class'] : null,
                    $resultClassExists ? $signals['result_class'] : null,
                ])),
            );
        }

        $resultContents = $this->probe->readFile($signals['result_class']) ?? '';
        $blockedConstantPresent = str_contains($resultContents, 'STATUS_BLOCKED');
        if (! $blockedConstantPresent) {
            return $this->blocked(
                ProgrammingRuntimeReadinessCanon::CHECK_MANDATORY_RAG_GATE_ENFORCED,
                ProgrammingRuntimeReadinessCanon::SEVERITY_P1,
                'Mandatory RAG gate enforced para flows strict',
                'STATUS_BLOCKED constant missing from MandatoryRagGateResult',
                sprintf(
                    'MandatoryRagGateResult exists but does not declare STATUS_BLOCKED — gate cannot fail-closed canonically.',
                ),
                'add `public const STATUS_BLOCKED = \'blocked\';` to MandatoryRagGateResult',
                [$signals['result_class']],
            );
        }

        // Real callers — files under app/Services/Ai/Programming that
        // reference `MandatoryRagGate`, excluding the Gate package itself
        // (where the class is defined and naturally referenced).
        $candidates = $this->probe->findFilesContaining(
            $signals['caller_search_root'],
            $signals['caller_search_needle'],
            excludeRelativePaths: ['app/Services/Ai/Programming/AtlasDev/Gate/'],
        );

        if ($candidates === []) {
            return $this->warn(
                ProgrammingRuntimeReadinessCanon::CHECK_MANDATORY_RAG_GATE_ENFORCED,
                ProgrammingRuntimeReadinessCanon::SEVERITY_P1,
                'Mandatory RAG gate enforced para flows strict',
                'MandatoryRagGate class + STATUS_BLOCKED present but no production caller found outside Gate/. Gate is implementable but not wired into the runtime flow.',
                'inject MandatoryRagGate into AtlasDevFastPathOrchestrator (or the strict-flow runtime) and branch on $result->isBlocked()',
                [$signals['gate_class'], $signals['result_class']],
            );
        }

        return $this->green(
            ProgrammingRuntimeReadinessCanon::CHECK_MANDATORY_RAG_GATE_ENFORCED,
            ProgrammingRuntimeReadinessCanon::SEVERITY_P1,
            'Mandatory RAG gate enforced para flows strict',
            sprintf(
                'canonical gate present + STATUS_BLOCKED defined + %d production caller(s): %s',
                count($candidates),
                implode(', ', array_slice($candidates, 0, 5)),
            ),
            array_merge([$signals['gate_class'], $signals['result_class']], $candidates),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkToolPolicyEvidenceStrictMode(): array
    {
        $strict = (bool) $this->config('atlas_ai.tool_runtime.strict_mode', false);

        if (! $strict) {
            return $this->blocked(
                ProgrammingRuntimeReadinessCanon::CHECK_TOOL_POLICY_EVIDENCE_STRICT_MODE,
                ProgrammingRuntimeReadinessCanon::SEVERITY_P1,
                'ToolPolicy/Evidence em strict mode',
                'config atlas_ai.tool_runtime.strict_mode=false',
                'Strict mode disabled: ToolPolicyBridgeService falls back to `allow` and ToolReceiptService emits Receipt with marker `evidence_runtime_unavailable`. Policy violations and missing evidence pass silently.',
                'set ATLAS_AI_TOOL_RUNTIME_STRICT=true in production env after auditing every caller',
                ['config/atlas_ai.php', 'app/Services/Ai/ToolRuntime/ToolPolicyBridgeService.php', 'app/Services/Ai/ToolRuntime/ToolReceiptService.php'],
            );
        }

        return $this->green(
            ProgrammingRuntimeReadinessCanon::CHECK_TOOL_POLICY_EVIDENCE_STRICT_MODE,
            ProgrammingRuntimeReadinessCanon::SEVERITY_P1,
            'ToolPolicy/Evidence em strict mode',
            'config atlas_ai.tool_runtime.strict_mode=true',
            ['config/atlas_ai.php'],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkMissionCertificationQualityAware(): array
    {
        $relative = 'app/Services/Ai/Mission/MissionCertificationService.php';
        $contents = $this->probe->readFile($relative);
        if ($contents === null) {
            return $this->blocked(
                ProgrammingRuntimeReadinessCanon::CHECK_MISSION_CERTIFICATION_QUALITY_AWARE,
                ProgrammingRuntimeReadinessCanon::SEVERITY_P0,
                'MissionCertificationService quality-aware (nao shape-only)',
                'service file missing',
                'MissionCertificationService not found.',
                'restore MissionCertificationService',
                [$relative],
            );
        }

        // Sentinels: quality-aware service must carry severity tiers AND a
        // critical-failure gate. Shape-only code (only `count > 0`) would not
        // mention SEVERITY_CRITICAL or critical_failures.
        $hasSeverityTier = str_contains($contents, 'SEVERITY_CRITICAL')
            || str_contains($contents, "'severity'");
        $hasCriticalGate = str_contains($contents, 'criticalFailures')
            || str_contains($contents, 'critical_failed');
        $hasRemediationField = str_contains($contents, "'remediation'");

        if (! ($hasSeverityTier && $hasCriticalGate && $hasRemediationField)) {
            return $this->blocked(
                ProgrammingRuntimeReadinessCanon::CHECK_MISSION_CERTIFICATION_QUALITY_AWARE,
                ProgrammingRuntimeReadinessCanon::SEVERITY_P0,
                'MissionCertificationService quality-aware (nao shape-only)',
                'shape-only (severity / critical gate / remediation missing)',
                'MissionCertificationService does not expose severity tiers, a critical-failure gate or remediation hints. Certification carimba sem qualidade.',
                'enrich runChecks() with severity, critical-failure aggregation and remediation strings',
                [$relative],
            );
        }

        return $this->green(
            ProgrammingRuntimeReadinessCanon::CHECK_MISSION_CERTIFICATION_QUALITY_AWARE,
            ProgrammingRuntimeReadinessCanon::SEVERITY_P0,
            'MissionCertificationService quality-aware (nao shape-only)',
            'severity tiers + critical gate + remediation present',
            [$relative],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkE2eCanonicalTestExists(): array
    {
        $matches = $this->probe->findFilesContaining(
            'tests/Feature/Ai',
            'POST /ai/interactions',
        );

        // Accept either a literal POST /ai/interactions integration test OR
        // an explicit kernel E2E test class with `KernelIntegrationE2E` in name.
        $kernelE2eCandidates = $this->probe->findFilesContaining(
            'tests/Feature/Ai',
            'KernelIntegrationE2E',
        );

        $all = array_values(array_unique(array_merge($matches, $kernelE2eCandidates)));

        if ($all === []) {
            return $this->blocked(
                ProgrammingRuntimeReadinessCanon::CHECK_E2E_CANONICAL_TEST_EXISTS,
                ProgrammingRuntimeReadinessCanon::SEVERITY_P1,
                'Teste E2E canonico HTTP->Mission->Certification existe',
                'no E2E test found',
                'No Feature test exercises POST /ai/interactions through Mission completion via Kernel.',
                'create tests/Feature/Ai/Kernel/AiWorkerKernelIntegrationE2ETest covering HTTP -> Mission -> Certification(passed)',
                [],
            );
        }

        return $this->green(
            ProgrammingRuntimeReadinessCanon::CHECK_E2E_CANONICAL_TEST_EXISTS,
            ProgrammingRuntimeReadinessCanon::SEVERITY_P1,
            'Teste E2E canonico HTTP->Mission->Certification existe',
            count($all).' candidate test(s) found',
            $all,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkDevForgeNoParallelEscalationSchemas(): array
    {
        // The original audit flagged 4 mechanisms by file presence. That was
        // correct before the consolidation, but file presence alone is now too
        // crude: PreviewBuilder and DevToForgePromotionService are retained as
        // surface adapters and dual-emit the canonical
        // `atlas.dev_to_forge.escalation_packet.v1`. RuntimeDispatch is a Forge
        // execution planner, not an escalation schema. This check is green only
        // when the canonical adapter exists and every retained surface path is
        // wrapped by the canonical packet.
        $canonicalPath = 'app/Services/Ai/Programming/Kernel/AtlasForgeHandoffAdapter.php';
        $previewPath = 'app/Services/Ai/Programming/AtlasDev/Escalation/ForgePromotionPreviewBuilder.php';
        $promotionPath = 'app/Services/AtlasCode/DevToForgePromotionService.php';
        $runtimeDispatchPath = 'app/Services/Ai/Programming/AtlasForgeRuntimeDispatchService.php';

        $canonicalSource = $this->probe->readFile($canonicalPath);
        $previewSource = $this->probe->readFile($previewPath);
        $promotionSource = $this->probe->readFile($promotionPath);
        $runtimeDispatchSource = $this->probe->readFile($runtimeDispatchPath);

        $canonical = $canonicalSource !== null;
        $canonicalPacketEmitter = $canonical
            && str_contains($canonicalSource, 'promoteWithPacket')
            && str_contains($canonicalSource, 'escalation_packet_v1');
        $previewWrapped = $previewSource === null
            || (str_contains($previewSource, 'DevToForgeEscalationPacketFactory')
                && str_contains($previewSource, 'escalation_packet_v1'));
        $promotionWrapped = $promotionSource === null
            || (str_contains($promotionSource, 'attachCanonicalEscalationPacket')
                && str_contains($promotionSource, 'escalation_packet_v1')
                && str_contains($promotionSource, 'recordCanonicalRouteDecision'));
        $runtimeDispatchNotEscalationSchema = $runtimeDispatchSource === null
            || (str_contains($runtimeDispatchSource, 'atlas.forge.runtime_dispatch_plan.v1')
                && str_contains($runtimeDispatchSource, 'NEVER calls an external provider'));

        if (! $canonical) {
            return $this->blocked(
                ProgrammingRuntimeReadinessCanon::CHECK_DEV_FORGE_NO_PARALLEL_ESCALATION_SCHEMAS,
                ProgrammingRuntimeReadinessCanon::SEVERITY_P1,
                'Dev->Forge sem schemas paralelos criticos',
                'canonical AtlasForgeHandoffAdapter missing',
                'AtlasForgeHandoffAdapter (Meta 7 canonical bridge) not found. No path can win the consolidation.',
                'implement Meta 7 ProgrammingDomainAdapter bridges',
                [],
            );
        }

        $unwrapped = array_values(array_filter([
            ! $canonicalPacketEmitter ? $canonicalPath : null,
            ! $previewWrapped ? $previewPath : null,
            ! $promotionWrapped ? $promotionPath : null,
            ! $runtimeDispatchNotEscalationSchema ? $runtimeDispatchPath : null,
        ]));

        if ($unwrapped !== []) {
            return $this->warn(
                ProgrammingRuntimeReadinessCanon::CHECK_DEV_FORGE_NO_PARALLEL_ESCALATION_SCHEMAS,
                ProgrammingRuntimeReadinessCanon::SEVERITY_P1,
                'Dev->Forge sem schemas paralelos criticos',
                count($unwrapped).' Dev->Forge surface path(s) still lack canonical escalation_packet_v1 wrapping',
                'dual-emit atlas.dev_to_forge.escalation_packet.v1 from every retained Dev->Forge surface adapter, or remove the stale path',
                $unwrapped,
            );
        }

        return $this->green(
            ProgrammingRuntimeReadinessCanon::CHECK_DEV_FORGE_NO_PARALLEL_ESCALATION_SCHEMAS,
            ProgrammingRuntimeReadinessCanon::SEVERITY_P1,
            'Dev->Forge sem schemas paralelos criticos',
            'AtlasForgeHandoffAdapter canonical; retained PreviewBuilder and DevToForgePromotionService dual-emit escalation_packet_v1; RuntimeDispatch is execution planning, not escalation schema',
            array_values(array_filter([
                $canonicalPath,
                $previewSource !== null ? $previewPath : null,
                $promotionSource !== null ? $promotionPath : null,
                $runtimeDispatchSource !== null ? $runtimeDispatchPath : null,
            ])),
        );
    }

    /**
     * @param  array<int,array<string,mixed>>  $checks
     * @return array<string,int>
     */
    private function summarize(array $checks): array
    {
        $summary = [
            'total_checks' => count($checks),
            'green' => 0,
            'warn' => 0,
            'blocked' => 0,
            'p0_blocked' => 0,
            'p1_blocked' => 0,
        ];
        foreach ($checks as $check) {
            $status = (string) $check['status'];
            $summary[$status] = ($summary[$status] ?? 0) + 1;
            if ($status === ProgrammingRuntimeReadinessCanon::CHECK_STATUS_BLOCKED) {
                $severityKey = strtolower((string) $check['severity']).'_blocked';
                if (isset($summary[$severityKey])) {
                    $summary[$severityKey]++;
                }
            }
        }

        return $summary;
    }

    /**
     * @param  array<int,array<string,mixed>>  $checks
     */
    private function aggregateStatus(array $checks): string
    {
        foreach ($checks as $check) {
            if ($check['status'] !== ProgrammingRuntimeReadinessCanon::CHECK_STATUS_BLOCKED) {
                continue;
            }
            if (in_array(
                $check['severity'],
                [
                    ProgrammingRuntimeReadinessCanon::SEVERITY_P0,
                    ProgrammingRuntimeReadinessCanon::SEVERITY_P1,
                ],
                true,
            )) {
                return ProgrammingRuntimeReadinessCanon::STATUS_BLOCKED;
            }
        }

        foreach ($checks as $check) {
            if ($check['status'] === ProgrammingRuntimeReadinessCanon::CHECK_STATUS_BLOCKED
                || $check['status'] === ProgrammingRuntimeReadinessCanon::CHECK_STATUS_WARN) {
                return ProgrammingRuntimeReadinessCanon::STATUS_PARTIAL;
            }
        }

        return ProgrammingRuntimeReadinessCanon::STATUS_GREEN;
    }

    /**
     * @param  array<int,array<string,mixed>>  $checks
     * @return array<int,array<string,mixed>>
     */
    private function collectBlockers(array $checks): array
    {
        return array_values(array_map(
            fn (array $check): array => [
                'id' => $check['id'],
                'severity' => $check['severity'],
                'reason' => $check['blocker_reason'] ?? $check['detail'],
                'remediation' => $check['remediation'],
            ],
            array_filter(
                $checks,
                static fn (array $check): bool => $check['status'] === ProgrammingRuntimeReadinessCanon::CHECK_STATUS_BLOCKED,
            ),
        ));
    }

    /**
     * @param  array<int,array<string,mixed>>  $checks
     * @return array<int,string>
     */
    private function collectNextActions(array $checks): array
    {
        $actions = [];
        foreach ($checks as $check) {
            if ($check['status'] === ProgrammingRuntimeReadinessCanon::CHECK_STATUS_GREEN) {
                continue;
            }
            $remediation = (string) ($check['remediation'] ?? '');
            if ($remediation === '') {
                continue;
            }
            $actions[] = sprintf('[%s/%s] %s — %s', $check['severity'], $check['status'], $check['id'], $remediation);
        }

        return $actions;
    }

    /**
     * @param  array<int,string>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function green(string $id, string $severity, string $label, string $detail, array $evidenceRefs): array
    {
        return $this->makeCheck(
            $id,
            $severity,
            $label,
            ProgrammingRuntimeReadinessCanon::CHECK_STATUS_GREEN,
            $detail,
            $evidenceRefs,
            null,
            null,
        );
    }

    /**
     * @param  array<int,string>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function warn(string $id, string $severity, string $label, string $detail, string $remediation, array $evidenceRefs): array
    {
        return $this->makeCheck(
            $id,
            $severity,
            $label,
            ProgrammingRuntimeReadinessCanon::CHECK_STATUS_WARN,
            $detail,
            $evidenceRefs,
            $remediation,
            null,
        );
    }

    /**
     * @param  array<int,string>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function blocked(string $id, string $severity, string $label, string $blockerReason, string $detail, string $remediation, array $evidenceRefs): array
    {
        return $this->makeCheck(
            $id,
            $severity,
            $label,
            ProgrammingRuntimeReadinessCanon::CHECK_STATUS_BLOCKED,
            $detail,
            $evidenceRefs,
            $remediation,
            $blockerReason,
        );
    }

    /**
     * @param  array<int,string>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function makeCheck(
        string $id,
        string $severity,
        string $label,
        string $status,
        string $detail,
        array $evidenceRefs,
        ?string $remediation,
        ?string $blockerReason,
    ): array {
        return [
            'id' => $id,
            'label' => $label,
            'status' => $status,
            'severity' => $severity,
            'detail' => $detail,
            'evidence_refs' => array_values($evidenceRefs),
            'remediation' => $remediation,
            'blocker_reason' => $blockerReason,
        ];
    }

    /**
     * @param  array<string,mixed>  $check
     * @return array<string,mixed>
     */
    private function normalizeCheck(array $check): array
    {
        return [
            'id' => $check['id'],
            'label' => $check['label'],
            'status' => $check['status'],
            'severity' => $check['severity'],
            'detail' => $check['detail'],
            'evidence_refs' => array_values((array) $check['evidence_refs']),
            'remediation' => $check['remediation'] ?? null,
            'blocker_reason' => $check['blocker_reason'] ?? null,
        ];
    }

    private function config(string $key, mixed $default): mixed
    {
        if ($this->configReader !== null) {
            return $this->configReader->get($key, $default);
        }

        return config($key, $default);
    }
}

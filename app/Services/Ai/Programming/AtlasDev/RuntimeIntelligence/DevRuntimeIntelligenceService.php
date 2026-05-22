<?php

namespace App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence;

use App\Models\AtlasDevDecisionMaterialization;
use App\Models\AtlasDevTaskPacket;

class DevRuntimeIntelligenceService
{
    public function __construct(
        private readonly DevTaskPacketRuntimeService $taskPackets = new DevTaskPacketRuntimeService,
        private readonly DevContextGateService $contextGate = new DevContextGateService,
        private readonly DevFailureCapsuleRuntimeService $failureCapsules = new DevFailureCapsuleRuntimeService,
        private readonly DevOutcomeMemoryService $outcomeMemory = new DevOutcomeMemoryService,
        private readonly DevRunCertificationService $runCertification = new DevRunCertificationService,
        private readonly DevNativeCapabilityOrchestrator $nativeCapabilities = new DevNativeCapabilityOrchestrator,
        private readonly DevDecisionMaterializationService $decisionMaterializations = new DevDecisionMaterializationService,
    ) {}

    /**
     * @param  array<string,mixed>  $task
     * @param  array<string,mixed>|null  $failure
     * @param  array<string,mixed>  $outcome
     * @return array<string,mixed>
     */
    public function materialize(array $task, ?array $failure = null, array $outcome = []): array
    {
        $taskPacket = $this->taskPackets->persist($task);
        $contextGate = $this->contextGate->persist($taskPacket);
        $failureCapsule = $failure === null ? null : $this->failureCapsules->persist($failure, $taskPacket);
        $outcomeMemory = $this->outcomeMemory->persist($outcome, $taskPacket, $failureCapsule);
        $nativeCapabilities = $this->nativeCapabilities->evaluate(
            $taskPacket->toArray(),
            [
                'context_gate' => $contextGate->toArray(),
                'failure' => $failureCapsule?->toArray() ?? $failure ?? [],
                'outcome' => $outcomeMemory->toArray(),
                'changed_files' => $outcomeMemory->changed_files ?? [],
                'scope_status' => 'ready',
                'diff_clean' => (bool) ($outcome['diff_clean'] ?? true),
                'provider' => $outcome['provider'] ?? null,
                'model' => $outcome['model'] ?? null,
                'senior_review_evidence' => $outcome['senior_review_evidence'] ?? [],
                'domains' => $outcome['domains'] ?? [],
                'repeat_failures' => $outcome['repeat_failures'] ?? 0,
            ],
        );
        $decisions = $this->persistDecisions($taskPacket, $nativeCapabilities['decisions'] ?? []);
        $certification = $this->runCertification->persist($taskPacket, $contextGate, $outcomeMemory, $failureCapsule, $decisions);

        return [
            'task_packet' => $taskPacket->fresh()?->toArray(),
            'context_gate' => $contextGate->fresh()?->toArray(),
            'failure_capsule' => $failureCapsule?->fresh()?->toArray(),
            'outcome_memory' => $outcomeMemory->fresh()?->toArray(),
            'native_capabilities' => $nativeCapabilities,
            'decision_materializations' => array_map(static fn ($decision): array => $decision->fresh()?->toArray() ?? $decision->toArray(), $decisions),
            'run_certification' => $certification->fresh()?->toArray(),
        ];
    }

    /**
     * Lightweight projection for request payloads. This does not persist and
     * is safe to run before provider execution.
     *
     * @param  array<string,mixed>  $task
     * @return array<string,mixed>
     */
    public function preview(array $task): array
    {
        $packet = $this->taskPackets->build($task);
        $gate = $this->contextGate->evaluate($packet);

        return [
            'schema_version' => 'atlas.dev.runtime_intelligence_preview.v1',
            'task_packet' => $packet,
            'context_gate' => $gate,
            'provider_safe' => (bool) ($gate['provider_safe'] ?? false),
            'native_capabilities' => $this->nativeCapabilities->evaluate($packet, ['context_gate' => $gate]),
        ];
    }

    /**
     * @param  array<string,mixed>  $decisions
     * @return list<AtlasDevDecisionMaterialization>
     */
    private function persistDecisions(AtlasDevTaskPacket $taskPacket, array $decisions): array
    {
        $models = [];
        foreach ($decisions as $kind => $decision) {
            if (! is_array($decision)) {
                continue;
            }
            $models[] = $this->decisionMaterializations->persist($taskPacket, (string) $kind, $decision);
        }

        return $models;
    }
}

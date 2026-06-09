<?php

namespace App\Services\Ai\SelfConstruction;

final class ReadinessCatalog
{
    /** @return list<string> */
    public static function requiredDocs(): array
    {
        return [
            'docs/engineering-knowledge-base/atlas-ai-self-construction-os.md',
            'docs/engineering-knowledge-base/self-construction/constitution.md',
            'docs/engineering-knowledge-base/self-construction/structural-contract-gate.md',
            'docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md',
            'docs/engineering-knowledge-base/self-construction/work-splitter-contract.md',
            'docs/engineering-knowledge-base/self-construction/scope-validator-contract.md',
            'docs/engineering-knowledge-base/self-construction/assignment-and-claim-contract.md',
            'docs/engineering-knowledge-base/self-construction/packet-consumption-runbook-contract.md',
            'docs/engineering-knowledge-base/self-construction/packet-evidence-report-contract.md',
            'docs/engineering-knowledge-base/self-construction/packet-completion-gate-contract.md',
            'docs/engineering-knowledge-base/self-construction/reservation-ledger-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-ledger-implementation-plan.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-ap-candidate.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-approval-request.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-approval-decision-template.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-post-approval-preflight.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-implementation-packet.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-storage-schema.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-repository-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-collision-guard-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-lease-lifecycle-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-readiness-projection-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-implementation-preflight-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-migration-blueprint-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-repository-blueprint-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-collision-guard-blueprint-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-lease-lifecycle-blueprint-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-readiness-projection-blueprint-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-runtime-build-packet-contract.md',
            'docs/engineering-knowledge-base/self-construction/ai-session-bootstrap-contract.md',
            'docs/engineering-knowledge-base/self-construction/packet-queue-contract.md',
            'docs/engineering-knowledge-base/self-construction/parallel-session-plan-contract.md',
            'docs/engineering-knowledge-base/self-construction/collision-matrix-contract.md',
            'docs/engineering-knowledge-base/self-construction/dependency-unlock-plan-contract.md',
            'docs/engineering-knowledge-base/self-construction/multi-session-readiness-gate-contract.md',
            'docs/engineering-knowledge-base/self-construction/single-session-instruction-packet-contract.md',
            'docs/engineering-knowledge-base/obras/shared-workspace-and-forge.md',
            'docs/engineering-knowledge-base/self-construction/meta-sdd-contract.md',
            'docs/engineering-knowledge-base/self-construction/capability-maturity-ladder.md',
            'docs/engineering-knowledge-base/self-construction/build-graph.md',
            'docs/engineering-knowledge-base/self-construction/implementation-priority-engine.md',
            'docs/engineering-knowledge-base/self-construction/autonomous-implementation-loop.md',
            'docs/engineering-knowledge-base/self-construction/self-programming-safety-contract.md',
            'docs/engineering-knowledge-base/self-construction/quality-bar-and-metrics.md',
            'docs/engineering-knowledge-base/self-construction/failure-modes.md',
            'docs/engineering-knowledge-base/self-construction/builder-persona-and-handoff.md',
            'docs/engineering-knowledge-base/self-construction/runtime-implementation-roadmap.md',
            'docs/ap/AP-691-atlas-self-construction-os-contract.md',
        ];
    }

    /** @return list<string> */
    public static function receiptAllowedFiles(): array
    {
        return [
            'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
            'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
            'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
            'docs/engineering-knowledge-base/atlas-ai-self-construction-os.md',
            'docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md',
            'docs/engineering-knowledge-base/self-construction/work-splitter-contract.md',
            'docs/engineering-knowledge-base/self-construction/scope-validator-contract.md',
            'docs/engineering-knowledge-base/self-construction/assignment-and-claim-contract.md',
            'docs/engineering-knowledge-base/self-construction/packet-consumption-runbook-contract.md',
            'docs/engineering-knowledge-base/self-construction/packet-evidence-report-contract.md',
            'docs/engineering-knowledge-base/self-construction/packet-completion-gate-contract.md',
            'docs/engineering-knowledge-base/self-construction/reservation-ledger-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-ledger-implementation-plan.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-ap-candidate.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-approval-request.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-approval-decision-template.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-post-approval-preflight.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-implementation-packet.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-storage-schema.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-repository-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-collision-guard-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-lease-lifecycle-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-readiness-projection-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-implementation-preflight-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-migration-blueprint-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-repository-blueprint-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-collision-guard-blueprint-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-lease-lifecycle-blueprint-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-readiness-projection-blueprint-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-runtime-build-packet-contract.md',
            'docs/engineering-knowledge-base/self-construction/ai-session-bootstrap-contract.md',
            'docs/engineering-knowledge-base/self-construction/packet-queue-contract.md',
            'docs/engineering-knowledge-base/self-construction/parallel-session-plan-contract.md',
            'docs/engineering-knowledge-base/self-construction/collision-matrix-contract.md',
            'docs/engineering-knowledge-base/self-construction/dependency-unlock-plan-contract.md',
            'docs/engineering-knowledge-base/self-construction/multi-session-readiness-gate-contract.md',
            'docs/engineering-knowledge-base/self-construction/single-session-instruction-packet-contract.md',
            'docs/engineering-knowledge-base/self-construction/runtime-implementation-roadmap.md',
            'docs/ap/AP-691-atlas-self-construction-os-contract.md',
        ];
    }

    /** @return list<string> */
    public static function hotForbiddenFiles(): array
    {
        return [
            'runtimes/python/voice_realtime/**',
            'app/Services/Ai/Voice/**',
            'app/Console/Commands/AtlasAiVoiceRealtimeCommand.php',
            'app/Services/Ai/Kernel/Architecture/KernelArchitectureStaticScanner.php',
            'docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md',
            'routes/**',
            'database/migrations/**',
            'config/**',
        ];
    }
}

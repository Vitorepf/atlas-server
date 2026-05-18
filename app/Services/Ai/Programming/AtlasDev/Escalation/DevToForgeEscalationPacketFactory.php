<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Escalation;

use App\Services\Ai\Programming\AtlasDev\Schemas\EscalationDecision;
use App\Services\Ai\Programming\AtlasDev\Schemas\EscalationPacket;
use Illuminate\Support\Str;

/**
 * Wires an Atlas Dev {@see EscalationDecision} into the canonical
 * `atlas.dev_to_forge.escalation_packet.v1` envelope.
 *
 * The decision already carries score/risk/signals/reasons; this factory adds
 * the human-readable + Forge-intake-required fields (original intent,
 * recommended Forge mode, suggested work packets, DoD, required evidence,
 * context refs, constraints, non-goals) so Forge receives ONE honest packet
 * instead of the 4 incompatible payloads the audit flagged.
 *
 * Audit reference:
 * `docs/engineering-knowledge-base/atlas-dev-forge-relationship-critical-audit.md`
 */
final class DevToForgeEscalationPacketFactory
{
    /**
     * Build the canonical packet for a given decision.
     *
     * Required inputs (audit-mandated, throw if missing):
     *
     *   - originalUserIntent — preserved verbatim from the human request.
     *   - promotionReason    — short prose explaining WHY Dev is handing off.
     *
     * Sensible defaults derived from the decision when caller omits them:
     *
     *   - normalizedIntent          ← `intentSummary` argument or original.
     *   - promotionTriggers         ← `$decision->reasons` (canonical short codes).
     *   - recommendedForgeMode      ← `sdd_intake` for R3+/sdd hint, else `obra_intake`.
     *   - scopeAssessment           ← human-readable from signals/score.
     *   - riskAssessment            ← human-readable from `$decision->riskLevel`.
     *   - ambiguityAssessment       ← from `signals->ambiguityScore` when present.
     *   - createdAt                 ← decision `triggeredAt`.
     *
     * Suggested work packets default to a single SDD intake stub so Forge has
     * a starting decomposition. Callers SHOULD pass real packet hints when
     * Dev already explored the workspace.
     *
     * @param  list<string>  $currentDevFindings
     * @param  list<string>  $completedDevActions
     * @param  list<string>  $incompleteDevActions
     * @param  list<array<string,mixed>>|null  $suggestedWorkPackets  null → default single SDD stub
     * @param  list<string>  $definitionOfDone
     * @param  list<string>  $requiredEvidence
     * @param  array<string,mixed>|null  $evidenceRefs  null → fully-zeroed canonical 6 slots
     * @param  list<string>  $contextRefs
     * @param  list<string>  $constraints
     * @param  list<string>  $nonGoals
     */
    public function fromEscalationDecision(
        EscalationDecision $decision,
        string $originalUserIntent,
        string $promotionReason,
        ?string $normalizedIntent = null,
        ?string $recommendedForgeMode = null,
        array $currentDevFindings = [],
        array $completedDevActions = [],
        array $incompleteDevActions = [],
        ?array $suggestedWorkPackets = null,
        array $definitionOfDone = [],
        array $requiredEvidence = [],
        ?array $evidenceRefs = null,
        array $contextRefs = [],
        ?string $contextPackHash = null,
        array $constraints = [],
        array $nonGoals = [],
        ?string $packetId = null,
    ): EscalationPacket {
        return EscalationPacket::issue(
            packetId: $packetId ?? (string) Str::uuid(),
            originalUserIntent: $originalUserIntent,
            normalizedIntent: $normalizedIntent ?? $originalUserIntent,
            promotionReason: $promotionReason,
            promotionTriggers: $this->triggersFromDecision($decision),
            scopeAssessment: $this->scopeAssessment($decision),
            riskAssessment: $this->riskAssessment($decision),
            ambiguityAssessment: $this->ambiguityAssessment($decision),
            currentDevFindings: $currentDevFindings,
            completedDevActions: $completedDevActions,
            incompleteDevActions: $incompleteDevActions,
            recommendedForgeMode: $recommendedForgeMode ?? $this->defaultForgeMode($decision),
            suggestedWorkPackets: $suggestedWorkPackets ?? $this->defaultWorkPackets($decision),
            definitionOfDone: $definitionOfDone,
            requiredEvidence: $requiredEvidence,
            evidenceRefs: $evidenceRefs ?? EscalationPacket::emptyEvidenceRefs(),
            contextRefs: $contextRefs,
            contextPackHash: $contextPackHash,
            constraints: $constraints,
            nonGoals: $nonGoals,
            createdAt: $decision->triggeredAt,
        );
    }

    /**
     * @return list<string>
     */
    private function triggersFromDecision(EscalationDecision $decision): array
    {
        $triggers = array_values(array_filter(
            $decision->reasons,
            static fn (string $r): bool => trim($r) !== '',
        ));
        if ($triggers === []) {
            // Decision invariants already guarantee non-empty reasons, but
            // we keep this defensive: every packet MUST carry at least one
            // trigger so the human handoff is explicit.
            $triggers = [EscalationPacket::TRIGGER_OPERATOR_REQUESTED];
        }

        return $triggers;
    }

    private function defaultForgeMode(EscalationDecision $decision): string
    {
        // sdd_required is the most common keyword Atlas Dev fires when scope
        // outgrows a fast-path patch. Architecture-level reasons map to
        // architecture_review. Everything else defaults to obra_intake.
        foreach ($decision->reasons as $reason) {
            $lower = strtolower($reason);
            if (str_contains($lower, 'sdd')) {
                return EscalationPacket::RECOMMENDED_FORGE_MODE_SDD_INTAKE;
            }
            if (str_contains($lower, 'architecture') || str_contains($lower, 'refactor_subsystem')) {
                return EscalationPacket::RECOMMENDED_FORGE_MODE_ARCHITECTURE_REVIEW;
            }
            if (str_contains($lower, 'long_run') || str_contains($lower, 'time_budget')) {
                return EscalationPacket::RECOMMENDED_FORGE_MODE_LONG_RUN;
            }
        }

        return EscalationPacket::RECOMMENDED_FORGE_MODE_OBRA_INTAKE;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function defaultWorkPackets(EscalationDecision $decision): array
    {
        // Single SDD-intake placeholder so Forge intake has at least one row
        // to bootstrap. Real callers should override with workspace-aware
        // packet hints (one per file/module/department).
        return [[
            'id' => 'wp_sdd_intake',
            'title' => 'Forge SDD intake from Dev escalation',
            'capability' => 'programming.forge',
            'why' => 'Dev escalation reasons: '.implode(', ', $decision->reasons),
        ]];
    }

    private function scopeAssessment(EscalationDecision $decision): string
    {
        return sprintf(
            'Dev escalation score %d/10; risk %s; signals exceed fast-path budget.',
            $decision->score,
            $decision->riskLevel,
        );
    }

    private function riskAssessment(EscalationDecision $decision): string
    {
        return sprintf(
            'risk_level=%s declared by EscalationDecisionEngine; human approval required=%s.',
            $decision->riskLevel,
            $decision->humanActionRequired ? 'true' : 'false',
        );
    }

    private function ambiguityAssessment(EscalationDecision $decision): string
    {
        $signals = $decision->signals->toCanonicalArray();
        $ambiguity = $signals['ambiguity_score'] ?? null;
        if ($ambiguity === null) {
            return 'ambiguity_score not declared by Dev signals; Forge intake should re-classify.';
        }

        return sprintf('ambiguity_score=%s (Dev signals snapshot).', (string) $ambiguity);
    }
}

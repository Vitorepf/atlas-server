<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Escalation;

use App\Services\Ai\Programming\AtlasDev\Schemas\EscalationDecision;
use App\Services\AtlasCode\DevToForgePromotionService;
use InvalidArgumentException;

/**
 * Builds the canonical preview payload Atlas Dev hands off to the human
 * when an {@see EscalationDecision} targets Forge or `obra_candidate`.
 *
 * Atlas Dev NEVER calls {@see DevToForgePromotionService}
 * automatically. The fast path emits this preview payload, persists it as
 * `preview_artifact_path` on the decision and lets the operator submit it
 * through the Attention queue. That keeps the "no auto Obra" invariant
 * (doc principal §20) honest at the seam between Atlas Dev and Atlas
 * Code/Forge.
 *
 * KNOWN LACUNA (documented in the report): the AtlasCode promotion service
 * currently consumes `(thread_id, workspace_slug)` and queries DB-bound
 * AiThread/AiMessage/AtlasProject models. Atlas Dev fast path doesn't
 * always have a thread row; it has a `run_id`. Wiring those two requires
 * either (a) a Surface adapter that resolves run_id -> thread_id, or
 * (b) a new entrypoint on DevToForgePromotionService accepting a `run_id`.
 * Both belong to the Surface fatia, not this one.
 *
 * Schema: `atlas.dev.forge_promotion_preview.v1`.
 */
final class ForgePromotionPreviewBuilder
{
    public const SCHEMA_VERSION = 'atlas.dev.forge_promotion_preview.v1';

    /**
     * @param  list<string>  $changedFiles  files Atlas Dev observed before
     *                                      escalating; informational only
     * @param  list<string>  $contextRefs  repo paths / hashes the human
     *                                     should review on Forge
     * @param  string|null  $originalUserIntent  when supplied alongside
     *                                           $promotionReason, the builder
     *                                           ALSO emits the canonical
     *                                           `atlas.dev_to_forge.escalation_packet.v1`
     *                                           under `escalation_packet_v1`.
     *                                           Optional + additive: legacy
     *                                           callers see no behavioural
     *                                           change. Audit ref:
     *                                           `atlas-dev-forge-relationship-critical-audit.md`.
     * @param  string|null  $promotionReason  short prose for the canonical packet.
     * @param  DevToForgeEscalationPacketFactory|null  $packetFactory  optional
     *                                                                 injection so tests can stub it;
     *                                                                 defaults to a fresh instance.
     */
    public function build(
        EscalationDecision $decision,
        string $intentSummary,
        array $changedFiles,
        array $contextRefs = [],
        ?string $workspaceHash = null,
        ?string $threadId = null,
        ?string $originalUserIntent = null,
        ?string $promotionReason = null,
        ?DevToForgeEscalationPacketFactory $packetFactory = null,
    ): array {
        if (trim($intentSummary) === '') {
            throw new InvalidArgumentException('ForgePromotionPreviewBuilder: intent_summary must not be empty.');
        }
        foreach ($changedFiles as $i => $f) {
            if (! is_string($f) || $f === '') {
                throw new InvalidArgumentException("ForgePromotionPreviewBuilder.changed_files[{$i}] must be a non-empty string.");
            }
        }
        foreach ($contextRefs as $i => $r) {
            if (! is_string($r) || $r === '') {
                throw new InvalidArgumentException("ForgePromotionPreviewBuilder.context_refs[{$i}] must be a non-empty string.");
            }
        }

        $targetTier = match ($decision->target) {
            EscalationDecision::TARGET_FORGE => 'forge_obra',
            EscalationDecision::TARGET_OBRA_CANDIDATE => 'obra_candidate',
            default => 'unknown',
        };

        $payload = [
            'changed_files' => array_values($changedFiles),
            'context_refs' => array_values($contextRefs),
            'decision_hash' => $decision->decisionHash,
            'human_action_required' => $decision->humanActionRequired,
            'intent_summary' => trim($intentSummary),
            'preview_artifact_path' => $decision->previewArtifactPath,
            'promotion_tier' => $targetTier,
            'reasons' => array_values($decision->reasons),
            'risk_level' => $decision->riskLevel,
            'run_id' => $decision->runId,
            'schema_version' => self::SCHEMA_VERSION,
            'score' => $decision->score,
            'signals' => $decision->signals->toCanonicalArray(),
            'submission_state' => 'pending_human_action',
            'target' => $decision->target,
            'task_contract_hash' => $decision->taskContractHash,
            'thread_id' => $threadId,
            'triggered_at' => $decision->triggeredAt,
            'workspace_hash' => $workspaceHash,
        ];

        // Canonical layout: alphabetical keys, stable for hashing/audit.
        ksort($payload);

        // Additive emission of the canonical
        // `atlas.dev_to_forge.escalation_packet.v1`. Audit (Meta gap P0)
        // requires Dev→Forge handoffs to carry ONE honest packet. We don't
        // break the legacy `forge_promotion_preview.v1` payload — we attach
        // the canonical packet alongside it under a dedicated key so
        // consumers can migrate without a flag day.
        if (is_string($originalUserIntent)
            && trim($originalUserIntent) !== ''
            && is_string($promotionReason)
            && trim($promotionReason) !== ''
        ) {
            $factory = $packetFactory ?? new DevToForgeEscalationPacketFactory;
            $packet = $factory->fromEscalationDecision(
                decision: $decision,
                originalUserIntent: $originalUserIntent,
                promotionReason: $promotionReason,
                normalizedIntent: trim($intentSummary),
                contextRefs: array_values($contextRefs),
                contextPackHash: $workspaceHash,
            );
            $payload['escalation_packet_v1'] = $packet->toCanonicalArray();
        }

        return $payload;
    }
}

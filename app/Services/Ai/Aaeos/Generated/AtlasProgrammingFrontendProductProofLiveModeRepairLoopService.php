<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\AtlasAaeosStringListNormalizer;

/**
 * Live-mode repair-loop product-proof gate evaluator.
 *
 * Pure, deterministic decider for the manifest doc
 * "Atlas Frontend Product Proof Live Mode Repair Loop". The doc proves one
 * operational cycle: a component is picked in the browser, a local variant is
 * previewed, the patch is accepted into source, and the session is recovered
 * from journal. This service evaluates a declared run of that cycle and returns
 * a single verdict — `proven`, `incomplete` or `claim_violation` — plus the
 * precise reasons, so a run can never be presented as proof while it skips a
 * documented stage, runs the stages out of order, or claims live-mode parity it
 * never earned with a real external-baseline replay.
 *
 * Contract (from the doc):
 *   Contratos: viewport desktop; evidencias: browser pick event,
 *     preview variant event, accepted variant diff, recover session.
 *   Fluxo: selecionar elemento -> gerar preview -> aceitar patch -> provar recovery.
 *   Regras para IA: "Nao declarar superioridade live mode sem replay rival real".
 *   forbidden_changes: "Declarar superioridade live mode sem replay real contra
 *     baseline externo."
 *   Escopo de Implementacao: "Manifest documental; nao contem journal real."
 *   Riscos: "Confundir contrato local com paridade operacional completa."
 *   Exemplos: Alt+click seleciona card, preview aplica CSS temporario, accept
 *     grava source.
 *
 * The service NEVER opens a browser, renders a preview, writes source, calls a
 * provider or touches the database. It evaluates a declared cycle state and
 * emits an auditable receipt; callers decide whether the run may be published as
 * Atlas Frontend live-mode repair-loop proof.
 *
 * @see docs/engineering-knowledge-base/domains/programming-frontend-product-proof-live_mode_repair_loop.md
 */
final class AtlasProgrammingFrontendProductProofLiveModeRepairLoopService
{
    /** Stable receipt schema id for the verdict this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.frontend.product_proof_live_mode_repair_loop_gate.v1';

    /** Canonical verdicts (closed set). */
    public const VERDICT_PROVEN = 'proven';
    public const VERDICT_INCOMPLETE = 'incomplete';
    public const VERDICT_CLAIM_VIOLATION = 'claim_violation';

    /**
     * Required viewport from the doc "Contratos": desktop. The live cycle is a
     * desktop interaction (Alt+click pick), so a non-desktop run is off-contract.
     */
    public const REQUIRED_VIEWPORT = 'desktop';

    /**
     * The four ordered stages of the doc "Fluxo". Order is load-bearing: you
     * cannot accept a patch you never previewed, nor recover a session that never
     * accepted anything. Each stage carries the evidence event the "Contratos"
     * section mandates for it.
     *
     * Stage id => required evidence event id.
     *
     * @var array<string,string>
     */
    public const STAGE_EVIDENCE = [
        'select_element' => 'browser_pick_event',
        'generate_preview' => 'preview_variant_event',
        'accept_patch' => 'accepted_variant_diff',
        'prove_recovery' => 'recover_session',
    ];

    /**
     * The documented stage order (keys of STAGE_EVIDENCE), as a list, so the
     * sequence can be checked positionally.
     *
     * @return list<string>
     */
    public static function stageOrder(): array
    {
        return array_keys(self::STAGE_EVIDENCE);
    }

    /**
     * Evaluate one live-mode repair-loop run against the documented contract.
     *
     * @param array<string,mixed> $run
     *        viewport          : string        the viewport the cycle ran in (desktop expected)
     *        completed_stages  : list<string>  stage ids actually completed
     *        evidence          : list<string>  evidence event ids captured
     *        claims_live_mode_parity : bool    does the run claim live-mode operational parity?
     *        external_replay_present : bool    is there a real external-baseline replay journal?
     *        real_journal_present    : bool    does a real (non-manifest) cycle journal exist?
     *
     * @return array<string,mixed> the verdict + audit receipt
     */
    public function evaluate(array $run): array
    {
        $viewport = $this->normalizeString($run['viewport'] ?? null);
        $completedStages = AtlasAaeosStringListNormalizer::lowerTrimmedStrings($run['completed_stages'] ?? []);
        $evidence = AtlasAaeosStringListNormalizer::lowerTrimmedStrings($run['evidence'] ?? []);

        $claimsParity = (bool) ($run['claims_live_mode_parity'] ?? false);
        $externalReplayPresent = (bool) ($run['external_replay_present'] ?? false);
        $realJournalPresent = (bool) ($run['real_journal_present'] ?? false);

        $blockers = [];
        $claimViolations = [];

        // --- Contratos viewport: the cycle is a desktop interaction.
        $viewportOk = $viewport === self::REQUIRED_VIEWPORT;
        if (! $viewportOk) {
            $blockers[] = 'wrong_viewport:expected_'.self::REQUIRED_VIEWPORT;
        }

        // --- Fluxo: every stage must be completed, and each completed stage must
        // carry its mandated evidence event (Contratos). A stage done without its
        // evidence is not provable.
        $stageChecks = [];
        foreach (self::STAGE_EVIDENCE as $stage => $evidenceId) {
            $stageDone = in_array($stage, $completedStages, true);
            $evidencePresent = in_array($evidenceId, $evidence, true);
            $stageChecks[$stage] = [
                'completed' => $stageDone,
                'evidence_id' => $evidenceId,
                'evidence_present' => $evidencePresent,
            ];

            if (! $stageDone) {
                $blockers[] = "missing_stage:{$stage}";
            }
            if (! $evidencePresent) {
                $blockers[] = "missing_evidence:{$evidenceId}";
            }
        }

        // --- Fluxo order: the completed stages, in the order supplied, must be a
        // prefix-respecting subsequence of the documented order. You may not
        // accept a patch before previewing, nor recover before accepting.
        $orderViolation = $this->firstOrderViolation($completedStages);
        if ($orderViolation !== null) {
            $blockers[] = "out_of_order:{$orderViolation}";
        }

        // --- Regras para IA + forbidden_changes: a live-mode operational parity
        // claim is ONLY allowed when a real external-baseline replay exists. This
        // is the doc's headline rule and its single hardest invariant.
        if ($claimsParity && ! $externalReplayPresent) {
            $claimViolations[] = 'live_mode_parity_claim_without_external_replay';
        }

        $stagesProven = $blockers === [];

        // Escopo de Implementacao: "Manifest documental; nao contem journal real."
        // Operational parity is authorized only once a REAL journal exists AND a
        // real external-baseline replay exists. Absent either, this stays a local
        // contract proof — never "paridade operacional completa" (Riscos).
        $operationalParityAuthorized = $realJournalPresent && $externalReplayPresent;

        // A forbidden claim is the most severe outcome: even a run whose stages
        // are fully proven must not be published while it overstates live-mode
        // parity it never replayed against an external baseline.
        if ($claimViolations !== []) {
            $verdict = self::VERDICT_CLAIM_VIOLATION;
        } elseif (! $stagesProven) {
            $verdict = self::VERDICT_INCOMPLETE;
        } else {
            $verdict = self::VERDICT_PROVEN;
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'proof_id' => 'live_mode_repair_loop',
            'verdict' => $verdict,
            'proven' => $verdict === self::VERDICT_PROVEN,
            'stages_proven' => $stagesProven,
            'viewport_ok' => $viewportOk,
            'stage_checks' => $stageChecks,
            'stage_order' => self::stageOrder(),
            'order_violation' => $orderViolation,
            'claims_live_mode_parity' => $claimsParity,
            'parity_claim_allowed' => $claimsParity && $claimViolations === [],
            'claim_violations' => $claimViolations,
            'blockers' => $blockers,
            // Escopo / Riscos: a local contract proof is NOT operational parity.
            'operational_parity_authorized' => $operationalParityAuthorized,
            'auditable' => true,
        ];
    }

    /**
     * Convenience predicate: may this run be published as Atlas Frontend
     * live-mode repair-loop proof? (true only when every stage is proven, in
     * order, with evidence, and no parity claim is overstated).
     *
     * @param array<string,mixed> $run
     */
    public function mayPublish(array $run): bool
    {
        return $this->evaluate($run)['verdict'] === self::VERDICT_PROVEN;
    }

    /**
     * Decide whether a live-mode operational parity claim is permitted for the
     * run, isolating the doc's headline "Regras para IA" rule for direct callers.
     *
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    public function parityClaimDecision(array $run): array
    {
        $claimsParity = (bool) ($run['claims_live_mode_parity'] ?? false);
        $externalReplayPresent = (bool) ($run['external_replay_present'] ?? false);

        $violations = [];
        if ($claimsParity && ! $externalReplayPresent) {
            $violations[] = 'live_mode_parity_claim_without_external_replay';
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'claims_live_mode_parity' => $claimsParity,
            'allowed' => $claimsParity && $violations === [],
            'requires' => ['external_replay_present'],
            'violations' => $violations,
        ];
    }

    /**
     * A canonical fully-proven run sample (all four stages completed in order,
     * each with its evidence, desktop viewport, no parity overclaim). Used by
     * callers/tests as the baseline to mutate.
     *
     * Note: by default the run does NOT claim parity and has no real journal,
     * faithful to "Escopo de Implementacao: manifest documental; nao contem
     * journal real." It is a proven LOCAL cycle, not authorized operational parity.
     *
     * @return array<string,mixed>
     */
    public function provenSample(): array
    {
        return [
            'viewport' => self::REQUIRED_VIEWPORT,
            'completed_stages' => self::stageOrder(),
            'evidence' => array_values(self::STAGE_EVIDENCE),
            'claims_live_mode_parity' => false,
            'external_replay_present' => false,
            'real_journal_present' => false,
        ];
    }

    /**
     * Find the first stage that appears out of documented order within the
     * supplied completed-stage sequence, ignoring ids that are not real stages.
     * Returns the offending stage id, or null when the sequence respects order.
     *
     * @param list<string> $completedStages
     */
    private function firstOrderViolation(array $completedStages): ?string
    {
        $rank = [];
        foreach (self::stageOrder() as $index => $stage) {
            $rank[$stage] = $index;
        }

        $maxSeen = -1;
        foreach ($completedStages as $stage) {
            if (! array_key_exists($stage, $rank)) {
                continue; // unknown id never establishes order
            }
            $thisRank = $rank[$stage];
            if ($thisRank < $maxSeen) {
                return $stage;
            }
            $maxSeen = $thisRank;
        }

        return null;
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = strtolower(trim($value));

        return $trimmed === '' ? null : $trimmed;
    }
}

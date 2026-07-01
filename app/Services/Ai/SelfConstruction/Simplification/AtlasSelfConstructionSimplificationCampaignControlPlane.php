<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Pure, read-only decision snapshot for a governed circuit-consolidation
 * (simplification) campaign. Combines redundancy maps, equivalence dossiers,
 * deletion plans, consumer impact, parity matrix, rollback receipts, replay
 * plans, and docs-sync blockers into one go/hold/fail_closed decision.
 *
 * REQUIRED INPUT SECTIONS (all must be present):
 *   redundancy_map:      {clusters: list<{cluster_id, members: list<string>}>}
 *   equivalence_dossier:  {behavior_equivalence_proven: bool}
 *   deletion_plan:        {safe: bool, targets: list<string>}
 *   consumer_impact:      {unsafe_consumers: list<string>}
 *   parity_matrix:        {parity_verified: bool}
 *   rollback_receipts:    {present: bool}
 *   replay_plan:          {ready: bool}
 *   docs_sync:            {required: bool}
 *
 * DECISION (first match wins):
 *   fail_closed — any required section missing, OR deletion_plan.safe=false,
 *                 OR consumer_impact.unsafe_consumers non-empty,
 *                 OR parity_matrix.parity_verified=false,
 *                 OR rollback_receipts.present=false,
 *                 OR replay_plan.ready=false
 *   hold        — all sections safe but equivalence not yet proven,
 *                 OR docs_sync.required=true
 *   go          — everything proven, safe, and no docs sync pending
 *
 * Pure: no I/O, no side effects — a read-only decision snapshot only.
 */
final class AtlasSelfConstructionSimplificationCampaignControlPlane
{
    public const SCHEMA = 'atlas.self_construction.simplification_campaign_control_plane.v1';

    public const DECISION_GO = 'go';

    public const DECISION_HOLD = 'hold';

    public const DECISION_FAIL_CLOSED = 'fail_closed';

    private const REQUIRED_SECTIONS = [
        'redundancy_map',
        'equivalence_dossier',
        'deletion_plan',
        'consumer_impact',
        'parity_matrix',
        'rollback_receipts',
        'replay_plan',
        'docs_sync',
    ];

    /**
     * Batch campaign snapshot: each candidate carries its own section bundle,
     * an `action_type` (delete|merge|additive_cleanup|import_rewrite) and a
     * `risk` label. High-risk candidates lacking parity/replay/rollback proof
     * are blocked outright; proven delete/merge candidates lead the next wave
     * ahead of additive cleanup, so real code removal is never starved by
     * lower-leverage tidy-up.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function planCampaign(array $input): array
    {
        $candidates = (array) ($input['candidates'] ?? []);

        $deletionFirst = [];
        $additive = [];
        $blockedHighRisk = [];
        $held = [];
        $knowledgeSyncRequired = false;

        foreach ($candidates as $candidate) {
            $candidate = (array) $candidate;
            $id = (string) ($candidate['id'] ?? '');
            $actionType = (string) ($candidate['action_type'] ?? '');
            $risk = (string) ($candidate['risk'] ?? 'low');

            $evaluation = $this->evaluateCandidate($candidate);
            $isHighRiskMissingProof = $risk === 'high'
                && (! $evaluation['proof_readiness'] || ! $evaluation['rollback_readiness']);

            $syncingAction = in_array($actionType, ['delete', 'merge', 'import_rewrite'], true);

            if ($isHighRiskMissingProof) {
                $blockedHighRisk[] = $id;

                continue;
            }

            if ($evaluation['decision'] !== self::DECISION_GO) {
                $held[] = $id;

                continue;
            }

            if (in_array($actionType, ['delete', 'merge'], true)) {
                $deletionFirst[] = $id;
            } else {
                $additive[] = $id;
            }

            if ($syncingAction) {
                $knowledgeSyncRequired = true;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'deletion_first_candidates' => $deletionFirst,
            'additive_candidates' => $additive,
            'blocked_high_risk_candidates' => $blockedHighRisk,
            'held_candidates' => $held,
            'next_wave' => array_merge($deletionFirst, $additive),
            'knowledge_sync_required' => $knowledgeSyncRequired,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function decide(array $input): array
    {
        $reasons = [];

        $missing = [];
        foreach (self::REQUIRED_SECTIONS as $section) {
            if (! isset($input[$section]) || ! is_array($input[$section])) {
                $missing[] = $section;
            }
        }
        if ($missing !== []) {
            foreach ($missing as $section) {
                $reasons[] = 'missing_section:'.$section;
            }

            return $this->closedEnvelope(self::DECISION_FAIL_CLOSED, $reasons);
        }

        $deletionPlan = (array) $input['deletion_plan'];
        $consumerImpact = (array) $input['consumer_impact'];
        $parityMatrix = (array) $input['parity_matrix'];
        $rollbackReceipts = (array) $input['rollback_receipts'];
        $replayPlan = (array) $input['replay_plan'];
        $equivalenceDossier = (array) $input['equivalence_dossier'];
        $docsSync = (array) $input['docs_sync'];
        $redundancyMap = (array) $input['redundancy_map'];

        $deletionSafe = (bool) ($deletionPlan['safe'] ?? false);
        $unsafeConsumers = array_values(array_map('strval', (array) ($consumerImpact['unsafe_consumers'] ?? [])));
        $parityVerified = (bool) ($parityMatrix['parity_verified'] ?? false);
        $rollbackPresent = (bool) ($rollbackReceipts['present'] ?? false);
        $replayReady = (bool) ($replayPlan['ready'] ?? false);
        $equivalenceProven = (bool) ($equivalenceDossier['behavior_equivalence_proven'] ?? false);
        $docsSyncRequired = (bool) ($docsSync['required'] ?? false);

        if (! $deletionSafe) {
            $reasons[] = 'unsafe_deletion_plan';
        }
        if ($unsafeConsumers !== []) {
            $reasons[] = 'unsafe_consumer_impact';
        }
        if (! $parityVerified) {
            $reasons[] = 'unsafe_parity';
        }
        if (! $rollbackPresent) {
            $reasons[] = 'unsafe_rollback';
        }
        if (! $replayReady) {
            $reasons[] = 'unsafe_replay';
        }

        $proofReadiness = $equivalenceProven && $parityVerified;
        $rollbackReadiness = $rollbackPresent && $replayReady;

        $targets = array_values(array_map('strval', (array) ($deletionPlan['targets'] ?? [])));
        if ($reasons !== []) {
            return [
                'schema' => self::SCHEMA,
                'decision' => self::DECISION_FAIL_CLOSED,
                'reasons' => array_values(array_unique($reasons)),
                'safe_waves' => [],
                'blocked_waves' => $targets,
                'next_action' => 'resolve_unsafe_findings_before_any_wave',
                'proof_readiness' => $proofReadiness,
                'rollback_readiness' => $rollbackReadiness,
                'docs_sync_required' => $docsSyncRequired,
            ];
        }

        if (! $equivalenceProven) {
            return [
                'schema' => self::SCHEMA,
                'decision' => self::DECISION_HOLD,
                'reasons' => ['behavior_equivalence_not_proven'],
                'safe_waves' => [],
                'blocked_waves' => $targets,
                'next_action' => 'prove_behavior_equivalence',
                'proof_readiness' => $proofReadiness,
                'rollback_readiness' => $rollbackReadiness,
                'docs_sync_required' => $docsSyncRequired,
            ];
        }

        if ($docsSyncRequired) {
            return [
                'schema' => self::SCHEMA,
                'decision' => self::DECISION_HOLD,
                'reasons' => ['docs_sync_required'],
                'safe_waves' => $targets,
                'blocked_waves' => [],
                'next_action' => 'sync_docs_before_merge',
                'proof_readiness' => $proofReadiness,
                'rollback_readiness' => $rollbackReadiness,
                'docs_sync_required' => $docsSyncRequired,
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'decision' => self::DECISION_GO,
            'reasons' => [],
            'safe_waves' => $targets,
            'blocked_waves' => [],
            'next_action' => 'proceed_with_consolidation',
            'proof_readiness' => $proofReadiness,
            'rollback_readiness' => $rollbackReadiness,
            'docs_sync_required' => $docsSyncRequired,
        ];
    }

    /**
     * Same fail_closed/hold/go decision as decide(), stripped down to just
     * {decision, proof_readiness, rollback_readiness} for per-candidate batch
     * evaluation. Missing required sections are treated as fail_closed.
     *
     * @param  array<string,mixed>  $sections
     * @return array{decision:string, proof_readiness:bool, rollback_readiness:bool}
     */
    private function evaluateCandidate(array $sections): array
    {
        foreach (self::REQUIRED_SECTIONS as $section) {
            if (! isset($sections[$section]) || ! is_array($sections[$section])) {
                return ['decision' => self::DECISION_FAIL_CLOSED, 'proof_readiness' => false, 'rollback_readiness' => false];
            }
        }

        $deletionSafe = (bool) ($sections['deletion_plan']['safe'] ?? false);
        $unsafeConsumers = (array) ($sections['consumer_impact']['unsafe_consumers'] ?? []);
        $parityVerified = (bool) ($sections['parity_matrix']['parity_verified'] ?? false);
        $rollbackPresent = (bool) ($sections['rollback_receipts']['present'] ?? false);
        $replayReady = (bool) ($sections['replay_plan']['ready'] ?? false);
        $equivalenceProven = (bool) ($sections['equivalence_dossier']['behavior_equivalence_proven'] ?? false);
        $docsSyncRequired = (bool) ($sections['docs_sync']['required'] ?? false);

        $proofReadiness = $equivalenceProven && $parityVerified;
        $rollbackReadiness = $rollbackPresent && $replayReady;

        if (! $deletionSafe || $unsafeConsumers !== [] || ! $parityVerified || ! $rollbackPresent || ! $replayReady) {
            return ['decision' => self::DECISION_FAIL_CLOSED, 'proof_readiness' => $proofReadiness, 'rollback_readiness' => $rollbackReadiness];
        }

        if (! $equivalenceProven || $docsSyncRequired) {
            return ['decision' => self::DECISION_HOLD, 'proof_readiness' => $proofReadiness, 'rollback_readiness' => $rollbackReadiness];
        }

        return ['decision' => self::DECISION_GO, 'proof_readiness' => $proofReadiness, 'rollback_readiness' => $rollbackReadiness];
    }

    /**
     * @param  list<string>  $reasons
     * @return array<string,mixed>
     */
    private function closedEnvelope(string $decision, array $reasons): array
    {
        return [
            'schema' => self::SCHEMA,
            'decision' => $decision,
            'reasons' => array_values(array_unique($reasons)),
            'safe_waves' => [],
            'blocked_waves' => [],
            'next_action' => 'supply_missing_input_sections',
            'proof_readiness' => false,
            'rollback_readiness' => false,
            'docs_sync_required' => false,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\MultiProject;

/**
 * Pure FACTS-only composer. Combines the per-lane organ verdicts (admission, freshness, isolation,
 * verification court, release governor, receipt policy, knowledge sync) into a single autonomy
 * readiness state for one external project stewardship lane.
 *
 *   state = ready    — every required organ_fact is conformant.
 *   state = hold     — refreshable observation(s) are missing/stale (e.g. context freshness) but no
 *                       organ has emitted a blocking failure.
 *   state = blocked  — any organ emitted a blocking failure (admission failure, isolation leak,
 *                       verification failure, release failure).
 *
 * Returns: {schema_version, state, ready, project_id, blockers, holds, organ_facts, next_atlas_actions}
 * NO scalar scoring; NO numeric magnitude; deterministic ordering.
 */
final class AtlasProjectLaneAutonomyReadiness
{
    public const SCHEMA = 'atlas.multiproject.lane_autonomy_readiness.v1';

    public const STATE_READY = 'ready';

    public const STATE_HOLD = 'hold';

    public const STATE_BLOCKED = 'blocked';

    /**
     * Organ keys consumed by compose():
     *
     *   admission             {admitted:bool, blocking_reasons?:list<string>}
     *   freshness             {conformant:bool, blockers?:list<string>}
     *   isolation             {passed:bool, leaked?:list<string>}
     *   verification_court    {passed:bool, failures?:list<string>}
     *   release_governor      {passed:bool, failures?:list<string>}
     *   receipt_policy        {passed:bool, blockers?:list<string>}
     *   knowledge_sync        {ready:bool, blockers?:list<string>, hold?:bool}
     *
     * @param  array<string,array<string,mixed>>  $organFacts
     * @return array<string,mixed>
     */
    public function compose(string $projectId, array $organFacts): array
    {
        $blockers = [];
        $holds = [];
        $nextActions = [];

        // ADMISSION — must be true; failure is BLOCKING.
        $adm = (array) ($organFacts['admission'] ?? []);
        if (! (bool) ($adm['admitted'] ?? false)) {
            $blockers[] = 'admission_failed';
            foreach ((array) ($adm['blocking_reasons'] ?? []) as $r) {
                $blockers[] = 'admission:'.(string) $r;
            }
            $nextActions[] = 'rerun_admission_with_corrected_manifest';
        }

        // ISOLATION — leak is BLOCKING.
        $iso = (array) ($organFacts['isolation'] ?? []);
        if (! (bool) ($iso['passed'] ?? false)) {
            $blockers[] = 'isolation_leak';
            foreach ((array) ($iso['leaked'] ?? []) as $r) {
                $blockers[] = 'isolation:'.(string) $r;
            }
            $nextActions[] = 'tighten_allowed_scope_roots';
        }

        // VERIFICATION COURT — failure is BLOCKING.
        $ver = (array) ($organFacts['verification_court'] ?? []);
        if (! (bool) ($ver['passed'] ?? false)) {
            $blockers[] = 'verification_failed';
            foreach ((array) ($ver['failures'] ?? []) as $r) {
                $blockers[] = 'verification:'.(string) $r;
            }
            $nextActions[] = 'rerun_verification_commands';
        }

        // RELEASE GOVERNOR — failure is BLOCKING.
        $rel = (array) ($organFacts['release_governor'] ?? []);
        if (! (bool) ($rel['passed'] ?? false)) {
            $blockers[] = 'release_failed';
            foreach ((array) ($rel['failures'] ?? []) as $r) {
                $blockers[] = 'release:'.(string) $r;
            }
            $nextActions[] = 'rerun_release_governor';
        }

        // RECEIPT POLICY — failure is BLOCKING (no receipt = no audit trail).
        $rec = (array) ($organFacts['receipt_policy'] ?? []);
        if (! (bool) ($rec['passed'] ?? false)) {
            $blockers[] = 'receipt_policy_failed';
            foreach ((array) ($rec['blockers'] ?? []) as $r) {
                $blockers[] = 'receipt:'.(string) $r;
            }
            $nextActions[] = 'reissue_lane_receipt';
        }

        // ROLLBACK GATE — failure is BLOCKING (must be able to roll back before merge).
        $rb = (array) ($organFacts['rollback'] ?? []);
        if (! (bool) ($rb['conformant'] ?? false)) {
            $blockers[] = 'rollback_failed';
            $nextActions[] = 'rerun_rollback_gate';
        }

        // RUNTIME SOAK — failure is BLOCKING (long-run invariants must be proved before merge).
        $soak = (array) ($organFacts['runtime_soak'] ?? []);
        if (! (bool) ($soak['passed'] ?? false)) {
            $blockers[] = 'runtime_soak_failed';
            $nextActions[] = 'rerun_verification_commands';
        }

        // FRESHNESS — stale observation is a HOLD (refreshable), not a BLOCK.
        $fresh = (array) ($organFacts['freshness'] ?? []);
        if (! (bool) ($fresh['conformant'] ?? false)) {
            $holds[] = 'context_freshness_stale';
            foreach ((array) ($fresh['blockers'] ?? []) as $r) {
                $holds[] = 'freshness:'.(string) $r;
            }
            $nextActions[] = 'refresh_context_pack';
        }

        // KNOWLEDGE SYNC — explicit hold beats blocker; a degraded-but-blocked path becomes a hold here
        // (the Loop can refresh it without operator intervention).
        $ks = (array) ($organFacts['knowledge_sync'] ?? []);
        if (! (bool) ($ks['ready'] ?? false)) {
            if ((bool) ($ks['hold'] ?? false)) {
                $holds[] = 'knowledge_sync_hold';
            } else {
                $holds[] = 'knowledge_sync_not_ready';
            }
            foreach ((array) ($ks['blockers'] ?? []) as $r) {
                $holds[] = 'knowledge_sync:'.(string) $r;
            }
            $nextActions[] = 'run_atlas_engineering_knowledge_sync';
        }

        if ($blockers !== []) {
            $state = self::STATE_BLOCKED;
        } elseif ($holds !== []) {
            $state = self::STATE_HOLD;
        } else {
            $state = self::STATE_READY;
        }

        return [
            'schema_version' => self::SCHEMA,
            'state' => $state,
            'ready' => $state === self::STATE_READY,
            'project_id' => $projectId,
            'blockers' => array_values($blockers),
            'holds' => array_values($holds),
            'organ_facts' => $organFacts,
            'next_atlas_actions' => array_values(array_unique($nextActions)),
        ];
    }
}

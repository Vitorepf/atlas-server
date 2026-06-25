<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\MergeGovernor;

/**
 * Pure policy that decides whether a verified Self-Construction candidate may be
 *   admitted | rejected | repair_required | blocked
 * BEFORE mainline integration.
 *
 * COMPOSES (read-only) facts from:
 *   - {@see AtlasMergeGovernorRiskClassifier}  → risk_level
 *   - {@see AtlasMergeGovernorRollbackPlanGate} → rollback_conformant + rollback_blockers
 *   - verification court verdict                → server_side_green + evidence_hash + missing_rerun
 *   - project lane facts                        → project_id alignment
 *   - release_window_policy                     → allowed risk_levels (e.g. {'low','medium'})
 *
 * INVARIANTS:
 *   - DETERMINISTIC envelope: identical input ⇒ byte-identical output.
 *   - PURE: no queue writes, no git, no shell, no provider call, no merge side effect.
 *   - NEVER trusts worker self-report alone — admitted requires server_side_green=true AND
 *     evidence_hash present.
 *   - NO scalar score / rank.
 */
final class AtlasMergeGovernorAdmissionPolicy
{
    public const SCHEMA = 'atlas.mergegovernor.admission_policy.v1';

    public const DECISION_ADMITTED = 'admitted';

    public const DECISION_REJECTED = 'rejected';

    public const DECISION_REPAIR = 'repair_required';

    public const DECISION_BLOCKED = 'blocked';

    /**
     * @param  array{
     *     project_id?:string,
     *     risk_classification?:array{risk_level?:string, reasons?:list<string>},
     *     rollback_gate?:array{conformant?:bool, blockers?:list<string>},
     *     verification_court?:array{verdict?:string, server_side_green?:bool, evidence_hash?:string, missing_rerun?:list<string>, project_id?:string},
     *     release_window_policy?:array{allowed_risk_levels?:list<string>}
     * }  $facts
     * @return array{schema:string, decision:string, blockers:list<string>, sources:array<string,mixed>}
     */
    public function decide(array $facts): array
    {
        $projectId = (string) ($facts['project_id'] ?? '');
        $risk = is_array($facts['risk_classification'] ?? null) ? $facts['risk_classification'] : [];
        $rollback = is_array($facts['rollback_gate'] ?? null) ? $facts['rollback_gate'] : [];
        $court = is_array($facts['verification_court'] ?? null) ? $facts['verification_court'] : [];
        $window = is_array($facts['release_window_policy'] ?? null) ? $facts['release_window_policy'] : [];

        $riskLevel = (string) ($risk['risk_level'] ?? '');
        $rollbackConformant = (bool) ($rollback['conformant'] ?? false);
        $rollbackBlockers = is_array($rollback['blockers'] ?? null) ? array_map('strval', $rollback['blockers']) : [];
        $serverSideGreen = (bool) ($court['server_side_green'] ?? false);
        $evidenceHash = (string) ($court['evidence_hash'] ?? '');
        $missingRerun = is_array($court['missing_rerun'] ?? null) ? array_map('strval', $court['missing_rerun']) : [];
        $courtProj = (string) ($court['project_id'] ?? $projectId);
        $allowedRiskLevels = is_array($window['allowed_risk_levels'] ?? null) ? array_map('strval', $window['allowed_risk_levels']) : ['low', 'medium'];

        $blockers = [];

        // BLOCKED branch — hard refusals.
        if ($riskLevel === AtlasMergeGovernorRiskClassifier::RISK_BLOCKED) {
            $blockers[] = 'risk_blocked';
            foreach ((array) ($risk['reasons'] ?? []) as $r) {
                $blockers[] = 'risk:'.(string) $r;
            }
        }
        if ($projectId !== '' && $courtProj !== '' && $courtProj !== $projectId) {
            $blockers[] = 'verification_court_project_mismatch:'.$courtProj.'!='.$projectId;
        }
        if ($riskLevel === AtlasMergeGovernorRiskClassifier::RISK_HIGH && ! $rollbackConformant) {
            $blockers[] = 'high_risk_requires_conformant_rollback';
        }
        if ($riskLevel !== '' && ! in_array($riskLevel, $allowedRiskLevels, true) && $riskLevel !== AtlasMergeGovernorRiskClassifier::RISK_BLOCKED) {
            $blockers[] = 'risk_level_outside_release_window:'.$riskLevel;
        }

        if ($blockers !== []) {
            sort($blockers, SORT_STRING);

            return $this->envelope(self::DECISION_BLOCKED, $blockers, $riskLevel, $rollbackConformant, $serverSideGreen, $evidenceHash);
        }

        // REJECTED branch — verification failed.
        if (! $serverSideGreen) {
            return $this->envelope(self::DECISION_REJECTED, ['verification_not_server_side_green'], $riskLevel, $rollbackConformant, $serverSideGreen, $evidenceHash);
        }

        // REPAIR_REQUIRED branch — verification green but proof incomplete or rollback weak.
        $repair = [];
        if ($evidenceHash === '') {
            $repair[] = 'evidence_hash_missing';
        }
        if ($missingRerun !== []) {
            foreach ($missingRerun as $gate) {
                $repair[] = 'missing_rerun:'.$gate;
            }
        }
        if (! $rollbackConformant) {
            $repair[] = 'rollback_not_conformant';
            foreach ($rollbackBlockers as $b) {
                $repair[] = 'rollback:'.$b;
            }
        }
        if ($repair !== []) {
            sort($repair, SORT_STRING);

            return $this->envelope(self::DECISION_REPAIR, $repair, $riskLevel, $rollbackConformant, $serverSideGreen, $evidenceHash);
        }

        // ADMITTED.
        return $this->envelope(self::DECISION_ADMITTED, [], $riskLevel, $rollbackConformant, $serverSideGreen, $evidenceHash);
    }

    /**
     * @param  list<string>  $blockers
     * @return array{schema:string, decision:string, blockers:list<string>, sources:array<string,mixed>}
     */
    private function envelope(string $decision, array $blockers, string $riskLevel, bool $rollbackConformant, bool $serverSideGreen, string $evidenceHash): array
    {
        return [
            'schema' => self::SCHEMA,
            'decision' => $decision,
            'blockers' => $blockers,
            'sources' => [
                'risk_level' => $riskLevel,
                'rollback_conformant' => $rollbackConformant,
                'server_side_green' => $serverSideGreen,
                'has_evidence_hash' => $evidenceHash !== '',
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSession;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusLoopPayloadNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusSelfConstructionAdmissionBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;

/**
 * Factory-max selection FALLBACK ladder + selection-support helpers, extracted
 * VERBATIM from AutonomousEvolutionSessionService by the GOD-DEBULK split. Holds
 * the four exhaustion-fallback passes (priority-backlog, canonical-backlog,
 * admission-bridge, starvation-refill/terminal-unlock/replenishment), the AP-806
 * admission report projection and the recent-maintenance streak counter.
 *
 * The selectCandidate SKELETON (setup, main pass, first-rank, top-candidate
 * resolution) and candidateRejectionReason (which calls the AP-806 honest-stop)
 * stay on the parent. The mutable selection state (candidates / candidateKeys /
 * rejections / priority / admission / canonical-backlog projections) is threaded
 * BY REFERENCE so the moved passes remain byte-behaviour-identical to the inline
 * originals; back-calls to shared parent predicates/services go through
 * {@see AutonomousEvolutionSessionService}. It never touches the RSI meta path.
 */
final class SelectionFallbackSection
{
    public function __construct(
        private readonly AutonomousEvolutionSessionService $parent,
    ) {}

    /**
     * Factory-max exhaustion fallback ladder (priority-backlog -> canonical-backlog
     * -> admission-bridge -> starvation-refill/terminal-unlock/replenishment). Runs
     * only when the native + seed passes produced no candidate. Returns a full
     * selection result when the starvation-refill branch terminates the ladder, or
     * null to hand control back to the parent's top-candidate resolution with the
     * (possibly refilled) mutable state.
     *
     * @param  array<string,mixed>  $ctx
     * @param  list<array<string,mixed>>  $candidates
     * @param  array<string,true>  $candidateKeys
     * @param  list<array<string,string>>  $rejections
     * @param  array<string,mixed>  $priority
     * @param  list<array{finding:array<string,mixed>,reason:string}>  $rejectedHighValue
     * @param  array<string,true>  $wastedStarvationRecoveryLocked
     * @return array<string,mixed>|null
     */
    public function resolveFactoryMaxFallback(
        array $ctx,
        array &$candidates,
        array &$candidateKeys,
        array &$rejections,
        array &$priority,
        mixed &$selectionCanonicalBacklog,
        mixed &$selectionAdmission,
        mixed &$completedSliceIdsForAdmission,
        array &$rejectedHighValue,
        array $wastedStarvationRecoveryLocked,
    ): ?array {
        $areaId = $ctx['areaId'];
        $focus = $ctx['focus'];
        $scopeProfile = $ctx['scopeProfile'];
        $forgeInputs = $ctx['forgeInputs'];
        $reviewLocked = $ctx['reviewLocked'];
        $terminalLocked = $ctx['terminalLocked'];
        $maintenanceBudgetExhausted = $ctx['maintenanceBudgetExhausted'];
        $envelope = $ctx['envelope'];
        $structuralRuntimeGapBacklogPending = $ctx['structuralRuntimeGapBacklogPending'];
        $provider = $ctx['provider'];

        if ($candidates === [] && $scopeProfile === AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX) {
            foreach ($this->parent->factoryMaxPriorityBacklogCandidates($priority) as $finding) {
                $finding = $this->parent->promoteSafeFactoryFinding($finding, $scopeProfile);
                if ($this->parent->findingIsReviewLocked($finding, $candidateKeys)) {
                    $rejections[] = [
                        'finding_id' => (string) ($finding['finding_id'] ?? ''),
                        'title' => (string) ($finding['title'] ?? ''),
                        'reason' => 'duplicate_candidate_key_in_pass',
                    ];

                    continue;
                }
                $allowedFiles = $this->parent->allowedFiles($finding);
                $rejection = $this->parent->candidateRejectionReason($finding, $allowedFiles, $reviewLocked, $scopeProfile, $areaId, $focus, $forgeInputs, $maintenanceBudgetExhausted, $terminalLocked, $envelope, $structuralRuntimeGapBacklogPending, $provider);
                if ($rejection !== '') {
                    $rejections[] = [
                        'finding_id' => (string) ($finding['finding_id'] ?? ''),
                        'title' => (string) ($finding['title'] ?? ''),
                        'reason' => $rejection,
                    ];

                    continue;
                }
                foreach ($this->parent->findingKeys($finding) as $key) {
                    $candidateKeys[$key] = true;
                }
                $candidates[] = $finding;
                break;
            }

            if ($candidates !== []) {
                $priority = $this->parent->priorityEngine->rank([
                    'area_id' => $areaId,
                    'focus' => AutonomousEvolutionSessionService::DEFAULT_FOCUS,
                    'candidates' => $candidates,
                    'scope_profile' => $scopeProfile,
                    'has_live_forge_authority' => $this->parent->hasLiveForgeAuthority($forgeInputs),
                ]);
            }
        }
        if ($candidates === [] && $scopeProfile === AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX) {
            $completedSliceIdsForAdmission = $this->parent->completedSemanticSliceIds($areaId);
            $selectionCanonicalBacklog = $this->parent->canonicalBacklog()->admissionReport(
                $this->parent->admissionBridge(),
                $areaId,
                $focus,
                $completedSliceIdsForAdmission,
            );

            foreach ($this->parent->canonicalBacklog()->findings($areaId, $focus) as $finding) {
                $finding = $this->parent->promoteSafeFactoryFinding($finding, $scopeProfile);
                if ($this->parent->findingIsReviewLocked($finding, $candidateKeys + $reviewLocked + $terminalLocked)) {
                    $rejections[] = [
                        'finding_id' => (string) ($finding['finding_id'] ?? ''),
                        'title' => (string) ($finding['title'] ?? ''),
                        'reason' => 'review_locked_existing_branch',
                    ];

                    continue;
                }

                $allowedFiles = $this->parent->allowedFiles($finding);
                $rejection = $this->parent->candidateRejectionReason($finding, $allowedFiles, $reviewLocked, $scopeProfile, $areaId, $focus, $forgeInputs, $maintenanceBudgetExhausted, $terminalLocked, $envelope, $structuralRuntimeGapBacklogPending, $provider);
                if ($rejection !== '') {
                    $rejections[] = [
                        'finding_id' => (string) ($finding['finding_id'] ?? ''),
                        'title' => (string) ($finding['title'] ?? ''),
                        'reason' => $rejection,
                    ];
                    if (in_array($rejection, AreaFocusSelfConstructionAdmissionBridgeService::ADMISSIBLE_REJECTION_REASONS, true)) {
                        $rejectedHighValue[] = ['finding' => $finding, 'reason' => $rejection];
                    }

                    continue;
                }

                foreach ($this->parent->findingKeys($finding) as $key) {
                    $candidateKeys[$key] = true;
                }
                $candidates[] = $finding;
            }

            if ($candidates !== []) {
                $priority = $this->parent->priorityEngine->rank([
                    'area_id' => $areaId,
                    'focus' => AutonomousEvolutionSessionService::DEFAULT_FOCUS,
                    'candidates' => $candidates,
                    'scope_profile' => $scopeProfile,
                    'has_live_forge_authority' => $this->parent->hasLiveForgeAuthority($forgeInputs),
                ]);
            }
        }
        // AP-806 admission bridge: before any synthetic starvation-recovery, try to
        // convert an authority-gated HIGH-VALUE reject into small governed packets
        // (Self-Construction) and admit the FIRST packet as a normal candidate the
        // SAME loop executes. Reuses AreaFocusSelfConstructionAdmissionBridgeService;
        // the narrowed packet is re-proven through the existing gates. If nothing
        // admits, fall through to the honest backlog stop — NEVER recovery filler.
        $selectionAdmission = null;
        if ($candidates === [] && $scopeProfile === AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX && $rejectedHighValue !== []) {
            $completedSliceIds = $completedSliceIdsForAdmission ?? $this->parent->completedSemanticSliceIds($areaId);
            foreach ($rejectedHighValue as $highValue) {
                $admission = $this->parent->admissionBridge()->admit(
                    (array) $highValue['finding'],
                    (string) $highValue['reason'],
                    $areaId,
                    $focus,
                    $completedSliceIds,
                );
                $packetFinding = is_array($admission['first_packet_finding'] ?? null) ? $admission['first_packet_finding'] : null;
                if (($admission['admissible'] ?? false) !== true || $packetFinding === null) {
                    $selectionAdmission ??= $admission;

                    continue;
                }
                $packetAllowed = $this->parent->allowedFiles($packetFinding);
                $packetRejection = $this->parent->candidateRejectionReason($packetFinding, $packetAllowed, $reviewLocked, $scopeProfile, $areaId, $focus, $forgeInputs, $maintenanceBudgetExhausted, $terminalLocked, $envelope, $structuralRuntimeGapBacklogPending, $provider);
                if ($packetRejection !== '' || $this->parent->findingIsReviewLocked($packetFinding, $candidateKeys + $reviewLocked + $terminalLocked)) {
                    $rejections[] = [
                        'finding_id' => (string) ($packetFinding['finding_id'] ?? ''),
                        'title' => (string) ($packetFinding['title'] ?? ''),
                        'reason' => $packetRejection !== '' ? 'admission_packet_'.$packetRejection : 'admission_packet_review_locked',
                    ];
                    $selectionAdmission = $admission;

                    continue;
                }
                foreach ($this->parent->findingKeys($packetFinding) as $key) {
                    $candidateKeys[$key] = true;
                }
                $candidates[] = $packetFinding;
                $selectionAdmission = $admission;
                break;
            }
            if ($candidates !== []) {
                $priority = $this->parent->priorityEngine->rank([
                    'area_id' => $areaId,
                    'focus' => AutonomousEvolutionSessionService::DEFAULT_FOCUS,
                    'candidates' => $candidates,
                    'scope_profile' => $scopeProfile,
                    'has_live_forge_authority' => $this->parent->hasLiveForgeAuthority($forgeInputs),
                ]);
            }
        }
        $selectionRefill = null;
        if ($candidates === [] && $scopeProfile === AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX) {
            if ($rejections === []) {
                $rejections[] = [
                    'finding_id' => '',
                    'title' => 'factory_max_no_executable_candidates',
                    'reason' => 'no_executable_candidates_after_selection_pass',
                ];
            }
            $candidate = $this->parent->factoryMaxStarvationRecoveryCandidate($rejections);
            $selectionRefill = $this->parent->factoryMaxSelectionRefillReceipt($rejections);
            if ($this->parent->findingIsReviewLocked($candidate, $terminalLocked + $wastedStarvationRecoveryLocked)) {
                $rejections[] = [
                    'finding_id' => (string) ($candidate['finding_id'] ?? ''),
                    'title' => (string) ($candidate['title'] ?? ''),
                    'reason' => 'terminal_locked_existing_failure',
                ];

                $terminalUnlockCandidates = $this->parent->factoryMaxTerminalBacklogUnlockCandidates($rejections);
                $terminalRankContext = $this->parent->terminalBacklogRankContext($rejections);
                foreach ($terminalUnlockCandidates as $unlockCandidate) {
                    if ($this->parent->findingIsReviewLocked($unlockCandidate, $reviewLocked + $terminalLocked + $candidateKeys)) {
                        $rejections[] = [
                            'finding_id' => (string) ($unlockCandidate['finding_id'] ?? ''),
                            'title' => (string) ($unlockCandidate['title'] ?? ''),
                            'reason' => 'terminal_unlock_candidate_locked',
                        ];

                        continue;
                    }

                    $priority = $this->parent->priorityEngine->rank([
                        'area_id' => $areaId,
                        'focus' => AutonomousEvolutionSessionService::DEFAULT_FOCUS,
                        'candidates' => [$unlockCandidate],
                        'scope_profile' => $scopeProfile,
                        'has_live_forge_authority' => $this->parent->hasLiveForgeAuthority($forgeInputs),
                    ] + $terminalRankContext);

                    return [
                        'finding' => $unlockCandidate,
                        'priority_report' => $priority,
                        'selection_rejections' => $rejections,
                        'selection_refill' => $selectionRefill + [
                            'terminal_unlock_strategy' => 'ap790_terminal_backlog_unlock',
                        ],
                        'selection_canonical_backlog' => $selectionCanonicalBacklog,
                    ];
                }

                $replenished = $this->parent->tryFactoryMaxTerminalBacklogReplenishmentSelection(
                    $areaId,
                    $forgeInputs,
                    $scopeProfile,
                    $reviewLocked,
                    $terminalLocked,
                    $candidateKeys,
                    $rejections,
                    $selectionRefill,
                    $priority,
                );
                if ($replenished !== null) {
                    return $replenished;
                }

                return [
                    'finding' => null,
                    'priority_report' => $priority,
                    'selection_rejections' => $rejections,
                    'selection_refill' => $selectionRefill,
                    'selection_canonical_backlog' => $selectionCanonicalBacklog,
                ];
            }
            $priority = $this->parent->priorityEngine->rank([
                'area_id' => $areaId,
                'focus' => AutonomousEvolutionSessionService::DEFAULT_FOCUS,
                'candidates' => [$candidate],
                'scope_profile' => $scopeProfile,
                'has_live_forge_authority' => $this->parent->hasLiveForgeAuthority($forgeInputs),
            ]);

            return [
                'finding' => $candidate,
                'priority_report' => $priority,
                'selection_rejections' => $rejections,
                'selection_refill' => $selectionRefill,
                'selection_canonical_backlog' => $selectionCanonicalBacklog,
            ];
        }

        return null;
    }

    /**
     * AP-806 admission report: an honest, machine-readable picture of WHY the loop
     * has (or has not) real eligible work, so an empty selection becomes a clear
     * backlog_exhausted/admission diagnosis instead of a synthetic recovery merge.
     *
     * @param  array<string,mixed>  $scan
     * @param  list<array<string,string>>  $rejections
     * @return array<string,mixed>
     */
    public function buildAdmissionReport(array $scan, array $rejections, int $acceptedCount, bool $hasForgeAuthority): array
    {
        $findings = AreaFocusLoopPayloadNormalizer::listOfArrays($scan['findings'] ?? []);
        $byReason = [];
        foreach ($rejections as $rejection) {
            $reason = (string) ($rejection['reason'] ?? 'unknown');
            if ($reason === '') {
                continue;
            }
            $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
        }
        arsort($byReason);

        $authorityGatedReasons = [
            'factory_max_rejects_high_risk_deep_finding_without_forge_authority',
            'factory_max_rejects_forge_without_live_authority',
            'factory_max_rejects_atlas_dev_topology_leak_without_authority',
            'factory_max_rejects_non_factory_scope_without_automerge_authority',
        ];
        $eligibleIfForgeAuthority = 0;
        foreach ($authorityGatedReasons as $reason) {
            $eligibleIfForgeAuthority += (int) ($byReason[$reason] ?? 0);
        }
        $routineCount = (int) ($byReason['factory_max_rejects_routine_missing_test_work'] ?? 0);

        $topBlockers = [];
        foreach (array_slice($byReason, 0, 5, true) as $reason => $count) {
            $topBlockers[] = ['reason' => $reason, 'count' => $count];
        }

        $nextUnlock = match (true) {
            $acceptedCount > 0 => 'eligible_work_available',
            $eligibleIfForgeAuthority > 0 && ! $hasForgeAuthority => 'wire_real_forge_authority_admits_'.$eligibleIfForgeAuthority.'_high_value_findings',
            $routineCount > 0 => 'balanced_scope_admits_'.$routineCount.'_coverage_findings_or_seed_structural_factory_work',
            default => 'deepen_factory_scoped_structural_backlog_no_eligible_distinct_work_remains',
        };

        return [
            'schema_version' => 'atlas.software_company_stewardship.factory_max_admission_report.v1',
            'total_findings' => count($findings),
            'accepted' => $acceptedCount,
            'rejected_total' => count($rejections),
            'rejected_by_reason' => $byReason,
            'top_blockers' => $topBlockers,
            'eligible_if_forge_authority' => $eligibleIfForgeAuthority,
            'routine_or_test_count' => $routineCount,
            'has_live_forge_authority' => $hasForgeAuthority,
            'next_unlock' => $nextUnlock,
        ];
    }

    public function recentFactoryMaintenanceCycleCount(string $areaId): int
    {
        $path = $this->parent->recordPath($areaId);
        if (! is_file($path)) {
            return 0;
        }

        $cycles = [];
        foreach ($this->parent->sessionRecordLines($path) as $line) {
            $record = json_decode($line, true);
            if (! is_array($record)) {
                continue;
            }
            foreach ((array) ($record['cycles'] ?? []) as $cycle) {
                if (is_array($cycle)) {
                    $status = (string) ($cycle['final_status'] ?? '');
                    if ($status === AutonomousEvolutionSessionService::STATUS_DRY_RUN || str_starts_with($status, 'dry_run')) {
                        continue;
                    }
                    $cycles[] = $cycle;
                    if (count($cycles) > AutonomousEvolutionSessionService::FACTORY_MAX_MAINTENANCE_STREAK_LIMIT + 3) {
                        array_shift($cycles);
                    }
                }
            }
        }

        $count = 0;
        foreach (array_reverse($cycles) as $cycle) {
            $status = (string) ($cycle['final_status'] ?? '');
            if (! in_array($status, ['cycle_completed', 'cycle_completed_waiting_review_or_merge'], true)) {
                break;
            }
            $finding = is_array($cycle['selected_finding'] ?? null) ? $cycle['selected_finding'] : [];
            if (! $this->parent->isFactoryMaintenanceFinding($finding)) {
                break;
            }
            $count++;
        }

        return $count;
    }
}

<?php

namespace App\Services\Ai\Mission;

use App\Models\AiMission;
use App\Models\AiMissionCertification;
use App\Models\AiMissionEvent;
use App\Models\AiMissionEvidenceRef;
use App\Models\AiObjective;
use App\Models\AiWorkOrder;
use App\Services\Ai\Mission\Support\MissionSuccessCriteriaNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Mission certification gate. As of 2026-05-18 the runChecks pipeline is
 * quality-aware (not shape-only): each check returns a structured record with
 * `requirement` (stable id, also exposed as `id`), `status` (passed|failed|warn),
 * `severity` (critical|high|medium|low), `message`, optional `evidence_refs`
 * and `remediation`.
 *
 * A certification only reaches `passed` when every CRITICAL check passes.
 * `warn` checks remain in `checked_requirements` for auditability but do not
 * keep the mission in `failed` status.
 */
class MissionCertificationService
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PASSED = 'passed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_BLOCKED = 'blocked';

    public const SEVERITY_CRITICAL = 'critical';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_LOW = 'low';

    public const CHECK_STATUS_PASSED = 'passed';

    public const CHECK_STATUS_FAILED = 'failed';

    public const CHECK_STATUS_WARN = 'warn';

    /**
     * Stable ids used both as `requirement` and `id` for callers that match by
     * either key. Kept as constants to avoid drift between tests and code.
     */
    public const CHECK_DOD_HAS_CRITERIA = 'dod_has_criteria';

    public const CHECK_OBJECTIVES_EXIST = 'objectives_exist';

    public const CHECK_OBJECTIVES_HAVE_SUCCESS_CRITERIA = 'objectives_have_success_criteria';

    public const CHECK_WORK_ORDERS_EXIST = 'work_orders_exist';

    public const CHECK_WORK_ORDERS_HAVE_RECEIPT_HASH = 'work_orders_have_receipt_hash';

    public const CHECK_EVIDENCE_REFS_EXIST = 'evidence_refs_exist';

    public const CHECK_EVIDENCE_REFS_HAVE_VALID_TYPE = 'evidence_refs_have_valid_type';

    public const CHECK_EVIDENCE_REFS_HAVE_VALID_HASH = 'evidence_refs_have_valid_hash';

    public const CHECK_EVIDENCE_REFS_HAVE_NON_EMPTY_REFERENCE = 'evidence_refs_have_non_empty_reference';

    public const CHECK_DOD_CRITERIA_COVERED_BY_EVIDENCE = 'dod_criteria_covered_by_evidence';

    public const CHECK_CANONICAL_EVENTS_RECORDED = 'canonical_events_recorded';

    public const CHECK_NO_UNRESOLVED_BLOCKERS = 'no_unresolved_blockers';

    public const CHECK_MISSION_STATUS_IS_CERTIFIABLE = 'mission_status_is_certifiable';

    /** Canonical event types every certifiable mission must have recorded. */
    private const REQUIRED_CANONICAL_EVENTS = [
        'mission.created',
        'objective.created',
        'work_order.created',
        'evidence.attached',
    ];

    /** Mission lifecycle statuses where certification is allowed to be `passed`. */
    private const CERTIFIABLE_STATUSES = [
        MissionLifecycleService::STATUS_RUNNING,
        MissionLifecycleService::STATUS_CERTIFYING,
        MissionLifecycleService::STATUS_REPAIRING,
    ];

    public function __construct(private readonly MissionLifecycleService $lifecycle) {}

    public function certify(AiMission $mission): AiMissionCertification
    {
        $checks = $this->runChecks($mission);

        $criticalFailures = array_values(array_filter(
            $checks,
            static fn (array $check): bool => $check['status'] !== self::CHECK_STATUS_PASSED
                && $check['severity'] === self::SEVERITY_CRITICAL,
        ));

        // `missing_requirements` lists FAILED checks only. `warn` checks remain
        // in `checked_requirements` and `summary.warn` for auditability without
        // gating completion.
        $missing = array_values(array_filter(
            $checks,
            static fn (array $check): bool => $check['status'] === self::CHECK_STATUS_FAILED,
        ));

        $status = $criticalFailures === [] ? self::STATUS_PASSED : self::STATUS_FAILED;

        $evidenceRefs = $mission->evidenceRefs()
            ->orderBy('created_at')
            ->pluck('id')
            ->all();

        $hashInput = [
            'mission_id' => $mission->id,
            'mission_uuid' => $mission->uuid,
            'status' => $status,
            'checked_requirements' => $checks,
            'missing_requirements' => $missing,
            'evidence_refs' => $evidenceRefs,
        ];

        $certificationHash = MissionCanonicalHash::sha256($hashInput);

        $certification = AiMissionCertification::query()->create([
            'uuid' => (string) Str::uuid(),
            'mission_id' => $mission->id,
            'status' => $status,
            'checked_requirements' => $checks,
            'missing_requirements' => $missing,
            'evidence_refs' => $evidenceRefs,
            'certification_hash' => $certificationHash,
            'certified_at' => $status === self::STATUS_PASSED ? Carbon::now() : null,
        ]);

        $mission->certification_hash = $certificationHash;
        $mission->evidence_pack_hash = MissionCanonicalHash::sha256($evidenceRefs);
        $mission->save();

        $this->lifecycle->recordEvent(
            $mission,
            'certification.recorded',
            'system',
            [
                'certification_id' => $certification->id,
                'status' => $status,
                'certification_hash' => $certificationHash,
                'missing_count' => count($missing),
                'critical_failures' => array_map(
                    static fn (array $check): string => (string) $check['requirement'],
                    $criticalFailures,
                ),
                'check_summary' => $this->summarize($checks),
            ],
            receiptHash: $certificationHash,
        );

        return $certification;
    }

    /**
     * Build the full quality-aware check list for a mission.
     *
     * @return array<int,array<string,mixed>>
     */
    public function runChecks(AiMission $mission): array
    {
        $objectives = $mission->objectives()->get();
        $workOrders = $mission->workOrders()->get();
        $evidence = $mission->evidenceRefs()->get();
        $events = $mission->events()->get();
        $dod = (array) $mission->definition_of_done;

        return [
            $this->checkDodHasCriteria($dod),
            $this->checkObjectivesExist($objectives),
            $this->checkObjectivesHaveSuccessCriteria($objectives),
            $this->checkWorkOrdersExist($workOrders),
            $this->checkWorkOrdersHaveReceiptHash($workOrders),
            $this->checkEvidenceRefsExist($evidence),
            $this->checkEvidenceRefsHaveValidType($evidence),
            $this->checkEvidenceRefsHaveValidHash($evidence),
            $this->checkEvidenceRefsHaveNonEmptyReference($evidence),
            $this->checkDodCriteriaCoveredByEvidence($dod, $evidence),
            $this->checkCanonicalEventsRecorded($events),
            $this->checkNoUnresolvedBlockers($mission, $events),
            $this->checkMissionStatusIsCertifiable($mission),
        ];
    }

    /**
     * Human-readable explanation of why a check list failed (or warned).
     *
     * @param  array<int,array<string,mixed>>  $checks
     * @return array<int,string>
     */
    public function explain(array $checks): array
    {
        return collect($checks)
            ->reject(static fn (array $check): bool => $check['status'] === self::CHECK_STATUS_PASSED)
            ->map(static function (array $check): string {
                $severity = strtoupper((string) ($check['severity'] ?? self::SEVERITY_MEDIUM));
                $status = strtoupper((string) $check['status']);
                $message = (string) ($check['message'] ?? $check['detail'] ?? '');
                $remediation = isset($check['remediation']) && $check['remediation'] !== ''
                    ? ' [remediation: '.$check['remediation'].']'
                    : '';

                return "[{$severity}/{$status}] {$check['requirement']}: {$message}{$remediation}";
            })->values()->all();
    }

    /**
     * @param  array<int,array<string,mixed>>  $checks
     * @return array<string,int>
     */
    public function summarize(array $checks): array
    {
        $summary = ['passed' => 0, 'failed' => 0, 'warn' => 0, 'critical_failed' => 0];
        foreach ($checks as $check) {
            $status = (string) $check['status'];
            $severity = (string) ($check['severity'] ?? self::SEVERITY_MEDIUM);
            $summary[$status] = ($summary[$status] ?? 0) + 1;
            if ($status === self::CHECK_STATUS_FAILED && $severity === self::SEVERITY_CRITICAL) {
                $summary['critical_failed']++;
            }
        }

        return $summary;
    }

    /**
     * @param  array<string,mixed>  $dod
     * @return array<string,mixed>
     */
    private function checkDodHasCriteria(array $dod): array
    {
        $criteria = self::nonEmptyCriteria($dod['criteria'] ?? []);

        $count = $criteria->count();

        return $this->result(
            self::CHECK_DOD_HAS_CRITERIA,
            $count > 0 ? self::CHECK_STATUS_PASSED : self::CHECK_STATUS_FAILED,
            self::SEVERITY_CRITICAL,
            $count > 0
                ? "definition_of_done.criteria has {$count} non-empty entry(ies)."
                : 'definition_of_done.criteria is empty; mission has no acceptance bar.',
            remediation: $count > 0
                ? null
                : 'Populate AiMission.definition_of_done.criteria[] with at least one description before certify().',
            detail: "criteria_count={$count}",
        );
    }

    /**
     * @param  Collection<int,AiObjective>  $objectives
     * @return array<string,mixed>
     */
    private function checkObjectivesExist(Collection $objectives): array
    {
        $count = $objectives->count();

        return $this->result(
            self::CHECK_OBJECTIVES_EXIST,
            $count > 0 ? self::CHECK_STATUS_PASSED : self::CHECK_STATUS_FAILED,
            self::SEVERITY_CRITICAL,
            $count > 0
                ? "{$count} objective(s) attached to mission."
                : 'Mission has zero objectives; cannot certify without at least one objective.',
            remediation: $count > 0 ? null : 'Run ObjectiveDecomposerService::decompose($mission) before certify().',
            detail: "objectives_count={$count}",
        );
    }

    /**
     * @param  Collection<int,AiObjective>  $objectives
     * @return array<string,mixed>
     */
    private function checkObjectivesHaveSuccessCriteria(Collection $objectives): array
    {
        if ($objectives->isEmpty()) {
            return $this->result(
                self::CHECK_OBJECTIVES_HAVE_SUCCESS_CRITERIA,
                self::CHECK_STATUS_FAILED,
                self::SEVERITY_HIGH,
                'No objectives to inspect for success_criteria.',
                remediation: 'Decompose objectives first; this check depends on objectives_exist.',
                detail: 'objectives_count=0',
            );
        }

        $offenders = $objectives->filter(static function (AiObjective $objective): bool {
            $criteria = self::nonEmptyCriteria($objective->success_criteria);

            return $criteria->isEmpty();
        });

        $count = $offenders->count();

        return $this->result(
            self::CHECK_OBJECTIVES_HAVE_SUCCESS_CRITERIA,
            $count === 0 ? self::CHECK_STATUS_PASSED : self::CHECK_STATUS_FAILED,
            self::SEVERITY_HIGH,
            $count === 0
                ? 'All objectives carry at least one non-empty success_criteria.'
                : "{$count} objective(s) without success_criteria; certification cannot validate acceptance.",
            evidenceRefs: $offenders->pluck('id')->all(),
            remediation: $count === 0 ? null : 'Populate AiObjective.success_criteria[] with at least one description for each objective.',
            detail: "objectives_missing_success_criteria={$count}",
        );
    }

    /**
     * @param  Collection<int,AiWorkOrder>  $workOrders
     * @return array<string,mixed>
     */
    private function checkWorkOrdersExist(Collection $workOrders): array
    {
        $count = $workOrders->count();

        return $this->result(
            self::CHECK_WORK_ORDERS_EXIST,
            $count > 0 ? self::CHECK_STATUS_PASSED : self::CHECK_STATUS_FAILED,
            self::SEVERITY_CRITICAL,
            $count > 0
                ? "{$count} work_order(s) planned for mission."
                : 'Mission has zero work_orders; execution path is missing.',
            remediation: $count > 0 ? null : 'Run WorkOrderFactoryService::plan($mission) before certify().',
            detail: "work_orders_count={$count}",
        );
    }

    /**
     * @param  Collection<int,AiWorkOrder>  $workOrders
     * @return array<string,mixed>
     */
    private function checkWorkOrdersHaveReceiptHash(Collection $workOrders): array
    {
        if ($workOrders->isEmpty()) {
            return $this->result(
                self::CHECK_WORK_ORDERS_HAVE_RECEIPT_HASH,
                self::CHECK_STATUS_FAILED,
                self::SEVERITY_CRITICAL,
                'No work_orders to inspect for receipt_hash.',
                remediation: 'Plan work_orders first; this check depends on work_orders_exist.',
                detail: 'work_orders_count=0',
            );
        }

        $offenders = $workOrders->filter(static function (AiWorkOrder $workOrder): bool {
            $hash = (string) ($workOrder->receipt_hash ?? '');

            return ! self::isValidSha256($hash);
        });

        $count = $offenders->count();

        return $this->result(
            self::CHECK_WORK_ORDERS_HAVE_RECEIPT_HASH,
            $count === 0 ? self::CHECK_STATUS_PASSED : self::CHECK_STATUS_FAILED,
            self::SEVERITY_CRITICAL,
            $count === 0
                ? 'Every work_order has a valid SHA-256 receipt_hash.'
                : "{$count} work_order(s) without valid receipt_hash; cannot prove deterministic plan.",
            evidenceRefs: $offenders->pluck('id')->all(),
            remediation: $count === 0
                ? null
                : 'Regenerate work_orders via WorkOrderFactoryService::createForObjective so receipt_hash is computed canonically.',
            detail: "work_orders_missing_receipt_hash={$count}",
        );
    }

    /**
     * @param  Collection<int,AiMissionEvidenceRef>  $evidence
     * @return array<string,mixed>
     */
    private function checkEvidenceRefsExist(Collection $evidence): array
    {
        $count = $evidence->count();

        return $this->result(
            self::CHECK_EVIDENCE_REFS_EXIST,
            $count > 0 ? self::CHECK_STATUS_PASSED : self::CHECK_STATUS_FAILED,
            self::SEVERITY_CRITICAL,
            $count > 0
                ? "{$count} evidence_ref(s) attached to mission."
                : 'Mission has zero evidence_refs; nothing to certify.',
            remediation: $count > 0 ? null : 'Attach at least one evidence_ref via MissionEvidenceService::attach() before certify().',
            detail: "evidence_refs_count={$count}",
        );
    }

    /**
     * @param  Collection<int,AiMissionEvidenceRef>  $evidence
     * @return array<string,mixed>
     */
    private function checkEvidenceRefsHaveValidType(Collection $evidence): array
    {
        if ($evidence->isEmpty()) {
            return $this->result(
                self::CHECK_EVIDENCE_REFS_HAVE_VALID_TYPE,
                self::CHECK_STATUS_FAILED,
                self::SEVERITY_CRITICAL,
                'No evidence_refs to inspect for type.',
                remediation: 'Attach evidence first; this check depends on evidence_refs_exist.',
                detail: 'evidence_refs_count=0',
            );
        }

        $offenders = $evidence->filter(static function (AiMissionEvidenceRef $ref): bool {
            return ! in_array((string) $ref->evidence_type, MissionEvidenceService::ALLOWED_TYPES, true);
        });
        $count = $offenders->count();

        return $this->result(
            self::CHECK_EVIDENCE_REFS_HAVE_VALID_TYPE,
            $count === 0 ? self::CHECK_STATUS_PASSED : self::CHECK_STATUS_FAILED,
            self::SEVERITY_CRITICAL,
            $count === 0
                ? 'Every evidence_ref carries a canonical evidence_type.'
                : "{$count} evidence_ref(s) with non-canonical evidence_type.",
            evidenceRefs: $offenders->pluck('id')->all(),
            remediation: $count === 0 ? null : 'Re-attach evidence using MissionEvidenceService::ALLOWED_TYPES.',
            detail: 'evidence_refs_with_invalid_type='.$count,
        );
    }

    /**
     * @param  Collection<int,AiMissionEvidenceRef>  $evidence
     * @return array<string,mixed>
     */
    private function checkEvidenceRefsHaveValidHash(Collection $evidence): array
    {
        if ($evidence->isEmpty()) {
            return $this->result(
                self::CHECK_EVIDENCE_REFS_HAVE_VALID_HASH,
                self::CHECK_STATUS_FAILED,
                self::SEVERITY_CRITICAL,
                'No evidence_refs to inspect for evidence_hash.',
                remediation: 'Attach evidence first.',
                detail: 'evidence_refs_count=0',
            );
        }

        $offenders = $evidence->filter(static function (AiMissionEvidenceRef $ref): bool {
            $hash = (string) ($ref->evidence_hash ?? '');

            return ! self::isValidSha256($hash);
        });
        $count = $offenders->count();

        return $this->result(
            self::CHECK_EVIDENCE_REFS_HAVE_VALID_HASH,
            $count === 0 ? self::CHECK_STATUS_PASSED : self::CHECK_STATUS_FAILED,
            self::SEVERITY_CRITICAL,
            $count === 0
                ? 'Every evidence_ref carries a SHA-256 evidence_hash.'
                : "{$count} evidence_ref(s) without valid SHA-256 evidence_hash; cannot prove evidence integrity.",
            evidenceRefs: $offenders->pluck('id')->all(),
            remediation: $count === 0 ? null : 'Re-attach evidence so MissionEvidenceService computes hashForReference().',
            detail: 'evidence_refs_with_invalid_hash='.$count,
        );
    }

    /**
     * @param  Collection<int,AiMissionEvidenceRef>  $evidence
     * @return array<string,mixed>
     */
    private function checkEvidenceRefsHaveNonEmptyReference(Collection $evidence): array
    {
        if ($evidence->isEmpty()) {
            return $this->result(
                self::CHECK_EVIDENCE_REFS_HAVE_NON_EMPTY_REFERENCE,
                self::CHECK_STATUS_FAILED,
                self::SEVERITY_HIGH,
                'No evidence_refs to inspect for evidence_ref payload.',
                remediation: 'Attach evidence first.',
                detail: 'evidence_refs_count=0',
            );
        }

        $offenders = $evidence->filter(static function (AiMissionEvidenceRef $ref): bool {
            return trim((string) $ref->evidence_ref) === '';
        });
        $count = $offenders->count();

        return $this->result(
            self::CHECK_EVIDENCE_REFS_HAVE_NON_EMPTY_REFERENCE,
            $count === 0 ? self::CHECK_STATUS_PASSED : self::CHECK_STATUS_FAILED,
            self::SEVERITY_HIGH,
            $count === 0
                ? 'Every evidence_ref has a non-empty reference payload.'
                : "{$count} evidence_ref(s) with empty evidence_ref payload; cannot audit trail.",
            evidenceRefs: $offenders->pluck('id')->all(),
            remediation: $count === 0 ? null : 'Re-attach evidence with a non-empty evidence_ref string.',
            detail: 'evidence_refs_with_empty_payload='.$count,
        );
    }

    /**
     * @param  array<string,mixed>  $dod
     * @param  Collection<int,AiMissionEvidenceRef>  $evidence
     * @return array<string,mixed>
     */
    private function checkDodCriteriaCoveredByEvidence(array $dod, Collection $evidence): array
    {
        $criteria = self::nonEmptyCriteria($dod['criteria'] ?? []);

        $criteriaCount = $criteria->count();
        $evidenceCount = $evidence->count();

        if ($criteriaCount === 0) {
            return $this->result(
                self::CHECK_DOD_CRITERIA_COVERED_BY_EVIDENCE,
                self::CHECK_STATUS_FAILED,
                self::SEVERITY_HIGH,
                'definition_of_done.criteria is empty; cannot map evidence coverage.',
                remediation: 'Populate criteria first; this check depends on dod_has_criteria.',
                detail: 'criteria_count=0',
            );
        }

        if ($evidenceCount === 0) {
            return $this->result(
                self::CHECK_DOD_CRITERIA_COVERED_BY_EVIDENCE,
                self::CHECK_STATUS_FAILED,
                self::SEVERITY_HIGH,
                "{$criteriaCount} DoD criterion(s) without any evidence_ref attached.",
                remediation: 'Attach at least one evidence_ref per DoD criterion (or run cycle until evidence_count >= criteria_count).',
                detail: "criteria_count={$criteriaCount} evidence_count=0",
            );
        }

        if ($evidenceCount >= $criteriaCount) {
            return $this->result(
                self::CHECK_DOD_CRITERIA_COVERED_BY_EVIDENCE,
                self::CHECK_STATUS_PASSED,
                self::SEVERITY_HIGH,
                "DoD coverage adequate: evidence_count={$evidenceCount} >= criteria_count={$criteriaCount}.",
                detail: "criteria_count={$criteriaCount} evidence_count={$evidenceCount}",
            );
        }

        // Partial coverage -> warn (does not block certification but is auditable).
        return $this->result(
            self::CHECK_DOD_CRITERIA_COVERED_BY_EVIDENCE,
            self::CHECK_STATUS_WARN,
            self::SEVERITY_MEDIUM,
            "Partial DoD coverage: evidence_count={$evidenceCount} < criteria_count={$criteriaCount}.",
            remediation: 'Attach an evidence_ref for each remaining DoD criterion to remove this warning.',
            detail: "criteria_count={$criteriaCount} evidence_count={$evidenceCount}",
        );
    }

    /**
     * @param  Collection<int,AiMissionEvent>  $events
     * @return array<string,mixed>
     */
    private function checkCanonicalEventsRecorded(Collection $events): array
    {
        $eventTypes = $events->pluck('event_type')->unique()->all();
        $missing = array_values(array_diff(self::REQUIRED_CANONICAL_EVENTS, $eventTypes));

        return $this->result(
            self::CHECK_CANONICAL_EVENTS_RECORDED,
            $missing === [] ? self::CHECK_STATUS_PASSED : self::CHECK_STATUS_FAILED,
            self::SEVERITY_CRITICAL,
            $missing === []
                ? 'All canonical events recorded: '.implode(', ', self::REQUIRED_CANONICAL_EVENTS).'.'
                : 'Missing canonical events: '.implode(', ', $missing).'.',
            remediation: $missing === []
                ? null
                : 'Re-run the mission factory/decomposer/work_order/evidence pipeline so canonical events are emitted.',
            detail: 'missing_event_types='.implode('|', $missing),
        );
    }

    /**
     * @param  Collection<int,AiMissionEvent>  $events
     * @return array<string,mixed>
     */
    private function checkNoUnresolvedBlockers(AiMission $mission, Collection $events): array
    {
        $blocked = $mission->status === MissionLifecycleService::STATUS_BLOCKED;
        $blockerReason = trim((string) ($mission->blocker_reason ?? ''));

        $blockerEvents = $events->filter(static function ($event): bool {
            $type = (string) $event->event_type;

            return $type === 'mission.transition'
                && (string) ($event->status_after ?? '') === MissionLifecycleService::STATUS_BLOCKED;
        });
        $repairEvents = $events->filter(static function ($event): bool {
            $type = (string) $event->event_type;

            return $type === 'mission.transition'
                && (string) ($event->status_after ?? '') === MissionLifecycleService::STATUS_REPAIRING;
        });

        $unresolved = $blocked || ($blockerEvents->count() > $repairEvents->count());

        if ($blocked) {
            return $this->result(
                self::CHECK_NO_UNRESOLVED_BLOCKERS,
                self::CHECK_STATUS_FAILED,
                self::SEVERITY_CRITICAL,
                'Mission is currently in `blocked` status'.($blockerReason !== '' ? " (reason: {$blockerReason})" : '').
                '; certification cannot pass while blocked.',
                remediation: 'Resolve the blocker, transition mission to `repairing` -> `running`, then re-run certify().',
                detail: 'mission_status=blocked',
            );
        }

        if ($unresolved) {
            return $this->result(
                self::CHECK_NO_UNRESOLVED_BLOCKERS,
                self::CHECK_STATUS_FAILED,
                self::SEVERITY_CRITICAL,
                'Mission history shows '.$blockerEvents->count().' blocker transition(s) but only '.$repairEvents->count().' repair transition(s); blockers are not fully resolved.',
                remediation: 'Record a `repairing` transition for every prior `blocked` transition before certify().',
                detail: 'blocker_events='.$blockerEvents->count().' repair_events='.$repairEvents->count(),
            );
        }

        return $this->result(
            self::CHECK_NO_UNRESOLVED_BLOCKERS,
            self::CHECK_STATUS_PASSED,
            self::SEVERITY_CRITICAL,
            'No unresolved blockers detected on the mission.',
            detail: 'blocker_events='.$blockerEvents->count().' repair_events='.$repairEvents->count(),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkMissionStatusIsCertifiable(AiMission $mission): array
    {
        $status = (string) $mission->status;
        $allowed = in_array($status, self::CERTIFIABLE_STATUSES, true);
        $terminalNegative = in_array(
            $status,
            [
                MissionLifecycleService::STATUS_FAILED,
                MissionLifecycleService::STATUS_CANCELLED,
            ],
            true,
        );

        if ($terminalNegative) {
            return $this->result(
                self::CHECK_MISSION_STATUS_IS_CERTIFIABLE,
                self::CHECK_STATUS_FAILED,
                self::SEVERITY_CRITICAL,
                "Mission is in terminal negative status `{$status}`; certification cannot pass.",
                remediation: 'Open a new mission; this one is closed.',
                detail: "mission_status={$status}",
            );
        }

        if (! $allowed) {
            return $this->result(
                self::CHECK_MISSION_STATUS_IS_CERTIFIABLE,
                self::CHECK_STATUS_WARN,
                self::SEVERITY_MEDIUM,
                "Mission status `{$status}` is unusual for certify(); expected one of `running|certifying|repairing`.",
                remediation: 'Transition mission to `running` or `certifying` before certify(); this warning is auditable but does not block.',
                detail: "mission_status={$status}",
            );
        }

        return $this->result(
            self::CHECK_MISSION_STATUS_IS_CERTIFIABLE,
            self::CHECK_STATUS_PASSED,
            self::SEVERITY_MEDIUM,
            "Mission status `{$status}` is acceptable for certify().",
            detail: "mission_status={$status}",
        );
    }

    /**
     * Structured check record helper. Keeps `requirement` as canonical id and
     * mirrors it on `id` for callers that expect either key.
     *
     * @param  array<int,string|null>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function result(
        string $requirement,
        string $status,
        string $severity,
        string $message,
        array $evidenceRefs = [],
        ?string $remediation = null,
        ?string $detail = null,
    ): array {
        return [
            'id' => $requirement,
            'requirement' => $requirement,
            'status' => $status,
            'severity' => $severity,
            'message' => $message,
            'remediation' => $remediation,
            'evidence_refs' => array_values(array_filter(
                $evidenceRefs,
                static fn ($v): bool => $v !== null && $v !== '',
            )),
            'detail' => $detail ?? $message,
        ];
    }

    /**
     * @return Collection<int,mixed>
     */
    private static function nonEmptyCriteria(mixed $criteria): Collection
    {
        return collect(MissionSuccessCriteriaNormalizer::descriptions($criteria))->values();
    }

    private static function isValidSha256(string $hash): bool
    {
        return $hash !== '' && preg_match('/^[a-f0-9]{64}$/', $hash) === 1;
    }
}

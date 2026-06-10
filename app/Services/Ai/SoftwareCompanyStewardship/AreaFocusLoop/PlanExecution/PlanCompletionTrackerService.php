<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusAppendOnlyJsonlRecorder;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusJsonlReader;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusLoopPayloadNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusScalarNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusSlugNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusStringListNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusUtcClock;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousLoopReceiptIntegrityService;

/**
 * Pilar 1 · Plan completion tracker for the Atlas 24h loop.
 *
 * Records the HONEST delivery state of a decomposed plan (atlas.plan_execution.decomposed_plan.v1)
 * as its slices are advanced by real loop cycles. For each RAW loop cycle handed in, it composes
 * AutonomousLoopReceiptIntegrityService::receiptFor() to obtain the canonical, auditable receipt,
 * then DERIVES (never accepts from caller input) two honesty signals:
 *
 *   - provider_proof: a cycle is real only when it merged with a real diff and the provider router
 *     was NOT used. provider_router_used=true OR empty changed_files => provider_proof=false.
 *   - acceptance_met: derived from receipt.validation.passed===true AND non-empty evidence_refs.
 *     Free-text "Aceite" criteria are NOT machine-verified; validation.passed is the hard floor.
 *
 * A slice reaches state='delivered' ONLY with provider_proof===true AND merge_hash!==null AND
 * acceptance_met===true. Merged-without-provider-proof or validation-failed records
 * 'in_progress'/'blocked', never 'delivered'. completion_pct = delivered_count/total_slices*100,
 * never rounding up phantom progress.
 *
 * Persistence is append-only JSONL (latest event per slice_id wins, idempotent, corruption-tolerant),
 * mirroring LongHorizonLoopDeliveryLedgerService. It NEVER invokes a provider, NEVER runs the loop,
 * NEVER merges. The only side effect is appending JSONL under storage/.
 */
final class PlanCompletionTrackerService
{
    public const LEDGER_SCHEMA = 'atlas.plan_execution.plan_completion_ledger.v1';

    public const EVENT_SCHEMA = 'atlas.plan_execution.plan_completion_ledger_event.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    /** Slice lifecycle states (verbatim from LongHorizonLoopDeliveryLedgerService to avoid drift). */
    public const SLICE_STATE_PLANNED = 'planned';

    public const SLICE_STATE_IN_PROGRESS = 'in_progress';

    public const SLICE_STATE_DELIVERED = 'delivered';

    public const SLICE_STATE_BLOCKED = 'blocked';

    private const SLICE_STATES = [
        self::SLICE_STATE_PLANNED,
        self::SLICE_STATE_IN_PROGRESS,
        self::SLICE_STATE_DELIVERED,
        self::SLICE_STATE_BLOCKED,
    ];

    public const PROVIDER_PROOF_BASIS_PROVIDER_CALL = 'provider_call_count';

    public const PROVIDER_PROOF_BASIS_ROUTER_DIFF = 'router_real_diff';

    public const PROVIDER_PROOF_BASIS_NONE = 'none';

    public const ACCEPTANCE_BASIS_PASSED = 'validation_passed_with_evidence';

    public const ACCEPTANCE_BASIS_RECONCILED_VALIDATION = 'reconciled_validation_passed_with_evidence';

    public const ACCEPTANCE_BASIS_SUPERVISED_EXISTING_DELIVERY = 'supervised_existing_delivery_validation_passed';

    public const ACCEPTANCE_BASIS_PENDING = 'operator_acceptance_pending';

    public const ACCEPTANCE_BASIS_FAILED = 'validation_failed';

    public const BLOCKER_PROVIDER_CALL_UNAVAILABLE = 'provider_call_count_unavailable';

    /** Finding 7: a slice last delivered under a stale plan_hash must re-prove, never inherit. */
    public const BLOCKER_PLAN_DRIFT_STALE_SLICE = 'plan_drift_stale_slice';

    /** Finding 9: a slice with 3+ consecutive non-delivered events is stuck (anti-inertia). */
    public const BLOCKER_SLICE_STUCK = 'slice_stuck';

    /** A blocked slice below the stuck threshold may be retried after runtime/scope repair. */
    public const BLOCKER_RETRYABLE_BLOCKED_SLICE = 'slice_retryable_blocked';

    /**
     * A later no-proof/no-merge block must not reopen a slice that already reached
     * provider-proof merge evidence. It needs acceptance/main reconciliation, not
     * another provider spend on the same slice.
     */
    public const BLOCKER_PROVIDER_PROOF_RECONCILIATION_REQUIRED = 'provider_proof_reconciliation_required';

    /** Historical executable `*Contract.php` false positives are re-admitted after gate repair. */
    public const BLOCKER_EXECUTABLE_CONTRACT_FALSE_POSITIVE_REHABILITATED = 'executable_contract_false_positive_rehabilitated';

    /**
     * Historical plan-only/pre-provider events did not persist enough blocker detail to
     * distinguish a real non-retryable slice from a supervisor-fixed preflight bug. Future
     * events are stamped with this version and still count toward stuck protection.
     */
    public const STUCK_POLICY_VERSION = 'post_pre_provider_rehab_v1';

    /** Legacy no-provider/no-merge events were ignored for stuck-counting and can retry. */
    public const BLOCKER_LEGACY_PRE_PROVIDER_ATTEMPTS_REHABILITATED = 'legacy_pre_provider_attempts_rehabilitated';

    private const STUCK_THRESHOLD = 3;

    private ?AutonomousLoopReceiptIntegrityService $receiptIntegrity = null;

    private ?string $storageRootOverride = null;

    public function setReceiptIntegrityForTesting(?AutonomousLoopReceiptIntegrityService $s): void
    {
        $this->receiptIntegrity = $s;
    }

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
    }

    public function storageRootOverride(): ?string
    {
        return $this->storageRootOverride;
    }

    private function receiptIntegrity(): AutonomousLoopReceiptIntegrityService
    {
        return $this->receiptIntegrity ??= new AutonomousLoopReceiptIntegrityService;
    }

    /**
     * Record one RAW loop cycle against a decomposed plan. Composes the canonical receipt,
     * derives provider_proof + acceptance_met deterministically, joins the receipt's
     * selected_finding.finding_id to a slice_id, appends a JSONL event, and returns the
     * updated plan_completion_ledger contract.
     *
     * @param  array{decomposed_plan:array<string,mixed>,area_id:string,cycle:array<string,mixed>}  $input
     * @return array<string,mixed>
     */
    public function recordCycle(array $input): array
    {
        $plan = is_array($input['decomposed_plan'] ?? null) ? $input['decomposed_plan'] : [];
        $areaId = AreaFocusSlugNormalizer::lowerSnakeTokenOrFallback((string) ($input['area_id'] ?? ''), 'agentic_engineering_os');
        $cycle = is_array($input['cycle'] ?? null) ? $input['cycle'] : [];

        $planId = (string) ($plan['plan_id'] ?? '');
        $slices = PlanSliceReadModel::planSlices($plan);

        // Compose the canonical receipt (never modify the integrity service).
        $receipt = $this->receiptIntegrity()->receiptFor($cycle);

        $findingId = (string) data_get($receipt, 'selected_finding.finding_id', '');
        $sliceId = $this->joinSliceId($slices, $findingId);

        $warnings = [];
        if ($sliceId === null) {
            // Unmatched finding_id: NEVER fabricate a slice; append no advance, return a warning.
            $warnings[] = 'finding_id_unmatched_no_slice_advance';

            $ledger = $this->rollup($planId, $areaId, $plan);
            $ledger['warnings'] = $warnings;
            $ledger['unmatched_finding_id'] = $findingId;

            return $ledger;
        }

        // ---- Derive provider_proof (NEVER from caller input) ----
        $lifecycleState = (string) ($receipt['lifecycle_state'] ?? '');
        $mergeHashRaw = (string) ($receipt['merge_hash'] ?? '');
        $changedFiles = AreaFocusStringListNormalizer::coercedStringValues($receipt['changed_files'] ?? []);
        $routerUsed = (bool) ($receipt['provider_router_used'] ?? false);

        $providerProof = $lifecycleState === AutonomousLoopReceiptIntegrityService::STATE_MERGED
            && $mergeHashRaw !== ''
            && $changedFiles !== []
            && $routerUsed === false;

        $providerCalls = (int) (
            data_get($cycle, 'owner_result.runtime_invocation.command_result.owner_cli_provider_calls')
            ?? data_get($cycle, 'runtime_invocation.command_result.owner_cli_provider_calls')
            // The plan-execution path returns the SESSION cycle, which surfaces the real
            // per-cycle provider-call count under owner_flow.execution_result (populated by
            // ownerFlowSummary from the same owner_result telemetry). Read it so a genuinely
            // provider-backed merge is not falsely flagged provider_call_count_unavailable.
            ?? data_get($cycle, 'owner_flow.execution_result.owner_cli_provider_calls')
            ?? 0
        );

        $providerCallUnavailable = false;
        if (! $providerProof) {
            $providerProofBasis = self::PROVIDER_PROOF_BASIS_NONE;
        } elseif ($providerCalls > 0) {
            $providerProofBasis = self::PROVIDER_PROOF_BASIS_PROVIDER_CALL;
        } else {
            // Best available real signal: a real diff with router_used=false, but the provider
            // call count was not readable. Name the honesty gap, never hide it.
            $providerProofBasis = self::PROVIDER_PROOF_BASIS_ROUTER_DIFF;
            $providerCallUnavailable = true;
        }

        // ---- Derive acceptance_met (NEVER from caller input) ----
        $validationPassed = data_get($receipt, 'validation.passed') === true;
        $evidenceRefs = $this->evidenceRefsList($receipt);

        $acceptanceMet = $validationPassed && $evidenceRefs !== [];
        // validation.passed is the HARD FLOOR; free-text Aceite is surfaced, not auto-checked.
        $acceptanceBasis = data_get($receipt, 'validation.passed') === false
            ? self::ACCEPTANCE_BASIS_FAILED
            : self::ACCEPTANCE_BASIS_PENDING;

        $mergeHash = $mergeHashRaw !== '' ? $mergeHashRaw : null;

        // ---- Derive slice state (real-or-blocked) ----
        $state = $this->deriveState($lifecycleState, $providerProof, $mergeHash, $acceptanceMet);

        $event = [
            'schema_version' => self::EVENT_SCHEMA,
            'plan_id' => $planId,
            // Finding 7: stamp the CURRENT plan_hash so a future plan revision can detect that
            // this delivery proof belongs to an older plan version and must be re-proven.
            'plan_hash' => $this->currentPlanHash($planId, $plan),
            'slice_id' => $sliceId,
            'state' => $state,
            'finding_id' => $findingId !== '' ? $findingId : null,
            'cycle_id' => ($cid = (string) ($receipt['cycle_id'] ?? data_get($cycle, 'cycle_id', ''))) !== '' ? $cid : null,
            'merge_hash' => $mergeHash,
            'provider_proof' => $providerProof,
            'provider_proof_basis' => $providerProofBasis,
            'acceptance_met' => $acceptanceMet,
            'acceptance_basis' => $acceptanceBasis,
            'evidence_refs' => $evidenceRefs,
            'stuck_policy_version' => self::STUCK_POLICY_VERSION,
            'recorded_at' => AreaFocusUtcClock::atomNow(),
        ];
        $event['event_hash'] = 'sha256:'.MissionCanonicalHash::sha256(AreaFocusLoopPayloadNormalizer::withoutVolatileEventFields($event));

        AreaFocusAppendOnlyJsonlRecorder::append($this->ledgerPath($planId, $areaId), $event);

        $ledger = $this->rollup($planId, $areaId, $plan);
        $ledger['warnings'] = $warnings;
        if ($providerCallUnavailable && ! in_array(self::BLOCKER_PROVIDER_CALL_UNAVAILABLE, $ledger['blockers'], true)) {
            $ledger['blockers'][] = self::BLOCKER_PROVIDER_CALL_UNAVAILABLE;
            $ledger['blockers'] = AreaFocusStringListNormalizer::uniqueStringValues($ledger['blockers']);
        }

        return $ledger;
    }

    /**
     * Reconcile a slice that already has provider-proof merge evidence but was recorded
     * before validation evidence was available/readable. This does not invoke a provider,
     * does not merge, and does not weaken the provider-proof floor: it can only append a
     * delivered event when an earlier event for the same slice has provider_proof+merge_hash
     * and the caller supplies a fresh passed validation result.
     *
     * @param  array{
     *     decomposed_plan:array<string,mixed>,
     *     area_id:string,
     *     slice_id:string,
     *     validation:array<string,mixed>
     * }  $input
     * @return array<string,mixed>
     */
    public function recordProviderProofReconciliation(array $input): array
    {
        $plan = is_array($input['decomposed_plan'] ?? null) ? $input['decomposed_plan'] : [];
        $areaId = AreaFocusSlugNormalizer::lowerSnakeTokenOrFallback((string) ($input['area_id'] ?? ''), 'agentic_engineering_os');
        $sliceId = (string) ($input['slice_id'] ?? '');
        $validation = is_array($input['validation'] ?? null) ? $input['validation'] : [];
        $planId = (string) ($plan['plan_id'] ?? '');

        $ledger = $this->rollup($planId, $areaId, $plan);
        $warnings = [];

        if ($planId === '' || $sliceId === '') {
            $ledger['warnings'] = ['provider_proof_reconciliation_missing_plan_or_slice'];

            return $ledger;
        }

        $slices = PlanSliceReadModel::planSlices($plan);
        $sliceExists = false;
        foreach ($slices as $slice) {
            if ((string) ($slice['slice_id'] ?? '') === $sliceId) {
                $sliceExists = true;
                break;
            }
        }
        if (! $sliceExists) {
            $ledger['warnings'] = ['provider_proof_reconciliation_slice_not_in_plan:'.$sliceId];

            return $ledger;
        }

        $prior = $this->latestProviderProofMergeEvent($planId, $areaId, $sliceId);
        if ($prior === null) {
            $ledger['warnings'] = ['provider_proof_reconciliation_missing_prior_provider_merge:'.$sliceId];

            return $ledger;
        }

        $currentPlanHash = $this->currentPlanHash($planId, $plan);
        if ($this->isStaleSlice($prior, $currentPlanHash)) {
            $ledger['warnings'] = ['provider_proof_reconciliation_prior_plan_hash_stale:'.$sliceId];

            return $ledger;
        }

        if (($validation['passed'] ?? null) !== true) {
            $ledger['warnings'] = ['provider_proof_reconciliation_validation_not_passed:'.$sliceId];

            return $ledger;
        }

        $evidenceRefs = AreaFocusStringListNormalizer::uniqueMergedStringValues(
            AreaFocusStringListNormalizer::preserveStrings($prior['evidence_refs'] ?? []),
            AreaFocusStringListNormalizer::preserveStrings($validation['evidence_refs'] ?? []),
        );
        if ($evidenceRefs === []) {
            $ledger['warnings'] = ['provider_proof_reconciliation_missing_evidence_refs:'.$sliceId];

            return $ledger;
        }

        $cycleId = 'plan_reconcile_'.$sliceId.'_'.substr(MissionCanonicalHash::sha256([
            'plan_id' => $planId,
            'slice_id' => $sliceId,
            'prior_cycle_id' => (string) ($prior['cycle_id'] ?? ''),
            'prior_merge_hash' => (string) ($prior['merge_hash'] ?? ''),
            'validation_commands' => AreaFocusStringListNormalizer::preserveStrings($validation['commands'] ?? []),
        ]), 0, 12);

        $event = [
            'schema_version' => self::EVENT_SCHEMA,
            'plan_id' => $planId,
            'plan_hash' => $currentPlanHash,
            'slice_id' => $sliceId,
            'state' => self::SLICE_STATE_DELIVERED,
            'finding_id' => AreaFocusScalarNormalizer::nullableString($prior['finding_id'] ?? null) ?? $sliceId,
            'cycle_id' => $cycleId,
            'merge_hash' => AreaFocusScalarNormalizer::nullableString($prior['merge_hash'] ?? null),
            'provider_proof' => true,
            'provider_proof_basis' => (string) ($prior['provider_proof_basis'] ?? self::PROVIDER_PROOF_BASIS_PROVIDER_CALL),
            'acceptance_met' => true,
            'acceptance_basis' => self::ACCEPTANCE_BASIS_RECONCILED_VALIDATION,
            'evidence_refs' => $evidenceRefs,
            'stuck_policy_version' => self::STUCK_POLICY_VERSION,
            'reconciliation' => [
                'schema_version' => 'atlas.plan_execution.provider_proof_validation_reconciliation.v1',
                'prior_cycle_id' => AreaFocusScalarNormalizer::nullableString($prior['cycle_id'] ?? null),
                'prior_merge_hash' => AreaFocusScalarNormalizer::nullableString($prior['merge_hash'] ?? null),
                'validation_passed' => true,
                'validation_commands' => AreaFocusStringListNormalizer::preserveStrings($validation['commands'] ?? []),
            ],
            'recorded_at' => AreaFocusUtcClock::atomNow(),
        ];
        $event['event_hash'] = 'sha256:'.MissionCanonicalHash::sha256(AreaFocusLoopPayloadNormalizer::withoutVolatileEventFields($event));

        AreaFocusAppendOnlyJsonlRecorder::append($this->ledgerPath($planId, $areaId), $event);

        $ledger = $this->rollup($planId, $areaId, $plan);
        $ledger['warnings'] = $warnings;

        return $ledger;
    }

    /**
     * Reconcile a slice whose implementation already exists in the current repo
     * and validates, but was not delivered by a provider-backed AP-786 merge. This
     * is intentionally NOT provider proof and must be reported separately from
     * autonomous loop delivery. It prevents provider re-spend on supervisor-salvaged
     * code while preserving the audit truth in the event payload.
     *
     * @param  array{
     *     decomposed_plan:array<string,mixed>,
     *     area_id:string,
     *     slice_id:string,
     *     validation:array<string,mixed>,
     *     commit_hash?:string,
     *     evidence_refs?:list<string>
     * }  $input
     * @return array<string,mixed>
     */
    public function recordSupervisedExistingDelivery(array $input): array
    {
        $plan = is_array($input['decomposed_plan'] ?? null) ? $input['decomposed_plan'] : [];
        $areaId = AreaFocusSlugNormalizer::lowerSnakeTokenOrFallback((string) ($input['area_id'] ?? ''), 'agentic_engineering_os');
        $sliceId = (string) ($input['slice_id'] ?? '');
        $validation = is_array($input['validation'] ?? null) ? $input['validation'] : [];
        $planId = (string) ($plan['plan_id'] ?? '');

        $ledger = $this->rollup($planId, $areaId, $plan);
        if ($planId === '' || $sliceId === '') {
            $ledger['warnings'] = ['supervised_existing_delivery_missing_plan_or_slice'];

            return $ledger;
        }

        $sliceExists = false;
        foreach (PlanSliceReadModel::planSlices($plan) as $slice) {
            if ((string) ($slice['slice_id'] ?? '') === $sliceId) {
                $sliceExists = true;
                break;
            }
        }
        if (! $sliceExists) {
            $ledger['warnings'] = ['supervised_existing_delivery_slice_not_in_plan:'.$sliceId];

            return $ledger;
        }

        if (($validation['passed'] ?? null) !== true) {
            $ledger['warnings'] = ['supervised_existing_delivery_validation_not_passed:'.$sliceId];

            return $ledger;
        }

        $evidenceRefs = AreaFocusStringListNormalizer::uniqueMergedStringValues(
            AreaFocusStringListNormalizer::preserveStrings($input['evidence_refs'] ?? []),
            AreaFocusStringListNormalizer::preserveStrings($validation['evidence_refs'] ?? []),
        );
        if ($evidenceRefs === []) {
            $ledger['warnings'] = ['supervised_existing_delivery_missing_evidence_refs:'.$sliceId];

            return $ledger;
        }

        $commitHash = AreaFocusScalarNormalizer::nullableString($input['commit_hash'] ?? null);
        $cycleId = 'plan_supervised_existing_'.$sliceId.'_'.substr(MissionCanonicalHash::sha256([
            'plan_id' => $planId,
            'slice_id' => $sliceId,
            'commit_hash' => $commitHash,
            'validation_commands' => AreaFocusStringListNormalizer::preserveStrings($validation['commands'] ?? []),
        ]), 0, 12);

        $event = [
            'schema_version' => self::EVENT_SCHEMA,
            'plan_id' => $planId,
            'plan_hash' => $this->currentPlanHash($planId, $plan),
            'slice_id' => $sliceId,
            'state' => self::SLICE_STATE_DELIVERED,
            'finding_id' => $sliceId,
            'cycle_id' => $cycleId,
            'merge_hash' => $commitHash,
            'provider_proof' => false,
            'provider_proof_basis' => self::PROVIDER_PROOF_BASIS_NONE,
            'acceptance_met' => true,
            'acceptance_basis' => self::ACCEPTANCE_BASIS_SUPERVISED_EXISTING_DELIVERY,
            'evidence_refs' => $evidenceRefs,
            'stuck_policy_version' => self::STUCK_POLICY_VERSION,
            'autonomous_delivery' => false,
            'delivery_authority' => 'supervisor_existing_delivery',
            'supervised_reconciliation' => [
                'schema_version' => 'atlas.plan_execution.supervised_existing_delivery_reconciliation.v1',
                'commit_hash' => $commitHash,
                'validation_passed' => true,
                'validation_commands' => AreaFocusStringListNormalizer::preserveStrings($validation['commands'] ?? []),
            ],
            'recorded_at' => AreaFocusUtcClock::atomNow(),
        ];
        $event['event_hash'] = 'sha256:'.MissionCanonicalHash::sha256(AreaFocusLoopPayloadNormalizer::withoutVolatileEventFields($event));

        AreaFocusAppendOnlyJsonlRecorder::append($this->ledgerPath($planId, $areaId), $event);

        $ledger = $this->rollup($planId, $areaId, $plan);
        $ledger['warnings'] = [];

        return $ledger;
    }

    /**
     * Pure read of the JSONL events for a plan/area, projected against the decomposed plan's
     * slices. Latest event per slice_id wins. Returns the plan_completion_ledger contract with
     * an honest completion_pct.
     *
     * @param  array<string,mixed>  $decomposedPlan
     * @return array<string,mixed>
     */
    public function rollup(string $planId, string $areaId, array $decomposedPlan): array
    {
        $areaId = AreaFocusSlugNormalizer::lowerSnakeTokenOrFallback($areaId, 'agentic_engineering_os');
        $slices = PlanSliceReadModel::planSlices($decomposedPlan);

        $path = $this->ledgerPath($planId, $areaId);
        [$events, $corrupted] = AreaFocusJsonlReader::rowsWithStringKey($path, 'slice_id');

        $currentPlanHash = $this->currentPlanHash($planId, $decomposedPlan);

        // Latest event per slice id (append-only history => last wins; idempotent replay).
        // Finding 9: while folding the (already-read) history, also count per-slice attempts and
        // the consecutive non-delivered streak. Counts ONLY — never retain event blobs (I7 bounded).
        $latest = [];
        $slicesById = [];
        foreach ($slices as $slice) {
            $slicesById[(string) $slice['slice_id']] = $slice;
        }
        $attemptCount = [];
        $consecutiveNonDelivered = [];
        $ignoredLegacyPreProviderAttempts = [];
        $ignoredExecutableContractFalsePositiveAttempts = [];
        $executableContractFalsePositiveSeen = [];
        $providerProofMergeSeen = [];
        foreach ($events as $event) {
            $sid = (string) ($event['slice_id'] ?? '');
            if ($sid === '') {
                continue;
            }
            if ($this->eventHasProviderProofMerge($event)) {
                $providerProofMergeSeen[$sid] = true;
            }
            $latest[$sid] = $event;
            if ($this->isLegacyPreProviderNoProofEvent($event)) {
                $ignoredLegacyPreProviderAttempts[$sid] = ($ignoredLegacyPreProviderAttempts[$sid] ?? 0) + 1;

                continue;
            }
            $slice = $slicesById[$sid] ?? [];
            if ($this->isExecutableContractFalsePositiveEvent($event, $slice, $executableContractFalsePositiveSeen[$sid] ?? false)) {
                $ignoredExecutableContractFalsePositiveAttempts[$sid] = ($ignoredExecutableContractFalsePositiveAttempts[$sid] ?? 0) + 1;
                $executableContractFalsePositiveSeen[$sid] = true;

                continue;
            }
            $attemptCount[$sid] = ($attemptCount[$sid] ?? 0) + 1;
            $eventState = AreaFocusScalarNormalizer::lowerChoice((string) ($event['state'] ?? ''), self::SLICE_STATES, self::SLICE_STATE_PLANNED);
            if ($eventState === self::SLICE_STATE_DELIVERED) {
                $consecutiveNonDelivered[$sid] = 0;
            } else {
                $consecutiveNonDelivered[$sid] = ($consecutiveNonDelivered[$sid] ?? 0) + 1;
            }
        }

        $blockers = [];
        if ($corrupted > 0) {
            $blockers[] = 'corrupted_ledger_lines_skipped';
        }

        // First pass: compute the delivered set so dependency_satisfied can be evaluated.
        // A slice whose latest event carries a stale plan_hash is NOT delivered here (Finding 7):
        // it must be re-proven under the current plan, never inherited as a dependency satisfier.
        $deliveredSet = [];
        foreach ($slices as $slice) {
            $sid = (string) $slice['slice_id'];
            $event = $latest[$sid] ?? null;
            if ($event !== null
                && AreaFocusScalarNormalizer::lowerChoice((string) ($event['state'] ?? ''), self::SLICE_STATES, self::SLICE_STATE_PLANNED) === self::SLICE_STATE_DELIVERED
                && ! $this->isStaleSlice($event, $currentPlanHash)) {
                $deliveredSet[$sid] = true;
            }
        }

        $sliceStates = [];
        $statusCounts = array_fill_keys(self::SLICE_STATES, 0);
        $deliveredCount = 0;
        $allDependenciesSatisfied = true;

        foreach ($slices as $slice) {
            $sid = (string) $slice['slice_id'];
            $dependsOn = AreaFocusStringListNormalizer::preserveStrings($slice['depends_on'] ?? []);
            $dependencySatisfied = PlanSliceReadModel::dependenciesSatisfied($dependsOn, $deliveredSet);

            $event = $latest[$sid] ?? null;
            if ($event === null) {
                $row = $this->plannedRow($sid, $dependsOn, $dependencySatisfied);
            } else {
                $state = AreaFocusScalarNormalizer::lowerChoice((string) ($event['state'] ?? self::SLICE_STATE_PLANNED), self::SLICE_STATES, self::SLICE_STATE_PLANNED);

                // Finding 7: a slice last delivered under a stale plan_hash is downgraded to
                // in_progress and a precise blocker is raised. Re-prove, never inherit.
                if ($state === self::SLICE_STATE_DELIVERED && $this->isStaleSlice($event, $currentPlanHash)) {
                    $state = self::SLICE_STATE_IN_PROGRESS;
                    unset($deliveredSet[$sid]);
                    $driftBlocker = self::BLOCKER_PLAN_DRIFT_STALE_SLICE.':'.$sid;
                    if (! in_array($driftBlocker, $blockers, true)) {
                        $blockers[] = $driftBlocker;
                    }
                }

                // Legacy plan-only/pre-provider failures happened before the tracker stamped
                // enough policy detail to make the stuck decision durable. Keep the evidence,
                // but project it as retryable so a fixed preflight/runtime can re-attempt once.
                if ($state !== self::SLICE_STATE_DELIVERED && $this->isLegacyPreProviderNoProofEvent($event)) {
                    $state = self::SLICE_STATE_IN_PROGRESS;
                    $rehabBlocker = self::BLOCKER_LEGACY_PRE_PROVIDER_ATTEMPTS_REHABILITATED.':'.$sid;
                    if (! in_array($rehabBlocker, $blockers, true)) {
                        $blockers[] = $rehabBlocker;
                    }
                }
                if ($state !== self::SLICE_STATE_DELIVERED
                    && $this->isExecutableContractFalsePositiveEvent($event, $slice, $executableContractFalsePositiveSeen[$sid] ?? false)) {
                    $state = self::SLICE_STATE_IN_PROGRESS;
                    $rehabBlocker = self::BLOCKER_EXECUTABLE_CONTRACT_FALSE_POSITIVE_REHABILITATED.':'.$sid;
                    if (! in_array($rehabBlocker, $blockers, true)) {
                        $blockers[] = $rehabBlocker;
                    }
                }

                // Honest dependency gate: a slice that merged out of order (predecessors not all
                // delivered) is NOT counted as delivered for the plan; it stays in_progress.
                if ($state === self::SLICE_STATE_DELIVERED && ! $dependencySatisfied) {
                    $state = self::SLICE_STATE_IN_PROGRESS;
                    unset($deliveredSet[$sid]);
                }

                // A single blocked attempt is not a terminal truth about a slice. It can be the
                // runner/decomposer/preflight bug that a supervisor just fixed. Keep append-only
                // honesty, but project the slice as retryable until the bounded stuck threshold.
                $sliceAttemptsForState = $attemptCount[$sid] ?? 0;
                if ($state === self::SLICE_STATE_BLOCKED && $sliceAttemptsForState < self::STUCK_THRESHOLD) {
                    if (($providerProofMergeSeen[$sid] ?? false) === true) {
                        $reconciliationBlocker = self::BLOCKER_PROVIDER_PROOF_RECONCILIATION_REQUIRED.':'.$sid;
                        if (! in_array($reconciliationBlocker, $blockers, true)) {
                            $blockers[] = $reconciliationBlocker;
                        }
                    } else {
                        $state = self::SLICE_STATE_IN_PROGRESS;
                        $retryableBlocker = self::BLOCKER_RETRYABLE_BLOCKED_SLICE.':'.$sid;
                        if (! in_array($retryableBlocker, $blockers, true)) {
                            $blockers[] = $retryableBlocker;
                        }
                    }
                }

                $row = [
                    'slice_id' => $sid,
                    'state' => $state,
                    'merge_hash' => AreaFocusScalarNormalizer::nullableString($event['merge_hash'] ?? null),
                    'provider_proof' => (bool) ($event['provider_proof'] ?? false),
                    'provider_proof_basis' => (string) ($event['provider_proof_basis'] ?? self::PROVIDER_PROOF_BASIS_NONE),
                    'acceptance_met' => (bool) ($event['acceptance_met'] ?? false),
                    'acceptance_basis' => (string) ($event['acceptance_basis'] ?? self::ACCEPTANCE_BASIS_PENDING),
                    'finding_id' => AreaFocusScalarNormalizer::nullableString($event['finding_id'] ?? null),
                    'cycle_id' => AreaFocusScalarNormalizer::nullableString($event['cycle_id'] ?? null),
                    'evidence_refs' => AreaFocusStringListNormalizer::preserveStrings($event['evidence_refs'] ?? []),
                    'depends_on' => $dependsOn,
                    'dependency_satisfied' => $dependencySatisfied,
                    'last_event_at' => (string) ($event['recorded_at'] ?? ''),
                ];

                if (($row['provider_proof_basis'] === self::PROVIDER_PROOF_BASIS_ROUTER_DIFF)
                    && ! in_array(self::BLOCKER_PROVIDER_CALL_UNAVAILABLE, $blockers, true)) {
                    $blockers[] = self::BLOCKER_PROVIDER_CALL_UNAVAILABLE;
                }
            }

            // Finding 9: surface bounded counts on every row (no event blobs). For a slice whose
            // latest event was downgraded above, the latest attempt is still non-delivered, so the
            // streak counted over history holds.
            $sliceAttempts = $attemptCount[$sid] ?? 0;
            $sliceStreak = $row['state'] === self::SLICE_STATE_DELIVERED
                ? 0
                : ($consecutiveNonDelivered[$sid] ?? 0);
            $row['attempt_count'] = $sliceAttempts;
            $row['consecutive_non_delivered'] = $sliceStreak;
            $row['ignored_legacy_pre_provider_attempt_count'] = $ignoredLegacyPreProviderAttempts[$sid] ?? 0;
            $row['ignored_executable_contract_false_positive_attempt_count'] = $ignoredExecutableContractFalsePositiveAttempts[$sid] ?? 0;

            if ($sliceStreak >= self::STUCK_THRESHOLD) {
                $stuckBlocker = self::BLOCKER_SLICE_STUCK.':'.$sid;
                if (! in_array($stuckBlocker, $blockers, true)) {
                    $blockers[] = $stuckBlocker;
                }
            }

            $sliceStates[$sid] = $row;
            $statusCounts[$row['state']]++;
            if ($row['state'] === self::SLICE_STATE_DELIVERED) {
                $deliveredCount++;
            }
            if (! $row['dependency_satisfied']) {
                $allDependenciesSatisfied = false;
            }
        }

        $totalSlices = count($slices);
        $completionPct = $totalSlices > 0
            ? round($deliveredCount / $totalSlices * 100, 1)
            : 0.0;

        $status = $this->ledgerStatus($totalSlices, $deliveredCount, $allDependenciesSatisfied);

        return [
            'schema_version' => self::LEDGER_SCHEMA,
            'plan_id' => $planId,
            'area_id' => $areaId,
            'status' => $status,
            'total_slices' => $totalSlices,
            'slice_states' => $sliceStates,
            'status_counts' => [
                'planned' => $statusCounts[self::SLICE_STATE_PLANNED],
                'in_progress' => $statusCounts[self::SLICE_STATE_IN_PROGRESS],
                'delivered' => $statusCounts[self::SLICE_STATE_DELIVERED],
                'blocked' => $statusCounts[self::SLICE_STATE_BLOCKED],
            ],
            'delivered_count' => $deliveredCount,
            'completion_pct' => $completionPct,
            'ledger_path' => $path,
            'plan_hash' => $currentPlanHash,
            'blockers' => AreaFocusStringListNormalizer::uniqueStringValues($blockers),
        ];
    }

    // ------------------------------------------------------------------ derivation

    private function deriveState(
        string $lifecycleState,
        bool $providerProof,
        ?string $mergeHash,
        bool $acceptanceMet,
    ): string {
        if ($lifecycleState === AutonomousLoopReceiptIntegrityService::STATE_BLOCKED
            || $lifecycleState === AutonomousLoopReceiptIntegrityService::STATE_FAILED) {
            return self::SLICE_STATE_BLOCKED;
        }

        // delivered ONLY with all three honest gates true.
        if ($providerProof && $mergeHash !== null && $acceptanceMet) {
            return self::SLICE_STATE_DELIVERED;
        }

        // Real work happened (merged or planned) but the honest gates were not all met.
        return self::SLICE_STATE_IN_PROGRESS;
    }

    /** @param array<string,mixed> $event */
    private function eventHasProviderProofMerge(array $event): bool
    {
        return (bool) ($event['provider_proof'] ?? false)
            && AreaFocusScalarNormalizer::nullableString($event['merge_hash'] ?? null) !== null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function latestProviderProofMergeEvent(string $planId, string $areaId, string $sliceId): ?array
    {
        [$events] = AreaFocusJsonlReader::rowsWithStringKey($this->ledgerPath($planId, $areaId), 'slice_id');
        $latest = null;
        foreach ($events as $event) {
            if ((string) ($event['slice_id'] ?? '') !== $sliceId) {
                continue;
            }
            if (! $this->eventHasProviderProofMerge($event)) {
                continue;
            }
            $latest = $event;
        }

        return $latest;
    }

    private function ledgerStatus(int $totalSlices, int $deliveredCount, bool $allDependenciesSatisfied): string
    {
        if ($totalSlices === 0) {
            return self::STATUS_BLOCKED;
        }
        if ($deliveredCount === $totalSlices && $allDependenciesSatisfied) {
            return self::STATUS_READY;
        }

        return self::STATUS_PARTIAL;
    }

    /**
     * @param  list<string>  $dependsOn
     * @return array<string,mixed>
     */
    private function plannedRow(string $sliceId, array $dependsOn, bool $dependencySatisfied): array
    {
        return [
            'slice_id' => $sliceId,
            'state' => self::SLICE_STATE_PLANNED,
            'merge_hash' => null,
            'provider_proof' => false,
            'provider_proof_basis' => self::PROVIDER_PROOF_BASIS_NONE,
            'acceptance_met' => false,
            'acceptance_basis' => self::ACCEPTANCE_BASIS_PENDING,
            'finding_id' => null,
            'cycle_id' => null,
            'evidence_refs' => [],
            'depends_on' => $dependsOn,
            'dependency_satisfied' => $dependencySatisfied,
            'last_event_at' => '',
            'attempt_count' => 0,
            'consecutive_non_delivered' => 0,
        ];
    }

    /**
     * Current canonical plan_hash for a plan. Prefers the plan-supplied plan_hash; otherwise
     * derives a deterministic hash from plan_id + ordered slice ids via MissionCanonicalHash.
     * Single source so recordCycle stamps EXACTLY what rollup compares against (Finding 7).
     *
     * @param  array<string,mixed>  $plan
     */
    private function currentPlanHash(string $planId, array $plan): string
    {
        $planHash = (string) ($plan['plan_hash'] ?? '');
        if ($planHash !== '') {
            return $planHash;
        }

        return 'sha256:'.MissionCanonicalHash::sha256([$planId, array_map(
            static fn (array $s): string => (string) $s['slice_id'],
            PlanSliceReadModel::planSlices($plan),
        )]);
    }

    /**
     * Finding 7: a slice event is stale when it carries a plan_hash that differs from the current
     * plan_hash. Events recorded before plan_hash stamping (no field) are NOT treated as stale —
     * absence is not drift — preserving back-compat with pre-existing ledgers.
     *
     * @param  array<string,mixed>  $event
     */
    private function isStaleSlice(array $event, string $currentPlanHash): bool
    {
        if ($currentPlanHash === '') {
            return false;
        }
        $eventPlanHash = (string) ($event['plan_hash'] ?? '');
        if ($eventPlanHash === '') {
            return false;
        }

        return $eventPlanHash !== $currentPlanHash;
    }

    /**
     * @param  array<string,mixed>  $event
     */
    private function isLegacyPreProviderNoProofEvent(array $event): bool
    {
        if ((string) ($event['stuck_policy_version'] ?? '') !== '') {
            return false;
        }

        $state = AreaFocusScalarNormalizer::lowerChoice((string) ($event['state'] ?? ''), self::SLICE_STATES, self::SLICE_STATE_PLANNED);
        if (! in_array($state, [self::SLICE_STATE_BLOCKED, self::SLICE_STATE_IN_PROGRESS], true)) {
            return false;
        }

        return (bool) ($event['provider_proof'] ?? false) === false
            && (string) ($event['provider_proof_basis'] ?? self::PROVIDER_PROOF_BASIS_NONE) === self::PROVIDER_PROOF_BASIS_NONE
            && AreaFocusScalarNormalizer::nullableString($event['merge_hash'] ?? null) === null
            && (bool) ($event['acceptance_met'] ?? false) === false
            && AreaFocusStringListNormalizer::preserveStrings($event['evidence_refs'] ?? []) === [];
    }

    /**
     * @param  array<string,mixed>  $event
     * @param  array<string,mixed>  $slice
     */
    private function isExecutableContractFalsePositiveEvent(array $event, array $slice, bool $priorFalsePositiveSeen = false): bool
    {
        if (! $this->sliceLooksExecutableContractOnly($slice)) {
            return false;
        }

        $state = AreaFocusScalarNormalizer::lowerChoice((string) ($event['state'] ?? ''), self::SLICE_STATES, self::SLICE_STATE_PLANNED);
        if (! in_array($state, [self::SLICE_STATE_BLOCKED, self::SLICE_STATE_IN_PROGRESS], true)) {
            return false;
        }
        if ((bool) ($event['provider_proof'] ?? false) !== false
            || (string) ($event['provider_proof_basis'] ?? self::PROVIDER_PROOF_BASIS_NONE) !== self::PROVIDER_PROOF_BASIS_NONE
            || AreaFocusScalarNormalizer::nullableString($event['merge_hash'] ?? null) !== null
            || (bool) ($event['acceptance_met'] ?? false) !== false) {
            return false;
        }

        $hasEvidence = AreaFocusStringListNormalizer::preserveStrings($event['evidence_refs'] ?? []) !== [];

        return $hasEvidence || $priorFalsePositiveSeen;
    }

    /** @param array<string,mixed> $slice */
    private function sliceLooksExecutableContractOnly(array $slice): bool
    {
        $hasContractProduct = false;
        foreach (AreaFocusStringListNormalizer::preserveStrings($slice['allowed_files'] ?? []) as $file) {
            $path = str_replace('\\', '/', $file);
            if ($path === '' || str_starts_with($path, 'tests/') || str_starts_with($path, 'test/') || str_ends_with($path, 'Test.php')) {
                continue;
            }
            if (! str_ends_with(basename($path), 'Contract.php')) {
                return false;
            }
            $hasContractProduct = true;
        }
        if (! $hasContractProduct) {
            return false;
        }

        $text = implode(' ', [
            (string) ($slice['objective'] ?? ''),
            (string) ($slice['delivery'] ?? ''),
            (string) data_get($slice, 'finding.title', ''),
            (string) data_get($slice, 'finding.detail', ''),
            implode(' ', AreaFocusStringListNormalizer::preserveStrings($slice['acceptance_criteria'] ?? [])),
        ]);
        foreach (['fromArray', 'toArray', 'defaults', 'score(', 'validate(', 'classify('] as $signal) {
            if (str_contains($text, $signal)) {
                return true;
            }
        }

        return false;
    }

    // ----------------------------------------------------------------- plan access

    /**
     * Join the receipt's selected_finding.finding_id to a slice's finding.finding_id.
     *
     * @param  list<array{slice_id:string,depends_on:list<string>,finding_id:string}>  $slices
     */
    private function joinSliceId(array $slices, string $findingId): ?string
    {
        if ($findingId === '') {
            return null;
        }
        foreach ($slices as $slice) {
            if ($slice['finding_id'] === $findingId) {
                return $slice['slice_id'];
            }
        }

        // The shared contract sets finding.finding_id EQUAL to slice_id, so also accept a
        // direct slice_id match as the unambiguous join key.
        foreach ($slices as $slice) {
            if ($slice['slice_id'] === $findingId) {
                return $slice['slice_id'];
            }
        }

        return null;
    }

    /**
     * The receipt's evidence_refs is an associative map; normalize to a list of non-empty strings.
     *
     * @param  array<string,mixed>  $receipt
     * @return list<string>
     */
    private function evidenceRefsList(array $receipt): array
    {
        $refs = $receipt['evidence_refs'] ?? [];
        if (! is_array($refs)) {
            return [];
        }

        return AreaFocusStringListNormalizer::preserveStrings(array_values($refs));
    }

    // ------------------------------------------------------------------- storage

    public function storageDir(string $planId, string $areaId): string
    {
        $base = $this->storageRootOverride
            ?? (function_exists('storage_path')
                ? storage_path('atlas/plan_execution/plan_completion')
                : sys_get_temp_dir().'/atlas/plan_execution/plan_completion');

        return $base.DIRECTORY_SEPARATOR.$areaId.DIRECTORY_SEPARATOR.AreaFocusSlugNormalizer::lowerSnakeTokenOrFallback($planId, 'plan');
    }

    public function ledgerPath(string $planId, string $areaId): string
    {
        return $this->storageDir($planId, AreaFocusSlugNormalizer::lowerSnakeTokenOrFallback($areaId, 'agentic_engineering_os'))
            .DIRECTORY_SEPARATOR.'completion_ledger.jsonl';
    }

    // ------------------------------------------------------------------- helpers

}

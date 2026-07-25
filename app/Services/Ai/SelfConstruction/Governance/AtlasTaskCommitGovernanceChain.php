<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Governance;

use App\Services\Ai\EngineeringKernel\AuthorizedMergeAction;
use App\Services\Ai\EngineeringKernel\CanonicalReleaseAuthorizationRequest;
use App\Services\Ai\EngineeringKernel\KernelEvidenceAuthority;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorAdmissionPolicy;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorReleaseDecisionLedger;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorRiskClassifier;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorRollbackPlanGate;
use App\Services\Ai\SelfConstruction\Support\CommitGovernancePureMappers;
use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtFalseGreenDetector;
use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtGateReplayPlan;
use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtVerdictLedger;
use Illuminate\Support\Str;
use Throwable;

/**
 * THE CONNECTIVE TISSUE (Lane 0 / spine) — composes the previously-ORPHANED governing organs over a worker's
 * about-to-land commit and RECORDS the verdict, so the Merge Governor and the Verification Court finally run
 * on real, live deliveries instead of sitting as standalone CLIs nobody calls.
 *
 * It sits in {@see AtlasTaskServingService::report()} between the Fase-2
 * server verification and the scoped commit, and chains four pure organs + two append-only ledgers:
 *   RiskClassifier (blast radius) → RollbackPlanGate (revertibility) → AdmissionPolicy (admit/reject/repair/block)
 *   → VerdictLedger (court record) + ReleaseDecisionLedger (governor record).
 *
 * GOVERNANCE MODE — the whole point of arming gradually ("armar aos poucos"):
 *   - off     : no-op, no ledger write (kill switch).
 *   - observe : DEFAULT. Runs every organ, RECORDS the verdict + release decision, but NEVER blocks the commit.
 *               This is what makes the bootstrap safe: the swarm building the government touches MergeGovernor /
 *               VerificationCourt / Task Fabric (which the RiskClassifier scores HIGH/blocked), so enforcing
 *               here would self-lock the very work that builds the governor. Observe proves the chain on real
 *               data without gating the bootstrap.
 *   - enforce : a non-`admitted` decision REFUSES the commit (caller keeps the lease, worker fixes & re-reports).
 *               Flip to this only when the operator arms the autonomous regime.
 *
 * FAIL-OPEN ABSOLUTE: any error inside this chain returns an admitting, non-blocking envelope. The hard lesson
 * from the Fase-2 `--without-tty` regression stands — governance can only ever ADD a recorded verdict; it must
 * NEVER become a new jam vector that wedges a good worker.
 */
final class AtlasTaskCommitGovernanceChain
{
    public const SCHEMA = 'atlas.task_serving.commit_governance.v1';

    public const MODE_OFF = 'off';

    public const MODE_OBSERVE = 'observe';

    public const MODE_ENFORCE = 'enforce';

    /** @var callable():string */
    private $clock;

    public function __construct(
        private readonly ?AtlasMergeGovernorRiskClassifier $riskClassifier = null,
        private readonly ?AtlasMergeGovernorRollbackPlanGate $rollbackGate = null,
        private readonly ?AtlasMergeGovernorAdmissionPolicy $admissionPolicy = null,
        private readonly ?AtlasVerificationCourtVerdictLedger $verdictLedger = null,
        private readonly ?AtlasMergeGovernorReleaseDecisionLedger $releaseLedger = null,
        ?callable $clock = null,
        private readonly ?string $modeOverride = null,
        private readonly ?AtlasVerificationCourtFalseGreenDetector $falseGreenDetector = null,
        private readonly ?AtlasTaskGovernancePolicyPlane $policyPlane = null,
        private readonly ?AtlasVerificationCourtGateReplayPlan $replayPlan = null,
        private readonly ?AtlasEvidenceLedger $evidenceLedger = null,
        private readonly ?KernelEvidenceAuthority $kernelEvidenceAuthority = null,
    ) {
        $this->clock = $clock ?? static fn (): string => now()->toIso8601String();
    }

    /**
     * Resolved governance mode (gradual arming). An explicit constructor override always wins (test/caller
     * injection); otherwise resolution goes through the {@see AtlasTaskGovernancePolicyPlane} — env override
     * beats the policy-plane's config-declared mode for $riskLevel, which beats the 'observe' default — so
     * the chain always RUNS and RECORDS but never blocks until the operator arms it.
     */
    public function mode(?string $riskLevel = null): string
    {
        if ($this->modeOverride !== null) {
            $raw = strtolower(trim($this->modeOverride));

            return in_array($raw, [self::MODE_OFF, self::MODE_OBSERVE, self::MODE_ENFORCE], true) ? $raw : self::MODE_OBSERVE;
        }

        return ($this->policyPlane ?? new AtlasTaskGovernancePolicyPlane)->modeFor($riskLevel ?? '');
    }

    /**
     * Govern one about-to-land commit. Pure orchestration over the organs + ledgers; never touches git.
     *
     * @param  array{
     *     task_packet_id?:string,
     *     project_id?:string,
     *     changed_files?:list<string>,
     *     verification?:array{passed?:bool, evidence_hash?:string, checks?:array<string,string>},
     *     budget_posture?:string
     * }  $context
     * @return array<string,mixed>
     */
    public function govern(array $context): array
    {
        $mode = $this->mode();
        if ($mode === self::MODE_OFF) {
            return $this->envelope($mode, true, false, 'skipped', '', [], ['verdict_ledger' => 'skipped', 'release_ledger' => 'skipped'], null, null);
        }

        try {
            $taskId = (string) ($context['task_packet_id'] ?? '');
            $projectId = (string) ($context['project_id'] ?? 'atlas-self-construction');
            $changed = CommitGovernancePureMappers::normalizeFiles((array) ($context['changed_files'] ?? []));
            $verification = is_array($context['verification'] ?? null) ? $context['verification'] : [];
            $budgetPosture = trim((string) ($context['budget_posture'] ?? ''));
            $serverGreen = (bool) ($verification['passed'] ?? false);
            $checks = is_array($verification['checks'] ?? null) ? $verification['checks'] : [];

            $organs = CommitGovernancePureMappers::touchedOrgans($changed);
            // Admission evidence must be explicit. The ledger still gets a stable
            // "unknown evidence" hash so blocked/repair decisions are auditable,
            // but that synthetic hash is never passed to AdmissionPolicy as proof.
            $admissionEvidenceHash = trim((string) ($verification['evidence_hash'] ?? ''));
            $ledgerEvidenceHash = $admissionEvidenceHash !== ''
                ? $admissionEvidenceHash
                : CommitGovernancePureMappers::deterministicHash(['unknown_evidence' => true, 'task_packet_id' => $taskId, 'changed' => $changed, 'checks' => $checks, 'green' => $serverGreen]);
            $evidenceRefs = $this->evidenceRefs($verification, $taskId, $ledgerEvidenceHash, $admissionEvidenceHash !== '');

            $scopeRoots = CommitGovernancePureMappers::scopeRoots($changed);
            $risk = ($this->riskClassifier ?? new AtlasMergeGovernorRiskClassifier)->classify([
                'changed_files' => $changed,
                'touched_organs' => $organs,
                'verification_result' => ['passed' => $serverGreen],
                'rollback_plan' => ['mode' => 'git_revert_scoped_commit'],
                'project_lane' => ['project_id' => $projectId, 'allowed_scope_roots' => $scopeRoots],
                'scope_deviations' => [],
                'task_evidence_ref' => $admissionEvidenceHash !== '' ? $admissionEvidenceHash : 'unknown_evidence',
            ]);

            $rollback = ($this->rollbackGate ?? new AtlasMergeGovernorRollbackPlanGate)->evaluate([
                'affected_files' => $changed,
                'restore_strategy' => 'git_revert_scoped_commit',
                'restore_target' => $taskId !== '' ? 'git_revert:'.$taskId : 'git_revert:HEAD',
                'pre_image_hash' => $ledgerEvidenceHash,
                'verification_command' => 'php artisan atlas:task test-suite',
                'verification_after_rollback' => ['php -l', 'artisan about', 'task tests'],
                'owner_scope' => $projectId,
                'project_lane' => ['project_id' => $projectId, 'allowed_scope_roots' => $scopeRoots],
            ]);

            // Required-rerun binding: resolved AFTER the risk classifier runs, since the required check
            // set is per-risk-level policy data. A required check that never ran, was skipped, or failed
            // lands in missing_rerun so the admission policy is no longer blind to a skipped re-run.
            // Policy plane I/O stays on the chain; pure comparison is CommitGovernancePureMappers.
            $missingRerun = CommitGovernancePureMappers::missingRerun(
                $this->missingRerunPolicyDeclaredGates((string) $risk['risk_level']),
                $checks,
            );
            $planHash = null;

            // High/critical replay-plan composition: the Verification Court's gate replay plan demands a
            // richer, risk-and-file-derived set of replay obligations (false-green-guard, receipt-quorum,
            // freshness-replay, worker-floor checks, ...) than the flat policy-declared required_checks
            // set. Low/medium risk NEVER reach this branch — their missing_rerun derivation stays exactly
            // the policy-only path above, byte-identical to before this change.
            if (in_array((string) $risk['risk_level'], ['high', 'critical'], true)) {
                $plan = ($this->replayPlan ?? new AtlasVerificationCourtGateReplayPlan)->derive([
                    'packet_facts' => ['declared_gates' => $this->missingRerunPolicyDeclaredGates((string) $risk['risk_level'])],
                    'evidence_contract_result' => ['accepted' => $serverGreen],
                    'changed_files' => $changed,
                    'risk_level' => (string) $risk['risk_level'],
                    'project_lane' => ['project_id' => $projectId, 'allowed_scope_roots' => $scopeRoots],
                ]);
                $planHash = CommitGovernancePureMappers::deterministicHash($plan);

                // Same leave-alone invariant as CommitGovernancePureMappers::missingRerun(): a demanded
                // gate that never appears in $checks at all was never observed to fail — flagging it would
                // retroactively tighten every caller that predates a full checks map. Only a gate that DID
                // run and reported anything other than 'pass' is a proven unmet obligation. Reuse the pure
                // comparator over the plan's command names that actually appear in $checks.
                $planRequired = [];
                foreach ((array) $plan['commands'] as $command) {
                    $name = (string) ($command['name'] ?? '');
                    if ($name !== '' && array_key_exists($name, $checks)) {
                        $planRequired[] = $name;
                    }
                }
                $unmetObligations = CommitGovernancePureMappers::missingRerun($planRequired, $checks);
                $missingRerun = array_values(array_unique([...$missingRerun, ...$unmetObligations]));
            }

            $admission = ($this->admissionPolicy ?? new AtlasMergeGovernorAdmissionPolicy)->decide([
                'project_id' => $projectId,
                'risk_classification' => ['risk_level' => (string) $risk['risk_level'], 'reasons' => (array) $risk['reasons']],
                'rollback_gate' => ['conformant' => (bool) $rollback['conformant'], 'blockers' => (array) $rollback['blockers']],
                'verification_court' => [
                    'server_side_green' => $serverGreen,
                    'evidence_hash' => $serverGreen ? $admissionEvidenceHash : '',
                    'missing_rerun' => $missingRerun,
                    'project_id' => $projectId,
                ],
                'release_window_policy' => ['allowed_risk_levels' => $this->releaseWindow()],
            ]);

            // Risk-level-aware refinement: once the packet's risk_level is known, the policy plane's
            // per-risk-level mode wins over the config-wide default (explicit override / env still absolute
            // since mode() checks those first — this can only ever narrow, never bypass, that precedence).
            $mode = $this->mode((string) $risk['risk_level']);

            $decision = (string) $admission['decision'];
            $blockers = array_values(array_map('strval', (array) $admission['blockers']));
            $admitted = $decision === AtlasMergeGovernorAdmissionPolicy::DECISION_ADMITTED;

            // Replay binding: bind the server-reported green to the planned/replayed command hashes so a
            // "passed=true" that never actually replayed its commands can't sail through as admitted.
            $replayVerdict = ($this->falseGreenDetector ?? new AtlasVerificationCourtFalseGreenDetector)->detect([
                'passed' => $serverGreen,
                'planned_commands' => (array) ($verification['planned_commands'] ?? []),
                'replay_results' => (array) ($verification['replay_results'] ?? []),
            ]);
            $falseGreenContradiction = $serverGreen && in_array($replayVerdict['verdict'], [
                AtlasVerificationCourtFalseGreenDetector::VERDICT_FAILED,
                AtlasVerificationCourtFalseGreenDetector::VERDICT_BLOCKED,
            ], true);

            if ($falseGreenContradiction) {
                $decision = 'false_green_replay_contradiction';
                $admitted = false;
                $blockers = array_values(array_unique([...$blockers, 'false_green_replay_contradiction', ...$replayVerdict['reasons']]));
            }

            $recorded = $this->record($taskId, $projectId, $decision, $blockers, $ledgerEvidenceHash, $risk, $rollback, $changed, $checks, $planHash, $evidenceRefs, $context);
            $ledgerBlockers = CommitGovernancePureMappers::ledgerErrorBlockers($recorded);
            if ($ledgerBlockers !== []) {
                return $this->envelope(
                    $mode,
                    false,
                    true,
                    'governance_ledger_error_fail_closed',
                    (string) ($risk['risk_level'] ?? ''),
                    array_values(array_unique([...$blockers, ...$ledgerBlockers])),
                    $recorded,
                    null,
                    $replayVerdict,
                    $planHash,
                    $missingRerun,
                    null,
                );
            }

            $enforcedBlock = $mode === self::MODE_ENFORCE && ! $admitted;
            $authority = $admitted ? $this->authorizedActionFromRecorded($recorded, $changed, $budgetPosture, $context) : null;

            return $this->envelope($mode, $admitted, $enforcedBlock, $decision, (string) $risk['risk_level'], $blockers, $recorded, null, $replayVerdict, $planHash, $missingRerun, $authority);
        } catch (Throwable $e) {
            // Failure posture depends on the resolved mode: observe/off can NEVER wedge a bootstrap
            // worker over a governance-internal bug (fail-open, admit, record nothing but the error) —
            // but enforce means the operator has armed the autonomous regime, and a crashing organ must
            // NOT silently wave every commit through. Enforce fails CLOSED: enforced_block=true, a
            // dedicated decision, and the exception class recorded in blockers so it's actionable.
            if ($mode === self::MODE_ENFORCE) {
                $exceptionClass = $e::class;
                $recorded = ['verdict_ledger' => 'error', 'release_ledger' => 'error'];
                try {
                    $taskId = (string) ($context['task_packet_id'] ?? '');
                    if ($taskId !== '') {
                        $recorded = $this->record(
                            $taskId,
                            (string) ($context['project_id'] ?? 'atlas-self-construction'),
                            'governance_error_fail_closed',
                            [$exceptionClass],
                            CommitGovernancePureMappers::deterministicHash(['unknown_evidence' => true, 'task_packet_id' => $taskId, 'error' => $exceptionClass]),
                            ['reasons' => []],
                            [],
                            (array) ($context['changed_files'] ?? []),
                            [],
                            null,
                            ['unknown:governance_error'],
                        );
                    }
                } catch (Throwable) {
                    // Best-effort only: a broken ledger must never mask the fail-closed decision itself.
                }

                return $this->envelope($mode, false, true, 'governance_error_fail_closed', '', [$exceptionClass], $recorded, $e->getMessage(), null);
            }

            // FAIL-OPEN: never wedge a worker because governance broke. Record nothing, admit, do not block.
            return $this->envelope($mode, true, false, 'fail_open_error', '', [], ['verdict_ledger' => 'error', 'release_ledger' => 'error'], $e->getMessage(), null);
        }
    }

    /**
     * Append the verdict (court) + release decision (governor). Each ledger failure is isolated and degrades to
     * a status string — a broken ledger never propagates out of the chain.
     *
     * @param  list<string>  $blockers
     * @param  array<string,mixed>  $risk
     * @param  array<string,mixed>  $rollback
     * @param  list<string>  $changed
     * @param  array<string,string>  $checks
     * @param  list<string>  $evidenceRefs
     * @return array{verdict_ledger:string, release_ledger:string}
     */
    private function record(string $taskId, string $projectId, string $decision, array $blockers, string $evidenceHash, array $risk, array $rollback, array $changed, array $checks, ?string $gateReplayPlanHash = null, array $evidenceRefs = [], array $prepareContext = []): array
    {
        if ($taskId === '') {
            return ['verdict_ledger' => 'skipped_no_task_id', 'release_ledger' => 'skipped_no_task_id'];
        }

        $decidedAt = ($this->clock)();
        $reasons = $blockers !== [] ? $blockers : (array) $risk['reasons'];
        $reasons = array_values(array_map('strval', $reasons));
        // Low/medium risk (gateReplayPlanHash=null) keeps this hash byte-identical to before the
        // replay-plan composition existed; high/critical folds the gate replay plan's hash in too,
        // so the recorded receipt proves WHICH replay obligations were demanded for this decision.
        $planHash = $gateReplayPlanHash !== null
            ? CommitGovernancePureMappers::deterministicHash(['risk' => $risk, 'rollback' => $rollback, 'gate_replay_plan_hash' => $gateReplayPlanHash])
            : CommitGovernancePureMappers::deterministicHash(['risk' => $risk, 'rollback' => $rollback]);
        $outcomeHash = CommitGovernancePureMappers::deterministicHash(['decision' => $decision, 'blockers' => $blockers, 'checks' => $checks]);
        $candidateHash = CommitGovernancePureMappers::deterministicHash(['changed' => $changed, 'evidence' => $evidenceHash]);
        $verificationHash = $evidenceHash !== '' ? $evidenceHash : $candidateHash;
        $rollbackHash = CommitGovernancePureMappers::deterministicHash($rollback);
        $prepareBinding = [
            'task_packet_id' => $taskId,
            'action' => 'commit',
            'candidate_hash' => $candidateHash,
            'verification_hash' => $verificationHash,
            'rollback_hash' => $rollbackHash,
            'changed_files' => $changed,
            'scope_hash' => CommitGovernancePureMappers::deterministicHash($changed),
            'base_commit' => trim((string) ($prepareContext['base_commit'] ?? '')),
            'tree_hash' => trim((string) ($prepareContext['tree_hash'] ?? '')),
            'lease_id' => trim((string) ($prepareContext['lease_id'] ?? '')),
            'lease_owner' => trim((string) ($prepareContext['lease_owner'] ?? '')),
            'fencing_token' => (int) ($prepareContext['fencing_token'] ?? 0),
        ];
        if (($prepareContext['requires_canary_settlement'] ?? false) === true) {
            $prepareBinding += [
                'order_hash' => trim((string) ($prepareContext['order_hash'] ?? '')),
                'delivery_id' => trim((string) ($prepareContext['delivery_id'] ?? '')),
                'evidence_hash' => trim((string) ($prepareContext['evidence_hash'] ?? '')),
            ];
        }

        $verdictStatus = 'error';
        try {
            $verdictStatus = (string) (($this->verdictLedger ?? $this->defaultVerdictLedger())->append([
                'task_packet_id' => $taskId,
                'evidence_hash' => $evidenceHash !== '' ? $evidenceHash : $candidateHash,
                'replay_plan_hash' => $planHash,
                'verdict' => CommitGovernancePureMappers::decisionToVerdict($decision),
                'reasons' => $reasons,
                'replay_outcome_hash' => $outcomeHash,
                'decided_at' => $decidedAt,
            ])['status'] ?? 'error');
        } catch (Throwable $e) {
            $verdictStatus = 'error:'.$e::class;
        }

        $releaseStatus = 'error';
        $releaseRow = null;
        $releaseLedger = $this->releaseLedger ?? $this->defaultReleaseLedger();
        try {
            $releaseAppend = $releaseLedger->append([
                'task_packet_id' => $taskId,
                'candidate_hash' => $candidateHash,
                'decision' => $decision,
                'reasons' => $reasons,
                'risk_level' => (string) ($risk['risk_level'] ?? ''),
                'verification_hash' => $verificationHash,
                'rollback_hash' => $rollbackHash,
                'changed_files_hash' => CommitGovernancePureMappers::deterministicHash($changed),
                'project_lane' => ['project_id' => $projectId],
                'decided_at' => $decidedAt,
                'evidence_refs' => $evidenceRefs !== [] ? $evidenceRefs : ['unknown:evidence_refs_missing'],
                'rollback_posture' => CommitGovernancePureMappers::rollbackPosture($rollback),
                'rejected_alternatives' => $decision === AtlasMergeGovernorAdmissionPolicy::DECISION_ADMITTED ? [] : ['release_without_governance_clearance'],
                'post_release_learning_hooks' => $decision === AtlasMergeGovernorAdmissionPolicy::DECISION_ADMITTED ? ['task_outcome_learning_candidate'] : [],
                'prepare_binding' => $prepareBinding,
            ]);
            $releaseStatus = (string) ($releaseAppend['status'] ?? 'error');
            $releaseRow = is_array($releaseAppend['row'] ?? null) ? $releaseAppend['row'] : null;
        } catch (Throwable $e) {
            $releaseStatus = 'error:'.$e::class;
        }

        return [
            'verdict_ledger' => $verdictStatus,
            'release_ledger' => $releaseStatus,
            'release_receipt' => is_array($releaseRow) ? (string) ($releaseRow['decision_hash'] ?? '') : '',
            'release_ledger_path' => $releaseLedger->path(),
            'release_row' => $releaseRow,
        ];
    }

    /**
     * @param  array<string,mixed>  $recorded
     * @param  list<string>  $changed
     * @return array<string,mixed>|null
     */
    private function authorizedActionFromRecorded(array $recorded, array $changed, string $budgetPosture, array $context): ?array
    {
        $row = is_array($recorded['release_row'] ?? null) ? $recorded['release_row'] : null;
        $ledgerPath = trim((string) ($recorded['release_ledger_path'] ?? ''));
        if ($row === null || $ledgerPath === '') {
            return null;
        }

        $baseCommit = trim((string) ($context['base_commit'] ?? ''));
        $treeHash = trim((string) ($context['tree_hash'] ?? ''));
        $leaseId = trim((string) ($context['lease_id'] ?? ''));
        $leaseOwner = trim((string) ($context['lease_owner'] ?? ''));
        $fencingToken = (int) ($context['fencing_token'] ?? 0);
        if ($baseCommit === '' || $treeHash === '' || $leaseId === '' || $leaseOwner === '' || $fencingToken < 1) {
            return null;
        }

        $scopeHash = CommitGovernancePureMappers::deterministicHash($changed);
        $nonce = (string) Str::uuid();
        $issuedAt = trim((string) ($row['decided_at'] ?? ($this->clock)()));
        $expiresAt = date(DATE_ATOM, strtotime($issuedAt) + 300);
        try {
            $authority = $this->kernelEvidenceAuthority ?? app(KernelEvidenceAuthority::class);
            $event = $authority->issueReleaseAuthorization(new CanonicalReleaseAuthorizationRequest(
                decisionHash: (string) ($row['decision_hash'] ?? ''),
                taskPacketId: (string) ($row['task_packet_id'] ?? ''),
                candidateHash: (string) ($row['candidate_hash'] ?? ''),
                verificationHash: (string) ($row['verification_hash'] ?? ''),
                rollbackHash: (string) ($row['rollback_hash'] ?? ''),
                files: $changed,
                scopeHash: $scopeHash,
                baseCommit: $baseCommit,
                treeHash: $treeHash,
                leaseId: $leaseId,
                leaseOwner: $leaseOwner,
                fencingToken: $fencingToken,
                nonce: $nonce,
                issuedAt: $issuedAt,
                expiresAt: $expiresAt,
                orderHash: trim((string) ($context['order_hash'] ?? '')),
                deliveryId: trim((string) ($context['delivery_id'] ?? '')),
                evidenceHash: trim((string) ($context['evidence_hash'] ?? '')),
                requiresCanarySettlement: ($context['requires_canary_settlement'] ?? false) === true,
                context: [
                    'correlation_id' => $nonce,
                    'scope_type' => 'task_packet',
                    'scope_id' => (string) ($row['task_packet_id'] ?? ''),
                ],
            ));
        } catch (Throwable) {
            return null;
        }
        if ($event === null) {
            return null;
        }

        return AuthorizedMergeAction::fromReleaseDecisionRow(
            row: $row,
            action: 'commit',
            releaseLedgerPath: $ledgerPath,
            files: $changed,
            metadata: [
                // This posture may buy more verification work upstream, but never a broader
                // authority class, longer TTL, or wider MergeActuator permission.
                'budget_posture' => $budgetPosture !== '' ? $budgetPosture : 'default',
                'lease_owner' => $leaseOwner,
            ],
            canonicalBinding: [
                'event_id' => (string) $event->event_id,
                'event_hash' => (string) ($event->event_hash ?: $event->payload_hash),
                'nonce' => $nonce,
                'base_commit' => $baseCommit,
                'tree_hash' => $treeHash,
                'scope_hash' => $scopeHash,
                'lease_id' => $leaseId,
                'fencing_token' => $fencingToken,
                'order_hash' => trim((string) ($context['order_hash'] ?? '')),
                'delivery_id' => trim((string) ($context['delivery_id'] ?? '')),
                'evidence_hash' => trim((string) ($context['evidence_hash'] ?? '')),
            ],
        )->toArray();
    }

    /**
     * @param  array<string,mixed>  $verification
     * @return list<string>
     */
    private function evidenceRefs(array $verification, string $taskId, string $ledgerEvidenceHash, bool $hasExplicitEvidenceHash): array
    {
        $refs = [];
        foreach ((array) ($verification['evidence_refs'] ?? []) as $ref) {
            $ref = trim((string) $ref);
            if ($ref !== '') {
                $refs[$ref] = true;
            }
        }
        if ($hasExplicitEvidenceHash) {
            $refs['verification_hash:'.$ledgerEvidenceHash] = true;
        } else {
            $refs['unknown:evidence_hash_missing'] = true;
        }
        if ($taskId !== '') {
            $refs['task_packet:'.$taskId] = true;
        }

        return array_keys($refs);
    }

    /** @return list<string> allowed risk levels for the release window (policy-plane driven, env-tunable). */
    private function releaseWindow(): array
    {
        return ($this->policyPlane ?? new AtlasTaskGovernancePolicyPlane)->releaseWindow();
    }

    /**
     * The policy-declared required_checks for this risk level, reused as the gate replay plan's
     * `packet_facts.declared_gates` input and as the required set for
     * {@see CommitGovernancePureMappers::missingRerun()} pure comparison.
     * I/O / policy plane stays on the chain.
     *
     * @return list<string>
     */
    private function missingRerunPolicyDeclaredGates(string $riskLevel): array
    {
        return ($this->policyPlane ?? new AtlasTaskGovernancePolicyPlane)->requiredChecksFor($riskLevel);
    }

    private function defaultVerdictLedger(): AtlasVerificationCourtVerdictLedger
    {
        return new AtlasVerificationCourtVerdictLedger(storage_path('atlas/governance/verification-court-verdict-ledger.jsonl'));
    }

    private function defaultReleaseLedger(): AtlasMergeGovernorReleaseDecisionLedger
    {
        return new AtlasMergeGovernorReleaseDecisionLedger(storage_path('atlas/governance/merge-governor-release-decision-ledger.jsonl'));
    }

    /**
     * @param  list<string>  $blockers
     * @param  array<string,mixed>  $recorded
     * @return array<string,mixed>
     */
    /** @param  list<string>|null  $missingRerun */
    private function envelope(string $mode, bool $admitted, bool $enforcedBlock, string $decision, string $riskLevel, array $blockers, array $recorded, ?string $error = null, ?array $replayVerdict = null, ?string $gateReplayPlanHash = null, ?array $missingRerun = null, ?array $authorizedMergeAction = null): array
    {
        return [
            'schema' => self::SCHEMA,
            'mode' => $mode,
            'ran' => $decision !== 'skipped',
            'decision' => $decision,
            'admitted' => $admitted,
            'enforced_block' => $enforcedBlock,
            'risk_level' => $riskLevel,
            'blockers' => $blockers,
            'recorded' => $recorded,
            'replay_verdict' => $replayVerdict,
            'gate_replay_plan_hash' => $gateReplayPlanHash,
            'missing_rerun' => $missingRerun ?? [],
            'authorized_merge_action' => $authorizedMergeAction,
            'error' => $error,
        ];
    }
}

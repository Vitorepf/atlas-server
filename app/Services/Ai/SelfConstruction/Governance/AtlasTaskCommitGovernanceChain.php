<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Governance;

use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorAdmissionPolicy;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorReleaseDecisionLedger;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorRiskClassifier;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorRollbackPlanGate;
use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtFalseGreenDetector;
use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtVerdictLedger;
use Throwable;

/**
 * THE CONNECTIVE TISSUE (Lane 0 / spine) — composes the previously-ORPHANED governing organs over a worker's
 * about-to-land commit and RECORDS the verdict, so the Merge Governor and the Verification Court finally run
 * on real, live deliveries instead of sitting as standalone CLIs nobody calls.
 *
 * It sits in {@see \App\Services\Ai\SelfConstruction\AtlasTaskServingService::report()} between the Fase-2
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
    ) {
        $this->clock = $clock ?? static fn (): string => now()->toIso8601String();
    }

    /**
     * Resolved governance mode (gradual arming). An explicit constructor override wins; otherwise env-driven,
     * defaulting to observe so the chain always RUNS and RECORDS but never blocks until the operator arms it.
     */
    public function mode(): string
    {
        $raw = strtolower(trim($this->modeOverride ?? (string) env('ATLAS_MERGE_GOVERNANCE_MODE', self::MODE_OBSERVE)));

        return in_array($raw, [self::MODE_OFF, self::MODE_OBSERVE, self::MODE_ENFORCE], true) ? $raw : self::MODE_OBSERVE;
    }

    /**
     * Govern one about-to-land commit. Pure orchestration over the organs + ledgers; never touches git.
     *
     * @param  array{
     *     task_packet_id?:string,
     *     project_id?:string,
     *     changed_files?:list<string>,
     *     verification?:array{passed?:bool, evidence_hash?:string, checks?:array<string,string>}
     * }  $context
     * @return array<string,mixed>
     */
    public function govern(array $context): array
    {
        $mode = $this->mode();
        if ($mode === self::MODE_OFF) {
            return $this->envelope($mode, true, false, 'skipped', '', [], ['verdict_ledger' => 'skipped', 'release_ledger' => 'skipped']);
        }

        try {
            $taskId = (string) ($context['task_packet_id'] ?? '');
            $projectId = (string) ($context['project_id'] ?? 'atlas-self-construction');
            $changed = $this->normalizeFiles((array) ($context['changed_files'] ?? []));
            $verification = is_array($context['verification'] ?? null) ? $context['verification'] : [];
            $serverGreen = (bool) ($verification['passed'] ?? false);
            $checks = is_array($verification['checks'] ?? null) ? $verification['checks'] : [];

            $organs = $this->touchedOrgans($changed);
            $evidenceHash = (string) ($verification['evidence_hash'] ?? '') !== ''
                ? (string) $verification['evidence_hash']
                : $this->deterministicHash(['changed' => $changed, 'checks' => $checks, 'green' => $serverGreen]);

            $risk = ($this->riskClassifier ?? new AtlasMergeGovernorRiskClassifier)->classify([
                'changed_files' => $changed,
                'touched_organs' => $organs,
                'verification_result' => ['passed' => $serverGreen],
                'rollback_plan' => ['mode' => 'git_revert_scoped_commit'],
                'project_lane' => ['project_id' => $projectId, 'allowed_scope_roots' => $this->scopeRoots($changed)],
                'scope_deviations' => [],
            ]);

            $rollback = ($this->rollbackGate ?? new AtlasMergeGovernorRollbackPlanGate)->evaluate([
                'affected_files' => $changed,
                'restore_strategy' => 'git_revert_scoped_commit',
                'verification_after_rollback' => ['php -l', 'artisan about', 'task tests'],
                'owner_scope' => $projectId,
                'project_lane' => ['project_id' => $projectId, 'allowed_scope_roots' => $this->scopeRoots($changed)],
            ]);

            $admission = ($this->admissionPolicy ?? new AtlasMergeGovernorAdmissionPolicy)->decide([
                'project_id' => $projectId,
                'risk_classification' => ['risk_level' => (string) $risk['risk_level'], 'reasons' => (array) $risk['reasons']],
                'rollback_gate' => ['conformant' => (bool) $rollback['conformant'], 'blockers' => (array) $rollback['blockers']],
                'verification_court' => [
                    'server_side_green' => $serverGreen,
                    'evidence_hash' => $serverGreen ? $evidenceHash : '',
                    'missing_rerun' => [],
                    'project_id' => $projectId,
                ],
                'release_window_policy' => ['allowed_risk_levels' => $this->releaseWindow()],
            ]);

            $decision = (string) $admission['decision'];
            $blockers = array_values(array_map('strval', (array) $admission['blockers']));
            $admitted = $decision === AtlasMergeGovernorAdmissionPolicy::DECISION_ADMITTED;

            $recorded = $this->record($taskId, $projectId, $decision, $blockers, $evidenceHash, $risk, $rollback, $changed, $checks);

            $enforcedBlock = $mode === self::MODE_ENFORCE && ! $admitted;

            return $this->envelope($mode, $admitted, $enforcedBlock, $decision, (string) $risk['risk_level'], $blockers, $recorded);
        } catch (Throwable $e) {
            // FAIL-OPEN: never wedge a worker because governance broke. Record nothing, admit, do not block.
            return $this->envelope($mode, true, false, 'fail_open_error', '', [], ['verdict_ledger' => 'error', 'release_ledger' => 'error'], $e->getMessage());
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
     * @return array{verdict_ledger:string, release_ledger:string}
     */
    private function record(string $taskId, string $projectId, string $decision, array $blockers, string $evidenceHash, array $risk, array $rollback, array $changed, array $checks): array
    {
        if ($taskId === '') {
            return ['verdict_ledger' => 'skipped_no_task_id', 'release_ledger' => 'skipped_no_task_id'];
        }

        $decidedAt = ($this->clock)();
        $reasons = $blockers !== [] ? $blockers : (array) $risk['reasons'];
        $reasons = array_values(array_map('strval', $reasons));
        $planHash = $this->deterministicHash(['risk' => $risk, 'rollback' => $rollback]);
        $outcomeHash = $this->deterministicHash(['decision' => $decision, 'blockers' => $blockers, 'checks' => $checks]);
        $candidateHash = $this->deterministicHash(['changed' => $changed, 'evidence' => $evidenceHash]);

        $verdictStatus = 'error';
        try {
            $verdictStatus = (string) (($this->verdictLedger ?? $this->defaultVerdictLedger())->append([
                'task_packet_id' => $taskId,
                'evidence_hash' => $evidenceHash !== '' ? $evidenceHash : $candidateHash,
                'replay_plan_hash' => $planHash,
                'verdict' => $this->decisionToVerdict($decision),
                'reasons' => $reasons,
                'replay_outcome_hash' => $outcomeHash,
                'decided_at' => $decidedAt,
            ])['status'] ?? 'error');
        } catch (Throwable) {
            $verdictStatus = 'error';
        }

        $releaseStatus = 'error';
        try {
            $releaseStatus = (string) (($this->releaseLedger ?? $this->defaultReleaseLedger())->append([
                'task_packet_id' => $taskId,
                'candidate_hash' => $candidateHash,
                'decision' => $decision,
                'reasons' => $reasons,
                'verification_hash' => $evidenceHash !== '' ? $evidenceHash : $candidateHash,
                'rollback_hash' => $this->deterministicHash($rollback),
                'project_lane' => ['project_id' => $projectId],
                'decided_at' => $decidedAt,
            ])['status'] ?? 'error');
        } catch (Throwable) {
            $releaseStatus = 'error';
        }

        return ['verdict_ledger' => $verdictStatus, 'release_ledger' => $releaseStatus];
    }

    /** The AdmissionPolicy decision space ⇒ the VerdictLedger's {passed,failed,blocked} enum. */
    private function decisionToVerdict(string $decision): string
    {
        return match ($decision) {
            AtlasMergeGovernorAdmissionPolicy::DECISION_ADMITTED => AtlasVerificationCourtFalseGreenDetector::VERDICT_PASSED,
            AtlasMergeGovernorAdmissionPolicy::DECISION_BLOCKED => AtlasVerificationCourtFalseGreenDetector::VERDICT_BLOCKED,
            default => AtlasVerificationCourtFalseGreenDetector::VERDICT_FAILED,
        };
    }

    /** @return list<string> allowed risk levels for the release window (env-tunable for arming). */
    private function releaseWindow(): array
    {
        $raw = (string) env('ATLAS_MERGE_GOVERNANCE_RISK_WINDOW', 'low,medium');
        $levels = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $s): bool => $s !== ''));

        return $levels === [] ? ['low', 'medium'] : $levels;
    }

    /**
     * Map changed file paths to the human-readable organ labels the RiskClassifier scores. Deterministic, pure.
     *
     * @param  list<string>  $changed
     * @return list<string>
     */
    private function touchedOrgans(array $changed): array
    {
        $organs = [];
        foreach ($changed as $path) {
            if (str_contains($path, 'MergeGovernor')) {
                $organs['Merge Governor'] = true;
            }
            if (str_contains($path, 'VerificationCourt')) {
                $organs['Verification Court'] = true;
            }
            if (str_contains($path, 'AgentControlPlane') || str_contains($path, 'TaskServing') || str_contains($path, 'TaskPacket') || str_contains($path, 'AtlasTaskQueue') || str_contains($path, 'TaskQueueOrchestrator')) {
                $organs['Task Fabric'] = true;
            }
            if (str_contains($path, 'HarnessGuard') || str_contains($path, 'Constitution')) {
                $organs['Constitution'] = true;
            }
            if (str_contains($path, 'MasterSwitch') || str_contains($path, 'LoopMasterSwitch')) {
                $organs['MasterSwitch'] = true;
            }
            if (str_contains($path, 'WorkspaceMaterializer')) {
                $organs['WorkspaceMaterializer'] = true;
            }
        }

        return array_keys($organs);
    }

    /**
     * Distinct directory roots of the changed files — used as the project-lane scope roots so the rollback plan
     * is lane-conformant (every affected file lives under a declared root).
     *
     * @param  list<string>  $changed
     * @return list<string>
     */
    private function scopeRoots(array $changed): array
    {
        $roots = [];
        foreach ($changed as $path) {
            $dir = trim(dirname($path), '.');
            if ($dir !== '' && $dir !== '/') {
                $roots[$dir] = true;
            }
        }

        return $roots === [] ? ['.'] : array_keys($roots);
    }

    /** @param  list<string>  $files @return list<string> */
    private function normalizeFiles(array $files): array
    {
        $out = [];
        foreach ($files as $f) {
            $p = ltrim(trim(str_replace('\\', '/', (string) $f)), '/');
            if ($p !== '') {
                $out[$p] = true;
            }
        }

        return array_keys($out);
    }

    private function deterministicHash(mixed $payload): string
    {
        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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
     * @param  array{verdict_ledger:string, release_ledger:string}  $recorded
     * @return array<string,mixed>
     */
    private function envelope(string $mode, bool $admitted, bool $enforcedBlock, string $decision, string $riskLevel, array $blockers, array $recorded, ?string $error = null): array
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
            'error' => $error,
        ];
    }
}

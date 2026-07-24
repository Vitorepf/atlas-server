<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Governance;

use App\Services\Ai\SelfConstruction\AtlasTaskCommitVerificationGate;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorAdmissionPolicy;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorReleaseDecisionLedger;
use Throwable;

/**
 * The Governor's canary leg: probes the CURRENT tree immediately after a task's commit landed,
 * reusing {@see AtlasTaskCommitVerificationGate} as the single source of post-land health
 * probes — the same checks that ran pre-land now run again on the landed tree.
 *
 * CLASSIFICATION (from the gate's own attribution, never re-derived):
 *   - the gate's checks are all green                          => canary_pass
 *   - the gate BLOCKED (a definitive failure it attributed to
 *     one of this task's changed files/classes)                => canary_fail_attributed, with a
 *                                                                   revert_candidate payload naming
 *                                                                   the task and commit
 *   - the gate PASSED but only by failing open (red it could
 *     not attribute to this task)                               => canary_inconclusive, empty candidate
 *
 * The sentinel only OBSERVES and RECORDS a receipt through {@see AtlasMergeGovernorReleaseDecisionLedger};
 * it never executes a revert — that stays with the operator/policy-triggered actuator. A ledger
 * failure is isolated to a status string, matching the fail-open ethos of the rest of the chain.
 */
class AtlasTaskPostLandCanarySentinel
{
    public const VERDICT_PASS              = 'canary_pass';
    public const VERDICT_FAIL_ATTRIBUTED   = 'canary_fail_attributed';
    public const VERDICT_INCONCLUSIVE      = 'canary_inconclusive';

    /** @var callable():string */
    private $clock;

    public function __construct(
        private readonly ?AtlasTaskCommitVerificationGate $gate = null,
        private readonly ?AtlasMergeGovernorReleaseDecisionLedger $ledger = null,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): string => now()->toIso8601String();
    }

    /**
     * @param  list<string>  $allowedFiles  the task's changed files, probed for attribution
     * @return array{verdict:string, revert_candidate:array<string,mixed>, checks:array<string,string>, ledger_status:string}
     */
    public function observe(string $taskPacketId, string $commitSha, array $allowedFiles): array
    {
        $gate = $this->gate ?? new AtlasTaskCommitVerificationGate;
        $result = $gate->verify($allowedFiles, $taskPacketId);

        if (($result['blocked'] ?? false) === true) {
            $verdict = self::VERDICT_FAIL_ATTRIBUTED;
            $revertCandidate = [
                'task_packet_id' => $taskPacketId,
                'commit_sha' => $commitSha,
                'suggested_command' => 'php artisan atlas:task:revert --task='.$taskPacketId,
            ];
        } elseif ((string) ($result['fail_open_reason'] ?? '') !== '') {
            $verdict = self::VERDICT_INCONCLUSIVE;
            $revertCandidate = [];
        } else {
            $verdict = self::VERDICT_PASS;
            $revertCandidate = [];
        }

        $ledgerStatus = $this->recordReceipt($taskPacketId, $commitSha, $allowedFiles, $verdict, (array) ($result['checks'] ?? []));

        return [
            'verdict' => $verdict,
            'revert_candidate' => $revertCandidate,
            'checks' => (array) ($result['checks'] ?? []),
            'ledger_status' => $ledgerStatus,
        ];
    }

    /** @param  list<string>  $allowedFiles  @param  array<string,string>  $checks */
    private function recordReceipt(string $taskPacketId, string $commitSha, array $allowedFiles, string $verdict, array $checks): string
    {
        $decision = match ($verdict) {
            self::VERDICT_PASS => AtlasMergeGovernorAdmissionPolicy::DECISION_ADMITTED,
            self::VERDICT_FAIL_ATTRIBUTED => AtlasMergeGovernorAdmissionPolicy::DECISION_BLOCKED,
            default => AtlasMergeGovernorAdmissionPolicy::DECISION_REPAIR,
        };

        try {
            $ledger = $this->ledger ?? $this->defaultLedger();

            return (string) ($ledger->append([
                'task_packet_id' => $taskPacketId,
                'candidate_hash' => $this->hash(['task' => $taskPacketId, 'commit' => $commitSha, 'files' => $allowedFiles]),
                'decision' => $decision,
                'reasons' => [$verdict],
                'risk_level' => 'post_land_canary',
                'verification_hash' => $this->hash($checks),
                'rollback_hash' => $this->hash(['commit_sha' => $commitSha]),
                'changed_files_hash' => $this->hash($allowedFiles),
                'project_lane' => ['project_id' => 'atlas-self-construction'],
                'decided_at' => ($this->clock)(),
                // v3 ledger requires evidence_refs + rollback_posture (throws otherwise → canary error).
                'evidence_refs' => [
                    'canary_verdict:'.$verdict,
                    'commit_sha:'.$commitSha,
                    'task_packet:'.$taskPacketId,
                ],
                'rollback_posture' => 'revertible:git_revert_task_packet',
                'rejected_alternatives' => [],
                'post_release_learning_hooks' => [],
            ])['status'] ?? 'error');
        } catch (Throwable) {
            return 'error';
        }
    }

    private function defaultLedger(): AtlasMergeGovernorReleaseDecisionLedger
    {
        return new AtlasMergeGovernorReleaseDecisionLedger(storage_path('atlas/governance/merge-governor-release-decision-ledger.jsonl'));
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\MergeGovernor;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use RuntimeException;

/**
 * Append-only local ledger for Merge Governor decisions. Records every admitted / rejected /
 * repair_required / blocked outcome so the audit trail exists BEFORE autonomous release authority grows.
 *
 * INVARIANTS:
 *   - APPEND-ONLY: kernel JsonlReceiptStore (flock LOCK_EX); existing rows are NEVER overwritten or edited.
 *   - VALIDATES: task_packet_id, candidate_hash, decision (allowlist), reasons, verification_hash,
 *     rollback_hash, project_lane, decided_at — missing/invalid ⇒ throws and DOES NOT WRITE.
 *   - IDEMPOTENT: duplicate decision_hash returns status=already_recorded without appending.
 *   - DETERMINISTIC: decision_hash = sha256 over canonical {task_packet_id, candidate_hash, decision,
 *     reasons (sorted), verification_hash, rollback_hash, project_lane.project_id, decided_at}.
 */
final class AtlasMergeGovernorReleaseDecisionLedger
{
    public const SCHEMA = 'atlas.mergegovernor.release_decision.v1';

    public const STATUS_OK = 'ok';

    public const STATUS_ALREADY = 'already_recorded';

    public const ALLOWED_DECISIONS = [
        AtlasMergeGovernorAdmissionPolicy::DECISION_ADMITTED,
        AtlasMergeGovernorAdmissionPolicy::DECISION_REJECTED,
        AtlasMergeGovernorAdmissionPolicy::DECISION_REPAIR,
        AtlasMergeGovernorAdmissionPolicy::DECISION_BLOCKED,
    ];

    public function __construct(private readonly string $ledgerPath) {}

    /**
     * @param  array{
     *     task_packet_id:string,
     *     candidate_hash:string,
     *     decision:string,
     *     reasons:list<string>,
     *     risk_level:string,
     *     verification_hash:string,
     *     rollback_hash:string,
     *     changed_files_hash:string,
     *     project_lane:array{project_id:string},
     *     decided_at:string
     * }  $payload
     * @return array{status:string, row?:array<string,mixed>}
     */
    public function append(array $payload): array
    {
        $taskId = (string) ($payload['task_packet_id'] ?? '');
        $candHash = (string) ($payload['candidate_hash'] ?? '');
        $decision = (string) ($payload['decision'] ?? '');
        $reasons = is_array($payload['reasons'] ?? null) ? array_values(array_map('strval', $payload['reasons'])) : null;
        $riskLevel = (string) ($payload['risk_level'] ?? '');
        $verHash = (string) ($payload['verification_hash'] ?? '');
        $rollbackHash = (string) ($payload['rollback_hash'] ?? '');
        $changedFilesHash = (string) ($payload['changed_files_hash'] ?? '');
        $lane = is_array($payload['project_lane'] ?? null) ? $payload['project_lane'] : null;
        $laneProj = (string) ($lane['project_id'] ?? '');
        $decidedAt = (string) ($payload['decided_at'] ?? '');

        if ($taskId === '') {
            throw new RuntimeException('release decision: missing task_packet_id');
        }
        if ($candHash === '') {
            throw new RuntimeException('release decision: missing candidate_hash');
        }
        if (! in_array($decision, self::ALLOWED_DECISIONS, true)) {
            throw new RuntimeException('release decision: invalid decision: '.($decision === '' ? 'missing' : $decision));
        }
        if ($reasons === null) {
            throw new RuntimeException('release decision: missing reasons (use [] for none)');
        }
        if ($riskLevel === '') {
            throw new RuntimeException('release decision: missing risk_level');
        }
        if ($verHash === '') {
            throw new RuntimeException('release decision: missing verification_hash');
        }
        if ($rollbackHash === '') {
            throw new RuntimeException('release decision: missing rollback_hash');
        }
        if ($changedFilesHash === '') {
            throw new RuntimeException('release decision: missing changed_files_hash');
        }
        if ($lane === null || $laneProj === '') {
            throw new RuntimeException('release decision: missing project_lane.project_id');
        }
        if ($decidedAt === '') {
            throw new RuntimeException('release decision: missing decided_at');
        }

        // New fields: evidence_refs, rejected_alternatives, rollback_posture, post_release_learning_hooks
        $evidenceRefs = is_array($payload['evidence_refs'] ?? null) ? array_values(array_map('strval', $payload['evidence_refs'])) : [];
        $rejectedAlternatives = is_array($payload['rejected_alternatives'] ?? null) ? array_values(array_map('strval', $payload['rejected_alternatives'])) : [];
        $rollbackPosture = (string) ($payload['rollback_posture'] ?? '');
        $postReleaseLearningHooks = is_array($payload['post_release_learning_hooks'] ?? null) ? array_values(array_map('strval', $payload['post_release_learning_hooks'])) : [];

        if ($evidenceRefs === []) {
            throw new RuntimeException('release decision: missing evidence_refs');
        }
        if ($rollbackPosture === '') {
            throw new RuntimeException('release decision: missing rollback_posture');
        }

        sort($reasons, SORT_STRING);
        $decisionHash = $this->computeHash($taskId, $candHash, $decision, $reasons, $riskLevel, $verHash, $rollbackHash, $changedFilesHash, $laneProj, $decidedAt);

        $row = [
            'schema' => self::SCHEMA,
            'task_packet_id' => $taskId,
            'candidate_hash' => $candHash,
            'decision' => $decision,
            'reasons' => $reasons,
            'risk_level' => $riskLevel,
            'verification_hash' => $verHash,
            'rollback_hash' => $rollbackHash,
            'changed_files_hash' => $changedFilesHash,
            'project_lane' => ['project_id' => $laneProj],
            'decided_at' => $decidedAt,
            'decision_hash' => $decisionHash,
            'evidence_refs' => $evidenceRefs,
            'rejected_alternatives' => $rejectedAlternatives,
            'rollback_posture' => $rollbackPosture,
            'post_release_learning_hooks' => $postReleaseLearningHooks,
        ];

        // Idempotency check runs INSIDE the store's write lock; null return aborts the append.
        $written = $this->store()->appendWith(function (?string $lastLine) use ($decisionHash, $row): ?array {
            foreach ($this->all() as $r) {
                if ((string) ($r['decision_hash'] ?? '') === $decisionHash) {
                    return null;
                }
            }

            return $row;
        });

        return $written === null ? ['status' => self::STATUS_ALREADY] : ['status' => self::STATUS_OK, 'row' => $row];
    }

    /**
     * Replay the ledger: recompute each decision_hash from stored fields and report any mismatch.
     *
     * @return array{valid:bool, entry_count:int, issues:list<array<string,mixed>>}
     */
    public function replay(): array
    {
        $entries = $this->all();
        $issues = [];
        foreach ($entries as $i => $entry) {
            $reasons = is_array($entry['reasons'] ?? null) ? array_values(array_map('strval', $entry['reasons'])) : [];
            sort($reasons, SORT_STRING);
            $recomputed = $this->computeHash(
                (string) ($entry['task_packet_id'] ?? ''),
                (string) ($entry['candidate_hash'] ?? ''),
                (string) ($entry['decision'] ?? ''),
                $reasons,
                (string) ($entry['risk_level'] ?? ''),
                (string) ($entry['verification_hash'] ?? ''),
                (string) ($entry['rollback_hash'] ?? ''),
                (string) ($entry['changed_files_hash'] ?? ''),
                (string) ($entry['project_lane']['project_id'] ?? ''),
                (string) ($entry['decided_at'] ?? ''),
            );
            if ($recomputed !== (string) ($entry['decision_hash'] ?? '')) {
                $issues[] = [
                    'index' => $i,
                    'task_packet_id' => (string) ($entry['task_packet_id'] ?? ''),
                    'reason' => 'hash_mismatch',
                ];
            }
        }

        return [
            'valid' => $issues === [],
            'entry_count' => count($entries),
            'issues' => $issues,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        return $this->store()->replay();
    }

    private function store(): JsonlReceiptStore
    {
        return new JsonlReceiptStore($this->ledgerPath);
    }

    /**
     * @return list<array<string,mixed>>  newest-first (lexicographic on decided_at desc)
     */
    public function listChronological(?string $sinceIso = null, ?string $untilIso = null): array
    {
        $rows = $this->all();
        if ($sinceIso !== null) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => (string) ($r['decided_at'] ?? '') >= $sinceIso));
        }
        if ($untilIso !== null) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => (string) ($r['decided_at'] ?? '') <= $untilIso));
        }
        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['decided_at'] ?? ''), (string) ($a['decided_at'] ?? '')));

        return $rows;
    }

    /**
     * @param  list<string>  $reasons  already sorted
     */
    private function computeHash(
        string $taskId, string $candHash, string $decision, array $reasons,
        string $riskLevel, string $verHash, string $rollbackHash, string $changedFilesHash,
        string $laneProj, string $decidedAt,
    ): string {
        $canonical = [
            'candidate_hash' => $candHash,
            'changed_files_hash' => $changedFilesHash,
            'decided_at' => $decidedAt,
            'decision' => $decision,
            'project_lane_project_id' => $laneProj,
            'reasons' => $reasons,
            'risk_level' => $riskLevel,
            'rollback_hash' => $rollbackHash,
            'task_packet_id' => $taskId,
            'verification_hash' => $verHash,
        ];
        ksort($canonical);

        return hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

}

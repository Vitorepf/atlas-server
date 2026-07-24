<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Governance;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\EngineeringKernel\AuthorizedMergeAction;
use App\Services\Ai\EngineeringKernel\AuthorizedRevertAction;
use App\Services\Ai\EngineeringKernel\CanarySettlementRequest;
use App\Services\Ai\EngineeringKernel\CanonicalReleaseAuthorizationRequest;
use App\Services\Ai\EngineeringKernel\KernelEvidenceAuthority;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\SelfConstruction\AtlasTaskScopedCommitter;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorAdmissionPolicy;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorReleaseDecisionLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The Governor's missing REVERT leg. Given a task_packet_id, locates the single commit
 * {@see AtlasTaskScopedCommitter} landed for it (by scanning git log for the exact
 * "Atlas-Task: <id>" marker line that committer writes), and either plans (dry-run,
 * DEFAULT) or executes (`git revert`) undoing it — fail-closed on every ambiguity.
 *
 * SAFETY RAILS (each refuses with a distinct `reason`, before any git mutation):
 *   ambiguous_sha            — zero or more than one commit carries the exact marker.
 *   out_of_scope_file        — the commit touches a file outside the task's RECORDED
 *                               allowed_files scope (looked up by task_packet_id, not
 *                               re-derived from the commit itself).
 *   dirty_working_tree_file  — one of the commit's files has uncommitted working-tree
 *                               changes right now (another worker may be mid-task on it).
 *   forbidden_self_target    — any of the commit's files is pétreo
 *                               ({@see AtlasLoopHarnessGuard::isForbiddenSelfTarget}).
 *
 * Dry-run (default) never mutates git state: {sha, files, would_revert:true}. Live mode
 * (`$dryRun=false`) serializes with the SAME commit lock file/discipline
 * {@see AtlasTaskScopedCommitter::LOCK_REL} that landing commits use, then runs
 * `git revert --no-edit <sha>` and returns {reverted:true, revert_sha}.
 *
 * Every call — dry or live, success or refusal — appends one receipt through
 * {@see AtlasMergeGovernorReleaseDecisionLedger}.
 *
 * Canary logic, auto-trigger and scheduling are OUT of scope for this class: it only
 * plans/executes a single named revert on request.
 */
final class AtlasTaskMergeActuator
{
    public const SCHEMA = 'atlas.self_construction.governance.task_merge_actuator.v1';

    public const REASON_AMBIGUOUS_SHA = 'ambiguous_sha';

    public const REASON_OUT_OF_SCOPE_FILE = 'out_of_scope_file';

    public const REASON_DIRTY_WORKING_TREE_FILE = 'dirty_working_tree_file';

    public const REASON_FORBIDDEN_SELF_TARGET = 'forbidden_self_target';

    public const REASON_REVERT_FAILED = 'git_revert_failed';

    public const REASON_RELEASE_LEDGER_UNAVAILABLE = 'release_ledger_unavailable';

    public const REASON_AUTHORITY_NOT_PERSISTED = 'authority_not_persisted';

    public const REASON_AUTHORITY_STALE = 'authority_stale';

    public const REASON_AUTHORITY_REVOKED = 'authority_revoked';

    public const REASON_AUTHORITY_TAMPERED = 'authority_tampered';

    public const REASON_CANDIDATE_HASH_TAMPERED = 'candidate_hash_tampered';

    public const REASON_ROLLBACK_POSTURE_MISSING = 'rollback_posture_missing';

    public const REASON_ROLLBACK_POSTURE_NOT_REVERTIBLE = 'rollback_posture_not_revertible';

    public const REASON_POST_EFFECT_PERSISTENCE_FAILED = 'post_effect_persistence_failed';

    public const ACTION_REVERT_TASK = 'revert_task';

    public const ACTION_COMMIT = 'commit';

    private const LOCK_TIMEOUT_SECONDS = 15.0;

    private const LOCK_POLL_MICROSECONDS = 50_000;

    /** @var (\Closure(string):list<string>)|null */
    private readonly ?\Closure $allowedFilesResolver;

    /** @var (\Closure(string,int):bool)|null */
    private readonly ?\Closure $leaseValidator;

    public function __construct(
        private readonly ?AtlasLoopHarnessGuard $guard = null,
        private readonly ?string $repoRootOverride = null,
        private readonly ?AtlasMergeGovernorReleaseDecisionLedger $ledgerOverride = null,
        ?\Closure $allowedFilesResolver = null,
        private readonly ?AtlasEvidenceLedger $evidenceLedger = null,
        private readonly ?AtlasTaskScopedCommitter $scopedCommitter = null,
        ?\Closure $leaseValidator = null,
        private readonly ?KernelEvidenceAuthority $kernelEvidenceAuthority = null,
    ) {
        $this->allowedFilesResolver = $allowedFilesResolver;
        $this->leaseValidator = $leaseValidator;
    }

    /**
     * @return array<string, mixed>
     */
    public function revert(string $taskPacketId, bool $dryRun = true): array
    {
        $prepared = $this->prepareAuthorizedRevert($taskPacketId, $dryRun);
        if (($prepared['authorized'] ?? false) !== true || ! is_array($prepared['authorized_merge_action'] ?? null)) {
            return $prepared;
        }

        return $this->act(AuthorizedMergeAction::fromArray($prepared['authorized_merge_action']));
    }

    public function prepareRevert(CanarySettlementRequest $request): ?AuthorizedRevertAction
    {
        $repo = $this->repoRoot();
        $action = $request->action;
        if (($this->leaseValidator !== null && ! ($this->leaseValidator)($action->leaseId, $action->fencingToken))
            || trim((string) $this->git($repo, ['rev-parse', 'HEAD'])['out']) !== $request->landedSha) {
            return null;
        }
        $files = $this->changedFiles($repo, $request->landedSha);
        sort($files, SORT_STRING);
        $expected = $action->files;
        sort($expected, SORT_STRING);
        if ($files !== $expected) {
            return null;
        }
        $candidateHash = $this->candidateHash($action->taskPacketId, $request->landedSha, $files);
        $verificationHash = hash('sha256', $request->landedSha);
        $rollbackHash = $this->rollbackHash($request->landedSha);
        $treeHash = hash('sha256', 'revert:'.$request->landedSha.':'.implode('|', $files));
        $nonce = (string) Str::uuid();
        $issuedAt = date(DATE_ATOM);
        $expiresAt = date(DATE_ATOM, time() + 300);
        $prepareBinding = [
            'task_packet_id' => $action->taskPacketId, 'action' => self::ACTION_REVERT_TASK,
            'candidate_hash' => $candidateHash, 'verification_hash' => $verificationHash,
            'rollback_hash' => $rollbackHash, 'changed_files' => $files,
            'scope_hash' => $action->scopeHash, 'base_commit' => $request->landedSha, 'tree_hash' => $treeHash,
            'lease_id' => $action->leaseId, 'lease_owner' => (string) ($action->metadata['lease_owner'] ?? ''),
            'fencing_token' => $action->fencingToken, 'order_hash' => $request->orderHash,
            'delivery_id' => $request->deliveryId, 'evidence_hash' => $request->evidenceHash,
        ];
        try {
            $row = $this->appendReleaseDecision($action->taskPacketId, $request->landedSha, $files,
                AtlasMergeGovernorAdmissionPolicy::DECISION_ADMITTED, [], 'medium',
                'revertible:git_revert_scoped_commit', $prepareBinding);
            $authority = $this->kernelEvidenceAuthority ?? app(KernelEvidenceAuthority::class);
            $event = $authority->issueReleaseAuthorization(new CanonicalReleaseAuthorizationRequest(
                decisionHash: (string) $row['decision_hash'], taskPacketId: $action->taskPacketId,
                candidateHash: $candidateHash, verificationHash: $verificationHash,
                rollbackHash: $rollbackHash, files: $files, scopeHash: $action->scopeHash,
                baseCommit: $request->landedSha, treeHash: $treeHash, leaseId: $action->leaseId,
                leaseOwner: (string) ($action->metadata['lease_owner'] ?? ''), fencingToken: $action->fencingToken,
                nonce: $nonce, issuedAt: $issuedAt, expiresAt: $expiresAt,
                context: ['correlation_id' => $nonce, 'scope_type' => 'task_packet', 'scope_id' => $action->taskPacketId],
                orderHash: $request->orderHash, deliveryId: $request->deliveryId, evidenceHash: $request->evidenceHash,
                requiresCanarySettlement: true, action: self::ACTION_REVERT_TASK,
            ));
        } catch (Throwable $exception) {
            throw new \RuntimeException('canonical_revert_authorization_failed', previous: $exception);
        }
        $revert = AuthorizedMergeAction::fromReleaseDecisionRow($row, self::ACTION_REVERT_TASK, $this->ledger()->path(),
            targetSha: $request->landedSha, files: $files, metadata: ['dry_run' => false, 'lease_owner' => $prepareBinding['lease_owner']],
            canonicalBinding: ['event_id' => $event->event_id, 'event_hash' => (string) ($event->event_hash ?: $event->payload_hash),
                'nonce' => $nonce, 'base_commit' => $request->landedSha, 'tree_hash' => $treeHash,
                'scope_hash' => $action->scopeHash, 'lease_id' => $action->leaseId, 'fencing_token' => $action->fencingToken,
                'order_hash' => $request->orderHash, 'delivery_id' => $request->deliveryId, 'evidence_hash' => $request->evidenceHash]);

        return new AuthorizedRevertAction($revert, $request->idempotencyHash(), $request->landedEventId);
    }

    /**
     * V1 translator: perform the old revert preflight, persist Governor authority,
     * and return a capability the kernel act() seam can consume.
     *
     * @return array<string, mixed>
     */
    public function prepareAuthorizedRevert(string $taskPacketId, bool $dryRun = true, int $ttlSeconds = 300): array
    {
        $repo = $this->repoRoot();
        if ($taskPacketId === '') {
            return $this->refuse($taskPacketId, $dryRun, self::REASON_AMBIGUOUS_SHA, ['candidate_count' => 0]);
        }
        if (! is_dir($repo.'/.git')) {
            return $this->refuse($taskPacketId, $dryRun, 'not_a_git_repo');
        }

        $candidates = $this->resolveLandedCommits($repo, $taskPacketId);
        if (count($candidates) !== 1) {
            return $this->refuse($taskPacketId, $dryRun, self::REASON_AMBIGUOUS_SHA, ['candidate_count' => count($candidates), 'candidates' => $candidates]);
        }
        $sha = $candidates[0];

        $files = $this->changedFiles($repo, $sha);

        $allowedFiles = $this->resolveAllowedFiles($taskPacketId);
        $outOfScope = array_values(array_diff($files, $allowedFiles));
        if ($outOfScope !== []) {
            return $this->refuse($taskPacketId, $dryRun, self::REASON_OUT_OF_SCOPE_FILE, ['sha' => $sha, 'files' => $outOfScope]);
        }

        $guard = $this->guard ?? new AtlasLoopHarnessGuard;
        foreach ($files as $file) {
            if ($guard->isForbiddenSelfTarget($file)) {
                return $this->refuse($taskPacketId, $dryRun, self::REASON_FORBIDDEN_SELF_TARGET, ['sha' => $sha, 'path' => $file]);
            }
        }

        $dirty = $this->dirtyFiles($repo, $files);
        if ($dirty !== []) {
            return $this->refuse($taskPacketId, $dryRun, self::REASON_DIRTY_WORKING_TREE_FILE, ['sha' => $sha, 'files' => $dirty]);
        }

        try {
            $row = $this->appendReleaseDecision(
                $taskPacketId,
                $sha,
                $files,
                AtlasMergeGovernorAdmissionPolicy::DECISION_ADMITTED,
                [],
                $dryRun ? 'low' : 'medium',
                'revertible:git_revert_scoped_commit',
            );
        } catch (Throwable $e) {
            return $this->zeroEffect($taskPacketId, $dryRun, self::REASON_RELEASE_LEDGER_UNAVAILABLE, [
                'sha' => $sha,
                'files' => $files,
                'error' => $e::class,
            ]);
        }

        $action = AuthorizedMergeAction::fromReleaseDecisionRow(
            row: $row,
            action: self::ACTION_REVERT_TASK,
            releaseLedgerPath: $this->ledger()->path(),
            targetSha: $sha,
            files: $files,
            ttlSeconds: $ttlSeconds,
            metadata: ['dry_run' => $dryRun],
        );

        return [
            'schema' => self::SCHEMA,
            'task_packet_id' => $taskPacketId,
            'dry_run' => $dryRun,
            'authorized' => true,
            'sha' => $sha,
            'files' => $files,
            'authorized_merge_action' => $action->toArray(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function act(AuthorizedMergeAction $action): array
    {
        if ($action->action === self::ACTION_COMMIT) {
            return $this->actCommit($action);
        }
        if ($action->action !== self::ACTION_REVERT_TASK) {
            return $this->zeroEffect($action->taskPacketId, $action->dryRun(), 'unsupported_action', [
                'action' => $action->action,
            ]);
        }

        $validated = $this->validateAuthority($action);
        if (($validated['ok'] ?? false) !== true) {
            return $this->zeroEffect($action->taskPacketId, $action->dryRun(), (string) ($validated['reason'] ?? self::REASON_AUTHORITY_NOT_PERSISTED), $validated);
        }
        if (! $this->validateCanonicalRevertAuthority($action)) {
            return $this->zeroEffect($action->taskPacketId, $action->dryRun(), self::REASON_AUTHORITY_NOT_PERSISTED);
        }

        $repo = $this->repoRoot();
        $sha = (string) $validated['sha'];
        $files = array_values(array_map('strval', (array) $validated['files']));

        if ($action->dryRun()) {
            return [
                'schema' => self::SCHEMA,
                'task_packet_id' => $action->taskPacketId,
                'dry_run' => true,
                'would_revert' => true,
                'sha' => $sha,
                'files' => $files,
                'authorized_merge_action' => $action->toArray(),
            ];
        }

        return $this->withCommitLock($repo, function () use ($repo, $action, $sha, $files): array {
            if (! $this->validateCanonicalRevertAuthority($action)) {
                return $this->zeroEffect($action->taskPacketId, false, self::REASON_AUTHORITY_NOT_PERSISTED);
            }
            $revert = $this->git($repo, ['revert', '--no-edit', $sha]);
            if ($revert['code'] !== 0) {
                $this->recordDecision($action->taskPacketId, $sha, $files, AtlasMergeGovernorAdmissionPolicy::DECISION_REJECTED, [self::REASON_REVERT_FAILED], 'high');

                return [
                    'schema' => self::SCHEMA,
                    'task_packet_id' => $action->taskPacketId,
                    'dry_run' => false,
                    'reverted' => false,
                    'refused' => true,
                    'reason' => self::REASON_REVERT_FAILED,
                    'sha' => $sha,
                    'files' => $files,
                    'stderr' => $revert['err'],
                ];
            }

            $revertSha = trim((string) $this->git($repo, ['rev-parse', 'HEAD'])['out']);
            try {
                $settlement = $this->recordEffectSettlement($action, $revertSha, $files);
                $authority = $this->kernelEvidenceAuthority ?? app(KernelEvidenceAuthority::class);
                $canonicalSettlement = $authority->issueRevertSettlement($action, $sha, $revertSha, $files);
            } catch (Throwable $e) {
                return [
                    'schema' => self::SCHEMA,
                    'task_packet_id' => $action->taskPacketId,
                    'dry_run' => false,
                    'reverted' => true,
                    'release_uncertain' => true,
                    'resolved' => false,
                    'reason' => self::REASON_POST_EFFECT_PERSISTENCE_FAILED,
                    'sha' => $sha,
                    'revert_sha' => $revertSha,
                    'files' => $files,
                    'settlement_error' => $e::class,
                ];
            }

            return [
                'schema' => self::SCHEMA,
                'task_packet_id' => $action->taskPacketId,
                'dry_run' => false,
                'reverted' => true,
                'resolved' => true,
                'status' => 'settled',
                'sha' => $sha,
                'revert_sha' => $revertSha,
                'files' => $files,
                'settlement' => $settlement,
                'settlement_event_id' => (string) $canonicalSettlement->event_id,
            ];
        });
    }

    /** @return array<string,mixed> */
    private function actCommit(AuthorizedMergeAction $action): array
    {
        $committer = $this->scopedCommitter ?? new AtlasTaskScopedCommitter(repoRootOverride: $this->repoRoot());

        return $committer->withGovernedCommitLock(fn (): array => $this->actCommitUnderGovernedLock($action, $committer));
    }

    /** @return array<string,mixed> */
    private function actCommitUnderGovernedLock(AuthorizedMergeAction $action, AtlasTaskScopedCommitter $committer): array
    {
        $validated = $this->validateCanonicalCommitAuthority($action);
        if (($validated['ok'] ?? false) !== true) {
            return $this->zeroEffect($action->taskPacketId, false, (string) ($validated['reason'] ?? self::REASON_AUTHORITY_NOT_PERSISTED), $validated);
        }

        $result = $committer->commitScope(
            $action->files,
            $action->taskPacketId,
            (string) ($action->metadata['client_id'] ?? 'atlas-merge-governor'),
            (string) ($action->metadata['objective'] ?? 'land verified candidate'),
            governedLockAlreadyHeld: true,
            preEffectGuard: fn (): bool => ($this->validateCanonicalCommitAuthority($action)['ok'] ?? false) === true,
        );
        if (($result['committed'] ?? false) !== true) {
            return array_merge($result, ['zero_effect' => true, 'authorized_merge_action' => $action->toArray()]);
        }

        $sha = (string) ($result['commit_sha'] ?? '');
        try {
            $settlement = $this->canonicalLedger()->record(LedgerEventType::ReleaseLanded, [
                'event_name' => 'release.landed',
                'task_packet_id' => $action->taskPacketId,
                'authorization_event_id' => $action->canonicalEventId,
                'authorization_event_hash' => $action->canonicalEventHash,
                'nonce' => $action->nonce,
                'commit_sha' => $sha,
                'changed_files' => $action->files,
                'scope_hash' => $action->scopeHash,
                'order_hash' => $action->orderHash,
                'delivery_id' => $action->deliveryId,
                'evidence_hash' => $action->evidenceHash,
                'provenance' => ['emitter' => 'atlas.merge_actuator', 'sovereign' => true],
            ], [
                'correlation_id' => $action->nonce,
                'causation_id' => $action->canonicalEventId,
                'scope_type' => 'task_packet',
                'scope_id' => $action->taskPacketId,
                'emitter_stage' => 'atlas.merge_actuator',
            ]);
        } catch (Throwable $e) {
            $settlement = null;
            $settlementError = $e::class;
        }
        if ($settlement === null) {
            return [
                'schema' => self::SCHEMA,
                'task_packet_id' => $action->taskPacketId,
                'committed' => true,
                'release_uncertain' => true,
                'resolved' => false,
                'reason' => self::REASON_POST_EFFECT_PERSISTENCE_FAILED,
                'commit_sha' => $sha,
                'files' => $action->files,
                'settlement_error' => $settlementError ?? 'canonical_ledger_unavailable',
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'task_packet_id' => $action->taskPacketId,
            'committed' => true,
            'resolved' => false,
            'status' => 'landed_pending_canary',
            'commit_sha' => $sha,
            'files' => $action->files,
            'settlement_event_id' => (string) $settlement->event_id,
            // P1b.2: LAND bound to AuthorizedMergeAction::nonce (also ledger correlation).
            'land_nonce' => $action->nonce,
            'authorization_event_id' => $action->canonicalEventId,
            'observed_write_set' => array_values($action->files),
        ];
    }

    /** @return array<string,mixed> */
    private function validateCanonicalCommitAuthority(AuthorizedMergeAction $action): array
    {
        if ($action->authorityHash === '' || ! $action->authorityHashValid()) {
            return ['ok' => false, 'reason' => self::REASON_AUTHORITY_TAMPERED];
        }
        if ($action->revoked) {
            return ['ok' => false, 'reason' => self::REASON_AUTHORITY_REVOKED];
        }
        if ($action->canonicalEventId === '' || $action->canonicalEventHash === '' || $action->nonce === '') {
            return ['ok' => false, 'reason' => self::REASON_AUTHORITY_NOT_PERSISTED];
        }
        if ($action->expiresAt === '' || strtotime($action->expiresAt) === false || strtotime($action->expiresAt) <= time()) {
            return ['ok' => false, 'reason' => self::REASON_AUTHORITY_STALE];
        }

        $ledger = $this->canonicalLedger();
        $event = $ledger->eventById($action->canonicalEventId);
        $authority = $this->kernelEvidenceAuthority ?? app(KernelEvidenceAuthority::class);
        if ($event === null || ! $authority->verifyReleaseAuthorization($event)) {
            return ['ok' => false, 'reason' => self::REASON_AUTHORITY_NOT_PERSISTED];
        }
        $persistedHash = (string) ($event->event_hash ?: $event->payload_hash);
        if (! hash_equals($persistedHash, $action->canonicalEventHash)) {
            return ['ok' => false, 'reason' => self::REASON_AUTHORITY_TAMPERED];
        }
        $payload = $event->getAttribute('payload');
        if (! is_array($payload)) {
            return ['ok' => false, 'reason' => self::REASON_AUTHORITY_TAMPERED];
        }
        $bindings = [
            'event_name' => 'release.authorized',
            'task_packet_id' => $action->taskPacketId,
            'action' => self::ACTION_COMMIT,
            'candidate_hash' => $action->candidateHash,
            'decision_hash' => $action->decisionHash,
            'verification_hash' => $action->verificationHash,
            'rollback_hash' => $action->rollbackHash,
            'scope_hash' => $action->scopeHash,
            'base_commit' => $action->baseCommit,
            'tree_hash' => $action->treeHash,
            'lease_id' => $action->leaseId,
            'lease_owner' => (string) ($action->metadata['lease_owner'] ?? ''),
            'fencing_token' => $action->fencingToken,
            'nonce' => $action->nonce,
            'issued_at' => $action->issuedAt,
            'expires_at' => $action->expiresAt,
            'order_hash' => $action->orderHash,
            'delivery_id' => $action->deliveryId,
            'evidence_hash' => $action->evidenceHash,
        ];
        foreach ($bindings as $key => $expected) {
            if (($payload[$key] ?? null) !== $expected) {
                return ['ok' => false, 'reason' => self::REASON_AUTHORITY_TAMPERED, 'binding' => $key];
            }
        }
        $eventFiles = array_values(array_map('strval', (array) ($payload['changed_files'] ?? [])));
        sort($eventFiles, SORT_STRING);
        $actionFiles = $action->files;
        sort($actionFiles, SORT_STRING);
        if ($eventFiles !== $actionFiles || ! str_starts_with((string) ($payload['rollback_posture'] ?? ''), 'revertible:')) {
            return ['ok' => false, 'reason' => self::REASON_AUTHORITY_TAMPERED, 'binding' => 'files_or_rollback_posture'];
        }
        if ($ledger->latestForCorrelation($action->nonce, 'release.landed') !== null) {
            return ['ok' => false, 'reason' => 'authority_nonce_replayed'];
        }

        $repo = $this->repoRoot();
        $head = trim((string) $this->git($repo, ['rev-parse', 'HEAD'])['out']);
        if ($head === '' || ! hash_equals($action->baseCommit, $head)) {
            return ['ok' => false, 'reason' => 'stale_base_commit'];
        }
        $files = $action->files;
        sort($files, SORT_STRING);
        if ($files === [] || ! hash_equals($action->scopeHash, $this->canonicalScopeHash($files))) {
            return ['ok' => false, 'reason' => self::REASON_OUT_OF_SCOPE_FILE];
        }
        $allowed = $this->resolveAllowedFiles($action->taskPacketId);
        sort($allowed, SORT_STRING);
        // Dev senior-loop fixtures use lease_id dev-* and delivery ids outside the
        // task-serving queue — there is no queue record of allowed_files. Honor the
        // AuthorizedMergeAction file set when the lease is the Dev-scoped fixture lease.
        if ($allowed === [] && str_starts_with($action->leaseId, 'dev-') && $files !== []) {
            $allowed = $files;
        }
        if ($allowed === [] || $files !== $allowed) {
            return ['ok' => false, 'reason' => self::REASON_OUT_OF_SCOPE_FILE];
        }
        $diff = (string) $this->git($repo, array_merge(['diff', '--binary', '--'], $files))['out'];
        if (! hash_equals($action->treeHash, hash('sha256', $diff))) {
            return ['ok' => false, 'reason' => 'stale_candidate_tree'];
        }
        if (! $this->leaseIsLive($action->leaseId, (string) ($action->metadata['lease_owner'] ?? ''), $action->fencingToken)) {
            return ['ok' => false, 'reason' => 'lost_lease_or_fencing'];
        }

        return ['ok' => true];
    }

    /** @param list<string> $files */
    private function canonicalScopeHash(array $files): string
    {
        return hash('sha256', (string) json_encode(array_values($files), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function canonicalLedger(): AtlasEvidenceLedger
    {
        return $this->evidenceLedger ?? app(AtlasEvidenceLedger::class);
    }

    private function leaseIsLive(string $leaseId, string $leaseOwner, int $fencingToken): bool
    {
        if ($this->leaseValidator !== null) {
            return ($this->leaseValidator)($leaseId, $fencingToken, $leaseOwner);
        }
        if ($leaseId === '' || $leaseOwner === '' || $fencingToken < 1 || ! Schema::hasTable('atlas_task_scope_reservations')) {
            return false;
        }

        return DB::table('atlas_task_scope_reservations')
            ->where('id', $leaseId)
            ->where('state', 'active')
            ->where('lease_owner', $leaseOwner)
            ->where('fencing_token', $fencingToken)
            ->where('lease_expires_at', '>', now())
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function refuse(string $taskPacketId, bool $dryRun, string $reason, array $extra = []): array
    {
        $sha = (string) ($extra['sha'] ?? '');
        $files = (array) ($extra['files'] ?? []);
        $this->recordDecision($taskPacketId, $sha, $files, AtlasMergeGovernorAdmissionPolicy::DECISION_REJECTED, [$reason], 'high');

        return $this->zeroEffect($taskPacketId, $dryRun, $reason, $extra);
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function zeroEffect(string $taskPacketId, bool $dryRun, string $reason, array $extra = []): array
    {
        return array_merge([
            'schema' => self::SCHEMA,
            'task_packet_id' => $taskPacketId,
            'dry_run' => $dryRun,
            'refused' => true,
            'reverted' => false,
            'zero_effect' => true,
            'reason' => $reason,
        ], $extra);
    }

    /**
     * @return array<string,mixed>
     */
    private function validateAuthority(AuthorizedMergeAction $action): array
    {
        if (! $action->authorityHashValid()) {
            return ['ok' => false, 'reason' => self::REASON_AUTHORITY_TAMPERED];
        }
        if ($action->revoked) {
            return ['ok' => false, 'reason' => self::REASON_AUTHORITY_REVOKED];
        }
        if ($action->expiresAt === '' || strtotime($action->expiresAt) === false || strtotime($action->expiresAt) <= time()) {
            return ['ok' => false, 'reason' => self::REASON_AUTHORITY_STALE];
        }

        $row = $this->authorityLedgerRow($action);
        if ($row === null) {
            return ['ok' => false, 'reason' => self::REASON_AUTHORITY_NOT_PERSISTED];
        }

        if ((string) ($row['decision'] ?? '') !== AtlasMergeGovernorAdmissionPolicy::DECISION_ADMITTED) {
            return ['ok' => false, 'reason' => self::REASON_AUTHORITY_REVOKED];
        }
        if (! hash_equals((string) ($row['candidate_hash'] ?? ''), $action->candidateHash)) {
            return ['ok' => false, 'reason' => self::REASON_CANDIDATE_HASH_TAMPERED];
        }
        if (! hash_equals((string) ($row['decision_hash'] ?? ''), $action->decisionHash)) {
            return ['ok' => false, 'reason' => self::REASON_AUTHORITY_TAMPERED];
        }

        $rollbackPosture = trim((string) ($row['rollback_posture'] ?? ''));
        if ($rollbackPosture === '') {
            return ['ok' => false, 'reason' => self::REASON_ROLLBACK_POSTURE_MISSING];
        }
        if (! str_starts_with($rollbackPosture, 'revertible:')) {
            return ['ok' => false, 'reason' => self::REASON_ROLLBACK_POSTURE_NOT_REVERTIBLE];
        }

        $repo = $this->repoRoot();
        $candidates = $this->resolveLandedCommits($repo, $action->taskPacketId);
        if (count($candidates) !== 1) {
            return ['ok' => false, 'reason' => self::REASON_AMBIGUOUS_SHA, 'candidate_count' => count($candidates), 'candidates' => $candidates];
        }
        $sha = $candidates[0];
        if ($action->targetSha !== '' && ! hash_equals($action->targetSha, $sha)) {
            return ['ok' => false, 'reason' => self::REASON_CANDIDATE_HASH_TAMPERED, 'sha' => $sha];
        }

        $files = $this->changedFiles($repo, $sha);
        $actionFiles = $action->files;
        sort($actionFiles, SORT_STRING);
        if ($actionFiles !== [] && $actionFiles !== $files) {
            return ['ok' => false, 'reason' => self::REASON_CANDIDATE_HASH_TAMPERED, 'sha' => $sha, 'files' => $files];
        }

        $expectedCandidateHash = $this->candidateHash($action->taskPacketId, $sha, $files);
        if (! hash_equals($expectedCandidateHash, $action->candidateHash)) {
            return ['ok' => false, 'reason' => self::REASON_CANDIDATE_HASH_TAMPERED, 'sha' => $sha, 'files' => $files];
        }
        $expectedChangedFilesHash = $this->changedFilesHash($files);
        if ((string) ($row['changed_files_hash'] ?? '') !== '' && ! hash_equals((string) $row['changed_files_hash'], $expectedChangedFilesHash)) {
            return ['ok' => false, 'reason' => self::REASON_CANDIDATE_HASH_TAMPERED, 'sha' => $sha, 'files' => $files];
        }

        $dirty = $this->dirtyFiles($repo, $files);
        if ($dirty !== []) {
            return ['ok' => false, 'reason' => self::REASON_DIRTY_WORKING_TREE_FILE, 'sha' => $sha, 'files' => $dirty];
        }

        return ['ok' => true, 'sha' => $sha, 'files' => $files, 'row' => $row];
    }

    private function validateCanonicalRevertAuthority(AuthorizedMergeAction $action): bool
    {
        if ($action->canonicalEventId === '' || $action->canonicalEventHash === '' || $action->orderHash === ''
            || $action->deliveryId === '' || $action->evidenceHash === ''
            || ($this->leaseValidator !== null && ! ($this->leaseValidator)($action->leaseId, $action->fencingToken))) {
            return false;
        }
        $ledger = $this->canonicalLedger();
        $event = $ledger->eventById($action->canonicalEventId);
        $payload = $event?->getAttribute('payload');
        $authority = $this->kernelEvidenceAuthority ?? app(KernelEvidenceAuthority::class);
        if ($event === null || ! $authority->verifyReleaseAuthorization($event) || ! is_array($payload)) {
            return false;
        }
        $persistedHash = (string) ($event->getAttribute('event_hash') ?: $event->getAttribute('payload_hash'));
        if ($persistedHash === '' || ! hash_equals($persistedHash, $action->canonicalEventHash)) {
            return false;
        }
        foreach (['event_name' => 'release.authorized', 'task_packet_id' => $action->taskPacketId,
            'action' => self::ACTION_REVERT_TASK, 'candidate_hash' => $action->candidateHash,
            'decision_hash' => $action->decisionHash, 'verification_hash' => $action->verificationHash,
            'rollback_hash' => $action->rollbackHash, 'changed_files' => $action->files,
            'scope_hash' => $action->scopeHash, 'base_commit' => $action->baseCommit,
            'tree_hash' => $action->treeHash, 'lease_id' => $action->leaseId,
            'lease_owner' => (string) ($action->metadata['lease_owner'] ?? ''),
            'fencing_token' => $action->fencingToken, 'nonce' => $action->nonce,
            'issued_at' => $action->issuedAt, 'expires_at' => $action->expiresAt,
            'order_hash' => $action->orderHash, 'delivery_id' => $action->deliveryId,
            'evidence_hash' => $action->evidenceHash] as $key => $expected) {
            if (($payload[$key] ?? null) !== $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function authorityLedgerRow(AuthorizedMergeAction $action): ?array
    {
        $ledger = new AtlasMergeGovernorReleaseDecisionLedger($action->releaseLedgerPath);
        foreach ($ledger->all() as $row) {
            if ((string) ($row['decision_hash'] ?? '') === $action->decisionHash) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $files
     * @param  list<string>  $reasons
     */
    private function recordDecision(string $taskPacketId, string $sha, array $files, string $decision, array $reasons, string $riskLevel): void
    {
        try {
            $this->appendReleaseDecision($taskPacketId, $sha, $files, $decision, $reasons, $riskLevel, $sha !== '' ? 'revertible:git_revert_scoped_commit' : 'blocked:unresolved_target');
        } catch (Throwable) {
            // Receipt-writing is best-effort audit trail; a ledger failure must never
            // block the fail-closed safety decision already made above.
        }
    }

    /**
     * @param  list<string>  $files
     * @param  list<string>  $reasons
     * @return array<string,mixed>
     */
    private function appendReleaseDecision(string $taskPacketId, string $sha, array $files, string $decision, array $reasons, string $riskLevel, string $rollbackPosture, array $prepareBinding = []): array
    {
        $ledger = $this->ledger();
        $append = $ledger->append([
            'task_packet_id' => $taskPacketId !== '' ? $taskPacketId : 'unknown',
            'candidate_hash' => $this->candidateHash($taskPacketId, $sha, $files),
            'decision' => $decision,
            'reasons' => array_values(array_map('strval', $reasons)),
            'risk_level' => $riskLevel !== '' ? $riskLevel : 'unknown',
            'verification_hash' => hash('sha256', $sha !== '' ? $sha : 'unresolved:'.$taskPacketId),
            'rollback_hash' => $this->rollbackHash($sha),
            'changed_files_hash' => $this->changedFilesHash($files),
            'project_lane' => ['project_id' => 'atlas-server'],
            'decided_at' => date(DATE_ATOM),
            'evidence_refs' => [$sha !== '' ? 'git_commit:'.$sha : 'git_commit:unresolved'],
            'rollback_posture' => $rollbackPosture,
            'rejected_alternatives' => $decision === AtlasMergeGovernorAdmissionPolicy::DECISION_ADMITTED ? [] : ['act_without_authorized_merge_action'],
            'post_release_learning_hooks' => $decision === AtlasMergeGovernorAdmissionPolicy::DECISION_ADMITTED ? ['merge_actuator_effect_settlement_required'] : [],
            'prepare_binding' => $prepareBinding,
        ]);

        return is_array($append['row'] ?? null) ? $append['row'] : [];
    }

    /**
     * @param  list<string>  $files
     * @return array<string,mixed>
     */
    private function recordEffectSettlement(AuthorizedMergeAction $action, string $effectSha, array $files): array
    {
        $ledger = new AtlasMergeGovernorReleaseDecisionLedger($action->settlementLedgerPath());
        $append = $ledger->append([
            'task_packet_id' => $action->taskPacketId,
            'candidate_hash' => hash('sha256', 'settle:'.$action->candidateHash.':'.$effectSha),
            'decision' => AtlasMergeGovernorAdmissionPolicy::DECISION_ADMITTED,
            'reasons' => ['effect_settled'],
            'risk_level' => $action->riskLevel !== '' ? $action->riskLevel : 'medium',
            'verification_hash' => hash('sha256', 'post_effect:'.$effectSha),
            'rollback_hash' => $action->rollbackHash !== '' ? $action->rollbackHash : $this->rollbackHash($action->targetSha),
            'changed_files_hash' => $action->changedFilesHash !== '' ? $action->changedFilesHash : $this->changedFilesHash($files),
            'project_lane' => ['project_id' => 'atlas-server'],
            'decided_at' => date(DATE_ATOM),
            'evidence_refs' => ['authority:'.$action->decisionHash, 'effect:'.$effectSha],
            'rollback_posture' => 'settled:revert_commit',
            'rejected_alternatives' => [],
            'post_release_learning_hooks' => ['release_effect_observed'],
        ]);

        return [
            'status' => (string) ($append['status'] ?? 'error'),
            'decision_hash' => (string) data_get($append, 'row.decision_hash', ''),
            'ledger_path' => $ledger->path(),
        ];
    }

    /** @param  list<string>  $files */
    private function candidateHash(string $taskPacketId, string $sha, array $files): string
    {
        $sortedFiles = $files;
        sort($sortedFiles, SORT_STRING);

        return hash('sha256', (string) json_encode([
            'action' => self::ACTION_REVERT_TASK,
            'task_packet_id' => $taskPacketId,
            'sha' => $sha,
            'files' => $sortedFiles,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param  list<string>  $files */
    private function changedFilesHash(array $files): string
    {
        $sortedFiles = $files;
        sort($sortedFiles, SORT_STRING);

        return hash('sha256', implode(',', $sortedFiles));
    }

    private function rollbackHash(string $sha): string
    {
        return hash('sha256', 'revert_of:'.($sha !== '' ? $sha : 'unresolved'));
    }

    private function ledger(): AtlasMergeGovernorReleaseDecisionLedger
    {
        return $this->ledgerOverride ?? new AtlasMergeGovernorReleaseDecisionLedger(
            storage_path('atlas/governance/merge-governor-release-decision-ledger.jsonl'),
        );
    }

    /** @return list<string> */
    private function resolveAllowedFiles(string $taskPacketId): array
    {
        if ($this->allowedFilesResolver !== null) {
            return array_values(array_map('strval', ($this->allowedFilesResolver)($taskPacketId)));
        }

        $record = AtlasTaskServingStack::queueRepo()->get($taskPacketId);
        if ($record === null) {
            return [];
        }

        $allowed = data_get($record, 'task_packet.normalized_scope.allowed_files')
            ?? data_get($record, 'task_packet.allowed_files')
            ?? data_get($record, 'allowed_files')
            ?? [];

        return array_values(array_map('strval', (array) $allowed));
    }

    /**
     * Scans `git log` for commits whose message carries the EXACT marker line
     * {@see AtlasTaskScopedCommitter::commitMessage()} writes: "Atlas-Task: <id>". Exact
     * line matching (not substring) avoids a "task-1" prefix colliding with "task-10".
     *
     * @return list<string>
     */
    private function resolveLandedCommits(string $repo, string $taskPacketId): array
    {
        $marker = 'Atlas-Task: '.$taskPacketId;
        $log = $this->git($repo, ['log', '--all', '--format=%H%x1e%B%x1d']);
        $out = (string) $log['out'];
        if ($out === '') {
            return [];
        }

        $shas = [];
        foreach (explode("\x1d", $out) as $entry) {
            $entry = trim($entry, "\n");
            if ($entry === '') {
                continue;
            }
            [$sha, $body] = array_pad(explode("\x1e", $entry, 2), 2, '');
            $lines = explode("\n", $body);
            foreach ($lines as $line) {
                if (trim($line) === $marker) {
                    $shas[] = $sha;
                    break;
                }
            }
        }

        return array_values(array_unique($shas));
    }

    /** @return list<string> */
    private function changedFiles(string $repo, string $sha): array
    {
        $result = $this->git($repo, ['diff-tree', '--no-commit-id', '--name-only', '-r', $sha]);
        $files = array_values(array_filter(array_map('trim', explode("\n", (string) $result['out'])), static fn (string $f): bool => $f !== ''));
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @param  list<string>  $files
     * @return list<string>
     */
    private function dirtyFiles(string $repo, array $files): array
    {
        if ($files === []) {
            return [];
        }
        $result = $this->git($repo, array_merge(['status', '--porcelain', '--'], $files));
        $out = (string) $result['out'];
        if (trim($out) === '') {
            return [];
        }
        $dirty = [];
        // Do NOT trim $out before splitting: porcelain's 2-char status code is followed by a
        // single leading space that a whole-string trim() would eat, shifting the substr(3)
        // offset and truncating the first character of the path.
        foreach (explode("\n", $out) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $dirty[] = trim(substr($line, 3));
        }

        return array_values(array_unique($dirty));
    }

    /**
     * @param  callable():array<string,mixed>  $callback
     * @return array<string, mixed>
     */
    private function withCommitLock(string $repo, callable $callback): array
    {
        $lockPath = $repo.'/'.AtlasTaskScopedCommitter::LOCK_REL;
        $handle = @fopen($lockPath, 'c');
        if ($handle === false) {
            return ['schema' => self::SCHEMA, 'reverted' => false, 'refused' => true, 'reason' => 'lock_open_failed'];
        }

        $deadline = microtime(true) + self::LOCK_TIMEOUT_SECONDS;
        try {
            while (true) {
                if (flock($handle, LOCK_EX | LOCK_NB)) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    return ['schema' => self::SCHEMA, 'reverted' => false, 'refused' => true, 'reason' => 'commit_lock_contended'];
                }
                usleep(self::LOCK_POLL_MICROSECONDS);
            }

            return $callback();
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /**
     * @param  list<string>  $args
     * @return array{code:int, out:string, err:string}
     */
    private function git(string $repo, array $args): array
    {
        $process = new Process(array_merge(['git'], $args), $repo);
        $process->setTimeout(60);
        try {
            $process->run();
        } catch (Throwable $e) {
            return ['code' => 1, 'out' => '', 'err' => $e->getMessage()];
        }

        return ['code' => (int) $process->getExitCode(), 'out' => $process->getOutput(), 'err' => $process->getErrorOutput()];
    }

    private function repoRoot(): string
    {
        return $this->repoRootOverride ?? base_path();
    }
}

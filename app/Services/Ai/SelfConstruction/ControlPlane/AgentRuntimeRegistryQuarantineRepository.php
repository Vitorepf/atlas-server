<?php

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\ControlPlane\Support\DiskJsonIndexLoader;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use App\Services\Ai\SelfConstruction\Support\EncodesPayloadAsPrettyJson;
use App\Services\Ai\SelfConstruction\Support\HashesPayloadCanonically;

/**
 * Persistent local quarantine ledger for Agent Control Plane agents.
 *
 * Persists quarantine state under
 * `atlas/self-construction/agent-control-plane/agent-quarantine/`.
 *
 * Runtime-safe: never dispatches, never claims, never calls providers,
 * never spends tokens, never writes the evidence ledger. Quarantine
 * blocks availability; release requires reviewer + reason. There is no
 * silent release.
 *
 * scope (AC2): an optional bounded list of capabilities/task_families/allowed_files this
 * quarantine restricts. Defaults to [] (unrestricted — blocks everything), purely additive.
 *
 * Evidence-gated release (AC3, opt-in): when a quarantine is declared with
 * require_evidence_for_release=true, release() also requires a non-empty evidence_refs list,
 * blocking with release_evidence_refs_missing otherwise. Quarantines that never set this flag
 * (every pre-existing caller) keep release()'s original reviewer+reason-only contract exactly.
 * Either way, the release receipt and release_history entry are appended, never deleting or
 * rewriting the original quarantine history.
 *
 * Dispatch block classification (AC4): dispatchBlock() reports quarantine_status distinguishing
 * active (currently quarantined, retry window not yet passed), expired (quarantined but
 * retry_after_at has passed — the time-based block has lapsed even though no explicit release()
 * has happened), released (explicitly released) and unrelated (never quarantined at all).
 * dispatch_block is true only for active.
 */
final class AgentRuntimeRegistryQuarantineRepository
{
    use AgentRuntimeRegistryStorageConcerns;

    use HashesPayloadCanonically;
    use EncodesPayloadAsPrettyJson;
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_runtime_registry_quarantine.v1';

    public const MODE = 'persistent_local_agent_runtime_registry_quarantine';

    public const STORAGE_PREFIX = 'atlas/self-construction/agent-control-plane/agent-quarantine';

    public const INDEX_PATH = self::STORAGE_PREFIX.'/index.json';

    public const LOCK_PATH = self::STORAGE_PREFIX.'/.lock';

    public const DEFAULT_DISK = 'local';

    public const REASONS = [
        'stale_heartbeat',
        'lease_violation',
        'scope_violation',
        'missing_continuation_summary',
        'evidence_failure',
        'operator_disabled',
        'runtime_safety_violation',
    ];

    public const TARGET_TYPE_WORKER      = 'worker';
    public const TARGET_TYPE_TASK_FAMILY = 'task_family';

    public const TARGET_TYPES = [self::TARGET_TYPE_WORKER, self::TARGET_TYPE_TASK_FAMILY];

    public function __construct(
        private readonly ?string $disk = null,
    ) {}

    /**
     * @param  array<string, mixed>  $reason
     * @return array<string, mixed>
     */
    public function quarantine(string $agentId, array $reason): array
    {
        return $this->withLock(function () use ($agentId, $reason): array {
            if (! $this->isValidAgentId($agentId)) {
                return $this->envelopeError('invalid_agent_id', $agentId);
            }

            $code = (string) ($reason['code'] ?? '');
            if (! in_array($code, self::REASONS, true)) {
                return $this->envelopeError('invalid_reason', $agentId, [
                    'requested_reason' => $code,
                    'allowed_reasons' => self::REASONS,
                ]);
            }
            $detail = (string) ($reason['detail'] ?? '');
            $declaredBy = (string) ($reason['declared_by'] ?? '');
            if ($declaredBy === '') {
                return $this->envelopeError('declared_by_missing', $agentId);
            }

            $targetType = (string) ($reason['target_type'] ?? self::TARGET_TYPE_WORKER);
            if (! in_array($targetType, self::TARGET_TYPES, true)) {
                $targetType = self::TARGET_TYPE_WORKER;
            }
            $evidence = is_array($reason['evidence'] ?? null)
                ? array_values(array_map('strval', $reason['evidence']))
                : array_values(array_filter([(string) ($reason['evidence'] ?? '')], static fn (string $e): bool => $e !== ''));
            $releaseCondition = (string) ($reason['release_condition'] ?? '');
            $retryAfterMinutes = max(0, (int) ($reason['retry_after_minutes'] ?? 0));
            $scope = is_array($reason['scope'] ?? null)
                ? array_values(array_map('strval', $reason['scope']))
                : array_values(array_filter([(string) ($reason['scope'] ?? '')], static fn (string $s): bool => $s !== ''));
            $requireEvidenceForRelease = (bool) ($reason['require_evidence_for_release'] ?? false);

            $now = CarbonImmutable::now();
            $nowIso = $now->toIso8601String();
            $existing = $this->readQuarantineFile($agentId);
            $entryId = (string) Str::uuid();

            $record = [
                'schema_version' => self::SCHEMA_VERSION,
                'agent_id' => $agentId,
                'target_type' => $targetType,
                'is_quarantined' => true,
                'quarantine_entry_id' => $entryId,
                'reason_code' => $code,
                'reason_detail' => $detail,
                'evidence' => $evidence,
                'release_condition' => $releaseCondition,
                'retry_after_minutes' => $retryAfterMinutes,
                'retry_after_at' => $retryAfterMinutes > 0 ? $now->addMinutes($retryAfterMinutes)->toIso8601String() : null,
                'scope' => $scope,
                'require_evidence_for_release' => $requireEvidenceForRelease,
                'declared_by' => $declaredBy,
                'declared_at' => $nowIso,
                'released' => false,
                'release_history' => $existing['release_history'] ?? [],
                'history' => $existing['history'] ?? [],
                'receipts' => $existing['receipts'] ?? [],
                'runtime_execution_allowed' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
                'ledger_write_allowed' => false,
            ];
            $now = $nowIso;
            $record['history'][] = [
                'event' => 'quarantined',
                'at' => $now,
                'reason_code' => $code,
                'reason_detail' => $detail,
                'declared_by' => $declaredBy,
            ];
            $record['receipts'][] = [
                'receipt_kind' => 'quarantine_declared',
                'recorded_at' => $now,
                'reason_code' => $code,
                'declared_by' => $declaredBy,
                'receipt_hash' => $this->stableHash([
                    'agent_id' => $agentId,
                    'entry_id' => $entryId,
                    'reason_code' => $code,
                ]),
            ];

            $this->writeQuarantineFile($agentId, $record);
            $this->updateIndex($agentId, $record);

            return $this->envelopeOk('quarantined', $record);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function release(string $agentId, array $payload): array
    {
        return $this->withLock(function () use ($agentId, $payload): array {
            if (! $this->isValidAgentId($agentId)) {
                return $this->envelopeError('invalid_agent_id', $agentId);
            }
            $reviewer = (string) ($payload['reviewer'] ?? '');
            $reason = (string) ($payload['reason'] ?? '');
            if ($reviewer === '') {
                return $this->envelopeError('reviewer_missing', $agentId);
            }
            if ($reason === '') {
                return $this->envelopeError('release_reason_missing', $agentId);
            }
            $record = $this->readQuarantineFile($agentId);
            if ($record === null) {
                return $this->envelopeError('not_quarantined', $agentId);
            }
            if ((bool) ($record['is_quarantined'] ?? false) !== true) {
                return $this->envelopeError('agent_not_currently_quarantined', $agentId);
            }

            $evidenceRefs = is_array($payload['evidence_refs'] ?? null)
                ? array_values(array_filter(array_map('strval', $payload['evidence_refs']), static fn (string $e): bool => $e !== ''))
                : [];
            if ((bool) ($record['require_evidence_for_release'] ?? false) && $evidenceRefs === []) {
                return $this->envelopeError('release_evidence_refs_missing', $agentId);
            }

            $now = CarbonImmutable::now()->toIso8601String();
            $record['is_quarantined'] = false;
            $record['released'] = true;
            $record['released_at'] = $now;
            $record['released_by'] = $reviewer;
            $record['release_reason'] = $reason;
            $record['release_evidence_refs'] = $evidenceRefs;
            $record['release_history'][] = [
                'at' => $now,
                'reviewer' => $reviewer,
                'reason' => $reason,
                'evidence_refs' => $evidenceRefs,
                'previous_reason_code' => (string) ($record['reason_code'] ?? ''),
            ];
            $record['history'][] = [
                'event' => 'released',
                'at' => $now,
                'reviewer' => $reviewer,
                'reason' => $reason,
                'evidence_refs' => $evidenceRefs,
            ];
            $record['receipts'][] = [
                'receipt_kind' => 'quarantine_released',
                'recorded_at' => $now,
                'reviewer' => $reviewer,
                'reason' => $reason,
                'evidence_refs' => $evidenceRefs,
                'receipt_hash' => $this->stableHash([
                    'agent_id' => $agentId,
                    'reviewer' => $reviewer,
                    'reason' => $reason,
                    'evidence_refs' => $evidenceRefs,
                    'at' => $now,
                ]),
            ];

            $this->writeQuarantineFile($agentId, $record);
            $this->updateIndex($agentId, $record);

            return $this->envelopeOk('released', $record);
        });
    }

    /**
     * @param  array<string, mixed>  $reason
     * @return array<string, mixed>
     */
    public function quarantineTaskFamily(string $taskFamilyId, array $reason): array
    {
        return $this->quarantine($taskFamilyId, array_merge($reason, ['target_type' => self::TARGET_TYPE_TASK_FAMILY]));
    }

    /**
     * Single read-only decision the dispatch matcher can consume directly:
     * is this target blocked right now, and if so what's the concrete release hint?
     *
     * quarantine_status (AC4) distinguishes: active (currently blocking), expired (quarantined
     * but retry_after_at has passed — no longer blocks dispatch on time grounds alone), released
     * (explicitly released), and unrelated (this target id was never quarantined).
     *
     * @return array{schema_version:string, target_id:string, dispatch_block:bool, target_type:?string, release_hint:?string, retry_after_at:?string, quarantine_status:string}
     */
    public function dispatchBlock(string $targetId): array
    {
        $record = $this->readQuarantineFile($targetId);
        $isQuarantined = $record !== null && (bool) ($record['is_quarantined'] ?? false);
        $retryAfterAt = $record['retry_after_at'] ?? null;

        // Corrupt file: fail closed — treat as active quarantine
        $isCorrupt = $record === null && $this->isQuarantineFileCorrupt($targetId);
        if ($isCorrupt) {
            $isQuarantined = true;
        }

        $isExpired = false;
        if ($isQuarantined && $retryAfterAt !== null) {
            try {
                $isExpired = CarbonImmutable::now()->greaterThanOrEqualTo(CarbonImmutable::parse((string) $retryAfterAt));
            } catch (Throwable) {
                $isExpired = false;
            }
        }

        $quarantineStatus = match (true) {
            $record === null && ! $isCorrupt => 'unrelated',
            $isCorrupt => 'active',
            ! $isQuarantined => 'released',
            $isExpired => 'expired',
            default => 'active',
        };
        $dispatchBlockFlag = $quarantineStatus === 'active';

        $releaseHint = null;
        if ($isQuarantined) {
            $releaseCondition = $isCorrupt ? '' : ((string) ($record['release_condition'] ?? ''));
            $releaseHint = $releaseCondition !== ''
                ? $releaseCondition
                : ($retryAfterAt !== null
                    ? sprintf('retry not before %s', $retryAfterAt)
                    : 'requires manual review() and release() by a reviewer with reason');
        }

        return [
            'schema_version'     => self::SCHEMA_VERSION,
            'target_id'          => $targetId,
            'dispatch_block'     => $dispatchBlockFlag,
            'target_type'        => $isCorrupt ? null : ($record['target_type'] ?? null),
            'release_hint'       => $releaseHint,
            'retry_after_at'     => $retryAfterAt,
            'quarantine_status'  => $quarantineStatus,
        ];
    }

    public function isQuarantined(string $agentId): bool
    {
        if (! $this->isValidAgentId($agentId)) {
            return false;
        }
        $record = $this->readQuarantineFile($agentId);
        if ($record === null) {
            // File missing or corrupt — distinguish: corrupt = fail closed (treat as quarantined)
            if ($this->isQuarantineFileCorrupt($agentId)) {
                return true;
            }
            return false;
        }

        return (bool) ($record['is_quarantined'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        $onlyActive = (bool) ($filters['only_active'] ?? false);
        $reasonCode = isset($filters['reason_code']) ? (string) $filters['reason_code'] : '';
        $limit = isset($filters['limit']) ? (int) $filters['limit'] : 0;

        $results = [];
        foreach ($this->loadIndex() as $entry) {
            $agentId = (string) ($entry['agent_id'] ?? '');
            if ($agentId === '') {
                continue;
            }
            $record = $this->readQuarantineFile($agentId);
            if ($record === null) {
                continue;
            }
            if ($onlyActive && ! (bool) ($record['is_quarantined'] ?? false)) {
                continue;
            }
            if ($reasonCode !== '' && (string) ($record['reason_code'] ?? '') !== $reasonCode) {
                continue;
            }
            $results[] = $record;
            if ($limit > 0 && count($results) >= $limit) {
                break;
            }
        }

        usort($results, static fn (array $a, array $b): int => strcmp((string) $a['agent_id'], (string) $b['agent_id']));

        return $results;
    }

    /**
     * @return list<string>
     */
    public function activeAgentIds(): array
    {
        $ids = [];
        foreach ($this->list(['only_active' => true]) as $record) {
            $ids[] = (string) ($record['agent_id'] ?? '');
        }

        return array_values(array_filter($ids, static fn (string $v): bool => $v !== ''));
    }

    /**
     * @return array<string, bool>
     */
    public function runtimeFlags(): array
    {
        return [
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readQuarantineFile(string $agentId): ?array
    {
        if (! $this->isValidAgentId($agentId)) {
            return null;
        }
        $path = $this->agentPath($agentId);
        $disk = $this->disk();
        if (! $disk->exists($path)) {
            return null;
        }
        $raw = (string) $disk->get($path);
        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        if (! is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * Returns true when the quarantine file exists but contains invalid JSON.
     * This distinguishes corrupt (fail-closed) from missing (never quarantined).
     */
    private function isQuarantineFileCorrupt(string $agentId): bool
    {
        if (! $this->isValidAgentId($agentId)) {
            return false;
        }
        $path = $this->agentPath($agentId);
        $disk = $this->disk();
        if (! $disk->exists($path)) {
            return false;
        }
        $raw = (string) $disk->get($path);
        try {
            json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function writeQuarantineFile(string $agentId, array $record): void
    {
        $this->disk()->put($this->agentPath($agentId), $this->encode($record));
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function updateIndex(string $agentId, array $record): void
    {
        $index = $this->loadIndex();
        $payload = [
            'agent_id' => $agentId,
            'is_quarantined' => (bool) ($record['is_quarantined'] ?? false),
            'reason_code' => (string) ($record['reason_code'] ?? ''),
            'declared_at' => (string) ($record['declared_at'] ?? ''),
            'released_at' => (string) ($record['released_at'] ?? ''),
        ];
        $found = false;
        foreach ($index as $i => $entry) {
            if ((string) ($entry['agent_id'] ?? '') === $agentId) {
                $index[$i] = $payload;
                $found = true;
                break;
            }
        }
        if (! $found) {
            $index[] = $payload;
        }
        $this->disk()->put(self::INDEX_PATH, $this->encode($index));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadIndex(): array
    {
        return DiskJsonIndexLoader::load($this->disk(), self::INDEX_PATH);
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function envelopeOk(string $event, array $record): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'event' => $event,
            'agent_id' => (string) ($record['agent_id'] ?? ''),
            'record' => $record,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
        ];
    }

}

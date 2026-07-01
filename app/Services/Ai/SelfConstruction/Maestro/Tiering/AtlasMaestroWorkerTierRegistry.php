<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Tiering;

use RuntimeException;
use Throwable;

/**
 * Persistent registry of declared per-client_id worker tier capacity. The single authority answering
 * "what tier can client X handle" — consulted by the Maestro routing policy.
 *
 * INVARIANTS:
 *   - Persistence is a single JSON snapshot under storage/atlas/maestro/worker-tier-registry.json
 *     (path overridable via constructor for tests).
 *   - WRITE-TEMP-THEN-RENAME — readers never see a torn write.
 *   - Snapshot key ordering: client_id ascending — byte-identical for same logical state.
 *   - declaredMaxTier ∈ {easy, hard, hardest} — anything else throws and IS NOT PERSISTED.
 *
 * Capability drift: recordCapabilityEvidence() attaches an observed_max_tier + evidence_recorded_at
 * to an already-registered client (re-register() intentionally WIPES stale evidence — a fresh
 * declaration requires fresh proof again). driftReport() compares declared vs observed tier and
 * evidence freshness to produce a deterministic upgrade/downgrade recommendation, reading ONLY
 * tier ranks and timestamps — never `meta` — so provider-sensitive meta content can never leak.
 */
final class AtlasMaestroWorkerTierRegistry
{
    public const ALLOWED_TIERS = [
        AtlasMaestroTaskTierClassifier::TIER_EASY,
        AtlasMaestroTaskTierClassifier::TIER_HARD,
        AtlasMaestroTaskTierClassifier::TIER_HARDEST,
    ];

    public function __construct(private readonly string $snapshotPath) {}

    /**
     * @param  array<string,mixed>  $meta
     * @return WorkerTierRecord
     */
    public function register(string $clientId, string $declaredMaxTier, array $meta = []): WorkerTierRecord
    {
        if ($clientId === '') {
            throw new RuntimeException('client_id is required');
        }
        if (! in_array($declaredMaxTier, self::ALLOWED_TIERS, true)) {
            throw new RuntimeException('declaredMaxTier outside allowed set: '.$declaredMaxTier);
        }
        $snapshot = $this->loadSnapshot();
        // Re-declaring intentionally wipes prior observed_max_tier/evidence_recorded_at — a fresh
        // declaration requires fresh proof again.
        $snapshot[$clientId] = ['client_id' => $clientId, 'declared_max_tier' => $declaredMaxTier, 'meta' => $meta, 'observed_max_tier' => '', 'evidence_recorded_at' => ''];
        $this->persistSnapshot($snapshot);

        return new WorkerTierRecord($clientId, $declaredMaxTier, $meta);
    }

    public function lookup(string $clientId): ?WorkerTierRecord
    {
        $snapshot = $this->loadSnapshot();
        if (! isset($snapshot[$clientId])) {
            return null;
        }
        $row = $snapshot[$clientId];

        return new WorkerTierRecord(
            (string) $row['client_id'],
            (string) $row['declared_max_tier'],
            (array) ($row['meta'] ?? []),
            (string) ($row['observed_max_tier'] ?? ''),
            (string) ($row['evidence_recorded_at'] ?? ''),
        );
    }

    /**
     * @return list<WorkerTierRecord>
     */
    public function list(): array
    {
        $out = [];
        foreach ($this->loadSnapshot() as $row) {
            $out[] = new WorkerTierRecord(
                (string) $row['client_id'],
                (string) $row['declared_max_tier'],
                (array) ($row['meta'] ?? []),
                (string) ($row['observed_max_tier'] ?? ''),
                (string) ($row['evidence_recorded_at'] ?? ''),
            );
        }

        return $out;
    }

    /**
     * Attaches proven current-capability evidence to an already-registered client — never creates
     * a new registration (returns null when the client is unknown, mirroring revoke()'s fail-open
     * bool-ish semantics). Declared tier and meta are left untouched.
     */
    public function recordCapabilityEvidence(string $clientId, string $observedMaxTier, string $evidenceRecordedAt): ?WorkerTierRecord
    {
        if (! in_array($observedMaxTier, self::ALLOWED_TIERS, true)) {
            throw new RuntimeException('observedMaxTier outside allowed set: '.$observedMaxTier);
        }
        $snapshot = $this->loadSnapshot();
        if (! isset($snapshot[$clientId])) {
            return null;
        }
        $snapshot[$clientId]['observed_max_tier'] = $observedMaxTier;
        $snapshot[$clientId]['evidence_recorded_at'] = $evidenceRecordedAt;
        $this->persistSnapshot($snapshot);

        return new WorkerTierRecord(
            $clientId,
            (string) $snapshot[$clientId]['declared_max_tier'],
            (array) $snapshot[$clientId]['meta'],
            $observedMaxTier,
            $evidenceRecordedAt,
        );
    }

    /**
     * Compares declared tier against the most recent observed capability evidence + its freshness
     * to produce a deterministic drift status and upgrade/downgrade recommendation. Reads ONLY tier
     * ranks and timestamps — never `meta` — so provider-sensitive meta content can never leak here.
     *
     * @return array{client_id:string, declared_max_tier:string, observed_max_tier:string, evidence_freshness:string, freshness_seconds:int|null, drift_status:string, recommendation:string}|null
     */
    public function driftReport(string $clientId, ?string $nowIso = null, int $staleAfterSeconds = 604800): ?array
    {
        $record = $this->lookup($clientId);
        if ($record === null) {
            return null;
        }

        $now = $nowIso !== null ? strtotime($nowIso) : time();
        $recordedAt = $record->evidenceRecordedAt !== '' ? strtotime($record->evidenceRecordedAt) : false;
        $freshnessSeconds = ($recordedAt !== false && $now !== false) ? max(0, $now - $recordedAt) : null;
        $isStale = $freshnessSeconds === null || $freshnessSeconds > $staleAfterSeconds;
        $evidenceFreshness = $isStale ? 'stale' : 'fresh';

        $declaredRank = $this->tierRank($record->declaredMaxTier);
        $observedRank = $record->observedMaxTier !== '' ? $this->tierRank($record->observedMaxTier) : null;

        if ($isStale) {
            $driftStatus = 'unverified';
            $recommendation = $declaredRank > 0 ? 'downgrade' : 'keep';
        } elseif ($observedRank === null) {
            $driftStatus = 'no_observed_signal';
            $recommendation = 'keep';
        } elseif ($observedRank > $declaredRank) {
            $driftStatus = 'drifted_up';
            $recommendation = 'upgrade';
        } elseif ($observedRank < $declaredRank) {
            $driftStatus = 'drifted_down';
            $recommendation = 'downgrade';
        } else {
            $driftStatus = 'aligned';
            $recommendation = 'keep';
        }

        return [
            'client_id' => $record->clientId,
            'declared_max_tier' => $record->declaredMaxTier,
            'observed_max_tier' => $record->observedMaxTier,
            'evidence_freshness' => $evidenceFreshness,
            'freshness_seconds' => $freshnessSeconds,
            'drift_status' => $driftStatus,
            'recommendation' => $recommendation,
        ];
    }

    private function tierRank(string $tier): int
    {
        return match ($tier) {
            AtlasMaestroTaskTierClassifier::TIER_EASY => 0,
            AtlasMaestroTaskTierClassifier::TIER_HARD => 1,
            AtlasMaestroTaskTierClassifier::TIER_HARDEST => 2,
            default => -1,
        };
    }

    /**
     * Derive the dispatch tier for a task packet based on local facts only.
     * No provider calls, no shell commands, no queue mutation.
     *
     * @param  array{risk_level?:string, required_evidence?:list<string>, allowed_files?:list<string>}  $taskPacket
     * @return array{assigned_tier:string, tier_candidates:list<array{tier:string,rationale:string}>, escalation_reasons:list<string>, local_facts:array{allowed_files_count:int,risk_level:string,evidence_count:int}}
     */
    public function tierFor(array $taskPacket): array
    {
        $riskLevel     = (string) ($taskPacket['risk_level'] ?? 'low');
        $allowedFiles  = array_values(array_filter(array_map('strval', (array) ($taskPacket['allowed_files'] ?? [])), static fn (string $f): bool => $f !== ''));
        $evidence      = array_values(array_filter(array_map('strval', (array) ($taskPacket['required_evidence'] ?? [])), static fn (string $e): bool => $e !== ''));

        $fileCount     = count($allowedFiles);
        $evidenceCount = count($evidence);

        $candidates        = [];
        $escalationReasons = [];

        // Signal 1: risk_level
        $riskTier = match ($riskLevel) {
            'low'           => AtlasMaestroTaskTierClassifier::TIER_EASY,
            'medium'        => AtlasMaestroTaskTierClassifier::TIER_HARD,
            default         => AtlasMaestroTaskTierClassifier::TIER_HARDEST,
        };
        if ($riskTier !== AtlasMaestroTaskTierClassifier::TIER_EASY) {
            $escalationReasons[] = 'risk_level:'.$riskLevel;
        }
        $candidates[] = ['tier' => $riskTier, 'rationale' => 'risk_level='.$riskLevel];

        // Signal 2: allowed_files breadth
        $fileTier = match (true) {
            $fileCount <= 1  => AtlasMaestroTaskTierClassifier::TIER_EASY,
            $fileCount <= 5  => AtlasMaestroTaskTierClassifier::TIER_HARD,
            default          => AtlasMaestroTaskTierClassifier::TIER_HARDEST,
        };
        if ($fileTier !== AtlasMaestroTaskTierClassifier::TIER_EASY) {
            $escalationReasons[] = 'allowed_files_count:'.$fileCount;
        }
        $candidates[] = ['tier' => $fileTier, 'rationale' => 'allowed_files_count='.$fileCount];

        // Signal 3: evidence requirements depth
        $evidenceTier = match (true) {
            $evidenceCount <= 1 => AtlasMaestroTaskTierClassifier::TIER_EASY,
            $evidenceCount <= 3 => AtlasMaestroTaskTierClassifier::TIER_HARD,
            default             => AtlasMaestroTaskTierClassifier::TIER_HARDEST,
        };
        if ($evidenceTier !== AtlasMaestroTaskTierClassifier::TIER_EASY) {
            $escalationReasons[] = 'evidence_count:'.$evidenceCount;
        }
        $candidates[] = ['tier' => $evidenceTier, 'rationale' => 'evidence_count='.$evidenceCount];

        $tierRank   = static fn (string $t): int => match ($t) {
            AtlasMaestroTaskTierClassifier::TIER_EASY    => 0,
            AtlasMaestroTaskTierClassifier::TIER_HARD    => 1,
            AtlasMaestroTaskTierClassifier::TIER_HARDEST => 2,
            default                                      => 99,
        };
        $assigned   = array_reduce($candidates, static function (string $max, array $c) use ($tierRank): string {
            return $tierRank($c['tier']) > $tierRank($max) ? $c['tier'] : $max;
        }, AtlasMaestroTaskTierClassifier::TIER_EASY);

        return [
            'assigned_tier'     => $assigned,
            'tier_candidates'   => $candidates,
            'escalation_reasons'=> array_values(array_unique($escalationReasons)),
            'local_facts'       => [
                'allowed_files_count' => $fileCount,
                'risk_level'          => $riskLevel,
                'evidence_count'      => $evidenceCount,
            ],
        ];
    }

    /**
     * Verify a list of worker tier definitions for structural integrity.
     * Blocks: duplicate tier_id, missing atlas_native tier, missing/zero max_concurrency,
     * any tier with steady_state_external_provider_required=true.
     *
     * @param  list<array<string,mixed>>  $tiers
     * @return array{passed:bool, blockers:list<string>}
     */
    public function verifyIntegrity(array $tiers): array
    {
        $blockers = [];
        $seenIds = [];
        $hasNative = false;

        foreach ($tiers as $tier) {
            if (! is_array($tier)) {
                continue;
            }
            $id = (string) ($tier['tier_id'] ?? '');

            if (isset($seenIds[$id])) {
                $blockers[] = 'duplicate_tier_id:'.$id;
            } else {
                $seenIds[$id] = true;
            }
            if ($id === 'atlas_native') {
                $hasNative = true;
            }
            $maxConcurrency = $tier['max_concurrency'] ?? null;
            if ($maxConcurrency === null || (int) $maxConcurrency <= 0) {
                $blockers[] = 'missing_max_concurrency:'.$id;
            }
            if ((bool) ($tier['steady_state_external_provider_required'] ?? false)) {
                $blockers[] = 'steady_state_external_provider_required:'.$id;
            }
        }
        if (! $hasNative) {
            $blockers[] = 'missing_atlas_native_tier';
        }

        return ['passed' => $blockers === [], 'blockers' => array_values($blockers)];
    }

    public function revoke(string $clientId): bool
    {
        $snapshot = $this->loadSnapshot();
        if (! isset($snapshot[$clientId])) {
            return false;
        }
        unset($snapshot[$clientId]);
        $this->persistSnapshot($snapshot);

        return true;
    }

    /**
     * @return array<string,array{client_id:string, declared_max_tier:string, meta:array<string,mixed>}>
     */
    private function loadSnapshot(): array
    {
        if (! is_file($this->snapshotPath)) {
            return [];
        }
        $raw = @file_get_contents($this->snapshotPath);
        if ($raw === false || $raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }
        if (! is_array($decoded)) {
            return [];
        }
        // The on-disk shape is a list ordered by client_id; reindex into the in-memory map.
        $map = [];
        foreach ($decoded as $row) {
            if (! is_array($row) || ! isset($row['client_id'])) {
                continue;
            }
            $map[(string) $row['client_id']] = [
                'client_id' => (string) $row['client_id'],
                'declared_max_tier' => (string) ($row['declared_max_tier'] ?? ''),
                'meta' => (array) ($row['meta'] ?? []),
                'observed_max_tier' => (string) ($row['observed_max_tier'] ?? ''),
                'evidence_recorded_at' => (string) ($row['evidence_recorded_at'] ?? ''),
            ];
        }

        return $map;
    }

    /**
     * @param  array<string,array{client_id:string, declared_max_tier:string, meta:array<string,mixed>}>  $snapshot
     */
    private function persistSnapshot(array $snapshot): void
    {
        $dir = dirname($this->snapshotPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        // On-disk shape = list ordered by client_id ascending (deterministic).
        ksort($snapshot, SORT_STRING);
        $orderedList = array_values($snapshot);
        $json = (string) json_encode($orderedList, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $tmp = $this->snapshotPath.'.tmp.'.bin2hex(random_bytes(6));
        $written = @file_put_contents($tmp, $json, LOCK_EX);
        if ($written === false) {
            throw new RuntimeException('Cannot write Maestro worker-tier registry snapshot');
        }
        if (! @rename($tmp, $this->snapshotPath)) {
            @unlink($tmp);
            throw new RuntimeException('Cannot atomically rename Maestro worker-tier registry snapshot into place');
        }
    }
}

/**
 * Immutable VO returned by {@see AtlasMaestroWorkerTierRegistry::lookup()} and ::list().
 */
final class WorkerTierRecord
{
    /**
     * @param  array<string,mixed>  $meta
     */
    public function __construct(
        public readonly string $clientId,
        public readonly string $declaredMaxTier,
        public readonly array $meta = [],
        public readonly string $observedMaxTier = '',
        public readonly string $evidenceRecordedAt = '',
    ) {}
}

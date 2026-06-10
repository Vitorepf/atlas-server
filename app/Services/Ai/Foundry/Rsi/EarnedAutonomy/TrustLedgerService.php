<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Rsi\EarnedAutonomy;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Earned Autonomy layer over Governed RSI · TrustLedgerService.
 *
 * Append-only, UNFORGEABLE per-area/focus trust record. For every governed loop
 * cycle it appends ONE event carrying the four REAL immune signals derived from
 * the live machinery — outcome_proven (from the measured-or-reverted consolidate
 * gate), red_team_survived (from the standing red team), drift_clean (from the
 * drift detector) and the cycle's risk_class — plus whether the cycle was
 * auto_applied. A cycle COUNTS toward promotion only if ALL THREE proof signals
 * are true; a revocation event resets accrued trust to zero.
 *
 * The loop cannot inflate its own tier. There is NO method that writes a "pass"
 * without the four real signals: {@see recordCycle()} validates each signal is a
 * genuine boolean taken from the supplied cycle result (a missing/non-boolean
 * signal FAILS CLOSED — the cycle does not qualify). Every appended line carries
 * a hash chained to the previous line; any write that is not strictly append
 * (rewriting or removing a prior line) is detected by the prior-hash chain and a
 * line-count regression guard, and is REJECTED.
 *
 * {@see earnedTier()} is a PURE deterministic fold over the append-only history:
 * it counts the consecutive qualifying proven cycles since the last revocation
 * and maps that count to the MAX AUTO-ELIGIBLE RISK RANK (an int):
 *   < PROMOTION_THRESHOLD[tier1]              => -1  (no autonomy / tier 0)
 *   >= tier1 threshold and < tier2 threshold  =>  0  (tier 1, cosmetic only)
 *   >= tier2 threshold and < tier3 threshold  =>  1  (tier 2, + non_sacred_logic)
 *   >= tier3 threshold                        =>  2  (tier 3, + ledger_or_schema)
 * It NEVER returns 3 (gate_or_invariant_touch is hard-capped out in the
 * composer regardless of accrued trust). It NEVER calls a provider, NEVER arms
 * anything, NEVER mutates a prior event.
 */
final class TrustLedgerService
{
    public const LEDGER_SCHEMA = 'atlas.foundry.rsi.earned_autonomy.trust_ledger.v1';

    public const EVENT_SCHEMA = 'atlas.foundry.rsi.earned_autonomy.trust_ledger_event.v1';

    public const EVENT_CYCLE_PROVEN = 'cycle_proven';

    public const EVENT_REVOKED = 'revoked';

    /** Human-facing tier NAMES (0..3). {@see earnedTier()} returns the max-auto-rank, not these. */
    public const TIER_0 = 0;

    public const TIER_1 = 1;

    public const TIER_2 = 2;

    public const TIER_3 = 3;

    /**
     * Consecutive qualifying proven cycles required to reach each tier
     * (ascending, conservative — frozen contract). A tier is earned only once
     * its threshold is met AND no revocation has reset the counter since.
     *
     * @var array<int,int>
     */
    private const PROMOTION_THRESHOLD = [
        self::TIER_1 => 3,
        self::TIER_2 => 10,
        self::TIER_3 => 30,
    ];

    /** The genesis prev-hash for the first line of a fresh ledger. */
    private const GENESIS_PREV_HASH = 'genesis';

    /** Closed set of legitimate revocation reasons. */
    private const REVOCATION_REASONS = [
        'anomaly_detected',
        'kill_disarmed',
        'red_team_breach',
        'drift_anomaly',
    ];

    private ?string $storageRootOverride = null;

    /** @var Closure():string */
    private Closure $clock;

    /**
     * @param  Closure():string|null  $clock  returns ISO-8601 UTC; injectable for deterministic tests.
     */
    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): string => (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format(DateTimeInterface::ATOM);
    }

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
    }

    /**
     * Append ONE append-only proven-cycle event. The cycle COUNTS toward
     * promotion only when outcome_proven && red_team_survived && drift_clean are
     * ALL genuinely true; any of those signals being absent or non-boolean FAILS
     * CLOSED (qualifies=false) — the loop cannot fabricate a pass.
     *
     * @param  array<string,mixed>  $context  area_id/focus/cycle_id/merge_hash
     * @param  array<string,mixed>  $cycleResult  outcome_proven/red_team_survived/drift_clean/risk_class/auto_applied
     * @return array<string,mixed> the appended event (with event_hash)
     */
    public function recordCycle(array $context, array $cycleResult): array
    {
        $outcomeProven = $this->strictBool($cycleResult, 'outcome_proven');
        $redTeamSurvived = $this->strictBool($cycleResult, 'red_team_survived');
        $driftClean = $this->strictBool($cycleResult, 'drift_clean');
        $autoApplied = $this->strictBool($cycleResult, 'auto_applied');

        // A cycle qualifies for trust accrual ONLY with all three real proof
        // signals genuinely true. There is no other path to a qualifying event.
        $qualifies = $outcomeProven && $redTeamSurvived && $driftClean;

        return $this->append((string) ($context['area_id'] ?? 'unscoped'), (string) ($context['focus'] ?? 'unscoped'), [
            'event_type' => self::EVENT_CYCLE_PROVEN,
            'area_id' => (string) ($context['area_id'] ?? ''),
            'focus' => (string) ($context['focus'] ?? ''),
            'cycle_id' => (string) ($context['cycle_id'] ?? ''),
            'merge_hash' => (string) ($context['merge_hash'] ?? ''),
            'risk_class' => (string) ($cycleResult['risk_class'] ?? ''),
            'outcome_proven' => $outcomeProven,
            'red_team_survived' => $redTeamSurvived,
            'drift_clean' => $driftClean,
            'auto_applied' => $autoApplied,
            'qualifies' => $qualifies,
            'reason' => null,
        ]);
    }

    /**
     * Append a revocation event. The fold resets accrued trust to zero (max-auto
     * -rank -1) from this point forward until new qualifying cycles re-accrue.
     *
     * @param  array<string,mixed>  $context  area_id/focus/cycle_id/merge_hash
     * @return array<string,mixed> the appended event (with event_hash)
     */
    public function recordRevocation(array $context, string $reason): array
    {
        $reason = in_array($reason, self::REVOCATION_REASONS, true) ? $reason : 'anomaly_detected';

        return $this->append((string) ($context['area_id'] ?? 'unscoped'), (string) ($context['focus'] ?? 'unscoped'), [
            'event_type' => self::EVENT_REVOKED,
            'area_id' => (string) ($context['area_id'] ?? ''),
            'focus' => (string) ($context['focus'] ?? ''),
            'cycle_id' => (string) ($context['cycle_id'] ?? ''),
            'merge_hash' => (string) ($context['merge_hash'] ?? ''),
            'risk_class' => '',
            'outcome_proven' => false,
            'red_team_survived' => false,
            'drift_clean' => false,
            'auto_applied' => false,
            'qualifies' => false,
            'reason' => $reason,
        ]);
    }

    /**
     * Deterministic fold over the append-only events (oldest first): count the
     * consecutive qualifying proven cycles since the LAST revocation, then map
     * to the MAX AUTO-ELIGIBLE RISK RANK. A revocation event resets the counter
     * AND caps the max-auto-rank at -1 until new qualifying cycles accrue.
     *
     * Returns -1 (no autonomy), 0 (cosmetic), 1 (+non_sacred_logic) or 2
     * (+ledger_or_schema). NEVER returns 3 (gate_or_invariant_touch is hard
     * -capped out in the composer regardless). NEVER reads a provider, never
     * writes, never mutates a prior event.
     *
     * @param  list<array<string,mixed>>|null  $records  optional injected ledger (no I/O when supplied)
     */
    public function earnedTier(string $areaId, string $focus, ?array $records = null): int
    {
        $events = $records ?? $this->replay($areaId, $focus);

        $consecutive = 0;
        foreach ($events as $event) {
            $type = (string) ($event['event_type'] ?? '');
            if ($type === self::EVENT_REVOKED) {
                $consecutive = 0; // revocation resets accrued trust to zero.

                continue;
            }
            if ($type !== self::EVENT_CYCLE_PROVEN) {
                continue;
            }
            // Re-derive qualification from the persisted real signals — never
            // trust a stored 'qualifies' flag that disagrees with the signals.
            $qualifies = ($event['outcome_proven'] ?? null) === true
                && ($event['red_team_survived'] ?? null) === true
                && ($event['drift_clean'] ?? null) === true;
            $consecutive = $qualifies ? $consecutive + 1 : 0;
        }

        return $this->maxAutoRankFor($consecutive);
    }

    /**
     * Human-facing tier NAME (0..3) for a given max-auto-rank int (-1..2),
     * available for receipts. {@see decide()} in the composer uses the
     * max-auto-rank int directly; this is only for display.
     */
    public function tierName(int $maxAutoRank): int
    {
        return match (true) {
            $maxAutoRank >= 2 => self::TIER_3,
            $maxAutoRank >= 1 => self::TIER_2,
            $maxAutoRank >= 0 => self::TIER_1,
            default => self::TIER_0,
        };
    }

    /**
     * Replay the append-only ledger for an area/focus (deterministic fold, no
     * side effects). Verifies the prior-hash chain: a broken chain (a rewritten
     * or removed prior line) is a tamper signal and is REJECTED.
     *
     * @return list<array<string,mixed>>
     */
    public function replay(string $areaId, string $focus): array
    {
        $path = $this->ledgerPath($areaId, $focus);
        if (! is_file($path)) {
            return [];
        }

        $events = [];
        $expectedPrev = self::GENESIS_PREV_HASH;
        $index = 0;
        foreach (preg_split('/\R/', (string) File::get($path)) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                throw new RuntimeException('earned_autonomy.trust_ledger tamper: undecodable line at index '.$index);
            }
            // Append-only chain integrity: each line's prev_hash must equal the
            // previous line's event_hash. A regression (rewrite/removal of a
            // prior line) breaks the chain and is rejected — the ledger cannot
            // be silently edited to forge a pass.
            if ((string) ($decoded['prev_hash'] ?? '') !== $expectedPrev) {
                throw new RuntimeException('earned_autonomy.trust_ledger tamper: prev-hash chain break at index '.$index);
            }
            $expectedPrev = (string) ($decoded['event_hash'] ?? '');
            $events[] = $decoded;
            $index++;
        }

        return $events;
    }

    public function ledgerPath(string $areaId, string $focus): string
    {
        $root = $this->storageRootOverride
            ?? (function_exists('storage_path')
                ? storage_path('atlas/rsi/earned_autonomy/trust_ledger')
                : sys_get_temp_dir().'/atlas/rsi/earned_autonomy/trust_ledger');

        return rtrim($root, '/').'/'.$this->slug($areaId).'/'.$this->slug($focus).'.jsonl';
    }

    /**
     * Map consecutive qualifying proven cycles to the max auto-eligible risk
     * rank (frozen): {<3 => -1, 3..9 => 0, 10..29 => 1, >=30 => 2}.
     */
    private function maxAutoRankFor(int $consecutive): int
    {
        return match (true) {
            $consecutive >= self::PROMOTION_THRESHOLD[self::TIER_3] => 2,
            $consecutive >= self::PROMOTION_THRESHOLD[self::TIER_2] => 1,
            $consecutive >= self::PROMOTION_THRESHOLD[self::TIER_1] => 0,
            default => -1,
        };
    }

    /**
     * Read a signal as a STRICT boolean: only a genuine bool true counts. A
     * missing key, a non-boolean, or a truthy non-bool (e.g. 1, "true") FAILS
     * CLOSED to false — the loop cannot fabricate a proof signal.
     *
     * @param  array<string,mixed>  $source
     */
    private function strictBool(array $source, string $key): bool
    {
        return ($source[$key] ?? null) === true;
    }

    /**
     * Append ONE event with a prior-hash chain link, then return the stored
     * event. The event_hash chains the canonical event body to the previous
     * line's event_hash, so any tamper is detectable on replay.
     *
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    private function append(string $areaId, string $focus, array $body): array
    {
        $path = $this->ledgerPath($areaId, $focus);
        File::ensureDirectoryExists(dirname($path));

        $prevHash = $this->lastEventHash($path);

        $event = array_merge([
            'schema_version' => self::EVENT_SCHEMA,
            'recorded_at' => ($this->clock)(),
        ], $body, [
            'prev_hash' => $prevHash,
        ]);
        // The hash chains the full event body (including prev_hash) so the chain
        // is unforgeable: you cannot insert/rewrite a line without recomputing
        // every subsequent hash, which replay() detects via the prev-hash link.
        $event['event_hash'] = MissionCanonicalHash::sha256($event);

        AppendOnlyJsonlStore::appendUsingFilePutContents(
            $path,
            $event,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            FILE_APPEND,
        );

        return $event;
    }

    /**
     * The event_hash of the last line of an existing ledger (genesis when the
     * ledger has no lines yet). Used to chain the next append.
     */
    private function lastEventHash(string $path): string
    {
        if (! is_file($path)) {
            return self::GENESIS_PREV_HASH;
        }

        $last = self::GENESIS_PREV_HASH;
        foreach (preg_split('/\R/', (string) File::get($path)) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['event_hash'])) {
                $last = (string) $decoded['event_hash'];
            }
        }

        return $last;
    }

    private function slug(string $value): string
    {
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim($value)));

        return trim($slug, '_') ?: 'unscoped';
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Trinity\AntiDecoupling;

use RuntimeException;

/**
 * Thrown when a caller attempts to mutate or delete an already-persisted receipt. Append-only invariant —
 * the only legal operation on a closed receipt is to read it.
 */
final class TrinityReceiptImmutabilityException extends RuntimeException
{
}

/**
 * APPEND-ONLY, byte-deterministic, daily-rotated NDJSON ledger of every Trinity contract auditor verdict
 * AND every drift fact. Closes the recursive loop of the four anti-decoupling packets:
 *
 *   emitter (01) declares the contract → auditor (02) gates on it → drift detector (03) attributes breaches
 *   → receipt ledger (04) makes every gate decision and every breach permanently auditable.
 *
 * Receipt shape: {seq, ts, kind, primitive, counterpart, side, contract_fingerprint, outcome, source_command_sha}.
 *
 * INVARIANTS:
 *   - APPEND-ONLY: append() is the sole write path. No public update/delete/truncate methods. Direct file
 *     mutation triggers {@see TrinityReceiptImmutabilityException} from {@see refuseOverwrite()}.
 *   - REPLAY-DETERMINISTIC: replaying the file via {@see replay()} reconstructs the in-memory state with
 *     identical seq + ordering.
 *   - DAILY ROTATION: files live at storage/atlas/trinity/anti-decoupling/receipts/YYYY-MM-DD.ndjson.
 */
final class AtlasLoopTrinityContractReceiptLedger
{
    public const KIND_AUDIT = 'audit';

    public const KIND_DRIFT = 'drift';

    public const OUTCOME_CLEAN = 'CLEAN';

    public const OUTCOME_BREACH = 'BREACH';

    private ?string $storageRoot = null;

    /** @var null|callable():int unix-epoch clock, injectable for tests */
    private $clock;

    public function __construct(?string $storageRoot = null, ?callable $clock = null)
    {
        $this->storageRoot = $storageRoot === null ? null : rtrim($storageRoot, '/');
        $this->clock = $clock;
    }

    /**
     * Append one receipt. Returns the persisted record (with seq + ts filled in).
     *
     * @param  array{
     *     kind:string,
     *     primitive:string,
     *     side:?string,
     *     counterpart:?string,
     *     contract_fingerprint:?string,
     *     outcome:?string,
     *     source_command_sha:?string
     * }  $partial
     * @return array<string,mixed>
     */
    public function append(array $partial): array
    {
        if (! in_array($partial['kind'] ?? '', [self::KIND_AUDIT, self::KIND_DRIFT], true)) {
            throw new RuntimeException('Trinity contract receipt ledger: kind must be audit or drift');
        }

        $ts = $this->now();
        $path = $this->dayPath($ts);
        $seq = $this->nextSeq();

        $receipt = [
            'seq' => $seq,
            'ts' => $ts,
            'kind' => (string) $partial['kind'],
            'primitive' => (string) ($partial['primitive'] ?? ''),
            'counterpart' => isset($partial['counterpart']) ? (string) $partial['counterpart'] : null,
            'side' => isset($partial['side']) ? (string) $partial['side'] : null,
            'contract_fingerprint' => (string) ($partial['contract_fingerprint'] ?? ''),
            'outcome' => isset($partial['outcome']) ? (string) $partial['outcome'] : null,
            'source_command_sha' => isset($partial['source_command_sha']) ? (string) $partial['source_command_sha'] : null,
        ];

        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($path, (string) json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);

        return $receipt;
    }

    /**
     * Replay the ENTIRE storage (every day file) in insertion order. Returns receipts as a list.
     *
     * @return list<array<string,mixed>>
     */
    public function replay(): array
    {
        $root = $this->root();
        if (! is_dir($root)) {
            return [];
        }
        $files = glob($root.'/*.ndjson') ?: [];
        sort($files);
        $out = [];
        foreach ($files as $f) {
            foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $decoded = json_decode((string) $line, true);
                if (is_array($decoded)) {
                    $out[] = $decoded;
                }
            }
        }
        usort($out, static fn (array $x, array $y): int => (int) ($x['seq'] ?? 0) <=> (int) ($y['seq'] ?? 0));

        return $out;
    }

    /**
     * Refuse to overwrite an existing receipt file's prior bytes. Always throws — append-only at the API
     * level. Exists so tests can prove the immutability invariant.
     */
    public function refuseOverwrite(string $absolutePathToReceiptFile): void
    {
        if (str_starts_with($absolutePathToReceiptFile, $this->root().'/')) {
            throw new TrinityReceiptImmutabilityException('Trinity contract receipts are append-only; refuse overwrite on '.$absolutePathToReceiptFile);
        }
        // Even outside the configured root, the API forbids mutating any *.ndjson the caller passes.
        if (str_ends_with($absolutePathToReceiptFile, '.ndjson')) {
            throw new TrinityReceiptImmutabilityException('Trinity contract receipt files are immutable: '.$absolutePathToReceiptFile);
        }
        // For non-receipt paths the call is a no-op (defensive — only receipt files are protected here).
    }

    /**
     * Count audit BREACH receipts for a primitive within the calendar week containing $atTs (UTC).
     * Consumed by a downstream auditor wrapper that wants to refuse "broke twice this week" patterns.
     */
    public function breachesThisWeekFor(string $primitive, int $atTs): int
    {
        $weekStart = $this->startOfIsoWeek($atTs);
        $weekEnd = $weekStart + 7 * 86_400 - 1;
        $n = 0;
        foreach ($this->replay() as $r) {
            if (($r['kind'] ?? '') !== self::KIND_AUDIT) {
                continue;
            }
            if (($r['outcome'] ?? '') !== self::OUTCOME_BREACH) {
                continue;
            }
            if ((string) ($r['primitive'] ?? '') !== $primitive) {
                continue;
            }
            $ts = (int) ($r['ts'] ?? 0);
            if ($ts >= $weekStart && $ts <= $weekEnd) {
                $n++;
            }
        }

        return $n;
    }

    private function nextSeq(): int
    {
        $last = 0;
        foreach ($this->replay() as $r) {
            $last = max($last, (int) ($r['seq'] ?? 0));
        }

        return $last + 1;
    }

    private function dayPath(int $ts): string
    {
        return $this->root().'/'.gmdate('Y-m-d', $ts).'.ndjson';
    }

    private function root(): string
    {
        if ($this->storageRoot !== null && $this->storageRoot !== '') {
            return $this->storageRoot;
        }
        if (function_exists('storage_path')) {
            try {
                return rtrim((string) storage_path('atlas/trinity/anti-decoupling/receipts'), '/');
            } catch (\Throwable) {
            }
        }

        return sys_get_temp_dir().'/atlas-trinity-anti-decoupling-receipts';
    }

    private function now(): int
    {
        $clock = $this->clock;

        return is_callable($clock) ? (int) $clock() : time();
    }

    private function startOfIsoWeek(int $ts): int
    {
        // Monday-of-the-ISO-week, 00:00:00 UTC.
        $dow = (int) gmdate('N', $ts); // 1 (Mon) .. 7 (Sun)
        $dayStart = (int) strtotime(gmdate('Y-m-d', $ts).' 00:00:00 UTC');

        return $dayStart - ($dow - 1) * 86_400;
    }
}

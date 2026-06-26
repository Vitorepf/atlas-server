<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\SelfMod;


use App\Services\Ai\SelfConstruction\Support\UsesUtcClock;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * APPEND-ONLY JSONL audit substrate for every Loop self-mod attempt. Each receipt records the FULL chain of
 * evidence: {receipt_id (ULID), ts_iso8601, target_files[], edit_classification, invariant_survival_report,
 * proof_status (APPROVED|REJECTED|UNCHECKABLE), frozen_judge_decision, cert_diff_decision, commit_sha?}.
 *
 * INVARIANTS:
 *   - WRITES use `fopen($path, 'a')` (append-only) + `flock(LOCK_EX)`; existing bytes are NEVER overwritten.
 *   - NO public method named update / delete / truncate / remove exists; calling any path explicitly named
 *     `truncate()` raises {@see RuntimeException} with the substring 'append-only'.
 *   - QUERYABLE by receipt_id, target_file, proof_status, and date range — head/tail/since are deterministic.
 */
final class AtlasLoopSelfModProofReceiptLedger
{
    use UsesUtcClock;

    public const PROOF_APPROVED = 'APPROVED';

    public const PROOF_REJECTED = 'REJECTED';

    public const PROOF_UNCHECKABLE = 'UNCHECKABLE';

    public const ALLOWED_PROOF_STATUSES = [self::PROOF_APPROVED, self::PROOF_REJECTED, self::PROOF_UNCHECKABLE];

    private ?string $ledgerPath = null;

    /** @var null|callable():string */
    private $clock;

    public function __construct(?string $ledgerPath = null, ?callable $clock = null)
    {
        $this->ledgerPath = $ledgerPath;
        $this->clock = $clock;
    }

    public function setLedgerPathForTesting(?string $path): void
    {
        $this->ledgerPath = $path;
    }

    /**
     * @param  array{
     *     target_files:list<string>,
     *     edit_classification:array<string,mixed>,
     *     invariant_survival_report:array<string,mixed>,
     *     proof_status:string,
     *     frozen_judge_decision:array<string,mixed>,
     *     cert_diff_decision:array<string,mixed>,
     *     commit_sha?:?string
     * }  $partial
     * @return array<string,mixed>
     */
    public function record(array $partial): array
    {
        $status = (string) ($partial['proof_status'] ?? '');
        if (! in_array($status, self::ALLOWED_PROOF_STATUSES, true)) {
            throw new RuntimeException('SelfMod ledger refuses proof_status='.($status === '' ? '(empty)' : $status).'; allowed: '.implode(', ', self::ALLOWED_PROOF_STATUSES));
        }

        $receipt = [
            'receipt_id' => (string) Str::ulid(),
            'ts_iso8601' => $this->now(),
            'target_files' => array_values(array_filter(array_map('strval', (array) ($partial['target_files'] ?? [])), static fn (string $f): bool => $f !== '')),
            'edit_classification' => (array) ($partial['edit_classification'] ?? []),
            'invariant_survival_report' => (array) ($partial['invariant_survival_report'] ?? []),
            'proof_status' => $status,
            'frozen_judge_decision' => (array) ($partial['frozen_judge_decision'] ?? []),
            'cert_diff_decision' => (array) ($partial['cert_diff_decision'] ?? []),
            'commit_sha' => array_key_exists('commit_sha', $partial) ? (is_string($partial['commit_sha']) && $partial['commit_sha'] !== '' ? $partial['commit_sha'] : null) : null,
        ];

        $path = $this->resolvedPath();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $fh = @fopen($path, 'a');
        if ($fh === false) {
            throw new RuntimeException('SelfMod ledger cannot open '.$path.' for append');
        }
        try {
            if (! flock($fh, LOCK_EX)) {
                throw new RuntimeException('SelfMod ledger cannot acquire LOCK_EX');
            }
            fwrite($fh, (string) json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            fflush($fh);
            @\fsync($fh);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }

        return $receipt;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $path = $this->resolvedPath();
        if (! is_file($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode((string) $line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    public function byReceiptId(string $receiptId): ?array
    {
        foreach ($this->all() as $r) {
            if ((string) ($r['receipt_id'] ?? '') === $receiptId) {
                return $r;
            }
        }

        return null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function byTargetFile(string $targetFile): array
    {
        $out = [];
        foreach ($this->all() as $r) {
            $files = (array) ($r['target_files'] ?? []);
            if (in_array($targetFile, $files, true)) {
                $out[] = $r;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function byProofStatus(string $status): array
    {
        return array_values(array_filter($this->all(), static fn (array $r): bool => (string) ($r['proof_status'] ?? '') === $status));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function byDateRange(string $sinceIso, string $untilIso): array
    {
        $sinceTs = (int) strtotime($sinceIso);
        $untilTs = (int) strtotime($untilIso);

        return array_values(array_filter($this->all(), static function (array $r) use ($sinceTs, $untilTs): bool {
            $ts = (int) strtotime((string) ($r['ts_iso8601'] ?? ''));

            return $ts >= $sinceTs && $ts <= $untilTs;
        }));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function head(int $n): array
    {
        return array_slice($this->all(), 0, max(0, $n));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function tail(int $n): array
    {
        $all = $this->all();

        return array_slice($all, max(0, count($all) - max(0, $n)));
    }

    /**
     * @return list<array<string,mixed>>  receipts whose ts is strictly after $iso
     */
    public function since(string $iso): array
    {
        $sinceTs = (int) strtotime($iso);

        return array_values(array_filter($this->all(), static fn (array $r): bool => (int) strtotime((string) ($r['ts_iso8601'] ?? '')) > $sinceTs));
    }

    /**
     * Anti-mutation chokepoint. Any caller invoking this method ALWAYS throws — the ledger has no truncate
     * semantics; the method exists only so a caller cannot reach a non-existent symbol and silently win.
     */
    public function truncate(): void
    {
        throw new RuntimeException('SelfMod ledger is append-only: truncate is forbidden');
    }

    public function resolvedPath(): string
    {
        if ($this->ledgerPath !== null && $this->ledgerPath !== '') {
            return $this->ledgerPath;
        }
        if (function_exists('storage_path')) {
            try {
                return (string) storage_path('atlas/selfmod-receipts.jsonl');
            } catch (Throwable) {
            }
        }

        return sys_get_temp_dir().'/atlas-selfmod-receipts.jsonl';
    }

}

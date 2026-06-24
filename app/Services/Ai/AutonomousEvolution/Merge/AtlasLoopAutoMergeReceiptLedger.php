<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Merge;

/**
 * WAVE-14 · AUTO-MERGE RECEIPT LEDGER — the append-only Evidence Ledger of every auto-merge attempt (allowed,
 * refused, merged, rolled_back, revert_failed) with the exact commit-SHA chain plus the three gate verdicts
 * (PreFlightGate, ConflictDetector, ReverseAuditor) and a UTC timestamp.
 *
 * STORAGE: one JSONL line per receipt at storage/ledgers/loop-automerge.jsonl (configurable via constructor for
 * tests). Each line carries a deterministic `receipt_id = sha256(canonical_body_without_receipt_id)`, so
 * mutating any byte of an existing line is detected by {@see verify()} via integrity_violation=true.
 *
 * CONCURRENCY: every record() acquires an exclusive `flock(LOCK_EX)` over the file, writes the line, fflush
 * + fsync, then releases. Two concurrent workers serialize on the lock — both receipts land intact.
 */
final class AtlasLoopAutoMergeReceiptLedger
{
    public const SCHEMA = 'atlas.loop.automerge_receipt.v1';

    /** Required keys on every emitted receipt — guarded by {@see assertSchema()} before write. */
    public const REQUIRED_KEYS = ['receipt_id', 'ts', 'proposal_id', 'base_sha', 'head_sha_before', 'head_sha_after', 'gate_verdicts', 'outcome'];

    /** Allowed outcome slugs — anything else throws (the ledger refuses unknown outcomes). */
    public const OUTCOME_ALLOW_REFUSED_PREFLIGHT = 'allow_refused_preflight';

    public const OUTCOME_REFUSED_CONFLICT = 'refused_conflict';

    public const OUTCOME_MERGED = 'merged';

    public const OUTCOME_ROLLED_BACK = 'rolled_back';

    public const OUTCOME_REVERT_FAILED = 'revert_failed';

    public const ALLOWED_OUTCOMES = [
        self::OUTCOME_ALLOW_REFUSED_PREFLIGHT,
        self::OUTCOME_REFUSED_CONFLICT,
        self::OUTCOME_MERGED,
        self::OUTCOME_ROLLED_BACK,
        self::OUTCOME_REVERT_FAILED,
    ];

    /** @var null|callable():string */
    private $clock;

    /**
     * @param  null|callable():string  $clock  ISO-8601 UTC timestamp source; defaults to gmdate(DATE_ATOM)
     */
    public function __construct(
        private readonly ?string $ledgerFile = null,
        ?callable $clock = null,
    ) {
        $this->clock = $clock;
    }

    /**
     * Append one receipt. The caller supplies all fields EXCEPT receipt_id and ts which the ledger fills in.
     *
     * @param  array{
     *     proposal_id:string,
     *     base_sha:string,
     *     head_sha_before:?string,
     *     head_sha_after:?string,
     *     gate_verdicts:array{preflight:?string, conflict:?string, reverse:?string},
     *     outcome:string
     * }  $partial
     */
    public function record(array $partial): string
    {
        if (! in_array($partial['outcome'] ?? '', self::ALLOWED_OUTCOMES, true)) {
            throw new \InvalidArgumentException('AutoMergeReceiptLedger refuses unknown outcome: '.($partial['outcome'] ?? 'null'));
        }

        $body = [
            'ts' => $this->now(),
            'proposal_id' => (string) ($partial['proposal_id'] ?? ''),
            'base_sha' => (string) ($partial['base_sha'] ?? ''),
            'head_sha_before' => $partial['head_sha_before'] ?? null,
            'head_sha_after' => $partial['head_sha_after'] ?? null,
            'gate_verdicts' => [
                'preflight' => $partial['gate_verdicts']['preflight'] ?? null,
                'conflict' => $partial['gate_verdicts']['conflict'] ?? null,
                'reverse' => $partial['gate_verdicts']['reverse'] ?? null,
            ],
            'outcome' => (string) $partial['outcome'],
            'schema_version' => self::SCHEMA,
        ];

        $canonical = self::canonicalize($body);
        $receiptId = hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $body['receipt_id'] = $receiptId;
        $body = self::canonicalize($body);
        $this->assertSchema($body);

        $path = $this->resolvedPath();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $fh = @fopen($path, 'c+');
        if ($fh === false) {
            throw new \RuntimeException('AutoMergeReceiptLedger cannot open '.$path);
        }
        try {
            if (! flock($fh, LOCK_EX)) {
                throw new \RuntimeException('AutoMergeReceiptLedger cannot acquire exclusive lock');
            }
            fseek($fh, 0, SEEK_END);
            fwrite($fh, (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            fflush($fh);
            @\fsync($fh);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }

        return $receiptId;
    }

    /**
     * Walk the ledger and recompute every receipt_id from its body; flag any mismatch. Returns ok=true on a
     * clean ledger (or an empty/missing one) and integrity_violation=true on the first tampered line.
     *
     * @return array{ok:bool, integrity_violation:bool, total:int, breaks:list<array{line:int, reason:string}>}
     */
    public function verify(): array
    {
        $path = $this->resolvedPath();
        if (! is_file($path)) {
            return ['ok' => true, 'integrity_violation' => false, 'total' => 0, 'breaks' => []];
        }

        $breaks = [];
        $total = 0;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $idx => $raw) {
            $total++;
            $decoded = json_decode((string) $raw, true);
            if (! is_array($decoded)) {
                $breaks[] = ['line' => $idx + 1, 'reason' => 'non_json'];

                continue;
            }
            $stored = (string) ($decoded['receipt_id'] ?? '');
            unset($decoded['receipt_id']);
            $canonical = self::canonicalize($decoded);
            $recomputed = hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            if (! hash_equals($stored, $recomputed)) {
                $breaks[] = ['line' => $idx + 1, 'reason' => 'receipt_id_mismatch'];
            }
        }

        return [
            'ok' => $breaks === [],
            'integrity_violation' => $breaks !== [],
            'total' => $total,
            'breaks' => $breaks,
        ];
    }

    /**
     * @param  array<string,mixed>  $body
     */
    private function assertSchema(array $body): void
    {
        foreach (self::REQUIRED_KEYS as $key) {
            if (! array_key_exists($key, $body)) {
                throw new \RuntimeException('AutoMergeReceiptLedger missing required key: '.$key);
            }
        }
        $verdicts = $body['gate_verdicts'] ?? null;
        if (! is_array($verdicts) || ! array_key_exists('preflight', $verdicts) || ! array_key_exists('conflict', $verdicts) || ! array_key_exists('reverse', $verdicts)) {
            throw new \RuntimeException('AutoMergeReceiptLedger gate_verdicts must include preflight, conflict, reverse');
        }
    }

    private function resolvedPath(): string
    {
        if ($this->ledgerFile !== null && $this->ledgerFile !== '') {
            return $this->ledgerFile;
        }

        return function_exists('storage_path')
            ? storage_path('ledgers/loop-automerge.jsonl')
            : sys_get_temp_dir().'/atlas-loop-automerge.jsonl';
    }

    private function now(): string
    {
        $clock = $this->clock;
        if (is_callable($clock)) {
            return (string) $clock();
        }

        return gmdate(DATE_ATOM);
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            $out = [];
            foreach ($value as $item) {
                $out[] = self::canonicalize($item);
            }

            return $out;
        }
        ksort($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::canonicalize($v);
        }

        return $out;
    }
}

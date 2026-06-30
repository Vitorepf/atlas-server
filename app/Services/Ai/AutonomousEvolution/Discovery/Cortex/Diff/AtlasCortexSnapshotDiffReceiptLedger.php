<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Diff;


use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
/**
 * Append-only auditable record of every diff computed by AtlasCortexSnapshotDiffEngine.
 *
 * One JSONL line per receipt under {root}/YYYY-MM-DD.jsonl containing:
 *   - schema = atlas.cortex.snapshot_diff_receipt.v1
 *   - computed_at_unix
 *   - left_snapshot_id / right_snapshot_id
 *   - scope_root
 *   - summary (the AtlasCortexSnapshotDiffSummary payload)
 *   - structural_diff_hash (sha256 over canonical structural sections — prose excluded)
 *
 * IDEMPOTENT on (left,right): seen($left,$right) scans today's + yesterday's file and returns the
 * cached receipt; record() short-circuits if a receipt exists.
 *
 * FAIL-OPEN: a failed write returns ['recorded' => false] but NEVER throws — observability cannot
 * be allowed to break the loop.
 */
final class AtlasCortexSnapshotDiffReceiptLedger
{
    use KsortsArraysByReference;

    public const SCHEMA = 'atlas.cortex.snapshot_diff_receipt.v1';

    private static ?string $rootOverride = null;

    public static function setRootForTesting(?string $root): void
    {
        self::$rootOverride = $root;
    }

    /**
     * Persist a receipt. Idempotent on (left,right) — returns the cached receipt when seen.
     *
     * @param  array<string,mixed>  $structuralDiff full diff including prose
     * @param  array<string,mixed>  $summary the AtlasCortexSnapshotDiffSummary payload
     * @return array<string,mixed>
     */
    public function record(string $left, string $right, string $scopeRoot, array $structuralDiff, array $summary): array
    {
        $existing = $this->seen($left, $right);
        if ($existing !== null) {
            return ['recorded' => true, 'cached' => true, 'receipt' => $existing, 'path' => $this->todayPath()];
        }

        $receipt = [
            'schema' => self::SCHEMA,
            'computed_at_unix' => time(),
            'left_snapshot_id' => $left,
            'right_snapshot_id' => $right,
            'scope_root' => $scopeRoot,
            'summary' => $summary,
            'structural_diff_hash' => $this->structuralHash($structuralDiff),
        ];
        $path = $this->todayPath();
        $ok = $this->appendLine($path, $receipt);
        if (! $ok) {
            return ['recorded' => false, 'path' => $path, 'receipt' => $receipt];
        }

        return ['recorded' => true, 'cached' => false, 'path' => $path, 'receipt' => $receipt];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function seen(string $left, string $right): ?array
    {
        foreach ($this->candidatePaths() as $path) {
            $found = $this->scanFile($path, $left, $right);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $diff
     */
    public function structuralHash(array $diff): string
    {
        $structural = $diff;
        unset($structural['prose'], $structural['doc_purposes_prose']);
        $this->ksortRecursiveByReference($structural);

        return hash('sha256', (string) json_encode($structural, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function root(): string
    {
        return self::$rootOverride ?? storage_path('app/atlas/loop/cortex/diff/receipts');
    }

    private function todayPath(): string
    {
        return $this->root().'/'.date('Y-m-d').'.jsonl';
    }

    /**
     * @return list<string>
     */
    private function candidatePaths(): array
    {
        $root = $this->root();

        return [
            $root.'/'.date('Y-m-d').'.jsonl',
            $root.'/'.date('Y-m-d', time() - 86400).'.jsonl',
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function scanFile(string $path, string $left, string $right): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }
        try {
            while (($line = fgets($fh)) !== false) {
                $decoded = json_decode(rtrim($line, "\n"), true);
                if (! is_array($decoded)) {
                    continue;
                }
                if (($decoded['left_snapshot_id'] ?? null) === $left && ($decoded['right_snapshot_id'] ?? null) === $right) {
                    return $decoded;
                }
            }
        } finally {
            fclose($fh);
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function appendLine(string $path, array $receipt): bool
    {
        $dir = \dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            return false;
        }
        $fh = @fopen($path, 'ab');
        if ($fh === false) {
            return false;
        }
        try {
            if (! @flock($fh, LOCK_EX)) {
                return false;
            }
            $line = json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
            $bytes = fwrite($fh, $line);
            if ($bytes === false || $bytes !== strlen($line)) {
                return false;
            }
        } finally {
            @flock($fh, LOCK_UN);
            fclose($fh);
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $arr
     */
}

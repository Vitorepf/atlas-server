<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Branching;

use App\Services\Ai\AutonomousEvolution\AtlasLoopWorkspaceMaterializerSupport2;
use Closure;
use RuntimeException;

/**
 * Decision service that the parent cycle calls after a spawned branch has finished.
 *
 * merge() routes the diff through an injected cert-gate callable (caller-supplied seam) —
 * NEVER bypasses cert. discard() deletes the sub-workspace and archives the manifest.
 * Idempotent: a terminal record per branch_id is durable.
 */
final class AtlasLoopCycleBranchMergeOrDiscard
{
    public const TERMINAL_MERGED = 'merged';
    public const TERMINAL_REJECTED = 'rejected_by_cert';
    public const TERMINAL_DISCARDED = 'discarded';

    /**
     * @param  Closure(string,array<string,mixed>):array{accepted:bool, reason?:string} $certGate
     *         Receives the branch diff content and the manifest array; returns accept/reject + reason.
     */
    public function __construct(
        private readonly string $historyRoot,
        private readonly Closure $certGate,
    ) {
        if (! is_dir($this->historyRoot)) {
            @mkdir($this->historyRoot, 0o755, true);
        }
    }

    /**
     * @return array<string,mixed> the terminal record
     */
    public function merge(AtlasLoopCycleBranchManifest $manifest, AtlasLoopCycleBranchOutcome $outcome): array
    {
        if ($existing = $this->loadHistory($manifest->branchId)) {
            return $existing;
        }
        $this->assertOutsideLiveSource($manifest->workspacePath);

        $verdict = ($this->certGate)($outcome->diffContent, $manifest->toArray());
        $accepted = (bool) ($verdict['accepted'] ?? false);
        $terminal = $accepted ? self::TERMINAL_MERGED : self::TERMINAL_REJECTED;

        $record = [
            'branch_id' => $manifest->branchId,
            'cert_reason' => (string) ($verdict['reason'] ?? ''),
            'diff_summary' => $outcome->diffSummary,
            'manifest' => $manifest->toArray(),
            'outcome_success' => $outcome->success,
            'provenance' => $outcome->provenance,
            'terminal_state' => $terminal,
        ];
        ksort($record);
        $this->saveHistory($manifest->branchId, $record);

        return $record;
    }

    /** @return array<string,mixed> */
    public function discard(AtlasLoopCycleBranchManifest $manifest): array
    {
        if ($existing = $this->loadHistory($manifest->branchId)) {
            return $existing;
        }
        $this->assertOutsideLiveSource($manifest->workspacePath);

        $this->rrm($manifest->workspacePath);

        $record = [
            'branch_id' => $manifest->branchId,
            'manifest' => $manifest->toArray(),
            'terminal_state' => self::TERMINAL_DISCARDED,
        ];
        ksort($record);
        $this->saveHistory($manifest->branchId, $record);

        return $record;
    }

    private function assertOutsideLiveSource(string $path): void
    {
        // Append a never-existing leaf so the pétreo guard inspects the live path.
        AtlasLoopWorkspaceMaterializerSupport2::assertOutsideLiveSource(rtrim($path, '/').'/__guard');
    }

    private function historyPath(string $branchId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $branchId) ?? $branchId;

        return rtrim($this->historyRoot, '/').'/'.$safe.'.json';
    }

    /** @return array<string,mixed>|null */
    private function loadHistory(string $branchId): ?array
    {
        $path = $this->historyPath($branchId);
        if (! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string,mixed> $record */
    private function saveHistory(string $branchId, array $record): void
    {
        ksort($record);
        $path = $this->historyPath($branchId);
        $bytes = (string) json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tmp = $path.'.tmp.'.bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $bytes) === false) {
            throw new RuntimeException('branch_history_write_failed:'.$tmp);
        }
        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('branch_history_rename_failed:'.$path);
        }
    }

    private function rrm(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach ((array) glob($dir.'/*') as $f) {
            $abs = (string) $f;
            if (is_dir($abs)) {
                $this->rrm($abs);
            } else {
                @unlink($abs);
            }
        }
        @rmdir($dir);
    }
}

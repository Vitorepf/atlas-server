<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Telemetry\RollingWindows;

use RuntimeException;

final class AtlasLoopRollingWindowReceiptLedger
{
    public function __construct(
        private readonly string $basePath = '',
        ?callable $clock = null,
    ) {
        $this->clock = $clock instanceof \Closure ? $clock : ($clock === null ? null : \Closure::fromCallable($clock));
    }

    private readonly ?\Closure $clock;

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    public function recordSnapshot(array $snapshot): array
    {
        $windowLabel = (string) ($snapshot['window_label'] ?? '');
        $windowStartIso = (string) ($snapshot['window_start_iso'] ?? '');
        $windowEndIso = (string) ($snapshot['window_end_iso'] ?? '');

        return $this->writeReceipt(
            $this->snapshotPath($windowLabel, $windowStartIso),
            $windowLabel,
            $windowStartIso,
            $windowEndIso,
            $snapshot,
        );
    }

    /**
     * @param  array<string,mixed>  $delta
     * @return array<string,mixed>
     */
    public function recordComparison(array $delta): array
    {
        $windowLabel = (string) ($delta['window_label'] ?? '');
        $windowStartIso = (string) ($delta['previous_window_start_iso'] ?? '');
        $windowEndIso = (string) ($delta['current_window_start_iso'] ?? '');

        return $this->writeReceipt(
            $this->comparisonPath($windowLabel, $windowEndIso),
            $windowLabel,
            $windowStartIso,
            $windowEndIso,
            $delta,
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function list(string $windowLabel, string $sinceIso): array
    {
        $directory = $this->snapshotDirectory($windowLabel);
        if (! is_dir($directory)) {
            return [];
        }

        $since = strtotime($sinceIso);
        $receipts = [];
        foreach (glob($directory.'/*.json') ?: [] as $path) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (! is_array($decoded)) {
                continue;
            }

            $windowStart = strtotime((string) ($decoded['window_start_iso'] ?? ''));
            if ($since !== false && $windowStart !== false && $windowStart < $since) {
                continue;
            }

            $receipts[] = $decoded;
        }

        usort($receipts, static fn (array $left, array $right): int => strcmp(
            (string) ($left['window_start_iso'] ?? ''),
            (string) ($right['window_start_iso'] ?? ''),
        ));

        return $receipts;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function writeReceipt(string $path, string $windowLabel, string $windowStartIso, string $windowEndIso, array $payload): array
    {
        $canonicalPayload = $this->canonicalJson($payload);
        $payloadSha = hash('sha256', $canonicalPayload);
        $receipt = [
            'window_label' => $windowLabel,
            'window_start_iso' => $windowStartIso,
            'window_end_iso' => $windowEndIso,
            'payload_sha256' => $payloadSha,
            'payload' => $payload,
            'recorded_at_iso' => $this->nowIso(),
            'schema_version' => 1,
        ];

        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        if (is_file($path)) {
            $existing = (string) file_get_contents($path);
            $decoded = json_decode($existing, true);
            $existingSha = is_array($decoded) ? (string) ($decoded['payload_sha256'] ?? '') : '';

            if ($existingSha === $payloadSha) {
                return is_array($decoded) ? $decoded : $receipt;
            }

            throw new RuntimeException('append_only_violation_existing_receipt_differs');
        }

        file_put_contents($path, $this->canonicalJson($receipt));

        return $receipt;
    }

    private function snapshotDirectory(string $windowLabel): string
    {
        return $this->basePath().'/'.$this->sanitizeWindowLabel($windowLabel);
    }

    private function snapshotPath(string $windowLabel, string $windowStartIso): string
    {
        return $this->snapshotDirectory($windowLabel).'/'.$this->sanitizeIso($windowStartIso).'.json';
    }

    private function comparisonPath(string $windowLabel, string $windowEndIso): string
    {
        return $this->basePath().'/comparisons/'.$this->sanitizeWindowLabel($windowLabel).'/'.$this->sanitizeIso($windowEndIso).'.json';
    }

    private function basePath(): string
    {
        return $this->basePath !== ''
            ? rtrim($this->basePath, '/')
            : storage_path('app/atlas/loop/rolling-windows');
    }

    private function sanitizeWindowLabel(string $windowLabel): string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $windowLabel) !== 1) {
            throw new RuntimeException('invalid_window_label');
        }

        return $windowLabel;
    }

    private function sanitizeIso(string $iso): string
    {
        return str_replace(['/', '\\'], '-', $iso);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function canonicalJson(array $payload): string
    {
        return json_encode($this->sortRecursive($payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortRecursive($item), $value);
        }

        ksort($value, SORT_STRING);
        $sorted = [];
        foreach ($value as $key => $item) {
            $sorted[$key] = $this->sortRecursive($item);
        }

        return $sorted;
    }

    private function nowIso(): string
    {
        if ($this->clock !== null) {
            return (string) call_user_func($this->clock);
        }

        return gmdate('c');
    }
}

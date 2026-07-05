<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\RegressionLock;

/**
 * Engineering Kernel mechanism (OBRA #4 S1): the append-only, content-addressed registry of
 * repaired failures locked as permanent regression cases — "a cada ciclo, o harness fica mais
 * difícil de quebrar" tornado mecânico.
 *
 * Owns: persisting one JSONL entry per repaired failure ({failure_signature, origin, failing_case,
 * locked_test_ref, flake_status}) with a deterministic lock_ref, and answering has(signature) so a
 * failure is locked exactly ONCE (dedupe sticky). Quarantined entries record the KNOWLEDGE of a
 * flaky failure without polluting the suite.
 * Must never own: deciding whether a delivery needs a lock (SovereignHonestyFloor invariant
 * regression_locked_for_repaired) or running the stability probe (RegressionLockWriter).
 */
final class RegressionLockLedger
{
    public const SCHEMA = 'atlas.engineering_kernel.regression_lock.v1';

    public const STATUS_LOCKED = 'locked';

    public const STATUS_QUARANTINED = 'quarantined';

    public function __construct(private readonly ?string $path = null) {}

    /**
     * First entry recorded for a failure signature, or null. Dedupe is sticky: the FIRST lock for a
     * signature wins; later occurrences reference it instead of appending a duplicate.
     *
     * @return array<string,mixed>|null
     */
    public function has(string $failureSignature): ?array
    {
        $failureSignature = trim($failureSignature);
        if ($failureSignature === '') {
            return null;
        }
        foreach ($this->all() as $entry) {
            if (($entry['failure_signature'] ?? null) === $failureSignature) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return array<string,mixed>
     */
    public function lock(array $entry): array
    {
        return $this->append($entry, self::STATUS_LOCKED);
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return array<string,mixed>
     */
    public function quarantine(array $entry): array
    {
        return $this->append($entry, self::STATUS_QUARANTINED);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $file = $this->file();
        if (! is_file($file)) {
            return [];
        }
        $entries = [];
        foreach (explode("\n", trim((string) file_get_contents($file))) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }

        return $entries;
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return array<string,mixed>
     */
    private function append(array $entry, string $status): array
    {
        $body = [
            'schema_version' => self::SCHEMA,
            'failure_signature' => trim((string) ($entry['failure_signature'] ?? '')),
            'origin' => (string) ($entry['origin'] ?? 'unknown'),
            'failing_case' => (string) ($entry['failing_case'] ?? ''),
            'locked_test_ref' => (string) ($entry['locked_test_ref'] ?? ''),
            'flake_status' => $status,
            'stability' => (string) ($entry['stability'] ?? ''),
        ];
        // Content-addressed: o ref deriva do QUE foi trancado, nunca de quando — replayável.
        $body['lock_ref'] = hash('sha256', json_encode([
            $body['failure_signature'], $body['origin'], $body['failing_case'], $body['locked_test_ref'], $status,
        ], JSON_THROW_ON_ERROR));
        $body['locked_at'] = now()->toIso8601String();

        $file = $this->file();
        $dir = dirname($file);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($file, json_encode($body, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);

        return $body;
    }

    private function file(): string
    {
        if ($this->path !== null && $this->path !== '') {
            return $this->path;
        }
        try {
            return storage_path('atlas/engineering_kernel/regression_locks.jsonl');
        } catch (\Throwable) {
            return sys_get_temp_dir().'/atlas-regression-locks.jsonl';
        }
    }
}

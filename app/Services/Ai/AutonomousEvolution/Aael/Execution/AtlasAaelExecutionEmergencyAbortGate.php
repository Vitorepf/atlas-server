<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael\Execution;

/**
 * Operator-initiated abort gate for AAEL execution.
 *
 * State lives in a single sentinel file. Operations:
 *   - raise(reason, ?safeStateCheckpointId): atomic write (tmp + rename).
 *   - clear(): removes the sentinel; touches NO other state (safe-state checkpoint preserved).
 *   - check(): returns FACTS only.
 *
 * Schema: atlas.aael.execution.abort.v1.
 *
 * Master switch: check() works regardless of master switch state — operator must be able to
 * inspect/abort even when the loop master is off.
 */
final class AtlasAaelExecutionEmergencyAbortGate
{
    public const SCHEMA = 'atlas.aael.execution.abort.v1';

    public function __construct(private readonly string $sentinelPath)
    {
        $dir = dirname($this->sentinelPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function raise(string $reason, ?string $safeStateCheckpointId = null): array
    {
        $body = [
            'schema' => self::SCHEMA,
            'reason' => $reason,
            'safe_state_checkpoint_id' => $safeStateCheckpointId,
            'raised_at_iso' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $bytes = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $tmp = $this->sentinelPath.'.tmp.'.bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $bytes) === false) {
            throw new \RuntimeException('AtlasAaelExecutionEmergencyAbortGate: tmp write failed');
        }
        if (! @rename($tmp, $this->sentinelPath)) {
            @unlink($tmp);
            throw new \RuntimeException('AtlasAaelExecutionEmergencyAbortGate: atomic rename failed');
        }

        return $body;
    }

    public function clear(): bool
    {
        if (! is_file($this->sentinelPath)) {
            return false;
        }

        return @unlink($this->sentinelPath);
    }

    /**
     * @return array<string,mixed>
     */
    public function check(): array
    {
        if (! is_file($this->sentinelPath)) {
            return [
                'schema' => self::SCHEMA,
                'aborted' => false,
                'reason' => null,
                'raised_at_iso' => null,
                'safe_state_checkpoint_id' => null,
            ];
        }

        $decoded = json_decode((string) @file_get_contents($this->sentinelPath), true);
        $reason = is_array($decoded) ? (string) ($decoded['reason'] ?? '') : '';
        $checkpoint = is_array($decoded) && isset($decoded['safe_state_checkpoint_id']) ? (string) $decoded['safe_state_checkpoint_id'] : null;
        $raisedAtIso = is_array($decoded) && isset($decoded['raised_at_iso']) ? (string) $decoded['raised_at_iso'] : gmdate('Y-m-d\TH:i:s\Z', (int) @filemtime($this->sentinelPath));

        return [
            'schema' => self::SCHEMA,
            'aborted' => true,
            'reason' => $reason !== '' ? $reason : null,
            'raised_at_iso' => $raisedAtIso,
            'safe_state_checkpoint_id' => $checkpoint,
        ];
    }
}

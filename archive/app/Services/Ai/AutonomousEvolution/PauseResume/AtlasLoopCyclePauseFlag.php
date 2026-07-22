<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\PauseResume;

/**
 * Local pause sentinel for the Loop cycle: raise/inspect/lower with a byte-stable hash.
 * FACT-only; no aggregate scoring, no judgement, no provider call. Atomic writes via tmp+rename.
 */
final class AtlasLoopCyclePauseFlag
{
    public const SCHEMA = 'atlas.loop.cycle_pause_flag.v1';

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
    public function raise(string $cycleId, string $phase, string $reason, ?string $raisedAt = null): array
    {
        $raisedAt = $raisedAt ?? gmdate('Y-m-d\TH:i:s\Z');
        $sentinel = [
            'schema_version' => self::SCHEMA,
            'cycle_id' => $cycleId,
            'phase' => $phase,
            'reason' => $reason,
            'raised_at' => $raisedAt,
        ];
        ksort($sentinel, SORT_STRING);
        $sentinel['sentinel_hash'] = $this->hashOf($sentinel);

        $this->atomicWrite($sentinel);

        return $sentinel;
    }

    public function isRaised(): bool
    {
        return $this->inspect() !== null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function inspect(): ?array
    {
        if (! is_file($this->sentinelPath)) {
            return null;
        }
        $raw = (string) file_get_contents($this->sentinelPath);
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }
        $claimed = $decoded['sentinel_hash'] ?? null;
        $copy = $decoded;
        unset($copy['sentinel_hash']);
        ksort($copy, SORT_STRING);
        if (! is_string($claimed) || $this->hashOf($copy) !== $claimed) {
            return null;
        }

        return $decoded;
    }

    public function lower(): bool
    {
        if (! is_file($this->sentinelPath)) {
            return false;
        }

        return @unlink($this->sentinelPath);
    }

    /**
     * @param  array<string,mixed>  $payload  must already be ksort'd and free of sentinel_hash
     */
    private function hashOf(array $payload): string
    {
        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string,mixed>  $sentinel
     */
    private function atomicWrite(array $sentinel): void
    {
        $tmp = $this->sentinelPath.'.tmp.'.bin2hex(random_bytes(4));
        $bytes = (string) json_encode($sentinel, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (@file_put_contents($tmp, $bytes) === false) {
            throw new \RuntimeException('AtlasLoopCyclePauseFlag: cannot write tmp '.$tmp);
        }
        if (! @rename($tmp, $this->sentinelPath)) {
            @unlink($tmp);
            throw new \RuntimeException('AtlasLoopCyclePauseFlag: cannot rename tmp into place');
        }
    }
}

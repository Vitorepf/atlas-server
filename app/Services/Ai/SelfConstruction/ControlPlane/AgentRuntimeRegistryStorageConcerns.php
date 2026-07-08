<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Storage/locking/validacao compartilhados dos repositories do AgentRuntimeRegistry
 * (paths, disk, lock, ids, listas) — eram 7 metodos clonados byte a byte entre
 * Repository/Heartbeat/Quarantine (censo de metodos duplicados 05/07).
 */
trait AgentRuntimeRegistryStorageConcerns
{
    private function agentPath(string $agentId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $agentId) ?? $agentId;

        return self::STORAGE_PREFIX.'/agent_'.$safe.'.json';
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->disk ?? self::DEFAULT_DISK);
    }

    private function envelopeError(string $reason, string $agentId, array $extra = []): array
    {
        return array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'blocked',
            'event' => 'blocked',
            'agent_id' => $agentId,
            'reason' => $reason,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
        ], $extra);
    }

    public function isAvailable(): bool
    {
        try {
            $disk = $this->disk();
            $probe = self::STORAGE_PREFIX.'/.health';
            $disk->put($probe, '');
            $exists = $disk->exists($probe);
            $disk->delete($probe);

            return $exists;
        } catch (Throwable) {
            return false;
        }
    }

    private function isValidAgentId(string $agentId): bool
    {
        return $agentId !== '' && (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]{1,127}$/', $agentId);
    }

    private function normalizeStringList(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $clean = trim((string) $value);
            if ($clean === '') {
                continue;
            }
            $normalized[$clean] = true;
        }
        $keys = array_keys($normalized);
        sort($keys);

        return array_values($keys);
    }

    private function withLock(callable $callback): mixed
    {
        $disk = $this->disk();
        $start = microtime(true);
        $lockToken = (string) Str::uuid();

        while (true) {
            if (! $disk->exists(self::LOCK_PATH)) {
                $disk->put(self::LOCK_PATH, $lockToken);
                $current = (string) $disk->get(self::LOCK_PATH);
                if ($current === $lockToken) {
                    break;
                }
            }
            if ((microtime(true) - $start) > 4.0) {
                break;
            }
            usleep(50_000);
        }

        try {
            return $callback();
        } finally {
            if ($disk->exists(self::LOCK_PATH)) {
                $disk->delete(self::LOCK_PATH);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai\Support;

use App\Models\AtlasMemoryEntry;
use Illuminate\Support\Str;

/**
 * Parsing/normalizacao de escopo de memoria (scope id/type, uuid, workspace scope) —
 * clonado byte a byte entre AtlasMemoryRegistryService e AtlasVerbatimMemoryService
 * (53 linhas no jscpd 05/07).
 */
trait MemoryScopeHelpers
{
    private function scopeId(string $scopeType, array $attributes): ?string
    {
        if ($scopeType === 'global') {
            return null;
        }

        $explicit = $attributes['scope_id'] ?? null;
        if (is_scalar($explicit) && trim((string) $explicit) !== '') {
            return trim((string) $explicit);
        }

        return match ($scopeType) {
            'project' => $this->stringOrNull($attributes['project_id'] ?? null),
            'task' => $this->stringOrNull($attributes['task_id'] ?? null),
            'engineering_run' => $this->stringOrNull($attributes['engineering_run_id'] ?? ($attributes['run_id'] ?? null)),
            'workspace' => $this->stringOrNull($attributes['workspace_id'] ?? null) ?? $this->workspaceScopeId($attributes['workspace'] ?? null),
            'session' => $this->stringOrNull($attributes['session_id'] ?? null),
            'user' => $this->stringOrNull($attributes['user_id'] ?? null),
            default => null,
        };
    }

    private function scopeType(array $attributes): string
    {
        $scopeType = (string) ($attributes['scope_type'] ?? '');
        if ($scopeType === '' && isset($attributes['scope'])) {
            $scopeType = (string) $attributes['scope'];
        }
        if ($scopeType === '') {
            $scopeType = match (true) {
                isset($attributes['engineering_run_id']) || isset($attributes['run_id']) => 'engineering_run',
                isset($attributes['task_id']) => 'task',
                isset($attributes['project_id']) => 'project',
                isset($attributes['workspace']) || isset($attributes['workspace_id']) => 'workspace',
                isset($attributes['session_id']) => 'session',
                isset($attributes['user_id']) => 'user',
                default => 'global',
            };
        }

        return in_array($scopeType, AtlasMemoryEntry::SCOPES, true) ? $scopeType : 'global';
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function uuidOrNull(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }

    private function workspaceScopeId(mixed $workspace): ?string
    {
        if (! is_scalar($workspace) || trim((string) $workspace) === '') {
            return null;
        }

        $workspace = trim((string) $workspace);

        return hash('sha256', realpath($workspace) ?: $workspace);
    }
}

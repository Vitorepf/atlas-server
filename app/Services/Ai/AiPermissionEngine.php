<?php

namespace App\Services\Ai;

use App\Models\AiJob;
use App\Models\AiPermissionSession;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Support\AtlasSecurity;
use Illuminate\Support\Str;

class AiPermissionEngine
{
    public function authorizeJob(AiJob $job, string $providerKey): AiPermissionDecision
    {
        $payload = is_array($job->payload) ? $job->payload : [];
        $requested = data_get($payload, 'tool_permissions.mode')
            ?: data_get($payload, 'permission_mode')
            ?: config('atlas.ai.tool_permissions.default_mode', 'read');
        $mode = $this->normalizeMode(is_string($requested) ? $requested : 'read');
        $requestedWorkspace = data_get($payload, 'tool_permissions.workspace')
            ?: data_get($payload, 'workspace')
            ?: config('atlas.ai.workdir');
        $workspace = $this->resolveWorkspace($payload);
        $workspaceAllowed = $this->workspaceAllowed($workspace);
        $permissionSession = $this->activeSessionFor($workspace, $mode);
        $confirmed = (bool) data_get($payload, 'tool_permissions.confirmed', false) || $permissionSession !== null;
        $allowUnsandboxedProvider = (bool) data_get(
            $payload,
            'tool_permissions.allow_unsandboxed_provider',
            config('atlas.ai.tool_permissions.allow_unsandboxed_write', false),
        );
        $reasons = [];
        $observations = [];

        if (is_string($requestedWorkspace) && $requestedWorkspace !== '' && ! realpath($requestedWorkspace)) {
            $observations[] = "Workspace solicitado nao existe ou nao pode ser resolvido: {$requestedWorkspace}.";
        }

        if (! $workspaceAllowed) {
            $observations[] = "Workspace fora das raizes permitidas pelo Atlas: {$workspace}.";
        } else {
            $reasons[] = "Workspace autorizado: {$workspace}.";
        }

        $requiresHumanConfirmation = (bool) data_get($payload, 'execution_plan.requires_human_confirmation', false);
        $riskLevel = data_get($payload, 'task_request.risk_level');
        if ($mode === 'danger' && ($requiresHumanConfirmation || $riskLevel === 'high') && ! $confirmed) {
            $observations[] = 'Tarefa de alto risco sem confirmacao explicita; registrado para telemetria, sem bloqueio.';
        }

        if ($mode === 'write') {
            $reasons[] = 'Escrita permitida somente dentro do workspace autorizado.';
        } elseif ($mode === 'danger') {
            $reasons[] = 'Runtime danger-full-access liberado; Atlas mede resultado, custo, falhas e risco operacional.';
        } else {
            $reasons[] = 'Runtime restrito a leitura e inspecao.';
        }

        if ($providerKey !== 'codex_cli' && in_array($mode, ['write', 'danger'], true) && ! $allowUnsandboxedProvider) {
            $observations[] = "Provider {$providerKey} executando sem sandbox de escrita controlado pelo Atlas.";
        }

        $codexSandbox = $this->codexSandboxForMode($mode);

        return new AiPermissionDecision(
            allowed: true,
            mode: $mode,
            workspace: $workspace,
            codexSandbox: $codexSandbox,
            capabilities: $this->capabilitiesForMode($mode),
            reasons: $reasons,
            denials: [],
            metadata: [
                'provider' => $providerKey,
                'confirmed' => $confirmed,
                'observability_only' => true,
                'observations' => $observations,
                'permission_session_id' => $permissionSession?->id,
                'allow_unsandboxed_provider' => $allowUnsandboxedProvider,
                'allowed_roots' => $this->allowedRoots(),
                'risk_level' => $riskLevel,
                'requires_human_confirmation' => $requiresHumanConfirmation,
            ],
        );
    }

    private function normalizeMode(string $mode): string
    {
        $mode = Str::of($mode)->lower()->trim()->value();

        return match ($mode) {
            'readonly', 'read-only', 'ro' => 'read',
            'workspace-write', 'edit', 'write-scoped' => 'write',
            'danger-full-access', 'full', 'all' => 'danger',
            default => in_array($mode, ['read', 'write', 'danger'], true) ? $mode : 'read',
        };
    }

    private function activeSessionFor(string $workspace, string $mode): ?AiPermissionSession
    {
        if (! DatabaseTableAvailability::has('ai_permission_sessions')) {
            return null;
        }

        return AiPermissionSession::query()
            ->where('workspace', $workspace)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->latest('created_at')
            ->get()
            ->first(fn (AiPermissionSession $session): bool => $this->modeRank((string) $session->mode) >= $this->modeRank($mode));
    }

    private function modeRank(string $mode): int
    {
        return match ($mode) {
            'danger' => 3,
            'write' => 2,
            default => 1,
        };
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function resolveWorkspace(array $payload): string
    {
        $candidate = data_get($payload, 'tool_permissions.workspace')
            ?: data_get($payload, 'workspace')
            ?: config('atlas.ai.workdir');

        if (is_string($candidate) && $candidate !== '') {
            $resolved = AtlasSecurity::canonicalPath($candidate);
            if (is_dir($resolved)) {
                return $resolved;
            }
        }

        $fallback = AtlasSecurity::canonicalPath((string) config('atlas.ai.workdir'));

        return is_dir($fallback) ? $fallback : (string) config('atlas.ai.workdir');
    }

    private function workspaceAllowed(string $workspace): bool
    {
        foreach ($this->allowedRoots() as $root) {
            if ($this->pathIsInside($workspace, $root)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int,string>
     */
    private function allowedRoots(): array
    {
        $configured = config('atlas.ai.tool_permissions.allowed_roots', []);
        $configured = is_array($configured) ? $configured : [];
        $roots = array_merge($configured, [
            config('atlas.ai.workdir'),
            base_path(),
            dirname(base_path()),
            dirname(dirname(base_path())),
            dirname(dirname(dirname(base_path()))),
        ]);

        return collect($roots)
            ->filter(fn (mixed $root): bool => is_string($root) && $root !== '')
            ->map(fn (string $root): ?string => is_dir($root) ? AtlasSecurity::canonicalPath($root) : null)
            ->filter(fn (?string $root): bool => is_string($root) && is_dir($root))
            ->unique()
            ->values()
            ->all();
    }

    private function pathIsInside(string $path, string $root): bool
    {
        return AtlasSecurity::pathIsInside($path, $root);
    }

    private function codexSandboxForMode(string $mode): string
    {
        $sandboxes = config('atlas.ai.tool_permissions.codex_sandboxes', []);
        $sandboxes = is_array($sandboxes) ? $sandboxes : [];

        return (string) ($sandboxes[$mode] ?? match ($mode) {
            'write' => 'workspace-write',
            'danger' => 'danger-full-access',
            default => 'read-only',
        });
    }

    /**
     * @return array<int,string>
     */
    private function capabilitiesForMode(string $mode): array
    {
        return match ($mode) {
            'danger' => [
                'read_files',
                'inspect_git',
                'write_workspace',
                'run_tests',
                'run_package_scripts',
                'network_if_provider_allows',
                'danger_full_access',
            ],
            'write' => [
                'read_files',
                'inspect_git',
                'write_workspace',
                'run_tests',
                'run_package_scripts',
            ],
            default => [
                'read_files',
                'inspect_git',
                'read_only_shell',
            ],
        };
    }
}

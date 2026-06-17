<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiJob;
use App\Models\AiPermissionSession;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Support\AtlasSecurity;
use Illuminate\Support\Str;

/**
 * Immutable resolution of every fact the engine needs to decide on a job.
 *
 * Materialized in one shot by {@see AiPermissionEngineSupport::resolve()} so the
 * orchestrator does not have to re-bind intermediate variables.
 */
final class AiPermissionResolution
{
    /**
     * @param  array<int,string>  $allowedRoots
     * @param  array<int,string>  $observations
     * @param  array<int,string>  $reasons
     * @param  array<int,string>  $capabilities
     */
    public function __construct(
        public readonly string $mode,
        public readonly string $workspace,
        public readonly string $requestedWorkspace,
        public readonly bool $workspaceAllowed,
        public readonly ?AiPermissionSession $permissionSession,
        public readonly bool $confirmed,
        public readonly bool $allowUnsandboxedProvider,
        public readonly bool $requiresHumanConfirmation,
        public readonly mixed $riskLevel,
        public readonly array $allowedRoots,
        public readonly array $observations,
        public readonly array $reasons,
        public readonly array $capabilities,
        public readonly string $codexSandbox,
    ) {}
}

/**
 * Single-class cohesive facade: every helper that used to bloat
 * {@see AiPermissionEngine::authorizeJob()} lives here, behind one
 * entry point ({@see resolve()}) that returns a fully populated
 * {@see AiPermissionResolution}.
 */
final class AiPermissionEngineSupport
{
    public function resolve(AiJob $job, string $providerKey): AiPermissionResolution
    {
        [$mode, $payload] = $this->resolveMode($job);

        $policy = $this->resolveWorkspacePolicy($payload);
        $allowedRoots = $this->allowedRoots();

        $permissionSession = $this->resolvePermissionSession($policy['workspace'], $mode);
        $confirmed = $this->resolveConfirmation($payload, $permissionSession);
        $allowUnsandboxedProvider = $this->resolveAllowUnsandboxedProvider($payload);
        $requiresHumanConfirmation = (bool) data_get($payload, 'execution_plan.requires_human_confirmation', false);
        $riskLevel = data_get($payload, 'task_request.risk_level');

        $observations = $this->collectObservations(
            $policy['requested'],
            $policy['workspace'],
            $policy['allowed'],
            $mode,
            $requiresHumanConfirmation,
            $riskLevel,
            $confirmed,
            $providerKey,
            $allowUnsandboxedProvider,
        );

        $reasons = $this->collectReasons($mode, $policy['workspace'], $policy['allowed']);

        return new AiPermissionResolution(
            mode: $mode,
            workspace: $policy['workspace'],
            requestedWorkspace: $policy['requested'],
            workspaceAllowed: $policy['allowed'],
            permissionSession: $permissionSession,
            confirmed: $confirmed,
            allowUnsandboxedProvider: $allowUnsandboxedProvider,
            requiresHumanConfirmation: $requiresHumanConfirmation,
            riskLevel: $riskLevel,
            allowedRoots: $allowedRoots,
            observations: $observations,
            reasons: $reasons,
            capabilities: $this->capabilitiesForMode($mode),
            codexSandbox: $this->codexSandboxForMode($mode),
        );
    }

    /**
     * @return array{0:string,1:array<string,mixed>}
     */
    public function resolveMode(AiJob $job): array
    {
        $payload = is_array($job->payload) ? $job->payload : [];
        $requested = data_get($payload, 'tool_permissions.mode')
            ?: data_get($payload, 'permission_mode')
            ?: config('atlas.ai.tool_permissions.default_mode', 'read');
        $mode = $this->normalizeMode(is_string($requested) ? $requested : 'read');

        return [$mode, $payload];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{workspace:string,requested:mixed,allowed:bool}
     */
    public function resolveWorkspacePolicy(array $payload): array
    {
        $requested = data_get($payload, 'tool_permissions.workspace')
            ?: data_get($payload, 'workspace')
            ?: config('atlas.ai.workdir');
        $workspace = $this->resolveWorkspace($payload);
        $allowed = $this->workspaceAllowed($workspace);

        return [
            'workspace' => $workspace,
            'requested' => $requested,
            'allowed' => $allowed,
        ];
    }

    /**
     * @return array<int,string>
     */
    public function collectObservations(
        mixed $requestedWorkspace,
        string $workspace,
        bool $workspaceAllowed,
        string $mode,
        bool $requiresHumanConfirmation,
        mixed $riskLevel,
        bool $confirmed,
        string $providerKey,
        bool $allowUnsandboxedProvider,
    ): array {
        $observations = [];

        if (is_string($requestedWorkspace) && $requestedWorkspace !== '' && ! realpath($requestedWorkspace)) {
            $observations[] = "Workspace solicitado nao existe ou nao pode ser resolvido: {$requestedWorkspace}.";
        }

        if (! $workspaceAllowed) {
            $observations[] = "Workspace fora das raizes permitidas pelo Atlas: {$workspace}.";
        }

        if ($mode === 'danger' && ($requiresHumanConfirmation || $riskLevel === 'high') && ! $confirmed) {
            $observations[] = 'Tarefa de alto risco sem confirmacao explicita; registrado para telemetria, sem bloqueio.';
        }

        if ($providerKey !== 'codex_cli' && in_array($mode, ['write', 'danger'], true) && ! $allowUnsandboxedProvider) {
            $observations[] = "Provider {$providerKey} executando sem sandbox de escrita controlado pelo Atlas.";
        }

        return $observations;
    }

    /**
     * @return array<int,string>
     */
    public function collectReasons(string $mode, string $workspace, bool $workspaceAllowed): array
    {
        $reasons = [];

        if ($workspaceAllowed) {
            $reasons[] = "Workspace autorizado: {$workspace}.";
        }

        if ($mode === 'write') {
            $reasons[] = 'Escrita permitida somente dentro do workspace autorizado.';
        } elseif ($mode === 'danger') {
            $reasons[] = 'Runtime danger-full-access liberado; Atlas mede resultado, custo, falhas e risco operacional.';
        } else {
            $reasons[] = 'Runtime restrito a leitura e inspecao.';
        }

        return $reasons;
    }

    public function resolveConfirmation(array $payload, ?AiPermissionSession $session): bool
    {
        return (bool) data_get($payload, 'tool_permissions.confirmed', false) || $session !== null;
    }

    public function resolveAllowUnsandboxedProvider(array $payload): bool
    {
        return (bool) data_get(
            $payload,
            'tool_permissions.allow_unsandboxed_provider',
            config('atlas.ai.tool_permissions.allow_unsandboxed_write', false),
        );
    }

    public function codexSandboxForMode(string $mode): string
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
    public function capabilitiesForMode(string $mode): array
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

    /**
     * @return array<int,string>
     */
    public function allowedRoots(): array
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

    public function resolvePermissionSession(string $workspace, string $mode): ?AiPermissionSession
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
            if (AtlasSecurity::pathIsInside($workspace, $root)) {
                return true;
            }
        }

        return false;
    }

    private function modeRank(string $mode): int
    {
        return match ($mode) {
            'danger' => 3,
            'write' => 2,
            default => 1,
        };
    }
}

/**
 * Builds the public {@see AiPermissionDecision} out of a fully resolved
 * {@see AiPermissionResolution} and the provider key. Centralizes the
 * 10-key metadata assembly so the engine does not have to.
 */
final class AiPermissionDecisionBuilder
{
    public function build(AiPermissionResolution $resolution, string $providerKey): AiPermissionDecision
    {
        return new AiPermissionDecision(
            allowed: true,
            mode: $resolution->mode,
            workspace: $resolution->workspace,
            codexSandbox: $resolution->codexSandbox,
            capabilities: $resolution->capabilities,
            reasons: $resolution->reasons,
            denials: [],
            metadata: [
                'provider' => $providerKey,
                'confirmed' => $resolution->confirmed,
                'observability_only' => true,
                'observations' => $resolution->observations,
                'permission_session_id' => $resolution->permissionSession?->id,
                'allow_unsandboxed_provider' => $resolution->allowUnsandboxedProvider,
                'allowed_roots' => $resolution->allowedRoots,
                'risk_level' => $resolution->riskLevel,
                'requires_human_confirmation' => $resolution->requiresHumanConfirmation,
            ],
        );
    }
}

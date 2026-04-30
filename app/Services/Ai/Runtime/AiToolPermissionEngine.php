<?php

namespace App\Services\Ai\Runtime;

use App\Models\AiPermissionSession;
use App\Support\AtlasSecurity;
use Illuminate\Support\Facades\Schema;

class AiToolPermissionEngine
{
    public function authorize(ToolInvocation $invocation): AiToolPermissionDecision
    {
        $request = PermissionRequest::fromInvocation($invocation);
        $requestedMode = $invocation->permissionMode;
        $denials = [];
        $reasons = [];
        $requiresApproval = false;

        if (! $this->workspaceAllowed($invocation->workspace)) {
            $denials[] = "Workspace fora das raizes permitidas pelo Atlas: {$invocation->workspace}.";
        } else {
            $reasons[] = "Workspace autorizado: {$invocation->workspace}.";
        }

        if (! $this->modeAllows($requestedMode, $request->requiredMode)) {
            $denials[] = "Permissao insuficiente: {$request->tool} exige {$request->requiredMode}, mas recebeu {$requestedMode}.";
        }

        if ($request->requiredMode === 'danger' && ! (bool) config('atlas.ai.tool_permissions.allow_danger', false)) {
            $denials[] = 'Modo danger-full-access esta bloqueado por configuracao global.';
        }

        foreach ($request->paths as $path) {
            $resolved = $this->resolvePath($invocation->workspace, $path);
            if (! $this->pathIsInside($resolved, $invocation->workspace)) {
                $denials[] = "Path fora do workspace autorizado: {$path}.";
            }
        }

        $sessionApproval = $this->activeSessionFor($invocation, $request);
        $approved = (bool) data_get($invocation->metadata, 'approved', false) || $sessionApproval !== null;
        $approvalSource = $sessionApproval ? 'permission_session' : data_get($invocation->metadata, 'approval_source');
        if ($denials === [] && ! $approved && $this->needsHumanApproval($request, $invocation)) {
            $requiresApproval = true;
            $denials[] = "A ferramenta {$request->tool} exige aprovacao humana para {$request->requiredMode}.";
        }

        if ($denials === []) {
            $reasons[] = "Modo {$requestedMode} satisfaz requisito {$request->requiredMode}.";
            if ($approved) {
                $reasons[] = "Aprovado por {$approvalSource}.";
            }
        }

        return new AiToolPermissionDecision(
            allowed: $denials === [],
            requiresApproval: $requiresApproval,
            request: $request,
            requestedMode: $requestedMode,
            reasons: $reasons,
            denials: $denials,
            metadata: [
                'approved' => $approved,
                'approval_source' => $approvalSource,
                'permission_session_id' => $sessionApproval?->id,
                'allowed_roots' => $this->allowedRoots(),
            ],
        );
    }

    public function requiredModeFor(ToolInvocation $invocation): string
    {
        return PermissionRequest::fromInvocation($invocation)->requiredMode;
    }

    private function needsHumanApproval(PermissionRequest $request, ToolInvocation $invocation): bool
    {
        if ($invocation->dryRun && in_array($request->tool, ['file.write', 'file.patch', 'git.apply_patch'], true)) {
            return false;
        }

        return $request->requiredMode !== 'read';
    }

    private function activeSessionFor(ToolInvocation $invocation, PermissionRequest $request): ?AiPermissionSession
    {
        if (! Schema::hasTable('ai_permission_sessions')) {
            return null;
        }

        return AiPermissionSession::query()
            ->where('workspace', $invocation->workspace)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->latest('created_at')
            ->get()
            ->first(function (AiPermissionSession $session) use ($invocation, $request): bool {
                if (! $this->modeAllows($session->mode, $request->requiredMode)) {
                    return false;
                }

                if ($session->mode === 'danger' && $session->expires_at && $session->expires_at->greaterThan(now()->addMinutes(30))) {
                    return false;
                }

                $tools = (array) ($session->allowed_tools ?? []);
                if ($tools !== [] && ! in_array($request->tool, $tools, true)) {
                    return false;
                }

                $paths = (array) ($session->allowed_paths ?? []);
                if ($paths === []) {
                    return true;
                }

                if ($request->paths === []) {
                    return true;
                }

                foreach ($request->paths as $path) {
                    $resolved = $this->resolvePath($invocation->workspace, $path);
                    $covered = collect($paths)->contains(function (mixed $allowed) use ($invocation, $resolved): bool {
                        if (! is_string($allowed) || $allowed === '') {
                            return false;
                        }

                        $allowedPath = $this->resolvePath($invocation->workspace, $allowed);

                        return $this->pathIsInside($resolved, $allowedPath);
                    });

                    if (! $covered) {
                        return false;
                    }
                }

                return true;
            });
    }

    private function modeAllows(string $actual, string $required): bool
    {
        return $this->modeRank($actual) >= $this->modeRank($required);
    }

    private function modeRank(string $mode): int
    {
        return match ($mode) {
            'danger' => 3,
            'write' => 2,
            default => 1,
        };
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
        ]);

        return collect($roots)
            ->filter(fn (mixed $root): bool => is_string($root) && $root !== '')
            ->map(fn (string $root): ?string => is_dir($root) ? AtlasSecurity::canonicalPath($root) : null)
            ->filter(fn (?string $root): bool => is_string($root) && is_dir($root))
            ->unique()
            ->values()
            ->all();
    }

    private function resolvePath(string $workspace, string $path): string
    {
        return AtlasSecurity::canonicalPath($path, $workspace, allowMissing: true);
    }

    private function pathIsInside(string $path, string $root): bool
    {
        return AtlasSecurity::pathIsInside($path, $root);
    }
}

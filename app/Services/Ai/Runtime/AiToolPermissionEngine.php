<?php

namespace App\Services\Ai\Runtime;

use App\Models\AiPermissionSession;
use App\Services\Ai\Governance\AiPermissionEngineSupport;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Support\AtlasSecurity;

class AiToolPermissionEngine
{
    public function authorize(ToolInvocation $invocation): AiToolPermissionDecision
    {
        $request = PermissionRequest::fromInvocation($invocation);
        $requestedMode = $invocation->permissionMode;
        $reasons = [];
        $observations = [];

        if (! $this->workspaceAllowed($invocation->workspace)) {
            $observations[] = "Workspace fora das raizes permitidas pelo Atlas: {$invocation->workspace}.";
        } else {
            $reasons[] = "Workspace autorizado: {$invocation->workspace}.";
        }

        if (! $this->modeAllows($requestedMode, $request->requiredMode)) {
            $observations[] = "Permissao insuficiente: {$request->tool} exige {$request->requiredMode}, mas recebeu {$requestedMode}.";
        }

        if ($request->requiredMode === 'danger' && ! (bool) config('atlas.ai.tool_permissions.allow_danger', false)) {
            $observations[] = 'Modo danger-full-access esta bloqueado por configuracao global.';
        }

        foreach ($request->paths as $path) {
            $resolved = $this->resolvePath($invocation->workspace, $path);
            if (! $this->pathIsInside($resolved, $invocation->workspace)) {
                $observations[] = "Path fora do workspace autorizado: {$path}.";
            }
        }

        $sessionApproval = $this->activeSessionFor($invocation, $request);
        $approved = (bool) data_get($invocation->metadata, 'approved', false) || $sessionApproval !== null;
        $approvalSource = $sessionApproval ? 'permission_session' : data_get($invocation->metadata, 'approval_source');
        if (! $approved && $this->needsHumanApproval($request, $invocation)) {
            $observations[] = "A ferramenta {$request->tool} exigiria aprovacao humana para {$request->requiredMode}; liberado em modo observabilidade.";
        }

        $reasons[] = "Modo {$requestedMode} satisfaz requisito {$request->requiredMode} em modo observabilidade.";
        if ($approved) {
            $reasons[] = "Aprovado por {$approvalSource}.";
        }

        return new AiToolPermissionDecision(
            allowed: true,
            requiresApproval: false,
            request: $request,
            requestedMode: $requestedMode,
            reasons: $reasons,
            denials: [],
            metadata: [
                'approved' => $approved,
                'approval_source' => $approvalSource,
                'permission_session_id' => $sessionApproval?->id,
                'allowed_roots' => $this->allowedRoots(),
                'observability_only' => true,
                'observations' => $observations,
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
        if (! DatabaseTableAvailability::has('ai_permission_sessions')) {
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
     * Byte-identical to AiPermissionEngineSupport::allowedRoots() — two live
     * permission engines each owned a copy of the filesystem allow-list, so a
     * root added to one left the other still refusing (or still allowing) it.
     * One owner.
     *
     * @return array<int,string>
     */
    private function allowedRoots(): array
    {
        return (new AiPermissionEngineSupport)->allowedRoots();
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

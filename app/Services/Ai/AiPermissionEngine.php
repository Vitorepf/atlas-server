<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiJob;

class AiPermissionEngine
{
    private readonly AiPermissionEngineSupport $support;
    private readonly AiPermissionDecisionBuilder $decisionBuilder;

    public function __construct(
        ?AiPermissionEngineSupport $support = null,
        ?AiPermissionDecisionBuilder $decisionBuilder = null,
    ) {
        $this->support = $support ?? new AiPermissionEngineSupport();
        $this->decisionBuilder = $decisionBuilder ?? new AiPermissionDecisionBuilder();
    }

    public function authorizeJob(AiJob $job, string $providerKey): AiPermissionDecision
    {
        $resolution = $this->support->resolve($job, $providerKey);
        $decision = $this->decisionBuilder->build($resolution, $providerKey);

        return $this->applyFailClosedGuard($job, $decision);
    }

    /**
     * Fail-closed guard: write/danger require a valid workspace-cert whose mode is
     * at least as privileged as the requested mode. read/observe does not require a
     * cert and stays allowed when the workspace policy permits.
     */
    private function applyFailClosedGuard(AiJob $job, AiPermissionDecision $decision): AiPermissionDecision
    {
        $mode = $decision->mode;
        if (! in_array($mode, ['write', 'danger'], true)) {
            return $decision;
        }

        $payload = is_array($job->payload) ? $job->payload : [];
        $cert = data_get($payload, 'tool_permissions.workspace_cert')
            ?? data_get($payload, 'workspace_cert')
            ?? null;

        if (! is_array($cert) || ($cert['status'] ?? '') !== 'available') {
            return $this->deniedDecision(
                $decision,
                'workspace_cert_unavailable',
                "Modo {$mode} requer workspace-cert com status=available; cert ausente ou negado.",
                ['remediation' => 'Rode a certificacao do workspace ou mude para modo read.'],
            );
        }

        $certMode = (string) ($cert['mode'] ?? 'read');
        if ($this->modeRank($certMode) < $this->modeRank($mode)) {
            return $this->deniedDecision(
                $decision,
                'workspace_cert_insufficient_mode',
                "Workspace-cert mode='{$certMode}' nao cobre modo requisitado '{$mode}'.",
                ['remediation' => 'Re-certifique o workspace para write/danger ou reduza o modo solicitado.'],
            );
        }

        return $decision;
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
     * @param  array<string, mixed>  $extra
     */
    private function deniedDecision(AiPermissionDecision $base, string $reasonCode, string $message, array $extra = []): AiPermissionDecision
    {
        $metadata = $base->metadata;
        $metadata['fail_closed_reason'] = $reasonCode;
        $metadata['fail_closed_message'] = $message;
        foreach ($extra as $key => $value) {
            $metadata[$key] = $value;
        }

        return new AiPermissionDecision(
            allowed: false,
            mode: $base->mode,
            workspace: $base->workspace,
            codexSandbox: $base->codexSandbox,
            capabilities: $base->capabilities,
            reasons: $base->reasons,
            denials: array_merge($base->denials, [$message]),
            metadata: $metadata,
        );
    }
}

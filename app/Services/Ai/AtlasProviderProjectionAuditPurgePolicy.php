<?php

namespace App\Services\Ai;

class AtlasProviderProjectionAuditPurgePolicy
{
    /**
     * @return array<string,mixed>
     */
    public function evaluate(bool $dryRun, ?string $operatorHeaderValue = null): array
    {
        $requiresOperator = ! $dryRun && (bool) config('atlas.ai.provider_projection_audit_purge.require_operator', false);
        $expectedToken = config('atlas.ai.provider_projection_audit_purge.operator_token');
        $tokenConfigured = is_string($expectedToken) && trim($expectedToken) !== '';
        $provided = trim((string) $operatorHeaderValue);

        return [
            'authorized' => ! $requiresOperator || $this->operatorHeaderAuthorized($provided, $expectedToken),
            'requires_operator' => $requiresOperator,
            'mode' => ! $requiresOperator ? 'atlas_token' : ($tokenConfigured ? 'operator_token' : 'operator_header'),
            'header' => $this->headerName(),
        ];
    }

    public function headerName(): string
    {
        $header = trim((string) config('atlas.ai.provider_projection_audit_purge.operator_header', 'X-Atlas-Operator'));

        return $header !== '' ? $header : 'X-Atlas-Operator';
    }

    private function operatorHeaderAuthorized(string $provided, mixed $expectedToken): bool
    {
        if (is_string($expectedToken) && trim($expectedToken) !== '') {
            return hash_equals(trim($expectedToken), $provided);
        }

        return in_array(strtolower($provided), ['1', 'true', 'yes', 'operator', 'admin', 'owner'], true);
    }
}

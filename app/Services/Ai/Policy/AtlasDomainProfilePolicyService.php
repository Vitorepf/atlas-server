<?php

namespace App\Services\Ai\Policy;

use App\Models\AiDomainProfile;
use App\Models\AiFlowProfile;
use App\Services\Ai\Support\DatabaseTableAvailability;

class AtlasDomainProfilePolicyService
{
    private const POLICY_FIELDS = [
        'model_policy',
        'context_policy',
        'skill_policy',
        'tool_policy',
        'memory_policy',
        'gate_policy',
    ];

    /**
     * @param  array<string,mixed>  $patch
     * @return array<string,mixed>
     */
    public function updateDomain(string $domainId, array $patch): array
    {
        $this->assertTablesAvailable();

        $domain = AiDomainProfile::query()->findOrFail($this->id($domainId));
        $domain->fill($this->domainPatch($patch));
        $domain->save();

        return $domain->refresh()->toArray();
    }

    /**
     * @param  array<string,mixed>  $patch
     * @return array<string,mixed>
     */
    public function updateFlow(string $flowId, array $patch): array
    {
        $this->assertTablesAvailable();

        $flow = AiFlowProfile::query()->findOrFail($this->id($flowId));
        $flow->fill($this->flowPatch($patch));
        $flow->save();

        return $flow->refresh()->toArray();
    }

    private function assertTablesAvailable(): void
    {
        if (! DatabaseTableAvailability::all(['ai_domain_profiles', 'ai_flow_profiles'])) {
            throw new \RuntimeException('AI domain/flow profile tables are not available. Run migrations before editing profile policies.');
        }
    }

    /**
     * @param  array<string,mixed>  $patch
     * @return array<string,mixed>
     */
    private function domainPatch(array $patch): array
    {
        $allowed = [
            'label',
            'status',
            'default_flow',
            'orchestrator',
            'runtime_family',
            'description',
            'autonomy_default',
            'background_allowed',
            'metadata',
            ...self::POLICY_FIELDS,
        ];

        return $this->normalizePatch($patch, $allowed);
    }

    /**
     * @param  array<string,mixed>  $patch
     * @return array<string,mixed>
     */
    private function flowPatch(array $patch): array
    {
        $allowed = [
            'label',
            'status',
            'orchestrator',
            'runtime',
            'description',
            'autonomy',
            'background_allowed',
            'requires_human_approval_for_destructive',
            'execution_policy',
            'metadata',
            ...self::POLICY_FIELDS,
        ];

        return $this->normalizePatch($patch, $allowed);
    }

    /**
     * @param  array<string,mixed>  $patch
     * @param  array<int,string>  $allowed
     * @return array<string,mixed>
     */
    private function normalizePatch(array $patch, array $allowed): array
    {
        $normalized = [];
        foreach ($allowed as $key) {
            if (! array_key_exists($key, $patch)) {
                continue;
            }

            if (in_array($key, ['background_allowed', 'requires_human_approval_for_destructive'], true)) {
                $normalized[$key] = filter_var($patch[$key], FILTER_VALIDATE_BOOLEAN);

                continue;
            }

            if ($key === 'status') {
                $status = strtolower(trim((string) $patch[$key]));
                if (in_array($status, ['active', 'draft', 'disabled', 'archived'], true)) {
                    $normalized[$key] = $status;
                }

                continue;
            }

            if ($key === 'metadata' || $key === 'execution_policy' || in_array($key, self::POLICY_FIELDS, true)) {
                if (is_array($patch[$key])) {
                    $normalized[$key] = $patch[$key];
                }

                continue;
            }

            $value = trim((string) $patch[$key]);
            $normalized[$key] = $value !== '' ? $value : null;
        }

        return $normalized;
    }

    private function id(string $id): string
    {
        return strtolower(trim($id));
    }
}

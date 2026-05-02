<?php

namespace App\Services\Tools;

use App\Models\AtlasToolDefinition;
use App\Models\AtlasToolPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class AtlasToolPolicyEngine
{
    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function decide(AtlasToolDefinition $definition, array $context = []): array
    {
        $policy = $this->policyFor($definition->slug, $context);
        $enabled = $policy?->enabled ?? (bool) $definition->default_enabled;
        $required = (bool) ($context['required'] ?? false);
        $riskLevel = (string) $definition->risk_level;
        $networkAllowed = (bool) ($context['network_allowed'] ?? false);
        $costAllowed = (bool) ($context['paid_tools_allowed'] ?? false);
        $dryRun = (bool) ($context['dry_run'] ?? false);
        $risks = (array) $definition->risks_json;
        $policyApproved = $this->policyApproved($policy);
        $policyNetworkAllowed = (bool) data_get($policy?->metadata, 'network_allowed', false);
        $reasons = [];
        $decision = 'allowed';

        if (! $enabled || $definition->status !== 'active') {
            $decision = $required ? 'denied' : 'skipped';
            $reasons[] = 'tool_disabled';
        }

        if ($decision === 'allowed' && $definition->cost_posture !== 'free_local' && ! $costAllowed) {
            $decision = 'requires_approval';
            $reasons[] = 'non_free_tool_requires_approval';
        }

        if ($decision === 'allowed' && in_array('may_use_network', $risks, true) && ! $networkAllowed && ! $policyNetworkAllowed) {
            $decision = $required ? 'requires_approval' : 'skipped';
            $reasons[] = 'network_not_allowed';
        }

        if ($decision === 'allowed' && in_array($riskLevel, ['high', 'critical'], true) && ! (bool) ($context['approved'] ?? false) && ! $policyApproved && ! $dryRun) {
            $decision = 'requires_approval';
            $reasons[] = 'high_risk_tool_requires_approval';
        }

        return [
            'decision' => $decision,
            'allowed' => $decision === 'allowed',
            'required' => $required,
            'failure_policy' => $policy?->failure_policy ?: $definition->default_failure_policy,
            'timeout_seconds' => $policy?->timeout_seconds ?: $definition->default_timeout_seconds,
            'risk_level' => $riskLevel,
            'reasons' => $reasons,
            'dry_run' => $dryRun,
            'policy_source' => $policy ? 'atlas_tool_policies' : 'tool_definition_default',
            'approval_status' => $policy ? $this->approvalStatus($policy) : 'not_configured',
        ];
    }

    private function policyFor(string $toolSlug, array $context): ?AtlasToolPolicy
    {
        if (! Schema::hasTable('atlas_tool_policies')) {
            return null;
        }

        $workspaceHash = isset($context['workspace'])
            ? hash('sha256', (string) $context['workspace'])
            : null;

        if ($workspaceHash) {
            $workspacePolicy = AtlasToolPolicy::query()
                ->where('scope_type', 'workspace')
                ->where('scope_id', $workspaceHash)
                ->where('tool_slug', $toolSlug)
                ->first();

            if ($workspacePolicy) {
                return $workspacePolicy;
            }
        }

        return AtlasToolPolicy::query()
            ->where('scope_type', 'global')
            ->whereNull('scope_id')
            ->where('tool_slug', $toolSlug)
            ->first();
    }

    private function policyApproved(?AtlasToolPolicy $policy): bool
    {
        return $policy !== null && $this->approvalStatus($policy) === 'approved';
    }

    private function approvalStatus(?AtlasToolPolicy $policy): string
    {
        if (! $policy) {
            return 'not_configured';
        }

        $metadata = (array) $policy->metadata;
        if (! (bool) ($metadata['approved'] ?? false)) {
            return 'not_approved';
        }

        $approvedUntil = $metadata['approved_until'] ?? null;
        if (! is_string($approvedUntil) || trim($approvedUntil) === '') {
            return 'approved';
        }

        try {
            return Carbon::parse($approvedUntil)->isFuture() ? 'approved' : 'expired';
        } catch (\Throwable) {
            return 'invalid';
        }
    }
}

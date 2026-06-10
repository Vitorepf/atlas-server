<?php

namespace App\Services\Tools;

use App\Models\AtlasToolDefinition;
use App\Models\AtlasToolPolicy;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;

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
        $approved = (bool) ($context['approved'] ?? false) || $policyApproved;
        $policyNetworkAllowed = (bool) data_get($policy?->metadata, 'network_allowed', false);
        $executionTier = $this->normalizedTier((string) ($definition->execution_tier ?? data_get($definition->metadata, 'execution_tier', 'T1')));
        $maxExecutionTier = $this->normalizedTier((string) ($context['max_execution_tier'] ?? data_get($policy?->metadata, 'max_execution_tier', 'T3')));
        $sandboxMode = $this->normalizedSandboxMode((string) ($context['sandbox_mode'] ?? data_get($policy?->metadata, 'sandbox_mode', 'workspace')));
        $privacyLevel = $this->normalizedPrivacyLevel((string) ($context['privacy_level'] ?? data_get($policy?->metadata, 'privacy_level', 'standard')));
        $taskType = $this->normalizedTaskType((string) ($context['task_type'] ?? data_get($policy?->metadata, 'task_type', 'manual')));
        $requiresProviderSafe = (bool) ($context['requires_provider_safe'] ?? data_get($policy?->metadata, 'requires_provider_safe', false));
        $providerSafe = $this->providerSafe($risks, $privacyLevel);
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

        if ($decision === 'allowed' && in_array($privacyLevel, ['sensitive', 'restricted'], true) && $this->usesNetwork($risks) && ! $approved && ! $dryRun) {
            $decision = $required ? 'requires_approval' : 'skipped';
            $reasons[] = 'privacy_network_requires_approval';
        }

        if ($decision === 'allowed' && $this->writesWorkspace($risks) && ! in_array($sandboxMode, ['worktree', 'docker'], true) && ! $approved && ! $dryRun) {
            $decision = 'requires_approval';
            $reasons[] = 'workspace_write_requires_sandbox_or_approval';
        }

        if ($decision === 'allowed' && $requiresProviderSafe && ! $providerSafe) {
            $decision = $required ? 'requires_approval' : 'skipped';
            $reasons[] = 'provider_unsafe_output';
        }

        if ($decision === 'allowed' && in_array($riskLevel, ['high', 'critical'], true) && ! $approved && ! $dryRun) {
            $decision = 'requires_approval';
            $reasons[] = 'high_risk_tool_requires_approval';
        }

        if ($decision === 'allowed' && $this->tierWeight($executionTier) > $this->tierWeight($maxExecutionTier)) {
            $decision = $required ? 'requires_approval' : 'skipped';
            $reasons[] = 'execution_tier_above_policy_budget';
        }

        return [
            'decision' => $decision,
            'allowed' => $decision === 'allowed',
            'required' => $required,
            'failure_policy' => $policy?->failure_policy ?: $definition->default_failure_policy,
            'timeout_seconds' => $policy?->timeout_seconds ?: $definition->default_timeout_seconds,
            'risk_level' => $riskLevel,
            'execution_tier' => $executionTier,
            'max_execution_tier' => $maxExecutionTier,
            'sandbox_mode' => $sandboxMode,
            'privacy_level' => $privacyLevel,
            'task_type' => $taskType,
            'requires_provider_safe' => $requiresProviderSafe,
            'provider_safe' => $providerSafe,
            'expected_cost' => $definition->expected_cost ?? data_get($definition->metadata, 'expected_cost', 'local_fast'),
            'default_trigger' => $definition->default_trigger ?? data_get($definition->metadata, 'default_trigger', 'manual_or_policy'),
            'authority_role' => $definition->authority_role ?? data_get($definition->metadata, 'authority_role', 'primary'),
            'authority_group' => $definition->authority_group ?? data_get($definition->metadata, 'authority_group'),
            'reasons' => $reasons,
            'dry_run' => $dryRun,
            'policy_source' => $policy ? 'atlas_tool_policies' : 'tool_definition_default',
            'approval_status' => $policy ? $this->approvalStatus($policy) : 'not_configured',
        ];
    }

    private function policyFor(string $toolSlug, array $context): ?AtlasToolPolicy
    {
        if (! DatabaseTableAvailability::has('atlas_tool_policies')) {
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

    private function normalizedTier(string $tier): string
    {
        $tier = strtoupper(trim($tier));

        return in_array($tier, ['T0', 'T1', 'T2', 'T3'], true) ? $tier : 'T1';
    }

    private function tierWeight(string $tier): int
    {
        return match ($this->normalizedTier($tier)) {
            'T0' => 0,
            'T1' => 1,
            'T2' => 2,
            'T3' => 3,
            default => 1,
        };
    }

    /**
     * @param  array<int,string>  $risks
     */
    private function usesNetwork(array $risks): bool
    {
        return in_array('network', $risks, true) || in_array('may_use_network', $risks, true);
    }

    /**
     * @param  array<int,string>  $risks
     */
    private function writesWorkspace(array $risks): bool
    {
        return collect($risks)->intersect([
            'may_write_workspace',
            'writes_workspace',
            'writes_workspace_mounted_state',
            'writes_workspace_via_mcp',
        ])->isNotEmpty();
    }

    /**
     * @param  array<int,string>  $risks
     */
    private function providerSafe(array $risks, string $privacyLevel): bool
    {
        if ($privacyLevel === 'restricted') {
            return false;
        }

        return ! collect($risks)->intersect([
            'secret_sensitive_output',
            'reads_secrets',
            'handles_credentials',
        ])->isNotEmpty();
    }

    private function normalizedSandboxMode(string $mode): string
    {
        $mode = strtolower(trim($mode));

        return in_array($mode, ['workspace', 'worktree', 'docker', 'host', 'none'], true) ? $mode : 'workspace';
    }

    private function normalizedPrivacyLevel(string $level): string
    {
        $level = strtolower(trim($level));

        return in_array($level, ['standard', 'sensitive', 'restricted'], true) ? $level : 'standard';
    }

    private function normalizedTaskType(string $taskType): string
    {
        $taskType = strtolower(trim($taskType));

        return preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $taskType) === 1 ? $taskType : 'manual';
    }
}

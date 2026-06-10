<?php

namespace App\Services\Tools;

use App\Models\AtlasToolPolicy;
use App\Models\AtlasToolRun;
use App\Services\Ai\Support\DatabaseTableAvailability;

class AtlasToolAuthorityPolicyService
{
    /**
     * @return array<string,mixed>
     */
    public function catalog(?string $workspace = null): array
    {
        $workspaceHash = $this->workspaceHash($workspace);
        $policies = collect($this->policies())
            ->map(fn (array $policy): array => $this->publicPolicy($policy, $this->overrideFor((string) $policy['authority_group'], $workspaceHash)))
            ->values()
            ->all();

        return [
            'status' => 'ok',
            'schema' => 'atlas.tool_authority_policies.v1',
            'summary' => [
                'policy_count' => count($policies),
                'blocking_policy_count' => collect($policies)->filter(fn (array $policy): bool => $policy['block_severities'] !== [])->count(),
                'warning_policy_count' => collect($policies)->filter(fn (array $policy): bool => $policy['warn_severities'] !== [])->count(),
            ],
            'policies' => $policies,
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public function setOverride(string $authorityGroup, ?string $workspace, array $options = []): AtlasToolPolicy
    {
        $this->ensureTables();

        $base = $this->policyFor($this->authorityGroupSlug($authorityGroup), null);
        $scopeType = $this->scopeType((string) ($options['scope_type'] ?? 'workspace'));
        $scopeId = $scopeType === 'workspace' ? $this->workspaceHash($workspace) : null;
        $thresholds = [
            'schema' => 'atlas.tool_authority_policy_override.v1',
            'authority_group' => $base['authority_group'],
            'policy' => (string) ($options['policy'] ?? $base['name'].'_override'),
            'block_severities' => $this->severityList($options['block_severities'] ?? $base['block']),
            'warn_severities' => $this->severityList($options['warn_severities'] ?? $base['warn']),
            'block_reason' => $this->reason($options['block_reason'] ?? $this->blockReason((string) $base['authority_group'])),
            'warn_reason' => $this->reason($options['warn_reason'] ?? $this->warnReason((string) $base['authority_group'])),
            'description' => $this->description($options['description'] ?? 'Operator override for authority gate policy.'),
        ];

        return AtlasToolPolicy::query()->updateOrCreate(
            [
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'tool_slug' => $this->policyToolSlug((string) $base['authority_group']),
            ],
            [
                'enabled' => true,
                'failure_policy' => 'authority_gate',
                'thresholds_json' => $thresholds,
                'metadata' => [
                    'policy_kind' => 'authority_gate',
                    'authority_group' => $base['authority_group'],
                    'configured_at' => now()->toJSON(),
                    'configured_by' => (string) ($options['configured_by'] ?? 'atlas_operator'),
                    'source' => (string) ($options['source'] ?? 'operator'),
                ],
            ],
        );
    }

    public function revokeOverride(string $authorityGroup, ?string $workspace, string $scopeType = 'workspace'): ?AtlasToolPolicy
    {
        $this->ensureTables();

        $authorityGroup = $this->authorityGroupSlug($authorityGroup);
        $scopeType = $this->scopeType($scopeType);
        $scopeId = $scopeType === 'workspace' ? $this->workspaceHash($workspace) : null;

        $policy = AtlasToolPolicy::query()
            ->where('scope_type', $scopeType)
            ->where('tool_slug', $this->policyToolSlug($authorityGroup))
            ->when($scopeId === null, fn ($query) => $query->whereNull('scope_id'), fn ($query) => $query->where('scope_id', $scopeId))
            ->first();

        if (! $policy) {
            return null;
        }

        $metadata = (array) $policy->metadata;
        $policy->forceFill([
            'enabled' => false,
            'metadata' => [
                ...$metadata,
                'revoked_at' => now()->toJSON(),
                'revocation_source' => 'operator',
            ],
        ])->save();

        return $policy->refresh();
    }

    /**
     * @return array{decision:string,policy:string,reason:string|null,authority_group:string,severity:string}
     */
    public function evaluateFinding(AtlasToolRun $run, object $finding): array
    {
        $group = $this->authorityGroup($run);
        $severity = $this->severity((string) ($finding->severity ?? 'medium'));
        $policy = $this->policyFor($group, $run->workspace_hash ?: $this->workspaceHash($run->workspace));

        if (in_array($severity, $policy['block'], true)) {
            return [
                'decision' => 'block',
                'policy' => $policy['name'],
                'reason' => $policy['block_reason'] ?? $this->blockReason($group),
                'authority_group' => $group,
                'severity' => $severity,
            ];
        }

        if (in_array($severity, $policy['warn'], true)) {
            return [
                'decision' => 'warn',
                'policy' => $policy['name'],
                'reason' => $policy['warn_reason'] ?? $this->warnReason($group),
                'authority_group' => $group,
                'severity' => $severity,
            ];
        }

        return [
            'decision' => 'pass',
            'policy' => $policy['name'],
            'reason' => null,
            'authority_group' => $group,
            'severity' => $severity,
        ];
    }

    public function authorityGroup(AtlasToolRun $run): string
    {
        return (string) (
            $run->tool?->authority_group
            ?? data_get($run->policy_decision_json, 'authority_group')
            ?? data_get($run->metadata_json, 'authority_group')
            ?? $run->tool?->category
            ?? $run->tool_slug
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function policyFor(string $group, ?string $workspaceHash = null): array
    {
        $base = collect($this->policies())
            ->firstWhere('authority_group', $group)
            ?? [
                'authority_group' => $group,
                'name' => 'default_high_blocks',
                'block' => ['critical', 'high'],
                'warn' => [],
                'block_reason' => 'blocking_finding',
                'warn_reason' => 'authority_finding_warning',
                'description' => 'Default policy: critical/high findings block.',
            ];

        return $this->applyOverride($base, $this->overrideFor($group, $workspaceHash));
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    private function publicPolicy(array $policy, ?AtlasToolPolicy $override = null): array
    {
        $effective = $this->applyOverride($policy, $override);

        return [
            'authority_group' => $effective['authority_group'],
            'policy' => $effective['name'],
            'block_severities' => $effective['block'],
            'warn_severities' => $effective['warn'],
            'block_reason' => $effective['block_reason'] ?? $this->blockReason((string) $effective['authority_group']),
            'warn_reason' => $effective['warn_reason'] ?? $this->warnReason((string) $effective['authority_group']),
            'description' => $effective['description'],
            'source' => $override ? (string) $override->scope_type : 'default',
            'policy_id' => $override?->id,
        ];
    }

    /**
     * @param  array<string,mixed>  $base
     * @return array<string,mixed>
     */
    private function applyOverride(array $base, ?AtlasToolPolicy $override): array
    {
        if (! $override || ! $override->enabled) {
            return [
                ...$base,
                'block_reason' => $base['block_reason'] ?? $this->blockReason((string) $base['authority_group']),
                'warn_reason' => $base['warn_reason'] ?? $this->warnReason((string) $base['authority_group']),
            ];
        }

        $thresholds = (array) $override->thresholds_json;

        return [
            ...$base,
            'name' => (string) ($thresholds['policy'] ?? $base['name']),
            'block' => $this->severityList($thresholds['block_severities'] ?? $base['block']),
            'warn' => $this->severityList($thresholds['warn_severities'] ?? $base['warn']),
            'block_reason' => $this->reason($thresholds['block_reason'] ?? $this->blockReason((string) $base['authority_group'])),
            'warn_reason' => $this->reason($thresholds['warn_reason'] ?? $this->warnReason((string) $base['authority_group'])),
            'description' => $this->description($thresholds['description'] ?? $base['description']),
        ];
    }

    private function overrideFor(string $group, ?string $workspaceHash): ?AtlasToolPolicy
    {
        if (! DatabaseTableAvailability::has('atlas_tool_policies')) {
            return null;
        }

        return AtlasToolPolicy::query()
            ->where('tool_slug', $this->policyToolSlug($group))
            ->where('enabled', true)
            ->where(function ($query) use ($workspaceHash): void {
                if ($workspaceHash) {
                    $query->where(function ($query) use ($workspaceHash): void {
                        $query->where('scope_type', 'workspace')->where('scope_id', $workspaceHash);
                    })->orWhere(function ($query): void {
                        $query->where('scope_type', 'global')->whereNull('scope_id');
                    });
                } else {
                    $query->where('scope_type', 'global')->whereNull('scope_id');
                }
            })
            ->orderByRaw("CASE WHEN scope_type = 'workspace' THEN 0 ELSE 1 END")
            ->latest('updated_at')
            ->first();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function policies(): array
    {
        return [
            [
                'authority_group' => 'secret_scan',
                'name' => 'secret_scan_any_confirmed_blocks',
                'block' => ['critical', 'high', 'medium', 'low'],
                'warn' => ['info'],
                'description' => 'Any confirmed secret-like finding blocks; informational detections warn.',
            ],
            ...collect(['semantic_sast', 'dependency_vulnerability', 'vulnerability_scan', 'iac_security'])
                ->map(fn (string $group): array => [
                    'authority_group' => $group,
                    'name' => $group.'_critical_high_blocks_medium_warns',
                    'block' => ['critical', 'high'],
                    'warn' => ['medium'],
                    'description' => 'Critical/high findings block; medium findings warn for triage.',
                ])
                ->all(),
            [
                'authority_group' => 'sbom',
                'name' => 'sbom_findings_warn_by_default',
                'block' => ['critical'],
                'warn' => ['high', 'medium', 'low'],
                'description' => 'SBOM presence is handled by release requirements; critical SBOM findings block and lower severities warn.',
            ],
            ...collect([
                'container_quality',
                'container_lint',
                'php_static_analysis',
                'ts_js_type_lint',
                'formatter',
                'api_contract',
                'visual_regression',
                'accessibility',
                'php_architecture',
                'ts_js_architecture',
            ])->map(fn (string $group): array => [
                'authority_group' => $group,
                'name' => $group.'_high_blocks_medium_warns',
                'block' => ['critical', 'high'],
                'warn' => ['medium'],
                'description' => 'Critical/high findings block; medium findings warn.',
            ])->all(),
        ];
    }

    private function severity(string $severity): string
    {
        $severity = strtolower(trim($severity));

        return in_array($severity, ['critical', 'high', 'medium', 'low', 'info'], true) ? $severity : 'medium';
    }

    /**
     * @return array<int,string>
     */
    private function severityList(mixed $severities): array
    {
        $values = is_array($severities) ? $severities : explode(',', (string) $severities);

        return collect($values)
            ->map(fn (mixed $severity): string => $this->severity((string) $severity))
            ->unique()
            ->values()
            ->all();
    }

    private function authorityGroupSlug(string $group): string
    {
        $group = strtolower(trim($group));

        return preg_match('/^[a-z][a-z0-9_:-]{0,79}$/', $group) === 1 ? $group : 'unknown';
    }

    private function policyToolSlug(string $group): string
    {
        return 'authority:'.$this->authorityGroupSlug($group);
    }

    private function workspaceHash(?string $workspace): ?string
    {
        if (! is_string($workspace) || trim($workspace) === '') {
            return null;
        }

        return hash('sha256', realpath($workspace) ?: $workspace);
    }

    private function scopeType(string $scopeType): string
    {
        return in_array($scopeType, ['workspace', 'global'], true) ? $scopeType : 'workspace';
    }

    private function reason(mixed $reason): string
    {
        $reason = strtolower(trim((string) $reason));

        return preg_match('/^[a-z][a-z0-9_:-]{0,119}$/', $reason) === 1 ? $reason : 'authority_finding_warning';
    }

    private function description(mixed $description): string
    {
        return str($description)->trim()->limit(300, '')->toString() ?: 'Authority gate policy.';
    }

    private function ensureTables(): void
    {
        if (! DatabaseTableAvailability::has('atlas_tool_policies')) {
            throw new \RuntimeException('Tool runtime tables are not migrated.');
        }
    }

    private function blockReason(string $group): string
    {
        return match ($group) {
            'secret_scan' => 'authority_secret_scan_finding',
            'semantic_sast' => 'authority_sast_high_finding',
            'dependency_vulnerability' => 'authority_dependency_vulnerability',
            'vulnerability_scan' => 'authority_vulnerability_scan',
            'iac_security' => 'authority_iac_security',
            'sbom' => 'authority_sbom_critical_finding',
            'container_quality', 'container_lint' => 'authority_container_lint',
            'formatter' => 'authority_format_required',
            'php_static_analysis', 'ts_js_type_lint' => 'authority_type_or_static_analysis',
            'api_contract' => 'authority_api_contract',
            'visual_regression' => 'authority_visual_regression',
            'accessibility' => 'authority_accessibility',
            'php_architecture', 'ts_js_architecture' => 'authority_architecture_boundary',
            default => 'blocking_finding',
        };
    }

    private function warnReason(string $group): string
    {
        return match ($group) {
            'semantic_sast' => 'authority_sast_medium_finding',
            'dependency_vulnerability' => 'authority_dependency_vulnerability_medium',
            'vulnerability_scan' => 'authority_vulnerability_scan_medium',
            'iac_security' => 'authority_iac_security_medium',
            'sbom' => 'authority_sbom_advisory_finding',
            'secret_scan' => 'authority_secret_scan_info_finding',
            default => 'authority_finding_warning',
        };
    }
}

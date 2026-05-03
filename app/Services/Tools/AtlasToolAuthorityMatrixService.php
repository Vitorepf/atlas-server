<?php

namespace App\Services\Tools;

use App\Models\AtlasToolDefinition;
use Illuminate\Support\Collection;

class AtlasToolAuthorityMatrixService
{
    public function __construct(private readonly AtlasToolRegistryService $registry) {}

    /**
     * @return array<string,mixed>
     */
    public function matrix(): array
    {
        $definitions = $this->registry->definitions()
            ->map(fn (AtlasToolDefinition $tool): array => $this->toolPayload($tool))
            ->values();

        $authorityGroups = $definitions
            ->groupBy(fn (array $tool): string => (string) ($tool['authority_group'] ?: $tool['category']))
            ->map(fn (Collection $tools, string $group): array => $this->authorityGroupPayload($group, $tools))
            ->sortKeys()
            ->values();

        $tiers = collect(['T0', 'T1', 'T2', 'T3'])
            ->mapWithKeys(fn (string $tier): array => [
                $tier => [
                    'tool_count' => $definitions->where('execution_tier', $tier)->count(),
                    'tools' => $definitions
                        ->where('execution_tier', $tier)
                        ->map(fn (array $tool): array => $this->compactToolPayload($tool))
                        ->sortBy('slug')
                        ->values()
                        ->all(),
                ],
            ])
            ->all();

        return [
            'status' => 'ok',
            'generated_at' => now()->toISOString(),
            'summary' => $this->summary($definitions, $authorityGroups),
            'tiers' => $tiers,
            'authority_groups' => $authorityGroups->all(),
            'recommendations' => $this->recommendations($authorityGroups),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function toolPayload(AtlasToolDefinition $tool): array
    {
        return [
            'slug' => $tool->slug,
            'name' => $tool->name,
            'type' => $tool->type,
            'category' => $tool->category,
            'risk_level' => $tool->risk_level,
            'cost_posture' => $tool->cost_posture,
            'execution_tier' => $this->executionTier($tool),
            'expected_cost' => (string) ($tool->expected_cost ?? data_get($tool->metadata, 'expected_cost', 'local_fast')),
            'default_trigger' => (string) ($tool->default_trigger ?? data_get($tool->metadata, 'default_trigger', 'manual_or_policy')),
            'authority_role' => $this->authorityRole($tool),
            'authority_group' => (string) ($tool->authority_group ?? data_get($tool->metadata, 'authority_group', $tool->category)),
            'status' => $tool->status,
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $tools
     * @return array<string,mixed>
     */
    private function authorityGroupPayload(string $group, Collection $tools): array
    {
        $sorted = $tools->sortBy([['execution_tier', 'asc'], ['authority_role', 'asc'], ['slug', 'asc']])->values();
        $primary = $sorted->filter(fn (array $tool): bool => $this->isPrimaryRole((string) $tool['authority_role']))->values();
        $complementary = $sorted->filter(fn (array $tool): bool => $this->isComplementaryRole((string) $tool['authority_role']))->values();
        $fallback = $sorted->where('authority_role', 'fallback')->values();
        $executors = $sorted->where('authority_role', 'executor')->values();
        $tierValues = $sorted->pluck('execution_tier')->unique()->sort()->values()->all();

        return [
            'authority_group' => $group,
            'tool_count' => $sorted->count(),
            'tier_span' => $tierValues,
            'primary_tools' => $primary->map(fn (array $tool): array => $this->compactToolPayload($tool))->all(),
            'complementary_tools' => $complementary->map(fn (array $tool): array => $this->compactToolPayload($tool))->all(),
            'fallback_tools' => $fallback->map(fn (array $tool): array => $this->compactToolPayload($tool))->all(),
            'executor_tools' => $executors->map(fn (array $tool): array => $this->compactToolPayload($tool))->all(),
            'high_risk_tools' => $sorted
                ->where('risk_level', 'high')
                ->map(fn (array $tool): array => $this->compactToolPayload($tool))
                ->values()
                ->all(),
            'release_heavy_tools' => $sorted
                ->where('expected_cost', 'release_heavy')
                ->map(fn (array $tool): array => $this->compactToolPayload($tool))
                ->values()
                ->all(),
            'missing_primary' => $primary->isEmpty() && $executors->isEmpty(),
            'duplicate_primary' => $primary->count() > 1,
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $definitions
     * @param  Collection<int,array<string,mixed>>  $authorityGroups
     * @return array<string,int>
     */
    private function summary(Collection $definitions, Collection $authorityGroups): array
    {
        return [
            'tool_count' => $definitions->count(),
            'authority_group_count' => $authorityGroups->count(),
            'primary_count' => $definitions->filter(fn (array $tool): bool => $this->isPrimaryRole((string) $tool['authority_role']))->count(),
            'complementary_count' => $definitions->filter(fn (array $tool): bool => $this->isComplementaryRole((string) $tool['authority_role']))->count(),
            'fallback_count' => $definitions->where('authority_role', 'fallback')->count(),
            'executor_count' => $definitions->where('authority_role', 'executor')->count(),
            'high_risk_count' => $definitions->where('risk_level', 'high')->count(),
            't0_count' => $definitions->where('execution_tier', 'T0')->count(),
            't1_count' => $definitions->where('execution_tier', 'T1')->count(),
            't2_count' => $definitions->where('execution_tier', 'T2')->count(),
            't3_count' => $definitions->where('execution_tier', 'T3')->count(),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $authorityGroups
     * @return array<int,array<string,mixed>>
     */
    private function recommendations(Collection $authorityGroups): array
    {
        return $authorityGroups
            ->flatMap(function (array $group): array {
                $recommendations = [];

                if ((bool) $group['missing_primary']) {
                    $recommendations[] = [
                        'code' => 'authority_group_missing_primary',
                        'severity' => 'medium',
                        'authority_group' => $group['authority_group'],
                        'message' => 'Define one primary tool so gates can suppress duplicate complementary findings deterministically.',
                    ];
                }

                if ((bool) $group['duplicate_primary']) {
                    $recommendations[] = [
                        'code' => 'authority_group_duplicate_primary',
                        'severity' => 'low',
                        'authority_group' => $group['authority_group'],
                        'message' => 'Multiple primary-like tools exist; keep this only when the group intentionally supports co-authority.',
                    ];
                }

                if ($group['authority_group'] === 'external_coding_agent' && $group['executor_tools'] !== []) {
                    $recommendations[] = [
                        'code' => 'external_agents_require_policy_boundary',
                        'severity' => 'high',
                        'authority_group' => $group['authority_group'],
                        'message' => 'External coding agents must remain executors behind Atlas indexing, policy, evidence and approval gates.',
                    ];
                }

                if ($group['release_heavy_tools'] !== [] && $group['executor_tools'] === [] && ! $this->hasFastCompanion($group)) {
                    $recommendations[] = [
                        'code' => 'release_heavy_group_without_fast_companion',
                        'severity' => 'medium',
                        'authority_group' => $group['authority_group'],
                        'message' => 'Add or designate a T0/T1/T2 companion before relying on release-heavy tools as the first feedback loop.',
                    ];
                }

                return $recommendations;
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $tool
     * @return array<string,mixed>
     */
    private function compactToolPayload(array $tool): array
    {
        return [
            'slug' => $tool['slug'],
            'name' => $tool['name'],
            'execution_tier' => $tool['execution_tier'],
            'expected_cost' => $tool['expected_cost'],
            'default_trigger' => $tool['default_trigger'],
            'authority_role' => $tool['authority_role'],
            'risk_level' => $tool['risk_level'],
            'status' => $tool['status'],
        ];
    }

    private function executionTier(AtlasToolDefinition $tool): string
    {
        $tier = strtoupper((string) ($tool->execution_tier ?? data_get($tool->metadata, 'execution_tier', 'T1')));

        return in_array($tier, ['T0', 'T1', 'T2', 'T3'], true) ? $tier : 'T1';
    }

    private function authorityRole(AtlasToolDefinition $tool): string
    {
        $role = (string) ($tool->authority_role ?? data_get($tool->metadata, 'authority_role', 'primary'));

        return $role !== '' ? $role : 'primary';
    }

    private function isPrimaryRole(string $role): bool
    {
        return in_array($role, ['primary', 'primary_or_complementary'], true);
    }

    private function isComplementaryRole(string $role): bool
    {
        return in_array($role, ['complementary', 'primary_or_complementary'], true);
    }

    /**
     * @param  array<string,mixed>  $group
     */
    private function hasFastCompanion(array $group): bool
    {
        return collect([
            ...$group['primary_tools'],
            ...$group['complementary_tools'],
            ...$group['fallback_tools'],
        ])->contains(fn (array $tool): bool => in_array($tool['execution_tier'], ['T0', 'T1', 'T2'], true));
    }
}

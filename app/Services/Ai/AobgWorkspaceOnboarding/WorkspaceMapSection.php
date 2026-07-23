<?php

declare(strict_types=1);

namespace App\Services\Ai\AobgWorkspaceOnboarding;

use App\Services\Ai\AtlasAobgWorkspaceOnboardingService as Facade;
use App\Services\Ai\Instrumentation\AtlasProviderProjectionService;
use Throwable;

/**
 * Map/fleet presentation family for the AOBG workspace-onboarding façade: fleet rows,
 * workspace readiness, quality scoring, next-action command hints and the provider-
 * projection status probe. Depends only on the leaf support + the provider-projection
 * service; never on another section.
 */
class WorkspaceMapSection
{
    public function __construct(
        private readonly AtlasProviderProjectionService $providerProjection,
        private readonly AobgWorkspaceOnboardingSupport $support,
    ) {}

    /**
     * @param  array<string,mixed>  $profile
     * @return array<string,mixed>
     */
    public function missingWorkspaceFleetRow(array $profile, ?string $workspacePath): array
    {
        $slug = $this->support->stringFromArray($profile, ['slug']);

        return [
            'workspace_id' => $slug,
            'profile_slug' => $slug,
            'name' => $this->support->stringFromArray($profile, ['name']),
            'kind' => $this->support->stringFromArray($profile, ['kind']),
            'workspace_path' => $workspacePath,
            'path_exists' => false,
            'readiness_status' => 'blocked',
            'safe_for_initial_context' => false,
            'safe_for_implementation' => false,
            'readiness_blockers' => ['workspace_path_missing'],
            'readiness_warnings' => [],
            'indexed' => false,
            'needs_onboarding' => true,
            'needs_reindex' => false,
            'freshness_status' => 'unknown',
            'symbol_count' => 0,
            'module_count' => 0,
            'file_count' => 0,
            'doc_link_count' => 0,
            'route_count' => 0,
            'command_count' => 0,
            'migration_count' => 0,
            'test_count' => 0,
            'provider_projection_status' => 'unknown',
            'quality_label' => 'blocked',
            'next_actions' => [
                'Fix the workspace profile path, then run '.base_path('bin/atlas').' aobg workspace activate-all --json',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $map
     * @param  array<string,mixed>  $profile
     * @return array<string,mixed>
     */
    public function workspaceFleetRow(array $map, array $profile): array
    {
        $inventory = (array) ($map['inventory'] ?? []);

        return [
            'workspace_id' => $map['workspace_id'] ?? $this->support->stringFromArray($profile, ['slug']),
            'profile_slug' => $this->support->stringFromArray($profile, ['slug']),
            'name' => $this->support->stringFromArray($profile, ['name']),
            'kind' => $this->support->stringFromArray($profile, ['kind']),
            'workspace_path' => $map['workspace_path'] ?? $this->support->profilePath($profile),
            'path_exists' => true,
            'readiness_status' => (string) ($map['readiness_status'] ?? 'blocked'),
            'safe_for_initial_context' => (bool) ($map['safe_for_initial_context'] ?? false),
            'safe_for_implementation' => (bool) ($map['safe_for_implementation'] ?? false),
            'readiness_blockers' => array_values((array) ($map['readiness_blockers'] ?? [])),
            'readiness_warnings' => array_values((array) ($map['readiness_warnings'] ?? [])),
            'indexed' => (bool) ($map['indexed'] ?? false),
            'needs_onboarding' => (bool) ($map['needs_onboarding'] ?? true),
            'needs_reindex' => (bool) ($map['needs_reindex'] ?? false),
            'freshness_status' => (string) ($map['freshness_status'] ?? 'unknown'),
            'freshness_reason' => (string) data_get($map, 'freshness.reason', ''),
            'freshness_checked_files' => (int) data_get($map, 'freshness.checked_files', 0),
            'freshness_changed_files' => array_values((array) data_get($map, 'freshness.changed_files', [])),
            'freshness_missing_files' => array_values((array) data_get($map, 'freshness.missing_files', [])),
            'last_index' => $map['last_index'] ?? null,
            'symbol_count' => (int) ($inventory['symbol_count'] ?? 0),
            'module_count' => (int) ($inventory['module_count'] ?? 0),
            'file_count' => (int) ($inventory['file_count'] ?? 0),
            'doc_link_count' => (int) ($inventory['doc_link_count'] ?? 0),
            'route_count' => (int) ($inventory['route_count'] ?? 0),
            'command_count' => (int) ($inventory['command_count'] ?? 0),
            'migration_count' => (int) ($inventory['migration_count'] ?? 0),
            'test_count' => (int) ($inventory['test_count'] ?? 0),
            'provider_projection_status' => (string) data_get($map, 'provider_projection.status', 'unknown'),
            'quality_label' => (string) data_get($map, 'quality.label', 'unknown'),
            'next_actions' => array_slice(array_values((array) ($map['next_actions'] ?? [])), 0, 4),
        ];
    }

    /**
     * @param  array<string,int>  $summary
     * @param  array<int,string>  $blockers
     * @param  array<int,string>  $warnings
     * @return array<int,string>
     */
    public function fleetNextActions(array $summary, array $blockers, array $warnings, int $limit): array
    {
        $actions = [];
        if (($summary['total_profiles'] ?? 0) === 0) {
            $actions[] = 'atlas workspace-intelligence register --workspace=<slug> --path=<path> --json';
        }
        if (in_array('workspace_path_missing', $blockers, true)) {
            $actions[] = 'atlas workspace-intelligence list --json';
        }
        if (($summary['needs_onboarding'] ?? 0) > 0 || ($summary['needs_reindex'] ?? 0) > 0 || $warnings !== []) {
            $actions[] = base_path('bin/atlas').' aobg workspace activate-all --json';
        }

        $actions[] = 'atlas aobg workspace map-all --detail=summary --limit='.$limit.' --json';
        $actions[] = 'atlas aobg workspace map --workspace=<workspace> --detail=samples --limit='.$limit.' --json';

        return array_values(array_unique($actions));
    }

    /**
     * @return array<string,mixed>
     */
    public function providerProjectionStatus(?string $workspacePath): array
    {
        if (! is_string($workspacePath) || $workspacePath === '') {
            return ['status' => 'unknown', 'reason' => 'workspace_path_missing'];
        }

        try {
            $status = $this->providerProjection->status('all', ['workspace' => $workspacePath], ['workspace' => $workspacePath]);

            return [
                'status' => $status['status'] ?? 'unknown',
                'ready' => (int) data_get($status, 'summary.ready', 0),
                'total' => (int) data_get($status, 'summary.total', 0),
                'manual_drift' => (int) data_get($status, 'summary.manual_drift', 0),
                'stale' => (int) data_get($status, 'summary.stale', 0),
                'unmanaged' => (int) data_get($status, 'summary.unmanaged', 0),
            ];
        } catch (Throwable $e) {
            return ['status' => 'unknown', 'exception' => class_basename($e)];
        }
    }

    /**
     * @param  array<string,mixed>  $status
     * @param  array<string,mixed>  $inventory
     * @return array<string,mixed>
     */
    public function mapQuality(array $status, array $inventory, ?string $workspacePath): array
    {
        $score = 0.0;
        $score += (bool) ($status['indexed'] ?? false) ? 0.35 : 0.0;
        $score += (int) ($inventory['symbol_count'] ?? 0) > 0 ? 0.2 : 0.0;
        $score += (int) ($inventory['module_count'] ?? 0) > 0 ? 0.15 : 0.0;
        $score += (int) ($inventory['file_count'] ?? 0) > 0 || (int) ($inventory['symbol_count'] ?? 0) > 0 ? 0.1 : 0.0;
        $score += (int) ($inventory['doc_link_count'] ?? 0) > 0 ? 0.1 : 0.0;
        $score += is_string($workspacePath) && is_dir($workspacePath) ? 0.1 : 0.0;
        if (($status['needs_reindex'] ?? false) === true) {
            $score = min($score, 0.59);
        }

        return [
            'score' => round(min(1.0, $score), 2),
            'label' => $score >= 0.85 ? 'strong' : ($score >= 0.6 ? 'usable' : 'thin'),
            'freshness_status' => $status['freshness_status'] ?? 'unknown',
            'needs_reindex' => (bool) ($status['needs_reindex'] ?? false),
            'note' => 'Score mede prontidao do mapa local; zero routes/migrations pode ser normal para apps sem backend Laravel.',
        ];
    }

    /**
     * @param  array<string,mixed>  $status
     * @param  array<string,mixed>  $inventory
     * @param  array<string,mixed>  $providerProjection
     * @return array<string,mixed>
     */
    public function workspaceReadiness(array $status, array $inventory, array $providerProjection): array
    {
        $blockers = [];
        $warnings = [];

        if ((bool) ($status['needs_onboarding'] ?? true)) {
            $blockers[] = 'workspace_not_indexed';
        }
        if ((bool) ($status['needs_reindex'] ?? false)) {
            $warnings[] = 'workspace_index_stale';
        }
        if ((int) ($inventory['symbol_count'] ?? 0) <= 0) {
            $blockers[] = 'code_symbols_empty';
        }
        if ((int) ($providerProjection['manual_drift'] ?? 0) > 0) {
            $warnings[] = 'provider_projection_manual_drift';
        }
        if ((int) ($providerProjection['stale'] ?? 0) > 0) {
            $warnings[] = 'provider_projection_stale';
        }
        if ((int) ($providerProjection['unmanaged'] ?? 0) > 0) {
            $warnings[] = 'provider_projection_unmanaged';
        }

        $readiness = $blockers !== []
            ? 'blocked'
            : ($warnings !== [] ? 'limited' : 'ready');

        return [
            'schema' => 'atlas.aobg.workspace_readiness.v1',
            'status' => $readiness,
            'safe_for_initial_context' => $blockers === [],
            'safe_for_implementation' => $readiness === 'ready',
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'required_before_implementation' => $readiness === 'ready' ? [] : array_values(array_filter([
                in_array('workspace_not_indexed', $blockers, true) || in_array('code_symbols_empty', $blockers, true)
                    ? (string) ($status['activation_command'] ?? '')
                    : null,
                in_array('workspace_index_stale', $warnings, true)
                    ? (string) ($status['onboard_command'] ?? '')
                    : null,
                ((int) ($providerProjection['stale'] ?? 0) > 0 || (int) ($providerProjection['manual_drift'] ?? 0) > 0)
                    ? 'php artisan atlas:memory:projection write --target=all --workspace='.(string) ($status['workspace_path'] ?? $status['workspace_id'] ?? '<workspace>').' --yes --json'
                    : null,
            ])),
            'policy' => [
                'read_only' => true,
                'provider_safe' => true,
                'raw_file_content_read' => false,
                'raw_diff_returned' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $status
     * @param  array<string,mixed>  $readiness
     * @return array<int,string>
     */
    public function workspaceNextActions(array $status, array $readiness, string $workspaceRef, int $limit): array
    {
        $actions = [];
        if ((bool) ($status['needs_onboarding'] ?? true)) {
            $actions[] = (string) ($status['activation_command'] ?? '');
        } elseif ((bool) ($status['needs_reindex'] ?? false)) {
            $actions[] = (string) ($status['onboard_command'] ?? '');
        }

        foreach ((array) ($readiness['required_before_implementation'] ?? []) as $required) {
            if (is_string($required) && $required !== '') {
                $actions[] = $required;
            }
        }

        $actions[] = 'atlas open-brain context "<task>" --workspace='.$this->commandWorkspaceArg($workspaceRef).' --json';
        $actions[] = $this->workspaceMapCommand($workspaceRef, $limit, Facade::MAP_DETAIL_SAMPLES);

        return array_values(array_unique(array_filter($actions, static fn (string $action): bool => trim($action) !== '')));
    }

    public function workspaceMapCommand(string $workspaceRef, int $limit, string $detail): string
    {
        return 'atlas aobg workspace map --workspace='.$this->commandWorkspaceArg($workspaceRef)
            .' --detail='.$detail
            .' --limit='.$limit
            .' --json';
    }

    private function commandWorkspaceArg(string $workspaceRef): string
    {
        return preg_match('/\s/', $workspaceRef) === 1
            ? '"'.str_replace('"', '\\"', $workspaceRef).'"'
            : $workspaceRef;
    }
}

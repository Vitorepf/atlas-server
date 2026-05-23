<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

use App\Models\AtlasWorkspaceProfile;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Atlas Code · Project/Workspace Profile read-model.
 *
 * See:
 *   - docs/engineering-knowledge-base/atlas-code-multi-project-workspace-os.md
 *   - docs/engineering-knowledge-base/atlas-code-programming-obras-operating-system.md
 *
 * Atlas Code não é Atlas-only. Cada produto que o Atlas opera (Atlas, Blackink,
 * etc.) é um Project/Workspace; Obras vivem dentro de um Project. Este service
 * é a primeira camada read-model, lendo de `config/atlas_projects.php`. Quando
 * houver UI de edição/persistência (DB), basta estender este service para
 * compor config + tabela própria.
 *
 * Princípios:
 *   - Sem mock data: workspace_path inexistente é reportado honestamente como
 *     `workspace_path_exists = false`. Nada de inventar diretórios.
 *   - Atlas é o profile default quando nada está selecionado.
 *   - O contrato exposto na API é estável: schema_version `atlas.code.workspace_profile.v1`.
 *   - Service nunca executa comando; só descreve o perfil para a UI/CLI.
 */
final class AtlasCodeWorkspaceProfileService
{
    public const SCHEMA_VERSION = 'atlas.code.workspace_profile.v1';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listProfiles(): array
    {
        $profiles = (array) config('atlas_projects.profiles', []);
        $shapedBySlug = [];
        foreach ($profiles as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $profile = $this->shape($raw);
            if ($profile['slug'] !== '') {
                $shapedBySlug[$profile['slug']] = $profile;
            }
        }

        foreach ($this->persistedProfiles() as $raw) {
            $profile = $this->shape($raw);
            if ($profile['slug'] !== '') {
                $shapedBySlug[$profile['slug']] = $profile;
            }
        }

        return array_values($shapedBySlug);
    }

    public function findBySlug(string $slug): ?array
    {
        $needle = trim(strtolower($slug));
        if ($needle === '') {
            return null;
        }
        foreach ($this->listProfiles() as $profile) {
            if ($profile['slug'] === $needle) {
                return $profile;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByPath(string $path): ?array
    {
        $needle = $this->normalizePath($path);
        if ($needle === null) {
            return null;
        }

        foreach ($this->listProfiles() as $profile) {
            foreach (['workspace_path', 'repo_root'] as $key) {
                $candidate = $this->normalizePath((string) ($profile[$key] ?? ''));
                if ($candidate !== null && $candidate === $needle) {
                    return $profile;
                }
            }
        }

        return null;
    }

    public function defaultSlug(): string
    {
        $configured = (string) config('atlas_projects.default_slug', 'atlas');
        $configured = trim(strtolower($configured));
        if ($configured === '') {
            return 'atlas';
        }
        if ($this->findBySlug($configured) === null) {
            $profiles = $this->listProfiles();

            return $profiles[0]['slug'] ?? 'atlas';
        }

        return $configured;
    }

    public function resolveActiveSlug(?string $requested): ?string
    {
        if ($requested !== null && $requested !== '') {
            $profile = $this->findBySlug($requested);
            if ($profile !== null) {
                return $profile['slug'];
            }
        }

        return $this->defaultSlug();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function upsertPersistedProfile(array $attributes): array
    {
        if (! Schema::hasTable('atlas_workspace_profiles')) {
            throw new InvalidArgumentException('atlas_workspace_profiles_table_missing');
        }

        $slug = trim(strtolower((string) ($attributes['slug'] ?? '')));
        if ($slug === '' || ! preg_match('/^[a-z0-9][a-z0-9-]{1,118}[a-z0-9]$/', $slug)) {
            throw new InvalidArgumentException('invalid_workspace_slug');
        }

        $payload = [
            'name' => $this->requiredString($attributes, 'name', $slug),
            'kind' => $this->optionalString($attributes, 'kind', 'product'),
            'workspace_path' => $this->optionalString($attributes, 'workspace_path', ''),
            'repo_root' => $this->optionalString($attributes, 'repo_root', (string) ($attributes['workspace_path'] ?? '')),
            'production_status' => $this->optionalString($attributes, 'production_status', 'development'),
            'stack_summary' => $this->optionalString($attributes, 'stack_summary', ''),
            'commands' => $this->stringMap($attributes['commands'] ?? []),
            'test_commands' => $this->stringList($attributes['test_commands'] ?? []),
            'build_commands' => $this->stringList($attributes['build_commands'] ?? []),
            'dev_server_command' => $this->optionalString($attributes, 'dev_server_command', null),
            'critical_areas' => $this->stringList($attributes['critical_areas'] ?? []),
            'docs_status' => $this->optionalString($attributes, 'docs_status', 'unknown'),
            'default_risk' => $this->optionalString($attributes, 'default_risk', 'medium'),
            'deployment_notes' => $this->optionalString($attributes, 'deployment_notes', ''),
            'surfaces_enabled' => $this->stringList($attributes['surfaces_enabled'] ?? ['atlas_ai', 'cartografia', 'code', 'atencao']),
            'source' => $this->optionalString($attributes, 'source', 'operator'),
            'status' => $this->optionalString($attributes, 'status', 'active'),
        ];

        AtlasWorkspaceProfile::query()->updateOrCreate(['slug' => $slug], $payload);

        return $this->findBySlug($slug) ?? $this->shape(array_merge(['slug' => $slug], $payload));
    }

    /**
     * @return array<string, mixed>
     */
    public function archivePersistedProfile(string $slug): array
    {
        if (! Schema::hasTable('atlas_workspace_profiles')) {
            throw new InvalidArgumentException('atlas_workspace_profiles_table_missing');
        }

        $needle = trim(strtolower($slug));
        if ($needle === '') {
            throw new InvalidArgumentException('invalid_workspace_slug');
        }

        $profile = AtlasWorkspaceProfile::query()->where('slug', $needle)->first();
        if (! $profile instanceof AtlasWorkspaceProfile) {
            throw new InvalidArgumentException('workspace_profile_not_persisted');
        }

        $profile->forceFill(['status' => 'archived'])->save();

        return $this->shape([
            'id' => (string) $profile->id,
            'slug' => (string) $profile->slug,
            'name' => (string) $profile->name,
            'kind' => (string) $profile->kind,
            'workspace_path' => (string) ($profile->workspace_path ?? ''),
            'repo_root' => (string) ($profile->repo_root ?? $profile->workspace_path ?? ''),
            'production_status' => (string) $profile->production_status,
            'stack_summary' => (string) ($profile->stack_summary ?? ''),
            'commands' => $profile->commands ?? [],
            'test_commands' => $profile->test_commands ?? [],
            'build_commands' => $profile->build_commands ?? [],
            'dev_server_command' => $profile->dev_server_command,
            'critical_areas' => $profile->critical_areas ?? [],
            'docs_status' => (string) $profile->docs_status,
            'default_risk' => (string) $profile->default_risk,
            'deployment_notes' => (string) ($profile->deployment_notes ?? ''),
            'surfaces_enabled' => $profile->surfaces_enabled ?? ['atlas_ai', 'cartografia', 'code', 'atencao'],
            'source' => (string) $profile->source,
            'status' => 'archived',
        ]);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function shape(array $raw): array
    {
        $slug = trim(strtolower((string) ($raw['slug'] ?? $raw['id'] ?? '')));
        $workspacePath = (string) ($raw['workspace_path'] ?? '');
        $repoRoot = (string) ($raw['repo_root'] ?? $workspacePath);
        $productionStatus = (string) ($raw['production_status'] ?? 'development');
        $defaultRisk = (string) ($raw['default_risk'] ?? 'medium');

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'id' => (string) ($raw['id'] ?? $slug),
            'slug' => $slug,
            'name' => (string) ($raw['name'] ?? $slug),
            'kind' => (string) ($raw['kind'] ?? 'product'),
            'workspace_path' => $workspacePath,
            'workspace_path_exists' => $workspacePath !== '' && @is_dir($workspacePath),
            'repo_root' => $repoRoot,
            'production_status' => $productionStatus,
            'stack_summary' => (string) ($raw['stack_summary'] ?? ''),
            'commands' => $this->stringMap($raw['commands'] ?? []),
            'test_commands' => $this->stringList($raw['test_commands'] ?? []),
            'build_commands' => $this->stringList($raw['build_commands'] ?? []),
            'dev_server_command' => isset($raw['dev_server_command']) && $raw['dev_server_command'] !== ''
                ? (string) $raw['dev_server_command']
                : null,
            'critical_areas' => $this->stringList($raw['critical_areas'] ?? []),
            'docs_status' => (string) ($raw['docs_status'] ?? 'unknown'),
            'default_risk' => $defaultRisk,
            'deployment_notes' => (string) ($raw['deployment_notes'] ?? ''),
            // Surfaces habilitadas para este Projeto. Default = todas, para
            // preservar retro-compatibilidade quando o profile não declara.
            'surfaces_enabled' => $this->stringList($raw['surfaces_enabled'] ?? ['atlas_ai', 'cartografia', 'code', 'atencao']),
            'source' => (string) ($raw['source'] ?? 'config'),
            'status' => (string) ($raw['status'] ?? 'active'),
            'safety' => [
                'execution_allowed' => $workspacePath !== '' && @is_dir($workspacePath),
                'execution_blocked_reason' => ($workspacePath !== '' && @is_dir($workspacePath))
                    ? null
                    : 'workspace_path ausente ou não acessível; apenas Consulta/Descoberta liberada.',
                'risk_floor' => $productionStatus === 'production' ? 'high' : $defaultRisk,
                'requires_explicit_intervention_review' => $productionStatus === 'production',
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function persistedProfiles(): array
    {
        if (! Schema::hasTable('atlas_workspace_profiles')) {
            return [];
        }

        return AtlasWorkspaceProfile::query()
            ->where('status', '!=', 'archived')
            ->orderBy('slug')
            ->get()
            ->map(fn (AtlasWorkspaceProfile $profile): array => [
                'id' => (string) $profile->id,
                'slug' => (string) $profile->slug,
                'name' => (string) $profile->name,
                'kind' => (string) $profile->kind,
                'workspace_path' => (string) ($profile->workspace_path ?? ''),
                'repo_root' => (string) ($profile->repo_root ?? $profile->workspace_path ?? ''),
                'production_status' => (string) $profile->production_status,
                'stack_summary' => (string) ($profile->stack_summary ?? ''),
                'commands' => $profile->commands ?? [],
                'test_commands' => $profile->test_commands ?? [],
                'build_commands' => $profile->build_commands ?? [],
                'dev_server_command' => $profile->dev_server_command,
                'critical_areas' => $profile->critical_areas ?? [],
                'docs_status' => (string) $profile->docs_status,
                'default_risk' => (string) $profile->default_risk,
                'deployment_notes' => (string) ($profile->deployment_notes ?? ''),
                'surfaces_enabled' => $profile->surfaces_enabled ?? ['atlas_ai', 'cartografia', 'code', 'atencao'],
                'source' => (string) $profile->source,
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return array_values($out);
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                continue;
            }
            if (trim($key) === '' || trim($value) === '') {
                continue;
            }
            $out[trim($key)] = trim($value);
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    private function requiredString(array $attributes, string $key, string $fallback): string
    {
        $value = $attributes[$key] ?? $fallback;
        if (! is_string($value)) {
            return $fallback;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? $fallback : $trimmed;
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    private function optionalString(array $attributes, string $key, ?string $fallback): ?string
    {
        $value = $attributes[$key] ?? $fallback;
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            return $fallback;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? $fallback : $trimmed;
    }

    private function normalizePath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        return rtrim(realpath($path) ?: $path, DIRECTORY_SEPARATOR);
    }
}

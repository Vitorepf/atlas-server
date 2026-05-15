<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

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
        $shaped = [];
        foreach ($profiles as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $shaped[] = $this->shape($raw);
        }

        return $shaped;
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
     * @param  mixed  $raw
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
     * @param  mixed  $raw
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
}

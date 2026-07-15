<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

/**
 * Atlas Code · M3 Radar read-model.
 *
 * A frota de repositórios num olhar, por EXCEÇÃO: um repo saudável devolve
 * apenas o nome — nenhum "sincronizado ✓" decorativo, nenhum contador que
 * não informa. Só o desvio fala.
 *
 * Princípios (docs/atlas-codigo-evolucao.md §1):
 *   - Zero dado inventado: repo ilegível é reportado como `readable=false`,
 *     nunca como "0 violações".
 *   - Ausência ≠ zero: `violations` só existe quando o scanner respondeu.
 *   - Service puro sobre read-models existentes; não executa git nem escreve.
 */
final class AtlasCodeReposService
{
    public const SCHEMA_VERSION = 'atlas.code.repos.v1';

    public function __construct(
        private readonly ?AtlasCodeWorkspaceProfileService $profiles = null,
        private readonly ?AtlasCodeViolationService $violations = null,
    ) {}

    /**
     * Projeção pura: dado o perfil e o resultado (ou ausência) do scan, diz o
     * que o radar mostra. Testável sem git e sem banco.
     *
     * @param  array<string,mixed>  $profile
     * @param  array<int,array<string,mixed>>|null  $violations  null = scanner não respondeu
     * @return array<string,mixed>
     */
    public function projectRepo(array $profile, ?array $violations): array
    {
        $slug = trim((string) ($profile['slug'] ?? ''));
        $path = trim((string) ($profile['repo_root'] ?? $profile['workspace_path'] ?? ''));
        // Diretório legível não basta: sem .git não há topologia para julgar
        // (o perfil guarda-chuva do workspace é uma pasta, não um repo).
        $isDirectory = $path !== '' && is_dir($path);
        $readable = $isDirectory && (is_dir($path.'/.git') || is_file($path.'/.git'));

        $repo = [
            'slug' => $slug,
            'name' => trim((string) ($profile['name'] ?? $slug)),
            'readable' => $readable,
        ];

        if (! $readable) {
            // Estado honesto: sem leitura não há juízo sobre saúde.
            $repo['unreadable_reason'] = match (true) {
                $path === '' => 'repository_path_missing',
                $isDirectory => 'not_a_git_repository',
                default => 'repository_path_unreadable',
            };

            return $repo;
        }

        if ($violations === null) {
            $repo['scan'] = 'unavailable';

            return $repo;
        }

        // Saudável = silêncio: a chave só existe quando há exceção.
        if ($violations !== []) {
            $repo['violations'] = count($violations);
            $repo['rules'] = array_values(array_unique(array_map(
                static fn (array $violation): string => (string) ($violation['rule_id'] ?? 'unknown'),
                $violations,
            )));
        }

        return $repo;
    }

    /**
     * @return array<string,mixed>
     */
    public function capture(): array
    {
        $profileService = $this->profiles ?? new AtlasCodeWorkspaceProfileService();
        $violationService = $this->violations ?? new AtlasCodeViolationService();

        $repos = [];
        foreach ($profileService->listProfiles() as $profile) {
            if (! is_array($profile)) {
                continue;
            }
            $slug = trim((string) ($profile['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $violations = null;
            try {
                $scan = $violationService->capture($slug);
                $violations = is_array($scan['violations'] ?? null) ? $scan['violations'] : [];
            } catch (\Throwable) {
                // Um repo que não responde não pode derrubar o radar inteiro.
                $violations = null;
            }

            $repos[] = $this->projectRepo($profile, $violations);
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'repos' => $repos,
        ];
    }
}

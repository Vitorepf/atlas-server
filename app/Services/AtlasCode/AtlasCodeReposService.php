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
            // Agrupado por regra: a tela precisa contar a HISTÓRIA ("17 obras
            // nunca voltaram à main"), não listar ids de regra soltos.
            $repo['issues'] = $this->groupIssues($violations);
        }

        return $repo;
    }

    /**
     * Agrupa por regra e mede a idade real do caso mais antigo. Só o que foi
     * medido aparece: sem `since` legível, nenhuma idade é inventada.
     *
     * @param  array<int,array<string,mixed>>  $violations
     * @return array<int, array<string,mixed>>
     */
    public function groupIssues(array $violations, ?int $now = null): array
    {
        $now ??= time();
        $byRule = [];
        foreach ($violations as $violation) {
            $rule = (string) ($violation['rule_id'] ?? 'unknown');
            $byRule[$rule] ??= ['rule_id' => $rule, 'count' => 0, 'severity' => 'medium', 'oldest_days' => null];
            $byRule[$rule]['count']++;

            $severity = (string) ($violation['severity'] ?? 'medium');
            if ($severity === 'high') {
                $byRule[$rule]['severity'] = 'high';
            }

            $since = $violation['since'] ?? null;
            if (is_string($since) && $since !== '') {
                $timestamp = strtotime($since);
                if ($timestamp !== false) {
                    $days = (int) floor(($now - $timestamp) / 86400);
                    $current = $byRule[$rule]['oldest_days'];
                    if ($current === null || $days > $current) {
                        $byRule[$rule]['oldest_days'] = max(0, $days);
                    }
                }
            }
        }

        // O caso mais grave e mais numeroso primeiro: a tela lê de cima.
        $issues = array_values($byRule);
        usort($issues, static function (array $a, array $b): int {
            $weight = static fn (array $i): int => $i['severity'] === 'high' ? 0 : 1;

            return [$weight($a), -$a['count']] <=> [$weight($b), -$b['count']];
        });

        return array_map(static function (array $issue): array {
            // Ausência é ausência: `oldest_days` só existe se foi medido.
            if ($issue['oldest_days'] === null) {
                unset($issue['oldest_days']);
            }

            return $issue;
        }, $issues);
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

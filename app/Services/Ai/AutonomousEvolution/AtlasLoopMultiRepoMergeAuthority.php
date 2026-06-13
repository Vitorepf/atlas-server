<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * L5-9 · A PORTA GOVERNADA POR-REPO (o Atlas trabalha nos SEUS projetos).
 *
 * O Loop nasceu evoluindo só o atlas-server (o "home repo"). O salto da Lista 5 é deixá-lo
 * manter os OUTROS repos do operador (blackink, nivor, …) com os MESMOS guards — mas com uma
 * porta de merge governada SEPARADA por-repo. O invariante pétreo é:
 *
 *   NEVER-MERGE DEFAULT TAMBÉM LÁ. Um repo ESTRANGEIRO (qualquer coisa que não seja o home
 *   repo) é never-merge por padrão e fail-closed: o auto-merge só atravessa quando o
 *   operador (a) ligou o recurso multi-repo (`atlas.ai.loop.multi_repo.enabled`) E (b)
 *   listou o caminho ABSOLUTO canônico daquele repo específico em `allowed_repos`.
 *
 * Por que uma autoridade dedicada (e não só mais uma flag no auto-merge):
 *   - O `auto_merge_to_main` é a porta do HOME repo. Reusá-lo para repos estrangeiros
 *     significaria "ligou para o atlas-server ⇒ pode mergear em blackink" — exatamente o
 *     vazamento que o L5-9 proíbe. A porta tem que ser por-repo.
 *   - A decisão precisa ser testável isoladamente (frozen test) e auditável: cada veredito
 *     carrega `scope` (home|foreign), `reason` e o caminho resolvido.
 *
 * Fail-closed por construção: na dúvida (caminho irresolúvel, feature off, repo fora da
 * lista) ⇒ NÃO autoriza. Ampliar a lista é decisão deliberada do operador; o default nunca
 * autoriza um repo estrangeiro.
 */
final class AtlasLoopMultiRepoMergeAuthority
{
    public const SCHEMA_VERSION = 'atlas.ai.loop_multi_repo_merge_authority.v1';

    public const SCOPE_HOME = 'home';

    public const SCOPE_FOREIGN = 'foreign';

    /**
     * Decide se o auto-merge pode atravessar para `$repoRoot`.
     *
     * @return array{
     *   schema_version:string,
     *   allowed:bool,
     *   scope:string,
     *   reason:string,
     *   resolved_repo:?string,
     *   home_repo:string,
     *   multi_repo_enabled:bool,
     *   allowed_repos:list<string>
     * }
     */
    public function authorize(string $repoRoot): array
    {
        $home = $this->canonical($this->homeRepo());
        $resolved = $this->canonical($repoRoot);
        $multiRepoEnabled = (bool) config('atlas.ai.loop.multi_repo.enabled', false);
        $allowed = $this->allowedRepos();

        $base = [
            'schema_version' => self::SCHEMA_VERSION,
            'home_repo' => $home,
            'multi_repo_enabled' => $multiRepoEnabled,
            'allowed_repos' => $allowed,
            'resolved_repo' => $resolved,
        ];

        // Caminho irresolúvel (repo inexistente / path quebrado) ⇒ fail-closed.
        if ($resolved === null) {
            return array_merge($base, [
                'allowed' => false,
                'scope' => self::SCOPE_FOREIGN,
                'reason' => 'repo_path_unresolvable',
            ]);
        }

        // HOME repo: governado pela porta clássica `auto_merge_to_main`. A autoridade
        // multi-repo NÃO reabre essa porta — ela só CLASSIFICA o repo como home e deixa o
        // auto-merge aplicar a sua própria flag de home (comportamento inalterado).
        if ($resolved === $home) {
            return array_merge($base, [
                'allowed' => (bool) config('atlas.ai.loop.auto_merge_to_main', false),
                'scope' => self::SCOPE_HOME,
                'reason' => (bool) config('atlas.ai.loop.auto_merge_to_main', false)
                    ? 'home_repo_auto_merge_enabled'
                    : 'home_repo_auto_merge_disabled',
            ]);
        }

        // REPO ESTRANGEIRO: never-merge default. Porta por-repo fail-closed.
        if (! $multiRepoEnabled) {
            return array_merge($base, [
                'allowed' => false,
                'scope' => self::SCOPE_FOREIGN,
                'reason' => 'multi_repo_disabled',
            ]);
        }
        if (! in_array($resolved, $allowed, true)) {
            return array_merge($base, [
                'allowed' => false,
                'scope' => self::SCOPE_FOREIGN,
                'reason' => 'foreign_repo_not_in_allowed_list',
            ]);
        }

        return array_merge($base, [
            'allowed' => true,
            'scope' => self::SCOPE_FOREIGN,
            'reason' => 'foreign_repo_operator_authorized',
        ]);
    }

    /**
     * Conveniência fail-closed: só true quando `authorize()` libera.
     */
    public function permits(string $repoRoot): bool
    {
        return (bool) $this->authorize($repoRoot)['allowed'];
    }

    /**
     * @return list<string>
     */
    private function allowedRepos(): array
    {
        $raw = config('atlas.ai.loop.multi_repo.allowed_repos', []);
        if (is_string($raw)) {
            // .env só carrega string: aceita CSV.
            $raw = array_filter(array_map('trim', explode(',', $raw)), static fn (string $s): bool => $s !== '');
        }
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $entry) {
            $canonical = $this->canonical((string) $entry);
            if ($canonical !== null) {
                $out[] = $canonical;
            }
        }

        return array_values(array_unique($out));
    }

    private function homeRepo(): string
    {
        return (string) (function_exists('base_path') ? base_path() : getcwd());
    }

    /**
     * Caminho canônico absoluto sem barra final. realpath() resolve symlinks e `..`; quando
     * o diretório não existe (path quebrado / repo removido) retorna null ⇒ fail-closed no
     * chamador. NÃO cria o diretório nem assume existência.
     */
    private function canonical(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }
        $real = realpath($path);
        if ($real === false) {
            return null;
        }

        return rtrim($real, '/');
    }
}

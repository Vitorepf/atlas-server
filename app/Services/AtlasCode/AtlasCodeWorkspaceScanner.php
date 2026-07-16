<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

use Symfony\Component\Process\Process;

/**
 * Atlas Code · descoberta do workspace real (M3 v2).
 *
 * O modelo mental correto do Mac do operador:
 *
 *   develop/
 *     Atlas/        ← PASTA de produto (atlas-server, atlas-native, …)
 *     blackink/     ← PASTA de produto (blackink-app, nivor-back-end, …)
 *     vitorepf-site ← repositório solto
 *
 * Uma pasta que contém repositórios NÃO é um repositório quebrado — é uma
 * pasta. Tratar "Atlas" como repo ilegível era um erro de modelo, não um
 * erro de dado. A tela precisa: RECENTES (o que você tocou por último) e
 * PASTAS (o resto, agrupado, sem duplicar).
 *
 * Princípios:
 *   - Zero invenção: repo é diretório com `.git`; sem git log legível, não há
 *     data — e sem data, nenhuma ordem é fabricada.
 *   - Profundidade limitada (2): descobrir, não indexar o disco.
 *   - Somente leitura: nenhum comando muta o workspace.
 */
final class AtlasCodeWorkspaceScanner
{
    public const SCHEMA_VERSION = 'atlas.code.repos.v2';

    /** Pastas que nunca são produto — ruído de máquina, não trabalho. */
    private const IGNORED = ['node_modules', 'vendor', '.git', 'DerivedData', 'build', 'dist', 'Pods', '.next'];

    public function __construct(
        private readonly int $timeoutSeconds = 10,
    ) {}

    public function workspaceRoot(): string
    {
        $configured = trim((string) (getenv('ATLAS_CODE_WORKSPACE_ROOT') ?: ''));
        if ($configured !== '' && is_dir($configured)) {
            return rtrim($configured, '/');
        }

        $home = trim((string) (getenv('HOME') ?: ''));
        foreach ([$home.'/develop', '/Users/vitorepf/develop'] as $candidate) {
            if ($candidate !== '/develop' && is_dir($candidate)) {
                return rtrim($candidate, '/');
            }
        }

        return '';
    }

    public function isRepository(string $path): bool
    {
        return is_dir($path.'/.git') || is_file($path.'/.git');
    }

    /**
     * Nome humano a partir do diretório: `atlas-native` → "Atlas Native",
     * `nivor-back-end` → "Nivor Back End". O disco fala kebab; a tela fala
     * português.
     */
    public function humanName(string $directory): string
    {
        $words = preg_split('/[-_.]+/', $directory) ?: [$directory];
        $words = array_map(static function (string $word): string {
            if ($word === '') {
                return '';
            }
            // Siglas conhecidas ficam como estão.
            if (in_array(strtolower($word), ['ai', 'os', 'ui', 'api', 'cli', 'db'], true)) {
                return strtoupper($word);
            }

            return mb_strtoupper(mb_substr($word, 0, 1)).mb_substr($word, 1);
        }, $words);

        return trim(implode(' ', array_filter($words)));
    }

    /**
     * Repos cuja HISTÓRIA não pôde ser lida na última varredura — falha, não
     * fato. "Repo sem commit" é fato (sai com data null e é dito); "não
     * consegui ler" é outra coisa, e sem esta lista os dois colapsavam no
     * mesmo null: git fora do PATH fazia os 13 repos virarem "sem data",
     * RECENTES ficava vazio e a tela afirmava que o operador não trabalhou em
     * nada — quando a varredura é que falhou.
     *
     * @var array<int,string>
     */
    private array $unreadable = [];

    /**
     * Último commit em epoch, ou null quando o repositório não tem história
     * legível. Null NUNCA vira 0 — repo sem data não é repo "de 1970".
     *
     * O `run()` fica em try/catch porque timeout LANÇA
     * (ProcessTimedOutException), não vira `isSuccessful() === false` — e o
     * controller devolve o capture() cru: sem o catch, UM repo travado
     * (fsmonitor preso, volume desmontado, index.lock órfão) virava 500 no
     * endpoint inteiro e os outros doze repos saudáveis sumiam junto.
     */
    public function lastCommitAt(string $path): ?int
    {
        $process = new Process(['git', 'log', '-1', '--format=%ct'], $path, null, null, $this->timeoutSeconds);

        try {
            $process->run();
        } catch (\Throwable) {
            $this->unreadable[] = $path;

            return null;
        }

        if (! $process->isSuccessful()) {
            // Repo genuinamente sem commit sai 128 com "does not have any
            // commits yet" — isso é FATO (sem data), não falha de leitura.
            // Qualquer outra saída (127 git ausente, permissão, corrupção) é
            // falha, e falha é registrada, nunca vestida de "sem história".
            if (! str_contains($process->getErrorOutput(), 'does not have any commits')) {
                $this->unreadable[] = $path;
            }

            return null;
        }
        $value = filter_var(trim($process->getOutput()), FILTER_VALIDATE_INT);

        return $value === false ? null : (int) $value;
    }

    /**
     * Onde mora o repositório de slug `X` — a pergunta que a tela faz quando o
     * operador toca num card do radar.
     *
     * Se dois produtos tiverem um repositório de mesmo nome, o slug é ambíguo e
     * a resposta é o silêncio: mostrar a história do repositório errado seria
     * uma mentira, e mentira é pior que erro numa ferramenta de governança.
     */
    public function locate(string $slug): ?string
    {
        // O slug é um segmento de diretório, nunca um caminho: '..' e '/' saem.
        // Espaço é caractere legítimo de nome de pasta — "Meu Projeto" existe
        // no disco, o radar o LISTA, e este filtro fazia o /code/ask negar que
        // ele existe: a mesma frota com duas verdades. O que o filtro guarda é
        // travessia (`/`, `..`), não estética de nome.
        if (preg_match('/^[A-Za-z0-9._\- ]+$/', $slug) !== 1 || $slug === '.' || $slug === '..' || trim($slug) === '') {
            return null;
        }

        $root = $this->workspaceRoot();
        if ($root === '') {
            return null;
        }

        $matches = [];
        foreach ($this->children($root) as $entry) {
            $path = $root.'/'.$entry;

            if ($this->isRepository($path)) {
                if ($entry === $slug) {
                    $matches[] = $path;
                }

                continue;
            }

            foreach ($this->children($path) as $child) {
                if ($child === $slug && $this->isRepository($path.'/'.$child)) {
                    $matches[] = $path.'/'.$child;
                }
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * @return array<int, array{slug:string, name:string, path:string, folder:?string, last_commit_at:?int}>
     */
    public function discover(): array
    {
        $root = $this->workspaceRoot();
        if ($root === '') {
            return [];
        }

        $repos = [];
        foreach ($this->children($root) as $entry) {
            $path = $root.'/'.$entry;

            if ($this->isRepository($path)) {
                // Repositório solto na raiz: sem pasta de produto.
                $repos[] = $this->shape($entry, $path, folder: null);

                continue;
            }

            // Pasta de produto: os repositórios estão um nível abaixo.
            foreach ($this->children($path) as $child) {
                $childPath = $path.'/'.$child;
                if ($this->isRepository($childPath)) {
                    $repos[] = $this->shape($child, $childPath, folder: $entry);
                }
            }
        }

        return $repos;
    }

    /**
     * @param  array<int, array<string,mixed>>  $repos
     * @return array<string,mixed>
     */
    public function organize(array $repos, int $recentLimit = 3): array
    {
        // Recentes: só quem tem data real. Sem data, não entra no pódio.
        $dated = array_values(array_filter($repos, static fn (array $r): bool => $r['last_commit_at'] !== null));
        usort($dated, static fn (array $a, array $b): int => $b['last_commit_at'] <=> $a['last_commit_at']);
        $recents = array_slice($dated, 0, max(0, $recentLimit));
        $recentSlugs = array_column($recents, 'path');

        // Pastas: tudo agrupado, SEM remover o que está nos recentes — a pasta
        // é a verdade do disco; os recentes são um atalho, não uma cópia.
        $byFolder = [];
        $loose = [];
        foreach ($repos as $repo) {
            if ($repo['folder'] === null) {
                $loose[] = $repo;

                continue;
            }
            $byFolder[$repo['folder']] ??= [];
            $byFolder[$repo['folder']][] = $repo;
        }

        $folders = [];
        foreach ($byFolder as $folder => $items) {
            usort($items, static fn (array $a, array $b): int => ($b['last_commit_at'] ?? 0) <=> ($a['last_commit_at'] ?? 0));
            $folders[] = [
                'slug' => $folder,
                'name' => $this->humanName($folder),
                'repositories' => count($items),
                'last_commit_at' => $items[0]['last_commit_at'] ?? null,
                'repos' => $items,
            ];
        }
        usort($folders, static fn (array $a, array $b): int => ($b['last_commit_at'] ?? 0) <=> ($a['last_commit_at'] ?? 0));
        usort($loose, static fn (array $a, array $b): int => ($b['last_commit_at'] ?? 0) <=> ($a['last_commit_at'] ?? 0));

        return [
            'recents' => $recents,
            'recent_paths' => $recentSlugs,
            'folders' => $folders,
            'loose' => $loose,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function capture(int $recentLimit = 3): array
    {
        $this->unreadable = [];
        $organized = $this->organize($this->discover(), $recentLimit);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'workspace_root' => $this->workspaceRoot(),
            'recents' => $organized['recents'],
            'folders' => $organized['folders'],
            'loose' => $organized['loose'],
        ];

        // Falha de leitura é DITA no payload, nunca vestida de "sem data".
        // A tela pode confessar "não consegui ler N repositórios" em vez de
        // mostrar um pódio vazio afirmando que ninguém trabalhou.
        if ($this->unreadable !== []) {
            $payload['scan_failures'] = array_values(array_unique(array_map('basename', $this->unreadable)));
        }

        return $payload;
    }

    /**
     * @return array{slug:string, name:string, path:string, folder:?string, last_commit_at:?int}
     */
    private function shape(string $directory, string $path, ?string $folder): array
    {
        return [
            'slug' => $directory,
            'name' => $this->humanName($directory),
            'path' => $path,
            'folder' => $folder,
            'last_commit_at' => $this->lastCommitAt($path),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function children(string $path): array
    {
        $entries = @scandir($path);
        if ($entries === false) {
            return [];
        }

        $children = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            if (in_array($entry, self::IGNORED, true)) {
                continue;
            }
            if (! is_dir($path.'/'.$entry)) {
                continue;
            }
            $children[] = $entry;
        }
        sort($children);

        return $children;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Atlas Code · WorkspaceFolderIntelligenceService.
 *
 * "Nascer inteligente": classifica a pasta de um Projeto e captura, de forma
 * read-only e limitada, o retrato estrutural que o operador precisa ver na
 * ficha — sem nunca tratar pasta como diretório burro.
 *
 *   - git_repository · a pasta É um repositório (tem .git) → branch, último
 *     commit, arquivos em modificação e contagens de estrutura (migrations,
 *     rotas, models, controllers, commands, tests…).
 *   - umbrella · a pasta NÃO é repo mas ADMINISTRA repositórios filhos
 *     (ex.: ~/develop/Atlas contém atlas-server, atlas-desktop…) → enumera os
 *     filhos com seus fatos git e agrega a freshness do conjunto.
 *   - plain_folder · pasta real sem git nem filhos com git.
 *   - missing · caminho vazio/inacessível.
 *
 * Hard rules (mesmo contrato do GitWorkspaceInspector):
 *   - NUNCA escreve na pasta (somente leitura).
 *   - NUNCA executa shell string (sempre array args).
 *   - Todo comando git tem timeout curto (3s) e falha vira null, não exceção.
 *   - Toda varredura de filesystem é CAPADA (nunca um scan ilimitado).
 *   - Resultado é cacheado por 60s por caminho — os fluxos de chat NÃO passam
 *     por aqui (somente os endpoints HTTP de workspaces consumidos pela UI).
 *
 * Schema: atlas.code.workspace_folder_intelligence.v1
 */
final class WorkspaceFolderIntelligenceService
{
    public const SCHEMA_VERSION = 'atlas.code.workspace_folder_intelligence.v1';

    /** AP-818 F2.0 — schema aditivo, emitido somente com o flag v2_enabled ON. */
    public const SCHEMA_VERSION_V2 = 'atlas.code.workspace_folder_intelligence.v2';

    private const CACHE_TTL_SECONDS = 60;

    private const GIT_TIMEOUT_SECONDS = 3;

    /** Máximo de entradas de 1º nível examinadas ao procurar repos filhos. */
    private const MAX_UMBRELLA_SCAN_ENTRIES = 64;

    /** Máximo de repos filhos reportados num guarda-chuva. */
    private const MAX_UMBRELLA_CHILDREN = 16;

    public function __construct(
        private readonly WorkspaceIntelligenceStatusReader $statusReader,
        private readonly \App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity $identity,
    ) {}

    /**
     * @return array<string, mixed>|null null quando o profile não tem pasta.
     */
    public function inspect(string $workspacePath): ?array
    {
        $path = rtrim($workspacePath, '/');
        if ($path === '') {
            return null;
        }

        try {
            return Cache::remember($this->cacheKey($path), self::CACHE_TTL_SECONDS, fn (): array => $this->compute($path));
        } catch (Throwable) {
            // Cache indisponível não pode derrubar o read-model — computa direto.
            try {
                return $this->compute($path);
            } catch (Throwable) {
                return null;
            }
        }
    }

    /**
     * Derruba o retrato cacheado de uma pasta — chamado pelo assembly (F2.1)
     * quando o índice muda, para a UI ver o estado novo no próximo GET.
     */
    public function forget(string $workspacePath): void
    {
        $path = rtrim($workspacePath, '/');
        if ($path === '') {
            return;
        }

        try {
            Cache::forget($this->cacheKey($path));
        } catch (Throwable) {
            // best-effort — TTL de 60s resolve sozinho.
        }
    }

    private function cacheKey(string $path): string
    {
        return 'atlas.folder_intel.v1:'.sha1($path);
    }

    /**
     * AP-818 F2.5 · O escopo de contexto de um workspace ativo: o próprio id
     * e — quando ele é um guarda-chuva — os ids PRÓPRIOS dos membros (filhos
     * com profile registrado têm grafo isolado; os demais já vivem dentro do
     * grafo agregado do umbrella). Fail-safe: qualquer erro degrada para
     * escopo single-workspace, o comportamento provado.
     *
     * @return array<int, string>
     */
    public function contextScopeIds(string $workspaceId): array
    {
        $ids = [$workspaceId];

        try {
            $profile = app(AtlasCodeWorkspaceProfileService::class)->findBySlug($workspaceId);
            $path = rtrim(trim((string) ($profile['workspace_path'] ?? '')), '/');
            if ($path === '' || ! @is_dir($path)) {
                return $ids;
            }

            $intel = $this->inspect($path);
            if (($intel['classification'] ?? '') !== 'umbrella') {
                return $ids;
            }

            foreach ($intel['children'] ?? [] as $child) {
                $childWorkspaceId = $this->identity->resolve((string) ($child['path'] ?? ''));
                if ($childWorkspaceId !== $workspaceId && ! in_array($childWorkspaceId, $ids, true)) {
                    $ids[] = $childWorkspaceId;
                }
            }
        } catch (Throwable) {
            return [$workspaceId];
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function compute(string $path): array
    {
        $base = [
            'schema_version' => self::SCHEMA_VERSION,
            'classification' => 'missing',
            'git' => null,
            'structure' => null,
            'children' => [],
            'children_truncated' => false,
            'freshness' => null,
            'computed_at' => now()->toJSON(),
        ];

        if (! @is_dir($path)) {
            return $base;
        }

        if ($this->isGitRepository($path)) {
            $git = $this->gitFacts($path);
            $structure = $this->structureCounts($path);

            return $this->withIntelligenceV2(array_merge($base, [
                'classification' => 'git_repository',
                'git' => $git,
                'structure' => $structure,
                'freshness' => [
                    'last_commit_at' => $git['last_commit_at'] ?? null,
                    'dirty_count' => $git['dirty_count'] ?? 0,
                ],
            ]), $path);
        }

        [$children, $truncated] = $this->umbrellaChildren($path);
        if ($children !== []) {
            $lastCommitAt = null;
            $dirtyTotal = 0;
            foreach ($children as $child) {
                $candidate = $child['last_commit_at'] ?? null;
                if ($candidate !== null && ($lastCommitAt === null || $candidate > $lastCommitAt)) {
                    $lastCommitAt = $candidate;
                }
                $dirtyTotal += (int) ($child['dirty_count'] ?? 0);
            }

            return $this->withIntelligenceV2(array_merge($base, [
                'classification' => 'umbrella',
                'children' => $children,
                'children_truncated' => $truncated,
                'freshness' => [
                    'last_commit_at' => $lastCommitAt,
                    'dirty_count' => $dirtyTotal,
                ],
            ]), $path);
        }

        return array_merge($base, ['classification' => 'plain_folder']);
    }

    /**
     * AP-818 F2.0 — enriquecimento v2, ADITIVO e flag-gated: com
     * `atlas.code_folder_intelligence.v2_enabled` OFF o payload v1 sai
     * byte-idêntico. Com ON:
     *
     *   - git_repository ganha `workspace_id` (W-7) + bloco `intelligence`
     *     (intelligence_status/modules/symbols/last_indexed_at do read-model).
     *   - umbrella ganha o mesmo por FILHO (`umbrella_members` resolvidos a
     *     workspace_id) + agregado `intelligence` {members_indexed,
     *     members_total, last_indexed_at}.
     *
     * Read-only e fail-safe: erro em qualquer fonte degrada para o retrato v1.
     *
     * @param  array<string, mixed>  $intel
     * @return array<string, mixed>
     */
    private function withIntelligenceV2(array $intel, string $path): array
    {
        if (! (bool) config('atlas.code_folder_intelligence.v2_enabled', false)) {
            return $intel;
        }

        try {
            $intel['schema_version'] = self::SCHEMA_VERSION_V2;

            if ($intel['classification'] === 'git_repository') {
                $workspaceId = $this->identity->resolve($path);
                $intel['workspace_id'] = $workspaceId;
                $intel['intelligence'] = $this->statusReader->status($workspaceId);

                // F2.3 — retrato indexado: fatos reais (amostras nomeadas,
                // top módulos, tipos de símbolo) quando o índice existe.
                if ($intel['intelligence']['intelligence_status'] === 'indexed') {
                    $intel['intelligence']['portrait'] = $this->statusReader->indexPortrait($workspaceId);
                }

                return $intel;
            }

            // umbrella: resolve cada filho a workspace_id + status do índice.
            // Filho COM profile próprio → grafo próprio; filho coberto pelo
            // umbrella (canon de identidade por prefixo) → fatia do grafo do
            // umbrella pelo root_path relativo, para mostrar os números DELE.
            $umbrellaWorkspaceId = $this->identity->resolve($path);
            $intel['workspace_id'] = $umbrellaWorkspaceId;
            $membersIndexed = 0;
            $lastIndexedAt = null;
            $memberStatuses = [];
            foreach ($intel['children'] as $i => $child) {
                $workspaceId = $this->identity->resolve((string) $child['path']);
                $status = $workspaceId === $umbrellaWorkspaceId
                    ? $this->statusReader->scopedStatus($umbrellaWorkspaceId, (string) $child['name'])
                    : $this->statusReader->status($workspaceId);
                $intel['children'][$i]['workspace_id'] = $workspaceId;
                $intel['children'][$i]['intelligence_status'] = $status['intelligence_status'];
                $intel['children'][$i]['indexed_symbols'] = $status['symbols'];

                $memberStatuses[] = $status['intelligence_status'];
                if ($status['intelligence_status'] === 'indexed') {
                    $membersIndexed++;
                }
                $candidate = $status['last_indexed_at'];
                if ($candidate !== null && ($lastIndexedAt === null || $candidate > $lastIndexedAt)) {
                    $lastIndexedAt = $candidate;
                }
            }

            // Agregado: trabalho em andamento ganha do estado de repouso.
            $aggregate = match (true) {
                in_array('indexing', $memberStatuses, true) => 'indexing',
                in_array('assembly_pending', $memberStatuses, true) => 'assembly_pending',
                $membersIndexed > 0 => 'indexed',
                in_array('failed', $memberStatuses, true) => 'failed',
                default => 'portrait_only',
            };

            $intel['intelligence'] = [
                'intelligence_status' => $aggregate,
                'members_indexed' => $membersIndexed,
                'members_total' => count($intel['children']),
                'last_indexed_at' => $lastIndexedAt,
            ];

            // F2.3 — o guarda-chuva indexado tem o grafo AGREGADO ("todos
            // juntos"): o retrato vem do grafo do próprio umbrella.
            if ($aggregate === 'indexed') {
                $intel['intelligence']['portrait'] = $this->statusReader->indexPortrait($umbrellaWorkspaceId);
            }

            return $intel;
        } catch (Throwable) {
            // v2 indisponível → retrato v1 continua válido e honesto.
            $intel['schema_version'] = self::SCHEMA_VERSION;

            return $intel;
        }
    }

    private function isGitRepository(string $path): bool
    {
        // .git pode ser diretório (clone normal) ou arquivo (worktree/submódulo).
        return @file_exists($path.'/.git');
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: bool}
     */
    private function umbrellaChildren(string $path): array
    {
        $entries = @scandir($path);
        if ($entries === false) {
            return [[], false];
        }

        $children = [];
        $examined = 0;
        $truncated = false;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            if (++$examined > self::MAX_UMBRELLA_SCAN_ENTRIES) {
                $truncated = true;
                break;
            }
            $childPath = $path.'/'.$entry;
            if (! @is_dir($childPath) || ! $this->isGitRepository($childPath)) {
                continue;
            }
            if (count($children) >= self::MAX_UMBRELLA_CHILDREN) {
                $truncated = true;
                break;
            }
            $facts = $this->gitFacts($childPath);
            $children[] = [
                'name' => $entry,
                'path' => $childPath,
                'branch' => $facts['branch'] ?? null,
                'last_commit_at' => $facts['last_commit_at'] ?? null,
                'dirty_count' => $facts['dirty_count'] ?? 0,
                'stacks' => $this->detectStacks($childPath),
            ];
        }

        // Mais recente primeiro — o operador vê onde a vida está acontecendo.
        usort($children, static fn (array $a, array $b): int => strcmp(
            (string) ($b['last_commit_at'] ?? ''),
            (string) ($a['last_commit_at'] ?? ''),
        ));

        return [$children, $truncated];
    }

    /**
     * @return array<string, mixed>
     */
    private function gitFacts(string $repoPath): array
    {
        $branch = $this->git($repoPath, ['rev-parse', '--abbrev-ref', 'HEAD']);
        $head = $this->git($repoPath, ['rev-parse', '--short', 'HEAD']);
        $log = $this->git($repoPath, ['log', '-1', '--format=%cI%x09%s']);
        $status = $this->git($repoPath, ['status', '--porcelain']);

        $lastCommitAt = null;
        $lastCommitSubject = null;
        if ($log !== null && str_contains($log, "\t")) {
            [$lastCommitAt, $lastCommitSubject] = explode("\t", $log, 2);
            $lastCommitAt = trim($lastCommitAt) !== '' ? trim($lastCommitAt) : null;
            $lastCommitSubject = trim($lastCommitSubject) !== '' ? mb_substr(trim($lastCommitSubject), 0, 120) : null;
        }

        $dirtyCount = 0;
        if ($status !== null && trim($status) !== '') {
            $dirtyCount = count(array_filter(explode("\n", trim($status)), static fn (string $l): bool => trim($l) !== ''));
        }

        return [
            'branch' => $branch,
            'head_short' => $head,
            'last_commit_at' => $lastCommitAt,
            'last_commit_subject' => $lastCommitSubject,
            'dirty_count' => $dirtyCount,
        ];
    }

    private function git(string $repoPath, array $args): ?string
    {
        try {
            $process = new Process(['git', '-C', $repoPath, ...$args], timeout: self::GIT_TIMEOUT_SECONDS);
            $process->run();
            if (! $process->isSuccessful()) {
                return null;
            }
            $output = trim($process->getOutput());

            return $output !== '' ? $output : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Retrato estrutural barato e honesto: contagens por convenção de stack,
     * lidas do índice do git (`git ls-files`) — 1 processo por repo, custo de
     * milissegundos, e respeita .gitignore (nada de node_modules/vendor).
     *
     * @return array<string, mixed>|null
     */
    private function structureCounts(string $path): ?array
    {
        $stacks = $this->detectStacks($path);
        $files = $this->trackedFiles($path);
        $counts = [];

        if (in_array('laravel', $stacks, true)) {
            $counts['migrations'] = $this->countMatching($files, 'database/migrations/', '.php');
            $counts['routes_files'] = $this->countMatching($files, 'routes/', '.php');
            $counts['models'] = $this->countMatching($files, 'app/Models/', '.php');
            $counts['controllers'] = $this->countMatching($files, 'app/Http/Controllers/', '.php');
            $counts['commands'] = $this->countMatching($files, 'app/Console/Commands/', '.php');
            $counts['services'] = $this->countMatching($files, 'app/Services/', '.php');
            $counts['tests'] = $this->countMatching($files, 'tests/', '.php');
        }

        if (in_array('node', $stacks, true)) {
            $counts['src_files'] = $this->countMatching($files, 'src/', '.ts', '.tsx', '.js', '.jsx')
                + $this->countMatching($files, 'apps/', '.ts', '.tsx', '.js', '.jsx');
        }

        if (in_array('rust', $stacks, true)) {
            $counts['rust_files'] = $this->countMatching($files, 'crates/', '.rs')
                + $this->countMatching($files, 'src/', '.rs');
        }

        $counts = array_filter($counts, static fn (int $value): bool => $value > 0);

        if ($stacks === [] && $counts === []) {
            return null;
        }

        return [
            'stacks' => $stacks,
            'counts' => $counts,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function trackedFiles(string $repoPath): array
    {
        $output = $this->git($repoPath, ['ls-files']);
        if ($output === null) {
            return [];
        }

        return explode("\n", $output);
    }

    /**
     * @param  array<int, string>  $files
     */
    private function countMatching(array $files, string $prefix, string ...$extensions): int
    {
        $count = 0;
        foreach ($files as $file) {
            if (! str_starts_with($file, $prefix)) {
                continue;
            }
            foreach ($extensions as $extension) {
                if (str_ends_with($file, $extension)) {
                    $count++;
                    break;
                }
            }
        }

        return $count;
    }

    /**
     * @return array<int, string>
     */
    private function detectStacks(string $path): array
    {
        $stacks = [];
        if (@is_file($path.'/artisan') && @is_file($path.'/composer.json')) {
            $stacks[] = 'laravel';
        }
        if (@is_file($path.'/package.json')) {
            $stacks[] = 'node';
        }
        if (@is_file($path.'/Cargo.toml')) {
            $stacks[] = 'rust';
        }

        return $stacks;
    }

}

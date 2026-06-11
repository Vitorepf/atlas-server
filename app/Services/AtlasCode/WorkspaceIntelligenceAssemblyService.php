<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

use App\Services\Engineering\CodeGraph\CodeGraphIndexLock;
use App\Services\Engineering\CodeGraph\CodeGraphSecretScanner;
use App\Services\Engineering\CodeGraph\CodeGraphSymbolBuilder;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspacePrivacy;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use App\Services\Tools\AtlasToolEvidenceStore;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * AP-818 F2.1 · Assembly de inteligência on-link — o trabalho que faz um
 * workspace "nascer inteligente" depois que o operador vincula uma pasta.
 *
 * GLUE por cima do substrato AP-815 (tabela §3 do AP é lei — nada aqui duplica
 * indexador, builder, identidade ou lock):
 *
 *   git_repository → o pipeline W-4 do workspace (index Code Intelligence +
 *     symbol graph), exatamente os MESMOS services do atlas:code-graph:pipeline.
 *   umbrella → o mesmo pipeline POR FILHO (lógica do index-all), em série
 *     (W-11 staged — nunca fan-out de processos), com G-5 secret-prescan por
 *     repo ANTES de ingerir e G-1 privacy class registrada.
 *   plain_folder | missing → nada a indexar (retrato da Fase 1 já cobre).
 *
 * Regras duras:
 *   - NUNCA roda inline em request HTTP ou fluxo de chat — somente via job de
 *     fila ({@see \App\Jobs\AssembleWorkspaceFolderIntelligenceJob}) ou CLI.
 *   - Lock W-10 por workspace (sem corrida com index manual); lock ocupado =
 *     skip honesto, nunca espera bloqueante.
 *   - Re-link com índice fresco (W-9 + janela fresh_minutes) = no-op por repo.
 *   - Evidence FOLDER_INTEL_ASSEMBLY_* com contagens e duração — nunca
 *     conteúdo de arquivo.
 */
final class WorkspaceIntelligenceAssemblyService
{
    public const EVIDENCE_TYPE = 'folder_intel_assembly';

    /** G-5 prescan: extensões que o indexador ingere (mesma lista do index-all). */
    private const INGESTED = ['php', 'ts', 'tsx', 'js', 'jsx', 'md'];

    private const SKIP_DIRS_PATTERN = '#[/\\\\](node_modules|vendor|dist|build|\.git)[/\\\\]#';

    /** Limites do prescan — bounded por contrato, nunca scan ilimitado. */
    private const PRESCAN_MAX_FILES = 4000;

    private const PRESCAN_MAX_FILE_BYTES = 800000;

    public function __construct(
        private readonly WorkspaceFolderIntelligenceService $folderIntelligence,
        private readonly WorkspaceIntelligenceStatusReader $statusReader,
        private readonly CodeGraphWorkspaceIdentity $identity,
        private readonly CodeGraphIndexLock $lock,
        private readonly CodeGraphWorkspacePrivacy $privacy,
        private readonly CodeGraphSecretScanner $secretScanner,
        private readonly EngineeringCodeIntelligenceService $codeIntelligence,
        private readonly CodeGraphSymbolBuilder $symbolBuilder,
        private readonly AtlasCodeWorkspaceProfileService $profiles,
        private readonly AtlasToolEvidenceStore $toolEvidence,
    ) {}

    /**
     * D-4 anti-destruição: o index com prune SÓ pode rodar sobre o caminho que
     * É DONO do workspace_id. Um repo coberto pelo profile de um guarda-chuva
     * (ex.: atlas-desktop → wid 'atlas') ESCALA para a raiz do profile — o
     * prune então enxerga a árvore inteira e jamais arquiva os módulos dos
     * irmãos (a classe do incidente dos 208k doc-links).
     */
    private function canonicalIndexPath(string $workspaceId, string $repoPath): string
    {
        try {
            $profile = $this->profiles->findBySlug($workspaceId);
            $profilePath = rtrim(trim((string) ($profile['workspace_path'] ?? '')), '/');
            if ($profilePath !== '' && @is_dir($profilePath)) {
                return $profilePath;
            }
        } catch (Throwable) {
            // sem profile dono → o próprio repo é o dono do wid derivado.
        }

        return $repoPath;
    }

    /**
     * F2.1 · Enfileira o assembly (o ÚNICO disparo automático permitido — o
     * trabalho pesado roda no job, jamais na request). Marca o estado
     * assembly_pending e derruba o retrato cacheado para a própria resposta
     * do upsert já mostrar o estado novo.
     *
     * @return array{queued: bool, reason?: string, workspace_id?: string}
     */
    public function queueAssembly(string $workspacePath, bool $force = false): array
    {
        $path = rtrim(trim($workspacePath), '/');
        if ($path === '' || ! @is_dir($path)) {
            return ['queued' => false, 'reason' => 'missing_path'];
        }

        $workspaceId = $this->identity->resolve($path);

        \App\Jobs\AssembleWorkspaceFolderIntelligenceJob::dispatch($path, $force);

        $this->statusReader->markQueued($workspaceId);
        $this->folderIntelligence->forget($path);
        $this->recordEvidence('queued', $workspaceId, "Assembly de inteligência enfileirado para {$path}", [
            'workspace_path' => $path,
        ]);

        return ['queued' => true, 'workspace_id' => $workspaceId];
    }

    /**
     * Monta a inteligência da pasta vinculada. Retorna um sumário por repo —
     * nunca lança (falha por repo vira linha 'failed' no sumário + evidence).
     *
     * @return array{status: string, workspace_path: string, repos: array<int, array<string, mixed>>}
     */
    public function assemble(string $workspacePath, bool $force = false): array
    {
        $startedAt = microtime(true);
        $path = rtrim(trim($workspacePath), '/');

        $summary = [
            'status' => 'ok',
            'workspace_path' => $path,
            'repos' => [],
        ];

        if ($path === '' || ! @is_dir($path)) {
            $summary['status'] = 'skipped_missing_path';

            return $summary;
        }

        // Marker da RAIZ: é ele que o agregado umbrella/repo lê enquanto o
        // assembly anda; precisa SEMPRE ser resolvido no fim (try/finally).
        $rootWorkspaceId = $this->identity->resolve($path);
        $this->statusReader->markRunning($rootWorkspaceId);

        try {
            return $this->assembleClassified($summary, $path, $force, $startedAt);
        } finally {
            $finalStatus = $summary['status'] ?? 'ok';
            if ($finalStatus === 'failed') {
                $this->statusReader->markFailed($rootWorkspaceId);
            } else {
                $this->statusReader->clearMarker($rootWorkspaceId);
            }
            $this->folderIntelligence->forget($path);
        }
    }

    /**
     * @param  array{status: string, workspace_path: string, repos: array<int, array<string, mixed>>}  $summary
     * @return array{status: string, workspace_path: string, repos: array<int, array<string, mixed>>}
     */
    private function assembleClassified(array &$summary, string $path, bool $force, float $startedAt): array
    {
        $intel = $this->folderIntelligence->inspect($path);
        $classification = (string) ($intel['classification'] ?? 'missing');

        // Quem indexar — alinhado ao canon de identidade (cobertura por prefixo):
        //   git_repository → o próprio repo.
        //   umbrella → UMA passada na árvore inteira (o grafo agregado do
        //     guarda-chuva, com root_path relativo por membro) + uma passada
        //     PRÓPRIA para cada filho com profile registrado (grafo isolado).
        //     Nunca indexar filho sem profile sob o wid do umbrella com prune —
        //     um membro apagaria os módulos do outro (risco §8 do AP-818).
        $repos = match ($classification) {
            'git_repository' => [['name' => basename($path), 'path' => $path]],
            'umbrella' => $this->umbrellaAssemblyTargets($path, $intel['children'] ?? []),
            default => [],
        };

        if ($repos === []) {
            $summary['status'] = 'skipped_not_indexable';
            $summary['classification'] = $classification;

            return $summary;
        }

        // W-11 staged: membros em série — nunca fan-out de processos pesados.
        foreach ($repos as $repo) {
            $summary['repos'][] = $this->assembleRepo((string) $repo['name'], (string) $repo['path'], $force);
        }

        $failed = array_filter($summary['repos'], static fn (array $r): bool => ($r['status'] ?? '') === 'failed');
        if ($failed !== [] && count($failed) === count($summary['repos'])) {
            $summary['status'] = 'failed';
        } elseif ($failed !== []) {
            $summary['status'] = 'partial';
        }

        $summary['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

        return $summary;
    }

    /**
     * Alvos de assembly de um guarda-chuva: a árvore inteira (grafo agregado,
     * keyado pelo wid do umbrella) primeiro, depois somente os filhos cujo
     * workspace_id resolve DIFERENTE do umbrella (profile próprio → grafo
     * isolado). Filhos cobertos pelo umbrella já entraram na passada da árvore.
     *
     * @param  array<int, array<string, mixed>>  $children
     * @return array<int, array{name: string, path: string}>
     */
    private function umbrellaAssemblyTargets(string $path, array $children): array
    {
        $umbrellaWorkspaceId = $this->identity->resolve($path);
        $targets = [['name' => basename($path), 'path' => $path]];

        foreach ($children as $child) {
            $childPath = (string) ($child['path'] ?? '');
            if ($childPath === '') {
                continue;
            }
            if ($this->identity->resolve($childPath) === $umbrellaWorkspaceId) {
                continue; // coberto pela passada da árvore — grafo do umbrella.
            }
            $targets[] = ['name' => (string) ($child['name'] ?? basename($childPath)), 'path' => $childPath];
        }

        return $targets;
    }

    /**
     * @return array<string, mixed>
     */
    private function assembleRepo(string $name, string $repoPath, bool $force): array
    {
        $startedAt = microtime(true);
        $workspaceId = $this->identity->resolve($repoPath);

        $row = [
            'name' => $name,
            'workspace_id' => $workspaceId,
            'privacy_class' => $this->privacy->classOf($workspaceId),
            'status' => 'pending',
        ];

        if (! $force && ! $this->statusReader->needsAssembly($workspaceId)) {
            $row['status'] = 'skipped_fresh';
            $this->statusReader->clearMarker($workspaceId);

            return $row;
        }

        // D-4: prune somente sobre o caminho dono do wid (escala para a raiz
        // do profile quando o repo é coberto por um guarda-chuva registrado).
        $indexPath = $this->canonicalIndexPath($workspaceId, $repoPath);
        if ($indexPath !== $repoPath) {
            $row['index_path_escalated'] = $indexPath;
        }

        $this->statusReader->markRunning($workspaceId);
        $this->recordEvidence('started', $workspaceId, "Assembly de inteligência iniciado para {$name}", [
            'workspace_path' => $repoPath,
            'index_path' => $indexPath,
        ]);

        $result = $this->lock->withLock($workspaceId, function () use ($indexPath, $workspaceId): array {
            // G-5 ANTES de ingerir — precedente blackink: chave real achada no prescan.
            $secrets = $this->secretPrescan($indexPath);

            $index = $this->codeIntelligence->index([
                'workspace' => $indexPath,
                'prune' => true,
            ]);

            $build = $this->symbolBuilder->build($workspaceId);

            return [
                'secrets' => $secrets,
                'modules' => (int) data_get($index, 'summary.module_count', 0),
                'symbols' => (int) data_get($index, 'summary.symbol_count', (int) data_get($index, 'symbol_count', 0)),
                'graph_status' => (string) ($build['status'] ?? 'unknown'),
                'graph_nodes' => (int) ($build['symbol_nodes'] ?? 0),
                'graph_edges' => (int) ($build['edges_written'] ?? 0),
            ];
        }, ttlSeconds: 1800);

        if (! $result['acquired']) {
            // Outro index está rodando neste workspace (W-10) — skip honesto.
            $row['status'] = 'locked';
            $this->statusReader->clearMarker($workspaceId);

            return $row;
        }

        try {
            $outcome = $result['result'];
            if (! is_array($outcome)) {
                throw new \RuntimeException('assembly_outcome_invalid');
            }

            $row = array_merge($row, $outcome, ['status' => 'ok']);
            $row['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

            $this->statusReader->recordIndexedSchemaVersion($workspaceId);
            $this->statusReader->clearMarker($workspaceId);

            $this->recordEvidence('completed', $workspaceId, "Assembly concluído para {$name}: {$row['modules']} módulos, {$row['symbols']} símbolos, grafo {$row['graph_status']}", [
                'workspace_path' => $repoPath,
                'modules' => $row['modules'],
                'symbols' => $row['symbols'],
                'graph_nodes' => $row['graph_nodes'],
                'graph_edges' => $row['graph_edges'],
                'secret_findings_high' => (int) data_get($row, 'secrets.high_severity', 0),
                'duration_ms' => $row['duration_ms'],
            ]);
        } catch (Throwable $e) {
            $row['status'] = 'failed';
            $row['error'] = substr($e->getMessage(), 0, 200);
            $row['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

            $this->statusReader->markFailed($workspaceId);
            $this->recordEvidence('failed', $workspaceId, "Assembly falhou para {$name}: {$row['error']}", [
                'workspace_path' => $repoPath,
                'error' => $row['error'],
                'duration_ms' => $row['duration_ms'],
            ]);
        }

        return $row;
    }

    /**
     * G-5 prescan bounded sobre os arquivos que SERÃO ingeridos. Fail-safe:
     * qualquer soluço de IO nunca bloqueia o assembly — o achado vai pra
     * evidence, a decisão de bloquear é do operador (mesma semântica do
     * index-all).
     *
     * @return array{files_scanned: int, findings: int, high_severity: int}
     */
    private function secretPrescan(string $repoPath): array
    {
        $files = 0;
        $findings = 0;
        $high = 0;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($repoPath, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if ($files >= self::PRESCAN_MAX_FILES) {
                    break;
                }
                $pathname = (string) $file->getPathname();
                if (preg_match(self::SKIP_DIRS_PATTERN, $pathname) === 1) {
                    continue;
                }
                if (! in_array(strtolower($file->getExtension()), self::INGESTED, true)) {
                    continue;
                }
                if ($file->getSize() > self::PRESCAN_MAX_FILE_BYTES) {
                    continue;
                }
                $files++;
                $scan = $this->secretScanner->scan((string) @file_get_contents($pathname));
                if (empty($scan['has_secrets'])) {
                    continue;
                }
                $findings += (int) ($scan['count'] ?? 0);
                foreach (($scan['findings'] ?? []) as $finding) {
                    if (($finding['severity'] ?? '') === 'high') {
                        $high++;
                    }
                }
            }
        } catch (Throwable) {
            // fail-safe — prescan parcial é melhor que assembly bloqueado.
        }

        return ['files_scanned' => $files, 'findings' => $findings, 'high_severity' => $high];
    }

    /**
     * Evidence best-effort no trilho canônico de tool-runs (AtlasToolEvidenceStore,
     * com receipt hash — o MESMO ledger onde o indexador grava cada index run).
     * O estágio do ciclo de vida (queued/started/completed/failed) vai em
     * metrics; indisponibilidade do ledger jamais derruba o assembly.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function recordEvidence(string $status, string $workspaceId, string $summary, array $metadata = []): void
    {
        try {
            $this->toolEvidence->recordExternalToolResult(self::EVIDENCE_TYPE, (string) ($metadata['workspace_path'] ?? $workspaceId), [
                'status' => $status === 'failed' ? 'failed' : 'passed',
                'required' => false,
                'failure_policy' => 'advisory',
                'policy_decision' => 'allowed',
                'duration_ms' => (int) ($metadata['duration_ms'] ?? 0),
                'exit_code' => $status === 'failed' ? 1 : 0,
                'category' => 'folder_intelligence',
                'metrics' => array_merge($metadata, [
                    'schema' => 'atlas.code.folder_intel_assembly.v1',
                    'lifecycle' => $status,
                    'workspace_id' => $workspaceId,
                    'summary' => $summary,
                ]),
            ]);
        } catch (Throwable) {
            // best-effort por contrato.
        }
    }
}

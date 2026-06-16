<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * L3-2 · Fonte de intents guiada pelo BACKLOG REAL do Atlas.
 *
 * O Marco Zero mediu 94% de waste: o Loop inventava melhorias genéricas porque o intent
 * que chega ao provider é vago ("melhore este arquivo"). Esta fonte vira a chave: em vez
 * de varrer arquivos ao acaso, ela emite alvos com OBJETIVO ESPECÍFICO derivado de
 * backlog real e endereçável:
 *
 *   1. MANIFESTO curado (`storage/app/atlas/loop/backlog-intents.json`) — itens explícitos
 *      {path, objective, priority} que o sistema ou o operador injeta (achados de sweep
 *      com file:line, resíduos de campanha, subsistemas com receipt fraco). Append-safe.
 *   2. CORPUS de falhas — os paths que reincidem no corpo de falhas reais (reusa o
 *      AtlasLoopEvidenceSignalService) viram intent "corrigir a falha recorrente em X".
 *
 * Cada candidato carrega um objetivo NOMEADO. A discovery, com a flag ON, os promove no
 * ranking (backlog_reach) — o Loop passa de "inventa tarefa" para "ataca o backlog real".
 * Read-only e fail-open: manifesto ausente/ilegível ⇒ lista vazia, nunca quebra.
 */
final class AtlasLoopBacklogIntentSource
{
    public const SCHEMA = 'atlas.loop.backlog_intent_source.v1';

    private const MANIFEST_REL = 'app/atlas/loop/backlog-intents.json';

    public function __construct(
        private readonly ?AtlasLoopEvidenceSignalService $evidence = null,
        private readonly ?AtlasLoopMetaHarnessIntentSource $metaHarness = null,
    ) {}

    /**
     * Candidatos de backlog com objetivo específico, ordenados por prioridade desc.
     *
     * @return list<array{path:string, objective:string, priority:float, source:string}>
     */
    public function candidates(string $repoRoot, int $limit = 12): array
    {
        $repoRoot = rtrim($repoRoot, '/');
        $out = [];

        foreach ($this->fromManifest($repoRoot) as $item) {
            $out[$item['path']] = $item; // dedup por path; manifesto vence o corpus
        }
        foreach ($this->fromFailureCorpus() as $item) {
            if (! isset($out[$item['path']])) {
                $out[$item['path']] = $item;
            }
        }
        // L6-1: a perna META — o Loop propõe melhoria do PRÓPRIO harness. Prioridade baixa
        // (perna de fundo), gated pela flag pétrea + flag desta perna, e passa pelo MESMO
        // chokepoint do HarnessGuard na discovery. Fail-closed: fonte ausente/flag OFF ⇒ no-op.
        foreach ($this->metaHarness?->candidates($repoRoot, $limit) ?? [] as $item) {
            if (! isset($out[$item['path']])) {
                $out[$item['path']] = $item;
            }
        }

        $rows = array_values($out);
        usort($rows, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);

        return array_slice($rows, 0, max(1, $limit));
    }

    /**
     * @return list<array{path:string, objective:string, priority:float, source:string}>
     */
    private function fromManifest(string $repoRoot): array
    {
        $path = $this->manifestPath();
        if ($path === null || ! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) @file_get_contents($path), true);
        if (! is_array($decoded)) {
            return [];
        }
        $items = is_array($decoded['items'] ?? null) ? $decoded['items'] : $decoded;

        $rows = [];
        foreach ($items as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $rel = ltrim(trim((string) ($raw['path'] ?? '')), '/');
            $objective = trim((string) ($raw['objective'] ?? ''));
            if ($rel === '' || $objective === '') {
                continue;
            }
            // Só admite alvos que existem na árvore (backlog endereçável, não fantasma).
            if (! is_file($repoRoot.'/'.$rel)) {
                continue;
            }
            $row = [
                'path' => $rel,
                'objective' => $objective,
                'priority' => $this->clamp01((float) ($raw['priority'] ?? 0.9)),
                'source' => trim((string) ($raw['source'] ?? 'manifest')) ?: 'manifest',
            ];
            // ACDE S1 — PRESERVE (never re-derive) the self-improvement marker the LossObserver already wrote
            // onto the manifest item. Without this the marker is silently stripped here and never reaches the
            // proposal, so the self-edit park gate can never fire. Additive: an ordinary item (no marker) keeps
            // the exact historical 4-key row shape (byte-identical); a self-improve item additionally carries
            // is_self_improvement=true + its human-frozen >=9 quality bar.
            if (($raw['is_self_improvement'] ?? false) === true) {
                $row['is_self_improvement'] = true;
                if (isset($raw['quality_bar_gate'])) {
                    $row['quality_bar_gate'] = $raw['quality_bar_gate'];
                }
                if (isset($raw['quality_bar'])) {
                    $row['quality_bar'] = $raw['quality_bar'];
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return list<array{path:string, objective:string, priority:float, source:string}>
     */
    private function fromFailureCorpus(): array
    {
        // O evidence service hoje só PESA paths dados (weights), não os ENUMERA. Enquanto
        // não existir um enumerador do corpus, esta perna fica fail-open (vazia) — o
        // manifesto curado é a fonte de backlog real e durável. Hook preservado para quando
        // `recurringPaths` existir (sem quebrar).
        if ($this->evidence === null || ! method_exists($this->evidence, 'recurringPaths')) {
            return [];
        }
        $weights = [];
        try {
            /** @phpstan-ignore-next-line method existence checked above */
            $weights = $this->evidence->recurringPaths(12);
        } catch (\Throwable) {
            return [];
        }

        $rows = [];
        foreach ($weights as $path => $weight) {
            $rel = ltrim((string) $path, '/');
            if ($rel === '') {
                continue;
            }
            $rows[] = [
                'path' => $rel,
                'objective' => 'Corrigir a falha recorrente registrada em '.$rel.' (corpus de evidência real).',
                'priority' => $this->clamp01(0.75 * (float) $weight + 0.2),
                'source' => 'failure_corpus',
            ];
        }

        return $rows;
    }

    private function manifestPath(): ?string
    {
        if (! function_exists('storage_path')) {
            return null;
        }

        return storage_path(self::MANIFEST_REL);
    }

    private function clamp01(float $v): float
    {
        return max(0.0, min(1.0, $v));
    }
}

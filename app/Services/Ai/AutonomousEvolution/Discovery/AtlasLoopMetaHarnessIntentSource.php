<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;

/**
 * L6-1 · A PERNA "o Loop propõe melhoria do PRÓPRIO harness".
 *
 * O L3-12 deu o guardrail (o Loop PODE tocar o harness não-segurança; o conjunto pétreo —
 * frozen judge, gates, never-merge, este guard — é INTOCÁVEL). O L6-1 EXERCITA: para o
 * braço `meta_harness` do medidor A/B sair de zero por conta própria, o Loop precisa de uma
 * FONTE determinística que ENFILEIRE tarefas de melhoria do harness não-segurança. Sem isto,
 * o braço meta nunca enche e o lift A/B fica soak-gated para sempre.
 *
 * Esta fonte é o produtor honesto desse braço:
 *   - DETERMINÍSTICA e PROVIDER-FREE: só enumera arquivos REAIS do harness na árvore;
 *   - PASSA PELO MESMO chokepoint do {@see AtlasLoopHarnessGuard}: cada candidato é filtrado
 *     pelo guard (admissível ⇔ harness não-proibido), e a discovery o re-filtra no chokepoint
 *     final — defense-in-depth, o conjunto pétreo NUNCA vira alvo;
 *   - FLAG-GATED DUPLO: precisa de `atlas.loop.meta_harness_targets` (a flag pétrea do L3-12)
 *     E de `atlas.loop.meta_harness_self_improve.enabled`. Qualquer uma OFF ⇒ lista vazia;
 *   - PRIORIDADE BAIXA: nunca starva o backlog ordinário — é a perna de fundo da fila;
 *   - FAIL-CLOSED: flag OFF, diretório ausente ou nenhum arquivo admissível ⇒ lista vazia.
 *
 * Cada item enfileirado tem `target_path` sob `app/Services/Ai/AutonomousEvolution/` (não
 * proibido), então o {@see \App\Services\Ai\AutonomousEvolution\AtlasLoopMetaHarnessAbLiftService}
 * o classifica no braço `meta_harness` automaticamente — o case_count vivo enche sozinho à
 * medida que o Loop roda.
 */
final class AtlasLoopMetaHarnessIntentSource
{
    public const SCHEMA = 'atlas.loop.meta_harness_intent_source.v1';

    /** Onde moram os arquivos do harness (relativo ao repo). */
    private const HARNESS_DIR = 'app/Services/Ai/AutonomousEvolution';

    public function __construct(
        private readonly ?AtlasLoopHarnessGuard $guard = null,
    ) {}

    /**
     * Intents de melhoria do harness não-segurança, ordenados determinísticamente.
     *
     * @return list<array{path:string, objective:string, priority:float, source:string}>
     */
    public function candidates(string $repoRoot, int $limit = 6): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $repoRoot = rtrim($repoRoot, '/');
        $base = $repoRoot.'/'.self::HARNESS_DIR;
        if (! is_dir($base)) {
            return [];
        }

        $guard = $this->guard ?? new AtlasLoopHarnessGuard();
        $priority = $this->clamp01((float) config('atlas.loop.meta_harness_self_improve.priority', 0.4));
        $cap = max(1, min(50, (int) config('atlas.loop.meta_harness_self_improve.max_candidates', 6)));

        $rows = [];
        foreach ($this->harnessPhpFiles($base) as $abs) {
            $rel = ltrim(str_replace($repoRoot.'/', '', $abs), '/');
            // O guard é a autoridade: harness não-segurança ⇒ admissible; pétreo ⇒ forbidden.
            // Só admite o que o guard libera com a flag meta ON.
            if ($guard->admit($rel, true) !== 'admissible') {
                continue;
            }
            if (! $guard->isHarnessTarget($rel)) {
                continue; // só o harness (paranoia: o glob já é escopado ao diretório)
            }
            $rows[$rel] = [
                'path' => $rel,
                'objective' => 'Melhorar o PRÓPRIO harness do Loop em '.basename($rel)
                    .' (cobrir edge-cases/guards faltantes; meta-melhoria L6-1, alvo de harness não-segurança).',
                'priority' => $priority,
                'source' => 'meta_harness_self_improve',
                'is_self_improvement' => true,
                'quality_bar' => (float) config('atlas.loop.quality_bar', 9.0),
            ];
        }

        // Ordem determinística por path (estável entre execuções), depois corta no cap.
        ksort($rows);

        return array_slice(array_values($rows), 0, min($cap, max(1, $limit)));
    }

    private function enabled(): bool
    {
        // Dupla trava: a flag pétrea do L3-12 (harness é alvo legítimo) E a flag desta perna.
        return (bool) config('atlas.loop.meta_harness_targets', false)
            && (bool) config('atlas.loop.meta_harness_self_improve.enabled', false);
    }

    /**
     * Arquivos PHP do harness, recursivos, escopados ao diretório do harness.
     *
     * @return list<string>
     */
    private function harnessPhpFiles(string $base): array
    {
        $out = [];
        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($it as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $out[] = $file->getPathname();
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $out;
    }

    private function clamp01(float $v): float
    {
        return max(0.0, min(1.0, $v));
    }
}

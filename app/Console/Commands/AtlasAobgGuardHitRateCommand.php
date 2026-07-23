<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Memory\AtlasMemoryRegistryService;
use App\Services\Ai\AtlasOpenBrainGuardService;
use App\Services\Ai\Brain\AtlasEvolutionDiary;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Console\Command;
use Throwable;

/**
 * WO-17-T0.2 — MEDIR o guard de decisão que JÁ existe (não reconstruir).
 *
 * Mede duas coisas sobre zonas com decisão registrada e publica no Diário
 * (Carta Regra 3) como entrada `evolucao-de-fase`:
 *
 *   1. CANDIDATE SET (antes/depois do T0.1) — para cada decisão, o conjunto de
 *      candidatos do recall a surfaceia CEGO (só priority+LIMIT) vs QUERY-AWARE
 *      (usando a própria decisão como pergunta)? O delta prova que o gargalo era
 *      retrieval — o núcleo do T0.2.
 *   2. GUARD REAL (end-to-end) — para cada decisão que nomeia um arquivo do repo,
 *      o `AtlasOpenBrainGuardService::evaluate()` surfaceia a decisão governante
 *      ao editar aquele arquivo? É o hit-rate do guard hoje.
 *
 * Read-only + local (zero provider spend). NÃO fabrica número: o N sai das
 * decisões reais; corpus pequeno (in-scope < LIMIT) ⇒ lift empírico limitado, e
 * o comando diz isso explicitamente (o salto determinístico está no teste).
 */
final class AtlasAobgGuardHitRateCommand extends Command
{
    protected $signature = 'atlas:aobg:guard-hitrate
        {--workspace= : workspace id/path scoping the decisions and the guard}
        {--limit=12 : candidate-set LIMIT to measure the blind vs query-aware cut}
        {--publish : write the measured hit-rate as an evolucao-de-fase entry in the Diary}
        {--json : machine-readable output}';

    protected $description = 'T0.2: measure the decision-guard hit-rate (blind vs query-aware candidate set + real guard) and publish it to the Evolution Diary.';

    public function handle(
        AtlasMemoryRegistryService $registry,
        AtlasOpenBrainGuardService $guard,
        AtlasEvolutionDiary $diary,
    ): int {
        if (! DatabaseTableAvailability::has('atlas_memory_entries')) {
            $this->warn('atlas_memory_entries indisponível — nada a medir.');

            return self::SUCCESS;
        }

        $workspace = (string) ($this->option('workspace') ?: (getcwd() ?: 'atlas-server'));
        $limit = max(1, (int) $this->option('limit'));

        $decisions = AtlasMemoryEntry::query()
            ->where('memory_type', 'decision')
            ->where('status', 'active')
            ->get();

        $context = ['workspace' => $workspace];
        $blindSet = $registry->relevantForContext($context, ['memory_type' => 'decision'], $limit);
        $inScope = $blindSet->count();

        $candidateBlindHits = 0;
        $candidateQueryHits = 0;
        $guardZones = 0;
        $guardHits = 0;
        $rows = [];

        foreach ($decisions as $decision) {
            $query = $this->queryFor($decision);
            if ($query === '') {
                continue;
            }

            $blind = $registry->relevantForContext($context, ['memory_type' => 'decision'], $limit);
            $aware = $registry->relevantForContext($context + ['query' => $query], ['memory_type' => 'decision'], $limit);
            $blindHas = $blind->contains(fn (AtlasMemoryEntry $e): bool => (string) $e->id === (string) $decision->id);
            $awareHas = $aware->contains(fn (AtlasMemoryEntry $e): bool => (string) $e->id === (string) $decision->id);
            $candidateBlindHits += $blindHas ? 1 : 0;
            $candidateQueryHits += $awareHas ? 1 : 0;

            $file = $this->resolveFile($decision);
            $guardHit = null;
            if ($file !== null) {
                $guardZones++;
                $guardHit = $this->guardSurfacesDecision($guard, $file, $query, $workspace);
                $guardHits += $guardHit ? 1 : 0;
            }

            $rows[] = [
                'title' => mb_substr((string) $decision->title, 0, 48),
                'blind' => $blindHas ? 'Y' : 'N',
                'query_aware' => $awareHas ? 'Y' : 'N',
                'file' => $file ?? '—',
                'guard' => $guardHit === null ? '—' : ($guardHit ? 'HIT' : 'miss'),
            ];
        }

        $measured = count($rows);
        $result = [
            'schema' => 'atlas.aobg.guard_hitrate.v1',
            'workspace' => $workspace,
            'limit' => $limit,
            'decisions_total' => $decisions->count(),
            'decisions_in_scope' => $inScope,
            'decisions_measured' => $measured,
            'candidate_hitrate_before_blind' => $this->rate($candidateBlindHits, $measured),
            'candidate_hitrate_after_query_aware' => $this->rate($candidateQueryHits, $measured),
            'guard_zones' => $guardZones,
            'guard_hitrate_now' => $this->rate($guardHits, $guardZones),
            'corpus_below_limit' => $inScope < $limit,
            'caveat' => $inScope < $limit
                ? 'corpus in-scope < LIMIT: candidate set não corta nada localmente — lift empírico limitado; o salto está provado por teste (AtlasGuardQueryAwareRecallTest).'
                : 'corpus in-scope >= LIMIT: o corte por priority é observável.',
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['decisão', 'cego', 'query-aware', 'arquivo', 'guard'], $rows);
            $this->line(sprintf(
                'candidate hit-rate  ANTES(cego)=%.2f  DEPOIS(query-aware)=%.2f  | guard hit-rate=%.2f (%d zonas)  | in-scope=%d limit=%d',
                $result['candidate_hitrate_before_blind'],
                $result['candidate_hitrate_after_query_aware'],
                $result['guard_hitrate_now'],
                $guardZones,
                $inScope,
                $limit,
            ));
            $this->line($result['caveat']);
        }

        if ((bool) $this->option('publish')) {
            $this->publish($diary, $result);
        }

        return self::SUCCESS;
    }

    /** Salient query term for a decision: title, else summary. */
    private function queryFor(AtlasMemoryEntry $decision): string
    {
        $q = trim((string) $decision->title);

        return $q !== '' ? $q : trim((string) $decision->summary);
    }

    /** Resolve a repo file the decision names (path or CamelCase class), or null. */
    private function resolveFile(AtlasMemoryEntry $decision): ?string
    {
        $text = (string) $decision->title.' '.(string) $decision->summary.' '.(string) $decision->body;

        if (preg_match('#\b(app|tests|config|database)/[A-Za-z0-9_/]+\.php\b#', $text, $m)) {
            return is_file(base_path($m[0])) ? $m[0] : null;
        }
        if (preg_match('/\b([A-Z][a-z0-9]+[A-Z][A-Za-z0-9]+)\b/', $text, $m)) {
            foreach (glob(base_path('app/**/'.$m[1].'.php'), GLOB_BRACE) ?: [] as $hit) {
                return ltrim(str_replace(base_path(), '', $hit), '/');
            }
        }

        return null;
    }

    private function guardSurfacesDecision(AtlasOpenBrainGuardService $guard, string $file, string $query, string $workspace): bool
    {
        try {
            $verdict = $guard->evaluate($file, ['workspace' => $workspace, 'diff' => $query]);

            return (bool) data_get($verdict, 'checks.decision_violation', false)
                || (int) data_get($verdict, 'counts.decisions', 0) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function rate(int $hits, int $total): float
    {
        return $total > 0 ? round($hits / $total, 4) : 0.0;
    }

    /** @param array<string,mixed> $result */
    private function publish(AtlasEvolutionDiary $diary, array $result): void
    {
        try {
            $entry = $diary->record(
                'evolucao-de-fase',
                sprintf(
                    'T0.2 guard hit-rate medido: candidate ANTES(cego)=%.2f DEPOIS(query-aware)=%.2f; guard real=%.2f (%d zonas); %d decisões medidas, in-scope=%d.',
                    $result['candidate_hitrate_before_blind'],
                    $result['candidate_hitrate_after_query_aware'],
                    $result['guard_hitrate_now'],
                    $result['guard_zones'],
                    $result['decisions_measured'],
                    $result['decisions_in_scope'],
                ),
                'T0.1 tornou relevantForContext query-aware mas recall() não repassava a pergunta ao candidate set — o guard ficava cego por construção. O forward de 1 linha (Hybrid::registryItems) religa o T0.1 ao guard. '.$result['caveat'],
                'commit + tests/Feature/Ai/AtlasGuardQueryAwareRecallTest.php',
            );
            $this->info('Diário: entrada '.($entry['id'] ?? '?').' (evolucao-de-fase) publicada.');
        } catch (Throwable $e) {
            $this->warn('Diário: falha ao publicar (fail-open): '.$e->getMessage());
        }
    }
}

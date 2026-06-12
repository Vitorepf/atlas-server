<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Models\AtlasLoopProposal;
use Illuminate\Support\Facades\DB;

/**
 * L3-1 · Funil instrumentado do Loop.
 *
 * O flywheel estava cego: 82 candidatas, 0 merges, e nenhuma visão de ONDE cada
 * unidade de trabalho parou. Este serviço lê os estados reais (tasks + proposals)
 * e devolve a contagem POR ESTÁGIO com as razões de parada — o instrumento que
 * transforma "0 merges" (narrativa) em "82 certificadas das quais N stale, M
 * drenáveis" (medição). Read-only, sem efeito colateral, fail-safe.
 *
 * Estágios (da descoberta ao merge):
 *   discovered → attempted → proposed → certified → drainable → merged
 * com dois ramos de saída honestos: retired_stale (diff não aplica mais) e
 * pending_tasks (descobertas ainda não atacadas).
 */
final class AtlasLoopFunnelService
{
    public const SCHEMA_VERSION = 'atlas.loop.funnel.v1';

    /**
     * @return array<string,mixed>
     */
    public function snapshot(?string $campaignId = null): array
    {
        $tasks = $this->taskCounts($campaignId);
        $proposals = $this->proposalCounts($campaignId);

        $discovered = array_sum($tasks);
        $attempted = ($tasks['done'] ?? 0) + ($tasks['running'] ?? 0);
        $pendingTasks = $tasks['pending'] ?? 0;

        $proposed = array_sum($proposals);
        $certified = $proposals[AtlasLoopProposal::STATUS_CERTIFIED] ?? 0;

        $drainable = $this->drainableCount($campaignId);
        $merged = $this->mergedCount($campaignId);
        $retiredStale = $this->retiredStaleCount($campaignId);

        $stages = [
            'discovered' => $discovered,
            'attempted' => $attempted,
            'proposed' => $proposed,
            'certified' => $certified,
            'drainable' => $drainable,
            'merged' => $merged,
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'campaign_id' => $campaignId,
            'stages' => $stages,
            'branches' => [
                'pending_tasks' => $pendingTasks,
                'retired_stale' => $retiredStale,
            ],
            'reasons' => [
                'tasks_by_status' => $tasks,
                'proposals_by_status' => $proposals,
            ],
            'conversion' => [
                'certified_to_merged' => $this->ratio($merged, $certified),
                'drainable_remaining' => $drainable,
            ],
            'verdict' => $this->verdict($certified, $drainable, $merged, $retiredStale),
        ];
    }

    /**
     * Frase honesta de uma linha: onde o flywheel está travado AGORA.
     */
    private function verdict(int $certified, int $drainable, int $merged, int $retiredStale): string
    {
        if ($certified === 0 && $merged === 0) {
            return 'sem propostas certificadas — descoberta/geração é o gargalo';
        }
        if ($drainable > 0 && $merged === 0) {
            return "{$drainable} certificadas drenáveis e 0 merges — o drain não rodou em cadência (gargalo de consumo)";
        }
        if ($merged > 0) {
            return "{$merged} merges em main; {$drainable} ainda drenáveis; {$retiredStale} aposentadas stale";
        }
        if ($drainable === 0 && $retiredStale > 0) {
            return "fila drenada: {$retiredStale} aposentadas (legadas/contractless, irreprováveis); aguardando propostas frescas com contrato do soak";
        }

        return 'flywheel em estado intermediário';
    }

    /**
     * @return array<string,int>
     */
    private function taskCounts(?string $campaignId): array
    {
        $q = DB::table('atlas_loop_tasks');
        if ($campaignId !== null) {
            $q->where('campaign_id', $campaignId);
        }

        return $q->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status')
            ->map(static fn ($v): int => (int) $v)->all();
    }

    /**
     * @return array<string,int>
     */
    private function proposalCounts(?string $campaignId): array
    {
        $q = DB::table('atlas_loop_proposals');
        if ($campaignId !== null) {
            $q->where('campaign_id', $campaignId);
        }

        return $q->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status')
            ->map(static fn ($v): int => (int) $v)->all();
    }

    private function drainableCount(?string $campaignId): int
    {
        $q = DB::table('atlas_loop_proposals')
            ->where('status', AtlasLoopProposal::STATUS_CERTIFIED)
            ->where('merged_to_main', false)
            ->whereNull('reviewed_at');
        if ($campaignId !== null) {
            $q->where('campaign_id', $campaignId);
        }

        return (int) $q->count();
    }

    private function mergedCount(?string $campaignId): int
    {
        $q = DB::table('atlas_loop_proposals')->where('merged_to_main', true);
        if ($campaignId !== null) {
            $q->where('campaign_id', $campaignId);
        }

        return (int) $q->count();
    }

    private function retiredStaleCount(?string $campaignId): int
    {
        $q = DB::table('atlas_loop_proposals')->where('merged_to_main', false)->whereNotNull('reviewed_at');
        if ($campaignId !== null) {
            $q->where('campaign_id', $campaignId);
        }

        return (int) $q->count();
    }

    private function ratio(int $num, int $den): float
    {
        if ($den <= 0) {
            return 0.0;
        }

        return round($num / $den, 4);
    }
}

<?php

namespace App\Services\Ai\Mission;

use App\Models\AiMission;
use App\Models\AiObjective;
use App\Models\AiWorkOrder;

/**
 * Step selection puro para o Follow-Through Loop.
 *
 * Não tem estado interno, não persiste, não cria efeito colateral.
 *
 * Regras canônicas:
 *   - `ready` é o status default de AiWorkOrder após `WorkOrderFactoryService`.
 *   - Estados terminais: `completed`, `failed`, `cancelled`, `simulated`,
 *     `handoff_dev`, `handoff_forge` (handoffs são considerados saída pra
 *     outra equipe — Follow-Through não os reprocessa no mesmo ciclo).
 *   - Bloqueados (`blocked`, `waiting_for_user`) NÃO são selecionados — o
 *     loop deve resolver o bloqueio antes.
 *   - Ordem: objective.priority ASC → work_order.created_at ASC.
 */
class WorkOrderSelectionService
{
    /**
     * Statuses considerados terminais (ciclo terminado para este WO).
     *
     * @var list<string>
     */
    public const TERMINAL_STATUSES = [
        'completed',
        'failed',
        'cancelled',
        'simulated',
        'handoff_dev',
        'handoff_forge',
    ];

    /**
     * Statuses bloqueados — Follow-Through NÃO seleciona estes, deve resolver
     * primeiro via blocker/repair branch.
     *
     * @var list<string>
     */
    public const BLOCKED_STATUSES = [
        'blocked',
        'waiting_for_user',
        'waiting_approval',
    ];

    /**
     * Statuses que indicam WO disponível pra próximo ciclo de execução.
     *
     * @var list<string>
     */
    public const SELECTABLE_STATUSES = [
        'ready',
        'pending',
        'in_progress',
    ];

    /**
     * Seleciona o próximo WorkOrder a ser processado pelo Follow-Through Loop.
     *
     * Algoritmo determinístico:
     *   1. Join AiWorkOrder ← AiObjective (mission_id).
     *   2. Filtro: status ∈ SELECTABLE_STATUSES.
     *   3. Ordem: objective.priority ASC, work_order.created_at ASC.
     *   4. Retorna o primeiro ou null se não há.
     */
    public function selectNext(AiMission $mission): ?AiWorkOrder
    {
        $orderedObjectiveIds = $mission->objectives()
            ->orderBy('priority')
            ->orderBy('created_at')
            ->pluck('id')
            ->all();

        if ($orderedObjectiveIds === []) {
            // Sem objectives → sem WO selecionável (mesmo que alguém criou WO orphan).
            return null;
        }

        $workOrders = $mission->workOrders()
            ->whereIn('status', self::SELECTABLE_STATUSES)
            ->orderBy('created_at')
            ->get();

        if ($workOrders->isEmpty()) {
            return null;
        }

        // Ordena pela ordem de objective.priority (priority do parent objective).
        $priorityIndex = array_flip($orderedObjectiveIds);
        $sorted = $workOrders->sortBy(function (AiWorkOrder $wo) use ($priorityIndex): int {
            $objectiveId = (string) ($wo->objective_id ?? '');

            return $priorityIndex[$objectiveId] ?? PHP_INT_MAX;
        })->values();

        return $sorted->first();
    }

    /**
     * Diagnóstico da seleção: quantos WO em cada bucket.
     *
     * @return array<string,mixed>
     */
    public function summary(AiMission $mission): array
    {
        $all = $mission->workOrders()->get();
        $selectable = 0;
        $blocked = 0;
        $terminal = 0;
        $other = 0;

        foreach ($all as $wo) {
            $status = (string) $wo->status;
            if (in_array($status, self::SELECTABLE_STATUSES, true)) {
                $selectable++;
            } elseif (in_array($status, self::BLOCKED_STATUSES, true)) {
                $blocked++;
            } elseif (in_array($status, self::TERMINAL_STATUSES, true)) {
                $terminal++;
            } else {
                $other++;
            }
        }

        return [
            'total' => $all->count(),
            'selectable' => $selectable,
            'blocked' => $blocked,
            'terminal' => $terminal,
            'other' => $other,
            'all_terminal' => $all->count() > 0 && $terminal === $all->count(),
            'has_selectable' => $selectable > 0,
        ];
    }

    /**
     * Resolve o objective alvo (parent) do WO selecionado, ou null.
     */
    public function objectiveFor(AiWorkOrder $workOrder): ?AiObjective
    {
        return $workOrder->objective()->first();
    }
}

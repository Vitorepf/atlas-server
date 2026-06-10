<?php

namespace App\Services\Ai\AutomationDomain;

use App\Models\AiAutomationEvolutionEvent;
use App\Models\AiAutomationPlan;
use App\Models\AiAutomationRun;
use App\Models\AiAutomationToolDecision;
use App\Services\Ai\Support\DatabaseTableAvailability;

/**
 * Read-only projection of the Automation / Tool Factory runtime for the
 * Atlas Control Plane (Meta 9). Returns a deterministic snapshot with
 * counters, status breakdowns and the most recent rows by kind.
 *
 * Tolerant: if any of the Automation tables is absent, the corresponding
 * block reports `status=missing` instead of throwing.
 */
class AutomationControlPlaneProjection
{
    public const SCHEMA = 'atlas.ai.automation_domain.control_plane.v1';

    /**
     * @return array<string,mixed>
     */
    public function snapshot(int $recentLimit = 10): array
    {
        return [
            'schema' => self::SCHEMA,
            'generated_at' => now()->toJSON(),
            'runs' => $this->runsBlock($recentLimit),
            'plans' => $this->plansBlock($recentLimit),
            'tool_decisions' => $this->toolDecisionsBlock($recentLimit),
            'evolution_events' => $this->evolutionBlock($recentLimit),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runsBlock(int $limit): array
    {
        if (! DatabaseTableAvailability::has('ai_automation_runs')) {
            return ['status' => 'missing', 'count' => 0, 'recent' => []];
        }

        $count = AiAutomationRun::query()->count();
        $byStatus = AiAutomationRun::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();
        $byKind = AiAutomationRun::query()
            ->selectRaw('run_kind, COUNT(*) as total')
            ->groupBy('run_kind')
            ->pluck('total', 'run_kind')
            ->all();

        $recent = AiAutomationRun::query()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(static fn ($r): array => [
                'id' => $r->id,
                'uuid' => $r->uuid,
                'run_kind' => $r->run_kind,
                'status' => $r->status,
                'mission_id' => $r->mission_id,
                'work_order_id' => $r->work_order_id,
                'next_action' => $r->next_action,
                'receipt_hash' => $r->receipt_hash,
                'created_at' => optional($r->created_at)->toJSON(),
            ])->all();

        return [
            'status' => 'ready',
            'count' => (int) $count,
            'by_status' => $byStatus,
            'by_kind' => $byKind,
            'recent' => $recent,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function plansBlock(int $limit): array
    {
        if (! DatabaseTableAvailability::has('ai_automation_plans')) {
            return ['status' => 'missing', 'count' => 0, 'recent' => []];
        }

        $count = AiAutomationPlan::query()->count();
        $byType = AiAutomationPlan::query()
            ->selectRaw('plan_type, COUNT(*) as total')
            ->groupBy('plan_type')
            ->pluck('total', 'plan_type')
            ->all();
        $byStatus = AiAutomationPlan::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();
        $blocked = (int) ($byStatus[AutomationDomainCanon::PLAN_STATUS_BLOCKED] ?? 0);

        $recent = AiAutomationPlan::query()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(static fn ($p): array => [
                'id' => $p->id,
                'plan_type' => $p->plan_type,
                'title' => $p->title,
                'status' => $p->status,
                'policy_decision' => $p->policy_decision,
                'plan_hash' => $p->plan_hash,
                'created_at' => optional($p->created_at)->toJSON(),
            ])->all();

        return [
            'status' => 'ready',
            'count' => (int) $count,
            'blocked' => $blocked,
            'by_type' => $byType,
            'by_status' => $byStatus,
            'recent' => $recent,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function toolDecisionsBlock(int $limit): array
    {
        if (! DatabaseTableAvailability::has('ai_automation_tool_decisions')) {
            return ['status' => 'missing', 'count' => 0, 'recent' => []];
        }

        $count = AiAutomationToolDecision::query()->count();
        $byKind = AiAutomationToolDecision::query()
            ->selectRaw('decision_kind, COUNT(*) as total')
            ->groupBy('decision_kind')
            ->pluck('total', 'decision_kind')
            ->all();

        $recent = AiAutomationToolDecision::query()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(static fn ($d): array => [
                'id' => $d->id,
                'need' => $d->need,
                'decision_kind' => $d->decision_kind,
                'selected_tool_id' => $d->selected_tool_id,
                'score' => (float) $d->score,
                'decision_hash' => $d->decision_hash,
                'created_at' => optional($d->created_at)->toJSON(),
            ])->all();

        return [
            'status' => 'ready',
            'count' => (int) $count,
            'by_kind' => $byKind,
            'recent' => $recent,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function evolutionBlock(int $limit): array
    {
        if (! DatabaseTableAvailability::has('ai_automation_evolution_events')) {
            return ['status' => 'missing', 'count' => 0, 'recent' => []];
        }

        $count = AiAutomationEvolutionEvent::query()->count();
        $byKind = AiAutomationEvolutionEvent::query()
            ->selectRaw('event_kind, COUNT(*) as total')
            ->groupBy('event_kind')
            ->pluck('total', 'event_kind')
            ->all();

        $recent = AiAutomationEvolutionEvent::query()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(static fn ($e): array => [
                'id' => $e->id,
                'tool_id' => $e->tool_id,
                'event_kind' => $e->event_kind,
                'recommendation' => $e->recommendation,
                'event_hash' => $e->event_hash,
                'created_at' => optional($e->created_at)->toJSON(),
            ])->all();

        return [
            'status' => 'ready',
            'count' => (int) $count,
            'by_kind' => $byKind,
            'recent' => $recent,
        ];
    }
}

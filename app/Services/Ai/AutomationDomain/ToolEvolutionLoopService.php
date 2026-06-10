<?php

namespace App\Services\Ai\AutomationDomain;

use App\Models\AiAutomationEvolutionEvent;
use App\Models\AiAutomationRun;
use App\Models\AiToolDefinition;
use App\Models\AiToolInvocation;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;

/**
 * Observes Tool Runtime invocations and emits canonical evolution events:
 *
 *   success_streak | failure_spike | decay | new_version_available |
 *   retire | promote
 *
 * Tolerant: when `ai_tool_invocations` is absent (Meta 5 missing), the loop
 * accepts in-memory observation samples instead of querying the DB. Either
 * way, every emitted event is hash-stamped and reproducible.
 */
class ToolEvolutionLoopService
{
    public const SUCCESS_STREAK_THRESHOLD = 5;

    public const FAILURE_SPIKE_THRESHOLD = 2;

    public const DECAY_USAGE_WINDOW_DAYS = 30;

    /**
     * @param  array<string,mixed>  $args
     */
    public function emitEvent(array $args, ?AiAutomationRun $run = null): AiAutomationEvolutionEvent
    {
        $toolId = (string) ($args['tool_id'] ?? '');
        if ($toolId === '') {
            throw AutomationDomainException::missingField('tool_id');
        }
        $eventKind = (string) ($args['event_kind'] ?? '');
        if (! in_array($eventKind, AutomationDomainCanon::EVOLUTION_EVENT_KINDS, true)) {
            throw AutomationDomainException::invalidEvolutionEventKind($eventKind);
        }

        $observation = (array) ($args['observation'] ?? []);
        $recommendation = $args['recommendation'] ?? $this->defaultRecommendation($eventKind);

        $hashInput = [
            'tool_id' => $toolId,
            'event_kind' => $eventKind,
            'observation' => $observation,
            'recommendation' => $recommendation,
        ];

        return AiAutomationEvolutionEvent::query()->create([
            'uuid' => (string) Str::uuid(),
            'automation_run_id' => $run?->id,
            'tool_id' => $toolId,
            'event_kind' => $eventKind,
            'observation' => $observation,
            'recommendation' => $recommendation,
            'event_hash' => AutomationCanonicalHash::sha256($hashInput),
        ]);
    }

    /**
     * Sweep recent Tool Runtime invocations and emit evolution events when
     * thresholds fire. Returns the list of newly-emitted events.
     *
     * @return array<int,AiAutomationEvolutionEvent>
     */
    public function observeFromInvocations(?AiAutomationRun $run = null, int $sampleLimit = 200): array
    {
        if (! DatabaseTableAvailability::all(['ai_tool_invocations', 'ai_tool_definitions'])) {
            return [];
        }

        try {
            $invocations = AiToolInvocation::query()
                ->orderByDesc('created_at')
                ->limit($sampleLimit)
                ->get();
        } catch (\Throwable) {
            return [];
        }

        if ($invocations->isEmpty()) {
            return [];
        }

        $byTool = $invocations->groupBy('tool_definition_id');
        $events = [];
        foreach ($byTool as $toolDefinitionId => $rows) {
            $tool = AiToolDefinition::query()->find($toolDefinitionId);
            if ($tool === null) {
                continue;
            }
            $toolId = (string) $tool->tool_id;
            $passed = $rows->where('invocation_status', 'passed')->count();
            $failed = $rows->where('invocation_status', 'failed')->count();
            $blocked = $rows->where('invocation_status', 'blocked')->count();
            $total = $rows->count();

            if ($passed >= self::SUCCESS_STREAK_THRESHOLD && $failed === 0) {
                $events[] = $this->emitEvent([
                    'tool_id' => $toolId,
                    'event_kind' => AutomationDomainCanon::EVOLUTION_SUCCESS_STREAK,
                    'observation' => ['passed' => $passed, 'failed' => 0, 'sample_size' => $total],
                    'recommendation' => AutomationDomainCanon::EVOLUTION_RECOMMENDATION_KEEP,
                ], $run);
            } elseif ($failed >= self::FAILURE_SPIKE_THRESHOLD && $failed > $passed) {
                $events[] = $this->emitEvent([
                    'tool_id' => $toolId,
                    'event_kind' => AutomationDomainCanon::EVOLUTION_FAILURE_SPIKE,
                    'observation' => ['passed' => $passed, 'failed' => $failed, 'sample_size' => $total],
                    'recommendation' => AutomationDomainCanon::EVOLUTION_RECOMMENDATION_REPLACE,
                ], $run);
            } elseif ($blocked > 0 && $passed === 0 && $failed === 0) {
                $events[] = $this->emitEvent([
                    'tool_id' => $toolId,
                    'event_kind' => AutomationDomainCanon::EVOLUTION_DECAY,
                    'observation' => ['blocked' => $blocked, 'sample_size' => $total, 'reason' => 'policy_blocks_only'],
                    'recommendation' => AutomationDomainCanon::EVOLUTION_RECOMMENDATION_MONITOR,
                ], $run);
            }
        }

        return $events;
    }

    private function defaultRecommendation(string $eventKind): string
    {
        return match ($eventKind) {
            AutomationDomainCanon::EVOLUTION_SUCCESS_STREAK => AutomationDomainCanon::EVOLUTION_RECOMMENDATION_KEEP,
            AutomationDomainCanon::EVOLUTION_FAILURE_SPIKE => AutomationDomainCanon::EVOLUTION_RECOMMENDATION_REPLACE,
            AutomationDomainCanon::EVOLUTION_DECAY => AutomationDomainCanon::EVOLUTION_RECOMMENDATION_MONITOR,
            AutomationDomainCanon::EVOLUTION_NEW_VERSION_AVAILABLE => AutomationDomainCanon::EVOLUTION_RECOMMENDATION_UPDATE,
            AutomationDomainCanon::EVOLUTION_RETIRE => AutomationDomainCanon::EVOLUTION_RECOMMENDATION_RETIRE,
            AutomationDomainCanon::EVOLUTION_PROMOTE => AutomationDomainCanon::EVOLUTION_RECOMMENDATION_KEEP,
            default => AutomationDomainCanon::EVOLUTION_RECOMMENDATION_MONITOR,
        };
    }
}

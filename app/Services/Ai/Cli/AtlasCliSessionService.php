<?php

namespace App\Services\Ai\Cli;

use App\Models\AiCompaction;
use App\Models\AiProviderHandoff;
use App\Models\AiSession;
use App\Models\AiSessionState;
use App\Models\AiThread;
use App\Models\AiTrace;
use App\Services\Ai\AiCompactionService;
use App\Services\Ai\AiProviderHandoffService;
use App\Services\Ai\AiSessionStateService;
use Illuminate\Support\Facades\Schema;

class AtlasCliSessionService
{
    public function __construct(
        private readonly AiSessionStateService $states,
        private readonly AiCompactionService $compactions,
        private readonly AiProviderHandoffService $handoffs,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function snapshot(string $workspace, ?string $threadId = null): array
    {
        $workspace = $this->workspace($workspace);
        $thread = $this->resolveThread($workspace, $threadId);

        if (! $thread) {
            return [
                'workspace' => $workspace,
                'thread' => null,
                'session' => null,
                'state' => null,
                'latest_compaction' => null,
                'latest_provider_handoff' => null,
            ];
        }

        $session = $this->activeSession($thread);
        $state = $this->activeState($thread, $session);
        $compaction = $this->latestCompaction($thread);
        $handoff = $this->latestHandoff($thread);

        return [
            'workspace' => $workspace,
            'thread' => $this->threadPayload($thread),
            'session' => $session ? $this->sessionPayload($session) : null,
            'state' => $state ? $this->statePayload($state) : null,
            'latest_compaction' => $compaction ? $this->compactionPayload($compaction) : null,
            'latest_provider_handoff' => $handoff ? $this->handoffPayload($handoff) : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $changes
     * @return array<string,mixed>
     */
    public function update(string $workspace, ?string $threadId, array $changes): array
    {
        $workspace = $this->workspace($workspace);
        $thread = $this->requireThread($workspace, $threadId);
        $session = $this->activeSession($thread);
        $state = $this->states->activeState($thread, $session);

        $metadata = $state->metadata ?? [];
        $notes = array_values(array_filter((array) data_get($changes, 'notes', []), 'is_string'));
        if ($notes !== []) {
            $existingNotes = is_array($metadata['operator_notes'] ?? null) ? $metadata['operator_notes'] : [];
            foreach ($notes as $note) {
                $existingNotes[] = [
                    'text' => $note,
                    'source' => 'atlas_cli',
                    'captured_at' => now()->toJSON(),
                ];
            }
            $metadata['operator_notes'] = array_slice($existingNotes, -24);
        }

        $update = [
            'version' => $state->version + 1,
            'metadata' => array_merge($metadata, [
                'last_update_source' => 'atlas_cli_manual',
                'last_manual_update_at' => now()->toJSON(),
            ]),
        ];

        foreach ([
            'objective' => 'objective',
            'phase' => 'current_phase',
            'topic' => 'current_topic',
            'position' => 'user_position',
        ] as $input => $column) {
            $value = data_get($changes, $input);
            if (is_string($value) && trim($value) !== '') {
                $update[$column] = trim($value);
            }
        }

        foreach ([
            'decisions' => 'decisions',
            'open_loops' => 'open_loops',
            'next_steps' => 'next_steps',
            'artifacts' => 'relevant_artifacts',
            'constraints' => 'constraints',
        ] as $input => $column) {
            $items = array_values(array_filter((array) data_get($changes, $input, []), 'is_string'));
            if ($items !== []) {
                $update[$column] = $this->mergeTextItems($state->{$column} ?? [], $items);
            }
        }

        $state->update($update);

        return $this->snapshot($workspace, $thread->id);
    }

    /**
     * @return array<string,mixed>
     */
    public function compact(string $workspace, ?string $threadId = null): array
    {
        $workspace = $this->workspace($workspace);
        $thread = $this->requireThread($workspace, $threadId);
        $session = $this->activeSession($thread);
        $compaction = $this->compactions->compact($thread, $session, 'manual', [
            'source' => 'atlas_cli',
        ]);

        return array_merge($this->snapshot($workspace, $thread->id), [
            'created_compaction' => $this->compactionPayload($compaction),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function handoff(string $workspace, ?string $threadId, string $toProvider, bool $compact = true): array
    {
        $workspace = $this->workspace($workspace);
        $thread = $this->requireThread($workspace, $threadId);
        $session = $this->requireSession($thread);
        $compaction = $compact ? $this->compactions->compact($thread, $session, 'provider_switch', [
            'source' => 'atlas_cli_handoff',
            'to_provider' => $toProvider,
        ]) : null;
        $handoff = $this->handoffs->create(
            thread: $thread,
            session: $session,
            toProvider: $toProvider,
            fromProvider: $thread->last_provider ?: $session->provider_last,
            reason: 'manual_cli_handoff',
            compaction: $compaction,
            metadata: ['source' => 'atlas_cli'],
        );

        return array_merge($this->snapshot($workspace, $thread->id), [
            'created_compaction' => $compaction ? $this->compactionPayload($compaction) : null,
            'created_provider_handoff' => $this->handoffPayload($handoff),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function setPendingSteer(string $workspace, ?string $threadId, string $message): array
    {
        $workspace = $this->workspace($workspace);
        $thread = $this->requireThread($workspace, $threadId);
        $state = $this->states->setPendingSteer($thread->id, $message);

        return array_merge($this->snapshot($workspace, $thread->id), [
            'pending_steer_set' => [
                'state_id' => $state->id,
                'version' => $state->version,
            ],
        ]);
    }

    /**
     * @return array{cancelled:bool,trace_id:?string,thread_id:?string,provider:?string,phase:?string}
     */
    public function cancelActiveTrace(string $workspace, ?string $threadId = null): array
    {
        $workspace = $this->workspace($workspace);
        if (! Schema::hasTable('ai_traces') || ! Schema::hasTable('ai_threads')) {
            return ['cancelled' => false, 'trace_id' => null, 'thread_id' => null, 'provider' => null, 'phase' => null];
        }

        $query = AiTrace::query()
            ->whereIn('status', ['queued', 'processing'])
            ->latest('updated_at');

        if ($threadId) {
            $query->where('thread_id', $threadId);
        } else {
            $query->whereHas('thread', function ($threadQuery) use ($workspace): void {
                $threadQuery
                    ->where('surface', 'atlas_cli')
                    ->where('workspace', $workspace)
                    ->where('status', 'active');
            });
        }

        $trace = $query->first();
        if (! $trace) {
            return ['cancelled' => false, 'trace_id' => null, 'thread_id' => null, 'provider' => null, 'phase' => null];
        }

        $trace->update([
            'status' => 'cancelled',
            'completed_at' => now(),
            'metadata' => array_merge($trace->metadata ?? [], [
                'cancelled_by' => 'atlas_cli_interrupt',
                'cancelled_at' => now()->toJSON(),
            ]),
        ]);

        if (Schema::hasTable('ai_jobs')) {
            $trace->jobs()->whereIn('status', ['queued', 'processing'])->update([
                'status' => 'cancelled',
                'finished_at' => now(),
                'error_code' => 'cancelled_by_operator',
                'error_message' => 'Interrompido pelo operador via atlas interrupt.',
            ]);
        }

        $phase = (string) data_get($trace->metadata ?? [], 'dev_execution_plan.current_phase');

        return [
            'cancelled' => true,
            'trace_id' => (string) $trace->id,
            'thread_id' => (string) $trace->thread_id,
            'provider' => (string) ($trace->provider ?: ''),
            'phase' => $phase !== '' ? $phase : null,
        ];
    }

    /**
     * @return array{plan_id:string,task:string,workspace:string,thread_id:string,trace_id:string,reason:string,operator_options:array<string,mixed>}|null
     */
    public function findResumablePlan(string $workspace, ?string $threadId = null): ?array
    {
        $workspace = $this->workspace($workspace);
        if (! Schema::hasTable('ai_traces') || ! Schema::hasTable('ai_threads')) {
            return null;
        }

        $query = AiTrace::query()
            ->whereHas('thread', function ($threadQuery) use ($workspace): void {
                $threadQuery
                    ->where('surface', 'atlas_cli')
                    ->where('workspace', $workspace);
            })
            ->whereNotNull('metadata')
            ->latest('updated_at');

        if ($threadId) {
            $query->where('thread_id', $threadId);
        }

        foreach ($query->limit(20)->get() as $trace) {
            $plan = data_get($trace->metadata ?? [], 'dev_execution_plan');
            if (! is_array($plan)) {
                continue;
            }
            $planId = (string) ($plan['plan_id'] ?? '');
            $objective = (string) ($plan['objective'] ?? '');
            if ($planId === '' || $objective === '') {
                continue;
            }
            $currentPhase = (string) ($plan['current_phase'] ?? '');
            $reasonStopped = (string) data_get($plan, 'iterations.reason_if_stopped', '');
            $traceCancelled = $trace->status === 'cancelled';
            $unfinished = $currentPhase !== 'finish' || $reasonStopped !== '' || $traceCancelled;
            if (! $unfinished) {
                continue;
            }

            $reason = $traceCancelled
                ? 'interrompido'
                : ($reasonStopped !== '' ? $reasonStopped : 'fase '.$currentPhase);

            return [
                'plan_id' => $planId,
                'task' => $objective,
                'workspace' => (string) ($plan['workspace'] ?? $workspace),
                'thread_id' => (string) $trace->thread_id,
                'trace_id' => (string) $trace->id,
                'reason' => $reason,
                'operator_options' => is_array($plan['operator_options'] ?? null) ? (array) $plan['operator_options'] : [],
            ];
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    public function clearPendingSteer(string $workspace, ?string $threadId): array
    {
        $workspace = $this->workspace($workspace);
        $thread = $this->requireThread($workspace, $threadId);
        $state = $this->states->clearPendingSteer($thread->id);

        return array_merge($this->snapshot($workspace, $thread->id), [
            'pending_steer_cleared' => [
                'state_id' => $state->id,
                'version' => $state->version,
            ],
        ]);
    }

    private function resolveThread(string $workspace, ?string $threadId = null): ?AiThread
    {
        if (! Schema::hasTable('ai_threads')) {
            return null;
        }

        if ($threadId) {
            return AiThread::query()
                ->whereKey($threadId)
                ->where('surface', 'atlas_cli')
                ->first();
        }

        return AiThread::query()
            ->where('surface', 'atlas_cli')
            ->where('workspace', $workspace)
            ->where('status', 'active')
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at')
            ->first();
    }

    private function requireThread(string $workspace, ?string $threadId = null): AiThread
    {
        $thread = $this->resolveThread($workspace, $threadId);
        if (! $thread) {
            throw new \RuntimeException('Nenhuma thread Atlas CLI ativa neste workspace.');
        }

        return $thread;
    }

    private function activeSession(AiThread $thread): ?AiSession
    {
        if (! Schema::hasTable('ai_sessions')) {
            return null;
        }

        return AiSession::query()
            ->where('thread_id', $thread->id)
            ->where('status', 'active')
            ->latest('started_at')
            ->first();
    }

    private function requireSession(AiThread $thread): AiSession
    {
        $session = $this->activeSession($thread);
        if (! $session) {
            throw new \RuntimeException('Nenhuma sessao ativa para esta thread.');
        }

        return $session;
    }

    private function activeState(AiThread $thread, ?AiSession $session): ?AiSessionState
    {
        if (! Schema::hasTable('ai_session_states')) {
            return null;
        }

        return $this->states->activeState($thread, $session);
    }

    private function latestCompaction(AiThread $thread): ?AiCompaction
    {
        if (! Schema::hasTable('ai_compactions')) {
            return null;
        }

        return AiCompaction::query()
            ->where('thread_id', $thread->id)
            ->latest('created_at')
            ->first();
    }

    private function latestHandoff(AiThread $thread): ?AiProviderHandoff
    {
        if (! Schema::hasTable('ai_provider_handoffs')) {
            return null;
        }

        return AiProviderHandoff::query()
            ->where('thread_id', $thread->id)
            ->latest('created_at')
            ->first();
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function mergeTextItems(array $existing, array $incoming): array
    {
        $items = collect($existing)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->values();

        foreach ($incoming as $text) {
            $items->push([
                'text' => trim($text),
                'source' => 'atlas_cli',
                'captured_at' => now()->toJSON(),
            ]);
        }

        return $items
            ->unique(fn (array $item): string => mb_strtolower((string) ($item['text'] ?? '')))
            ->take(-24)
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function threadPayload(AiThread $thread): array
    {
        return [
            'id' => $thread->id,
            'title' => $thread->title,
            'summary' => $thread->summary,
            'status' => $thread->status,
            'provider' => $thread->last_provider,
            'message_count' => $thread->message_count,
            'last_message_at' => $thread->last_message_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sessionPayload(AiSession $session): array
    {
        return [
            'id' => $session->id,
            'status' => $session->status,
            'purpose' => $session->purpose,
            'provider_primary' => $session->provider_primary,
            'provider_last' => $session->provider_last,
            'message_count' => $session->message_count,
            'started_at' => $session->started_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function statePayload(AiSessionState $state): array
    {
        return [
            'id' => $state->id,
            'version' => $state->version,
            'objective' => $state->objective,
            'current_phase' => $state->current_phase,
            'current_topic' => $state->current_topic,
            'user_position' => $state->user_position,
            'decisions' => $state->decisions ?? [],
            'open_loops' => $state->open_loops ?? [],
            'next_steps' => $state->next_steps ?? [],
            'relevant_artifacts' => $state->relevant_artifacts ?? [],
            'constraints' => $state->constraints ?? [],
            'pending_steer' => $state->pending_steer,
            'provider_context' => $state->provider_context ?? [],
            'operator_notes' => data_get($state->metadata, 'operator_notes', []),
            'updated_at' => $state->updated_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function compactionPayload(AiCompaction $compaction): array
    {
        return [
            'id' => $compaction->id,
            'reason' => $compaction->reason,
            'quality_gate_status' => $compaction->quality_gate_status,
            'source_message_count' => $compaction->source_message_count,
            'token_estimate_before' => $compaction->token_estimate_before,
            'token_estimate_after' => $compaction->token_estimate_after,
            'summary' => $compaction->summary,
            'created_at' => $compaction->created_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function handoffPayload(AiProviderHandoff $handoff): array
    {
        return [
            'id' => $handoff->id,
            'from_provider' => $handoff->from_provider,
            'to_provider' => $handoff->to_provider,
            'reason' => $handoff->reason,
            'brief_text' => $handoff->brief_text,
            'compaction_id' => $handoff->compaction_id,
            'created_at' => $handoff->created_at?->toJSON(),
        ];
    }

    private function workspace(string $workspace): string
    {
        return realpath($workspace) ?: $workspace;
    }
}

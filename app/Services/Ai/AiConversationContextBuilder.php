<?php

namespace App\Services\Ai;

use App\Models\AiCompaction;
use App\Models\AiMessage;
use App\Models\AiProviderHandoff;
use App\Models\AiSessionState;
use App\Models\AiThread;
use App\Services\Ai\Context\ConversationContextInput;
use Illuminate\Support\Str;

class AiConversationContextBuilder
{
    public function __construct(private readonly ConversationContextInput $input) {}

    public function build(array $options): array
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $threadId = $this->threadId($options, $payload);
        $payloadTurns = $this->payloadTurns($payload);

        if (! $threadId) {
            return [
                'thread_id' => null,
                'thread_title' => null,
                'thread_summary' => null,
                'active_state' => null,
                'latest_compaction' => null,
                'latest_provider_handoff' => null,
                'source' => $payloadTurns ? 'payload_conversation_context' : 'none',
                'instruction' => $this->instruction($payload),
                'recent_turns' => $payloadTurns,
            ];
        }

        $thread = AiThread::query()->find($threadId);
        if (! $thread) {
            return [
                'thread_id' => $threadId,
                'thread_title' => null,
                'thread_summary' => null,
                'active_state' => null,
                'latest_compaction' => null,
                'latest_provider_handoff' => null,
                'source' => $payloadTurns ? 'payload_conversation_context' : 'missing_thread',
                'instruction' => $this->instruction($payload),
                'recent_turns' => $payloadTurns,
            ];
        }

        $state = AiSessionState::query()
            ->where('thread_id', $thread->id)
            ->where('active', true)
            ->latest('updated_at')
            ->first();

        $compaction = AiCompaction::query()
            ->where('thread_id', $thread->id)
            ->latest('created_at')
            ->first();

        $messages = $this->recentMessages($thread, $compaction);

        $handoff = AiProviderHandoff::query()
            ->where('thread_id', $thread->id)
            ->latest('created_at')
            ->first();

        $turns = $messages->map(fn (AiMessage $message): array => [
            'role' => $message->role === 'summary' ? 'assistant' : $message->role,
            'text' => Str::limit(trim($message->content), $message->role === 'summary' ? 2400 : 1800, '...'),
            'provider' => $message->provider,
            'trace_id' => $message->trace_id,
            'message_id' => $message->id,
            'position' => $message->position,
        ])->all();

        return [
            'thread_id' => $thread->id,
            'thread_title' => $thread->title,
            'thread_summary' => $thread->summary,
            'active_state' => $state ? [
                'id' => $state->id,
                'session_id' => $state->session_id,
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
                'provider_context' => $state->provider_context ?? [],
                'updated_at' => $state->updated_at?->toJSON(),
            ] : null,
            'latest_compaction' => $compaction ? [
                'id' => $compaction->id,
                'reason' => $compaction->reason,
                'summary' => $compaction->summary,
                'source_position_end' => $compaction->source_position_end,
                'quality_gate_status' => $compaction->quality_gate_status,
                'created_at' => $compaction->created_at?->toJSON(),
            ] : null,
            'latest_provider_handoff' => $handoff ? [
                'id' => $handoff->id,
                'from_provider' => $handoff->from_provider,
                'to_provider' => $handoff->to_provider,
                'reason' => $handoff->reason,
                'brief_text' => $handoff->brief_text,
                'created_at' => $handoff->created_at?->toJSON(),
            ] : null,
            'context_window' => $this->contextWindow($messages, $compaction),
            'source' => $this->source($turns, $payloadTurns, $compaction),
            'instruction' => $this->instruction($payload),
            'recent_turns' => $turns ?: $payloadTurns,
        ];
    }

    private function recentMessages(AiThread $thread, ?AiCompaction $compaction)
    {
        $limit = $this->recentTurnLimit();
        $query = AiMessage::query()
            ->where('thread_id', $thread->id)
            ->whereIn('role', ['user', 'assistant', 'summary'])
            ->where('status', '!=', 'redacted');

        if ($compaction?->source_position_end) {
            $query->where('position', '>', $compaction->source_position_end);
        }

        return $query
            ->orderByDesc('position')
            ->limit($limit)
            ->get()
            ->sortBy('position')
            ->values();
    }

    private function contextWindow($messages, ?AiCompaction $compaction): array
    {
        return [
            'mode' => $compaction?->source_position_end ? 'after_latest_compaction' : 'recent_tail',
            'recent_turn_limit' => $this->recentTurnLimit(),
            'messages_included' => $messages->count(),
            'compacted_through_position' => $compaction?->source_position_end,
            'first_position' => $messages->min('position'),
            'last_position' => $messages->max('position'),
        ];
    }

    private function source(array $turns, array $payloadTurns, ?AiCompaction $compaction): string
    {
        if ($turns) {
            return $compaction?->source_position_end ? 'ai_messages_after_compaction' : 'ai_messages';
        }

        if ($payloadTurns) {
            return 'payload_conversation_context';
        }

        return $compaction?->source_position_end ? 'latest_compaction_only' : 'thread_without_messages';
    }

    private function threadId(array $options, array $payload): ?string
    {
        foreach ([
            $options['thread_id'] ?? null,
            data_get($payload, 'thread_id'),
            data_get($payload, 'conversation_context.thread_id'),
        ] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function payloadTurns(array $payload): array
    {
        $turns = data_get($payload, 'conversation_context.turns', []);
        if (! is_array($turns)) {
            return [];
        }

        return collect($turns)
            ->filter(fn (mixed $turn): bool => is_array($turn) && is_string($turn['text'] ?? null) && trim($turn['text']) !== '')
            ->take(-$this->payloadTurnLimit())
            ->map(fn (array $turn): array => [
                'role' => in_array(($turn['role'] ?? ''), ['user', 'assistant'], true) ? $turn['role'] : 'user',
                'text' => Str::limit(trim((string) $turn['text']), 1800, '...'),
                'provider' => is_string($turn['provider'] ?? null) ? $turn['provider'] : null,
                'trace_id' => is_string($turn['trace_id'] ?? null) ? $turn['trace_id'] : null,
            ])
            ->values()
            ->all();
    }

    private function instruction(array $payload): string
    {
        $instruction = data_get($payload, 'conversation_context.instruction');

        return is_string($instruction) && trim($instruction) !== ''
            ? trim($instruction)
            : 'Use este contexto para resolver referencias curtas, continuidade de trabalho e troca de provider.';
    }

    private function recentTurnLimit(): int
    {
        return $this->input->recentTurnLimit();
    }

    private function payloadTurnLimit(): int
    {
        return $this->input->payloadTurnLimit();
    }
}

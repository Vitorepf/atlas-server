<?php

namespace App\Services\Ai;

use App\Models\AiCompaction;
use App\Models\AiMessage;
use App\Models\AiProviderHandoff;
use App\Models\AiSession;
use App\Models\AiSessionState;
use App\Models\AiThread;
use Illuminate\Support\Str;

class AiProviderHandoffService
{
    public function createIfSwitching(AiThread $thread, AiSession $session, ?string $toProvider, ?AiCompaction $compaction = null, array $metadata = []): ?AiProviderHandoff
    {
        if (! $toProvider || $toProvider === 'claude_codex') {
            return null;
        }

        $fromProvider = $thread->last_provider ?: $session->provider_last;
        if (! $fromProvider || $fromProvider === $toProvider || $fromProvider === 'claude_codex') {
            return null;
        }

        return $this->create($thread, $session, $toProvider, $fromProvider, 'provider_switch', $compaction, $metadata);
    }

    public function create(AiThread $thread, AiSession $session, string $toProvider, ?string $fromProvider = null, string $reason = 'provider_switch', ?AiCompaction $compaction = null, array $metadata = []): AiProviderHandoff
    {
        $state = AiSessionState::query()
            ->where('thread_id', $thread->id)
            ->where('active', true)
            ->latest('updated_at')
            ->first();

        $recentMessages = AiMessage::query()
            ->where('thread_id', $thread->id)
            ->whereIn('role', ['user', 'assistant'])
            ->where('status', '!=', 'redacted')
            ->orderByDesc('position')
            ->limit(8)
            ->get()
            ->sortBy('position')
            ->values();

        $briefJson = [
            'schema_version' => 1,
            'thread_id' => $thread->id,
            'session_id' => $session->id,
            'from_provider' => $fromProvider,
            'to_provider' => $toProvider,
            'reason' => $reason,
            'objective' => $state?->objective ?: $thread->title,
            'current_phase' => $state?->current_phase,
            'current_topic' => $state?->current_topic,
            'decisions' => $state?->decisions ?? [],
            'open_loops' => $state?->open_loops ?? [],
            'next_steps' => $state?->next_steps ?? [],
            'relevant_artifacts' => $state?->relevant_artifacts ?? [],
            'thread_summary' => $thread->summary,
            'compaction_id' => $compaction?->id,
            'recent_turns' => $recentMessages->map(fn (AiMessage $message): array => [
                'role' => $message->role,
                'provider' => $message->provider,
                'position' => $message->position,
                'text' => Str::limit($message->content, 900, '...'),
            ])->all(),
        ];

        $briefText = $this->briefText($briefJson);

        return AiProviderHandoff::query()->create([
            'thread_id' => $thread->id,
            'session_id' => $session->id,
            'from_provider' => $fromProvider,
            'to_provider' => $toProvider,
            'reason' => $reason,
            'brief_text' => $briefText,
            'brief_json' => $briefJson,
            'compaction_id' => $compaction?->id,
            'metadata' => array_merge($metadata, [
                'created_by' => 'ai_provider_handoff_service',
            ]),
        ]);
    }

    private function briefText(array $brief): string
    {
        $lines = [
            'Provider handoff Atlas',
            'De: '.($brief['from_provider'] ?: 'n/a').' -> Para: '.$brief['to_provider'],
            'Objetivo: '.($brief['objective'] ?: 'n/a'),
        ];

        if (! empty($brief['current_phase'])) {
            $lines[] = 'Fase: '.$brief['current_phase'];
        }

        if (! empty($brief['thread_summary'])) {
            $lines[] = 'Resumo da thread: '.$brief['thread_summary'];
        }

        foreach ([
            'decisions' => 'Decisoes',
            'open_loops' => 'Pendencias',
            'next_steps' => 'Proximos passos',
        ] as $key => $label) {
            $items = collect($brief[$key] ?? [])->pluck('text')->filter()->take(5);
            if ($items->isNotEmpty()) {
                $lines[] = $label.': '.$items->implode(' | ');
            }
        }

        $recent = collect($brief['recent_turns'] ?? [])
            ->map(fn (array $turn): string => ($turn['role'] ?? 'user').': '.($turn['text'] ?? ''))
            ->take(6)
            ->implode(' || ');

        if ($recent !== '') {
            $lines[] = 'Ultimos turnos: '.$recent;
        }

        return Str::limit(implode("\n", $lines), 12000, '...');
    }
}

<?php

namespace App\Services\Ai;

use App\Models\AiCompaction;
use App\Models\AiMessage;
use App\Models\AiProviderHandoff;
use App\Models\AiSession;
use App\Models\AiSessionState;
use App\Models\AiThread;
use App\Services\Ai\Compaction\CompactionMustKeepExtractor;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use Illuminate\Support\Str;

class AiProviderHandoffService
{
    public function __construct(
        private readonly AiCompactionService $compactions,
        private readonly CompactionMustKeepExtractor $mustKeepExtractor,
    ) {}

    public function createIfSwitching(AiThread $thread, AiSession $session, ?string $toProvider, ?AiCompaction $compaction = null, array $metadata = []): ?AiProviderHandoff
    {
        if (! $toProvider || $this->providerIsSkipped($toProvider)) {
            return null;
        }

        $fromProvider = $thread->last_provider ?: $session->provider_last;
        if (! $fromProvider || $fromProvider === $toProvider || $this->providerIsSkipped($fromProvider)) {
            return null;
        }

        return $this->create($thread, $session, $toProvider, $fromProvider, 'provider_switch', $compaction, $metadata);
    }

    public function create(AiThread $thread, AiSession $session, string $toProvider, ?string $fromProvider = null, string $reason = 'provider_switch', ?AiCompaction $compaction = null, array $metadata = []): AiProviderHandoff
    {
        $state = $this->activeState($thread);

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

        $handoff = AiProviderHandoff::query()->create([
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

        $receipt = $this->recordReceipt(
            $thread,
            $session,
            $state,
            $handoff->id,
            'provider_handoff',
            $metadata + [
                'reason' => $reason,
                'from_provider' => $fromProvider,
                'to_provider' => $toProvider,
            ],
        );

        $handoff->forceFill([
            'metadata' => array_merge($handoff->metadata ?? [], [
                'long_horizon_compaction_receipt_uuid' => $receipt['receipt_uuid'] ?? null,
                'long_horizon_compaction_receipt_hash' => $receipt['receipt_hash'] ?? null,
                'long_horizon_compaction_receipt_scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_HANDOFF,
                'long_horizon_compaction_receipt_coverage' => $receipt['must_keep_coverage'] ?? null,
                'long_horizon_compaction_receipt_loss_risk' => $receipt['loss_risk'] ?? null,
            ]),
        ])->save();

        return $handoff->refresh();
    }

    /**
     * Fair mode records that a provider switch occurred without creating an
     * injectable handoff row. The receipt is audit-only and cannot become the
     * latest_provider_handoff selected by AiConversationContextBuilder.
     *
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    public function recordFairModeLossReceipt(AiThread $thread, AiSession $session, ?string $toProvider, array $metadata = []): array
    {
        $fromProvider = $thread->last_provider ?: $session->provider_last;
        $state = $this->activeState($thread);
        $mustKeep = $this->mustKeepItems($state);
        if ($mustKeep === []) {
            $mustKeep = [[
                'id' => 'fair_mode:handoff_brief',
                'kind' => 'decision',
                'digest' => 'Provider handoff brief intentionally disabled by fair mode',
                'payload' => ['fair_mode' => true],
            ]];
        }

        return $this->recordReceipt(
            $thread,
            $session,
            $state,
            'fair:'.$thread->id.':'.$session->id,
            'fair_mode_handoff_loss',
            $metadata + [
                'from_provider' => $fromProvider,
                'to_provider' => $toProvider,
                'fair_mode' => true,
            ],
            array_map(
                static fn (array $item): array => [
                    'id' => (string) $item['id'],
                    'reason' => AtlasLongHorizonCanon::DISCARDED_REASON_LOW_SIGNAL,
                ],
                $mustKeep,
            ),
            $mustKeep,
        );
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

    private function activeState(AiThread $thread): ?AiSessionState
    {
        return AiSessionState::query()
            ->where('thread_id', $thread->id)
            ->where('active', true)
            ->latest('updated_at')
            ->first();
    }

    /**
     * @return list<array{id:string,kind:string,digest:?string,payload:mixed}>
     */
    private function mustKeepItems(?AiSessionState $state): array
    {
        return $this->mustKeepExtractor->extract($state);
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  list<array{id:string,reason:string}>  $forcedDiscards
     * @param  list<array{id:string,kind:string,digest:?string,payload:mixed>>|null  $mustKeepOverride
     * @return array<string,mixed>
     */
    private function recordReceipt(
        AiThread $thread,
        AiSession $session,
        ?AiSessionState $state,
        string $scopeId,
        string $reason,
        array $metadata,
        array $forcedDiscards = [],
        ?array $mustKeepOverride = null,
    ): array {
        $mustKeep = $mustKeepOverride ?? $this->mustKeepItems($state);

        return $this->compactions->compactForScope([
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_HANDOFF,
            'scope_id' => $scopeId,
            'source_context_refs' => [
                ['kind' => 'thread', 'ref' => (string) $thread->id],
                ['kind' => 'session', 'ref' => (string) $session->id],
                ['kind' => 'handoff_reason', 'ref' => $reason],
            ],
            'must_keep_items' => $mustKeep,
            'forced_discards' => $forcedDiscards,
            'evidence_refs' => [
                'thread:'.$thread->id,
                'session:'.$session->id,
            ],
            'metadata' => $metadata,
        ]);
    }

    private function providerIsSkipped(string $provider): bool
    {
        return in_array($provider, $this->skipProviders(), true);
    }

    /**
     * @return list<string>
     */
    private function skipProviders(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $provider): string => trim((string) $provider),
            (array) config('atlas.ai.handoff_skip_providers', ['claude_codex']),
        )));
    }
}

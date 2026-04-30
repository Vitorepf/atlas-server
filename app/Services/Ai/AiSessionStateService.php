<?php

namespace App\Services\Ai;

use App\Models\AiSession;
use App\Models\AiSessionState;
use App\Models\AiThread;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AiSessionStateService
{
    private const TRANSACTION_ATTEMPTS = 5;

    public function updateForUserInput(AiThread $thread, AiSession $session, string $input, array $options = []): AiSessionState
    {
        return DB::transaction(function () use ($thread, $session, $input, $options): AiSessionState {
            $state = $this->lockedActiveState($thread, $session);
            $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
            $taskRequest = data_get($payload, 'task_request', []);
            $executionPlan = data_get($payload, 'execution_plan', []);

            $objective = $this->objective($state, $thread, $input);
            $phase = (string) (data_get($executionPlan, 'workflow') ?: data_get($taskRequest, 'task_type') ?: $state->current_phase ?: 'direct_answer_with_context');
            $artifacts = $this->mergeItems($state->relevant_artifacts ?? [], $this->artifactsFromPayload($payload));

            $state->update([
                'session_id' => $session->id,
                'version' => $state->version + 1,
                'objective' => $objective,
                'current_phase' => $phase,
                'current_topic' => $this->currentTopic($thread, $input),
                'user_position' => Str::limit($input, 1200, '...'),
                'decisions' => $this->mergeItems($state->decisions ?? [], $this->extractSignals($input, 'decision')),
                'open_loops' => $this->mergeItems($state->open_loops ?? [], $this->extractSignals($input, 'open_loop')),
                'next_steps' => $this->mergeItems($state->next_steps ?? [], $this->extractSignals($input, 'next_step')),
                'relevant_artifacts' => $artifacts,
                'constraints' => $this->mergeItems($state->constraints ?? [], $this->constraintsFromPayload($payload)),
                'provider_context' => array_merge($state->provider_context ?? [], [
                    'requested_provider' => data_get($payload, 'requested_provider') ?: ($options['provider'] ?? null),
                    'requested_agent' => data_get($payload, 'requested_agent'),
                    'workflow_mode' => data_get($payload, 'atlas_workflow_mode'),
                    'last_user_message_at' => now()->toJSON(),
                ]),
                'quality_notes' => $this->qualityNotes($state),
                'metadata' => array_merge($state->metadata ?? [], [
                    'last_update_source' => 'user_input',
                    'updated_by' => 'ai_session_state_service',
                ]),
            ]);

            return $state->refresh();
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function updateForAssistantResponse(AiThread $thread, AiSession $session, string $response, array $metadata = []): AiSessionState
    {
        return DB::transaction(function () use ($thread, $session, $response, $metadata): AiSessionState {
            $state = $this->lockedActiveState($thread, $session);

            $state->update([
                'session_id' => $session->id,
                'version' => $state->version + 1,
                'decisions' => $this->mergeItems($state->decisions ?? [], $this->extractSignals($response, 'decision')),
                'open_loops' => $this->mergeItems($state->open_loops ?? [], $this->extractSignals($response, 'open_loop')),
                'next_steps' => $this->mergeItems($state->next_steps ?? [], $this->extractSignals($response, 'next_step')),
                'provider_context' => array_merge($state->provider_context ?? [], [
                    'last_assistant_provider' => $metadata['provider'] ?? null,
                    'last_assistant_message_at' => now()->toJSON(),
                ]),
                'quality_notes' => $this->qualityNotes($state),
                'metadata' => array_merge($state->metadata ?? [], [
                    'last_update_source' => 'assistant_response',
                    'last_trace_id' => $metadata['trace_id'] ?? null,
                ]),
            ]);

            return $state->refresh();
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function activeState(AiThread $thread, ?AiSession $session = null): AiSessionState
    {
        return DB::transaction(function () use ($thread, $session): AiSessionState {
            /** @var AiThread $lockedThread */
            $lockedThread = AiThread::query()
                ->whereKey($thread->id)
                ->lockForUpdate()
                ->firstOrFail();

            $state = AiSessionState::query()
                ->where('thread_id', $lockedThread->id)
                ->where('active', true)
                ->latest('updated_at')
                ->lockForUpdate()
                ->first();

            if ($state) {
                return $state;
            }

            return AiSessionState::query()->create(array_filter([
                'thread_id' => $lockedThread->id,
                'session_id' => $session?->id,
                'version' => 1,
                'active' => true,
                'objective' => $lockedThread->title,
                'current_phase' => 'initial',
                'current_topic' => $lockedThread->title,
                'user_position' => null,
                'decisions' => [],
                'open_loops' => [],
                'next_steps' => [],
                'relevant_artifacts' => [],
                'constraints' => [],
                'provider_context' => [],
                'quality_notes' => [],
                'metadata' => ['created_by' => 'ai_session_state_service'],
                'pending_steer' => $this->supportsPendingSteer() ? null : '__atlas_drop__',
            ], fn (mixed $value): bool => $value !== '__atlas_drop__'));
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function setPendingSteer(string $threadId, string $message, ?string $sessionId = null): AiSessionState
    {
        $message = trim($message);
        if ($message === '') {
            throw new \InvalidArgumentException('Steer nao pode ficar vazio.');
        }

        if (! $this->supportsPendingSteer()) {
            throw new \RuntimeException('Coluna ai_session_states.pending_steer indisponivel. Rode as migrations do Atlas.');
        }

        return DB::transaction(function () use ($threadId, $message, $sessionId): AiSessionState {
            /** @var AiThread $thread */
            $thread = AiThread::query()
                ->whereKey($threadId)
                ->lockForUpdate()
                ->firstOrFail();

            $session = $sessionId
                ? AiSession::query()->whereKey($sessionId)->where('thread_id', $thread->id)->first()
                : AiSession::query()->where('thread_id', $thread->id)->where('status', 'active')->latest('started_at')->first();

            $state = $this->lockedActiveState($thread, $session);
            $state->update([
                'session_id' => $session?->id ?: $state->session_id,
                'version' => $state->version + 1,
                'pending_steer' => Str::limit($message, 4000, '...'),
                'provider_context' => array_merge($state->provider_context ?? [], [
                    'pending_steer_set_at' => now()->toJSON(),
                ]),
                'metadata' => array_merge($state->metadata ?? [], [
                    'last_update_source' => 'pending_steer',
                    'pending_steer_updated_at' => now()->toJSON(),
                ]),
            ]);

            return $state->refresh();
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function clearPendingSteer(string $threadId): AiSessionState
    {
        if (! $this->supportsPendingSteer()) {
            throw new \RuntimeException('Coluna ai_session_states.pending_steer indisponivel. Rode as migrations do Atlas.');
        }

        return DB::transaction(function () use ($threadId): AiSessionState {
            /** @var AiThread $thread */
            $thread = AiThread::query()
                ->whereKey($threadId)
                ->lockForUpdate()
                ->firstOrFail();

            $session = AiSession::query()
                ->where('thread_id', $thread->id)
                ->where('status', 'active')
                ->latest('started_at')
                ->first();

            $state = $this->lockedActiveState($thread, $session);
            $state->update([
                'version' => $state->version + 1,
                'pending_steer' => null,
                'provider_context' => array_merge($state->provider_context ?? [], [
                    'pending_steer_cleared_at' => now()->toJSON(),
                ]),
                'metadata' => array_merge($state->metadata ?? [], [
                    'last_update_source' => 'pending_steer_clear',
                    'pending_steer_cleared_at' => now()->toJSON(),
                ]),
            ]);

            return $state->refresh();
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function consumePendingSteer(AiThread $thread, ?AiSession $session = null): ?string
    {
        if (! $this->supportsPendingSteer()) {
            return null;
        }

        return DB::transaction(function () use ($thread, $session): ?string {
            $state = $this->lockedActiveState($thread, $session);
            $steer = is_string($state->pending_steer) ? trim($state->pending_steer) : '';
            if ($steer === '') {
                return null;
            }

            $state->update([
                'version' => $state->version + 1,
                'pending_steer' => null,
                'provider_context' => array_merge($state->provider_context ?? [], [
                    'pending_steer_consumed_at' => now()->toJSON(),
                ]),
                'metadata' => array_merge($state->metadata ?? [], [
                    'last_update_source' => 'pending_steer_consumed',
                    'pending_steer_consumed_at' => now()->toJSON(),
                ]),
            ]);

            return $steer;
        }, self::TRANSACTION_ATTEMPTS);
    }

    private function lockedActiveState(AiThread $thread, ?AiSession $session = null): AiSessionState
    {
        $state = $this->activeState($thread, $session);

        return AiSessionState::query()
            ->whereKey($state->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function supportsPendingSteer(): bool
    {
        return Schema::hasTable('ai_session_states')
            && Schema::hasColumn('ai_session_states', 'pending_steer');
    }

    private function objective(AiSessionState $state, AiThread $thread, string $input): string
    {
        $trimmed = trim($input);
        if (mb_strlen($trimmed) > 12 && ! $this->isShortReference($trimmed)) {
            return Str::limit($trimmed, 260, '...');
        }

        return $state->objective ?: $thread->title;
    }

    private function currentTopic(AiThread $thread, string $input): string
    {
        $trimmed = trim($input);
        if (mb_strlen($trimmed) >= 8) {
            return Str::limit($trimmed, 160, '...');
        }

        return $thread->title;
    }

    private function extractSignals(string $text, string $kind): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+|\n+/', trim($text)) ?: [];
        $needles = match ($kind) {
            'decision' => ['decid', 'defin', 'regra', 'ficou', 'vamos', 'opcao escolhida', 'opção escolhida'],
            'next_step' => ['proximo', 'próximo', 'implementar', 'criar', 'fazer', 'rodar', 'validar', 'corrigir'],
            default => ['?', 'duvida', 'dúvida', 'falta', 'preciso entender', 'verificar', 'risco', 'pendente'],
        };

        return collect($sentences)
            ->map(fn (string $sentence): string => trim($sentence))
            ->filter(function (string $sentence) use ($needles): bool {
                if ($sentence === '' || mb_strlen($sentence) < 8) {
                    return false;
                }

                $lower = Str::lower($sentence);
                foreach ($needles as $needle) {
                    if (str_contains($lower, $needle)) {
                        return true;
                    }
                }

                return false;
            })
            ->take(5)
            ->map(fn (string $sentence): array => [
                'text' => Str::limit($sentence, 280, '...'),
                'source' => 'heuristic',
                'captured_at' => now()->toJSON(),
            ])
            ->values()
            ->all();
    }

    private function artifactsFromPayload(array $payload): array
    {
        $items = [];
        foreach (['repo', 'branch', 'workspace'] as $key) {
            $value = data_get($payload, $key);
            if (is_string($value) && trim($value) !== '') {
                $items[] = ['type' => $key, 'value' => trim($value)];
            }
        }

        foreach (['dirty_files', 'relevant_files'] as $key) {
            foreach ((array) data_get($payload, $key, []) as $file) {
                if (is_string($file) && trim($file) !== '') {
                    $items[] = ['type' => $key === 'dirty_files' ? 'dirty_file' : 'relevant_file', 'value' => trim($file)];
                }
            }
        }

        return $items;
    }

    private function constraintsFromPayload(array $payload): array
    {
        return collect((array) data_get($payload, 'constraints', []))
            ->filter(fn (mixed $item): bool => is_string($item) && trim($item) !== '')
            ->map(fn (string $item): array => ['text' => trim($item), 'source' => 'payload'])
            ->values()
            ->all();
    }

    private function qualityNotes(AiSessionState $state): array
    {
        $notes = $state->quality_notes ?? [];
        $notes[] = [
            'text' => 'Estado atualizado de forma deterministica pelo Atlas; provider recebe contexto Atlas, nao memoria solta.',
            'source' => 'system',
            'captured_at' => now()->toJSON(),
        ];

        return array_slice($notes, -8);
    }

    private function mergeItems(array $existing, array $incoming, int $limit = 16): array
    {
        $items = collect([...$existing, ...$incoming])
            ->filter(fn (mixed $item): bool => is_array($item) && ($item['text'] ?? $item['value'] ?? null) !== null)
            ->unique(fn (array $item): string => Str::lower((string) ($item['text'] ?? $item['value'])))
            ->values()
            ->all();

        return array_slice($items, max(0, count($items) - $limit));
    }

    private function isShortReference(string $input): bool
    {
        $normalized = Str::of($input)->lower()->trim()->value();

        return in_array($normalized, ['a', 'b', 'c', 'ambos', 'isso', 'continua', 'continue', 'sim', 'não', 'nao'], true);
    }
}

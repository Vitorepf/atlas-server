<?php

namespace App\Services\Ai;

use App\Models\AiSession;
use App\Models\AiThread;
use App\Services\Ai\ValueObjects\AiThreadResolution;
use Illuminate\Support\Str;
use RuntimeException;

class AiThreadResolver
{
    public function resolve(string $input, array $options): AiThreadResolution
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $explicitThreadId = $this->firstString(
            $options['thread_id'] ?? null,
            data_get($payload, 'thread_id'),
            data_get($payload, 'conversation_context.thread_id'),
        );

        if (($options['new_thread'] ?? false) === true) {
            return new AiThreadResolution($this->createThread($input, $options, 'explicit_new_thread'), 'explicit_new_thread', true);
        }

        if ($explicitThreadId) {
            $thread = AiThread::query()->find($explicitThreadId);
            if (! $thread) {
                throw new RuntimeException('AI thread not found.');
            }

            if ($thread->status !== 'active') {
                $thread->update(['status' => 'active']);
            }

            return new AiThreadResolution($this->updateThreadModeFromPayload($thread->refresh(), $payload), 'explicit_thread_id', false);
        }

        $explicitSessionId = $this->firstString(
            $options['session_id'] ?? null,
            data_get($payload, 'session_id'),
            data_get($payload, 'conversation_context.session_id'),
        );
        if ($explicitSessionId) {
            $session = AiSession::query()->with('thread')->find($explicitSessionId);
            if (! $session || ! $session->thread) {
                throw new RuntimeException('AI session not found.');
            }

            if ($session->thread->status !== 'active') {
                $session->thread->update(['status' => 'active']);
            }

            return new AiThreadResolution($this->updateThreadModeFromPayload($session->thread->refresh(), $payload), 'explicit_session_id', false);
        }

        if ($this->allowsImplicitContinuation($options, $payload)) {
            $candidate = $this->latestContinuationCandidate($input, $options);
            if ($candidate) {
                return new AiThreadResolution($candidate, 'latest_continuation_candidate', false);
            }
        }

        return new AiThreadResolution($this->createThread($input, $options, 'implicit_new_thread'), 'implicit_new_thread', true);
    }

    private function allowsImplicitContinuation(array $options, array $payload): bool
    {
        return ($options['allow_implicit_thread_continuation'] ?? false) === true
            || data_get($payload, 'allow_implicit_thread_continuation') === true;
    }

    private function latestContinuationCandidate(string $input, array $options): ?AiThread
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $hasRecentPayloadTurns = is_array(data_get($payload, 'conversation_context.turns'))
            && count(data_get($payload, 'conversation_context.turns')) > 0;

        if (! $hasRecentPayloadTurns && ! $this->isShortReference($input)) {
            return null;
        }

        $surface = $this->surface($options, $payload);
        $workspace = $this->workspace($payload);

        return AiThread::query()
            ->where('status', 'active')
            ->where('surface', $surface)
            ->when($workspace, fn ($query) => $query->where('workspace', $workspace))
            ->orderByRaw('last_message_at DESC NULLS LAST')
            ->orderByDesc('created_at')
            ->first();
    }

    private function createThread(string $input, array $options, string $reason): AiThread
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];

        return AiThread::query()->create([
            'title' => $this->titleFromInput($input),
            'status' => 'active',
            'surface' => $this->surface($options, $payload),
            'workspace' => $this->workspace($payload),
            'source_type' => $options['source_type'] ?? 'app',
            'source_id' => $options['source_id'] ?? null,
            'metadata' => [
                'created_by' => 'ai_thread_resolver',
                'creation_reason' => $reason,
                'requested_agent' => data_get($payload, 'requested_agent'),
                'requested_provider' => data_get($payload, 'requested_provider') ?: ($options['provider'] ?? null),
                'app_surface' => data_get($payload, 'app_surface'),
                'atlas_focus' => data_get($payload, 'atlas_focus'),
                'initial_focus' => data_get($payload, 'atlas_focus'),
                'current_focus' => data_get($payload, 'atlas_focus'),
                'atlas_mode' => data_get($payload, 'atlas_mode'),
                'current_mode' => data_get($payload, 'atlas_mode'),
                'routing_task' => data_get($payload, 'routing_task'),
                'routing_domain' => data_get($payload, 'routing_domain'),
            ],
        ]);
    }

    private function updateThreadModeFromPayload(AiThread $thread, array $payload): AiThread
    {
        $focus = $this->firstString(data_get($payload, 'atlas_focus'));
        $mode = $this->firstString(data_get($payload, 'atlas_mode'));
        $routingTask = $this->firstString(data_get($payload, 'routing_task'));
        $routingDomain = $this->firstString(data_get($payload, 'routing_domain'));
        $requestedProvider = $this->firstString(data_get($payload, 'requested_provider'));

        if (! $focus && ! $mode && ! $routingTask && ! $routingDomain && ! $requestedProvider) {
            return $thread;
        }

        $metadata = $thread->metadata ?? [];
        $previousFocus = $this->firstString($metadata['atlas_focus'] ?? null);
        $previousMode = $this->firstString($metadata['atlas_mode'] ?? null);
        $changed = false;

        if ($focus && $focus !== $previousFocus) {
            $history = is_array($metadata['focus_history'] ?? null) ? $metadata['focus_history'] : [];
            $history[] = [
                'from' => $previousFocus,
                'to' => $focus,
                'at' => now()->toJSON(),
                'source' => 'ai_thread_resolver',
            ];

            $metadata['initial_focus'] = $metadata['initial_focus'] ?? ($previousFocus ?: $focus);
            $metadata['atlas_focus'] = $focus;
            $metadata['current_focus'] = $focus;
            $metadata['focus_history'] = array_slice($history, -20);
            $changed = true;
        }

        if ($mode && $mode !== $previousMode) {
            $history = is_array($metadata['mode_history'] ?? null) ? $metadata['mode_history'] : [];
            $history[] = [
                'from' => $previousMode,
                'to' => $mode,
                'at' => now()->toJSON(),
                'source' => 'ai_thread_resolver',
            ];

            $metadata['initial_mode'] = $metadata['initial_mode'] ?? ($previousMode ?: $mode);
            $metadata['atlas_mode'] = $mode;
            $metadata['current_mode'] = $mode;
            $metadata['mode_history'] = array_slice($history, -20);
            $changed = true;
        }

        foreach ([
            'routing_task' => $routingTask,
            'routing_domain' => $routingDomain,
            'requested_provider' => $requestedProvider,
        ] as $key => $value) {
            if ($value && ($metadata[$key] ?? null) !== $value) {
                $metadata[$key] = $value;
                $changed = true;
            }
        }

        if (! $changed) {
            return $thread;
        }

        $thread->update(['metadata' => $metadata]);

        return $thread->refresh();
    }

    private function titleFromInput(string $input): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($input)) ?: 'Nova conversa Atlas';

        return Str::limit($normalized, 90, '');
    }

    private function surface(array $options, array $payload): string
    {
        $surface = $this->firstString(data_get($payload, 'app_surface'), data_get($payload, 'surface'));
        if ($surface) {
            return Str::limit($surface, 80, '');
        }

        return match ($options['source_type'] ?? 'app') {
            'manual' => 'api',
            'scheduled' => 'automation',
            'system' => 'system',
            default => 'atlas_ai_sheet',
        };
    }

    private function workspace(array $payload): ?string
    {
        $workspace = $this->firstString(data_get($payload, 'workspace'), config('atlas.ai.workdir'));

        return $workspace ? Str::limit($workspace, 500, '') : null;
    }

    private function isShortReference(string $input): bool
    {
        $text = Str::of($input)->lower()->trim()->value();
        $normalized = preg_replace('/\s+/', ' ', $text) ?: '';
        if ($normalized === '' || mb_strlen($normalized) > 80) {
            return false;
        }

        $exact = [
            'a',
            'b',
            'c',
            'ambos',
            'as duas',
            'os dois',
            'isso',
            'esse',
            'essa',
            'continua',
            'continue',
            'sim',
            'nao',
            'não',
        ];

        return in_array($normalized, $exact, true)
            || str_starts_with($normalized, 'faz isso')
            || str_starts_with($normalized, 'segue')
            || str_starts_with($normalized, 'continua');
    }

    private function firstString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}

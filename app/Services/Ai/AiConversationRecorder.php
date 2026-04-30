<?php

namespace App\Services\Ai;

use App\Models\AiMessage;
use App\Models\AiSession;
use App\Models\AiThread;
use App\Models\AiTrace;
use Illuminate\Support\Facades\DB;

class AiConversationRecorder
{
    private const TRANSACTION_ATTEMPTS = 5;

    public function recordUserMessage(AiThread $thread, AiTrace $trace, string $content, array $metadata = []): AiMessage
    {
        return $this->recordTraceMessage($thread, $trace, 'user', $content, [
            'status' => 'final',
            'provider' => null,
            'model' => null,
            'agent_slug' => $trace->agent_slug,
            'metadata' => $metadata,
        ]);
    }

    public function recordAssistantMessage(AiTrace $trace, string $content, array $metadata = []): ?AiMessage
    {
        $content = trim($content);
        if ($content === '' || ! $trace->thread_id) {
            return null;
        }

        $thread = $trace->thread ?: AiThread::query()->find($trace->thread_id);
        if (! $thread) {
            return null;
        }

        return $this->recordTraceMessage($thread, $trace, 'assistant', $content, [
            'status' => $trace->status === 'failed' ? 'failed' : 'final',
            'provider' => $trace->provider,
            'model' => $trace->model,
            'agent_slug' => $trace->agent_slug,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @param  array{status:string,provider:?string,model:?string,agent_slug:?string,metadata:array}  $attributes
     */
    private function recordTraceMessage(AiThread $thread, AiTrace $trace, string $role, string $content, array $attributes): AiMessage
    {
        return DB::transaction(function () use ($thread, $trace, $role, $content, $attributes): AiMessage {
            $existing = AiMessage::query()
                ->where('trace_id', $trace->id)
                ->where('role', $role)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $existing->update([
                    'content' => $content,
                    'status' => $attributes['status'],
                    'provider' => $attributes['provider'],
                    'model' => $attributes['model'],
                    'agent_slug' => $attributes['agent_slug'],
                    'token_estimate' => $this->estimateTokens($content),
                    'occurred_at' => now(),
                    'metadata' => array_merge($existing->metadata ?? [], $attributes['metadata']),
                ]);

                $this->touchThread($thread, $trace, $attributes['provider']);

                return $existing->refresh();
            }

            /** @var AiThread $lockedThread */
            $lockedThread = AiThread::query()
                ->whereKey($thread->id)
                ->lockForUpdate()
                ->firstOrFail();

            $position = ((int) AiMessage::query()
                ->where('thread_id', $lockedThread->id)
                ->max('position')) + 1;

            $message = AiMessage::query()->create([
                'thread_id' => $lockedThread->id,
                'trace_id' => $trace->id,
                'position' => $position,
                'role' => $role,
                'status' => $attributes['status'],
                'content' => $content,
                'provider' => $attributes['provider'],
                'model' => $attributes['model'],
                'agent_slug' => $attributes['agent_slug'],
                'token_estimate' => $this->estimateTokens($content),
                'occurred_at' => now(),
                'metadata' => $attributes['metadata'],
            ]);

            $this->touchThread($lockedThread, $trace, $attributes['provider']);

            return $message;
        }, self::TRANSACTION_ATTEMPTS);
    }

    private function touchThread(AiThread $thread, AiTrace $trace, ?string $provider): void
    {
        $messageCount = AiMessage::query()->where('thread_id', $thread->id)->count();
        $tokenEstimate = (int) AiMessage::query()->where('thread_id', $thread->id)->sum('token_estimate');

        $thread->update([
            'status' => 'active',
            'last_trace_id' => $trace->id,
            'last_provider' => $provider ?: $trace->provider ?: $thread->last_provider,
            'message_count' => $messageCount,
            'last_message_at' => now(),
            'metadata' => array_merge($thread->metadata ?? [], [
                'last_agent_slug' => $trace->agent_slug,
                'last_intent' => $trace->intent,
            ]),
        ]);

        if ($trace->session_id) {
            AiSession::query()
                ->whereKey($trace->session_id)
                ->update([
                    'provider_last' => $provider ?: $trace->provider,
                    'message_count' => $messageCount,
                    'token_estimate' => $tokenEstimate,
                ]);
        }
    }

    private function estimateTokens(string $content): int
    {
        return max(1, (int) ceil(mb_strlen($content) / 4));
    }
}

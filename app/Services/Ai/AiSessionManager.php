<?php

namespace App\Services\Ai;

use App\Models\AiMessage;
use App\Models\AiSession;
use App\Models\AiThread;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AiSessionManager
{
    private const TRANSACTION_ATTEMPTS = 5;

    public function ensureActive(AiThread $thread, ?string $provider, string $input, array $options = []): AiSession
    {
        return DB::transaction(function () use ($thread, $provider, $input, $options): AiSession {
            /** @var AiThread $lockedThread */
            $lockedThread = AiThread::query()
                ->whereKey($thread->id)
                ->lockForUpdate()
                ->firstOrFail();

            $explicitSession = $this->explicitSession($lockedThread, $options);
            if ($explicitSession) {
                $this->pauseOtherActiveSessions($lockedThread, $explicitSession);

                if ($explicitSession->status !== 'active') {
                    $explicitSession->update([
                        'status' => 'active',
                        'ended_at' => null,
                        'metadata' => array_merge($explicitSession->metadata ?? [], [
                            'reactivated_at' => now()->toJSON(),
                            'reactivated_by' => 'ai_session_manager',
                        ]),
                    ]);
                }

                return $this->touchSession($explicitSession->refresh(), $provider, $options);
            }

            $session = AiSession::query()
                ->where('thread_id', $lockedThread->id)
                ->where('status', 'active')
                ->latest('started_at')
                ->lockForUpdate()
                ->first();

            if ($session && $this->isIdleExpired($session)) {
                $idleMinutes = $this->idleAgeMinutes($session);
                $this->pauseSession($session, 'idle_timeout');

                return $this->createSession($lockedThread, $provider, $input, $options, [
                    'creation_reason' => 'session_idle_resume',
                    'resumed_from_session_id' => $session->id,
                    'idle_minutes' => $idleMinutes,
                ]);
            }

            if (! $session) {
                return $this->createSession($lockedThread, $provider, $input, $options, [
                    'creation_reason' => 'new_active_session',
                ]);
            }

            return $this->touchSession($session, $provider, $options);
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function close(AiSession $session, string $status = 'completed'): AiSession
    {
        $session->update([
            'status' => in_array($status, ['completed', 'paused', 'abandoned'], true) ? $status : 'completed',
            'ended_at' => now(),
            'message_count' => AiMessage::query()->where('thread_id', $session->thread_id)->count(),
            'token_estimate' => (int) AiMessage::query()->where('thread_id', $session->thread_id)->sum('token_estimate'),
        ]);

        return $session->refresh();
    }

    private function createSession(AiThread $thread, ?string $provider, string $input, array $options, array $metadata = []): AiSession
    {
        return AiSession::query()->create([
            'thread_id' => $thread->id,
            'status' => 'active',
            'purpose' => $this->purposeFromInput($input),
            'provider_primary' => $provider,
            'provider_last' => $provider,
            'started_at' => now(),
            'message_count' => AiMessage::query()->where('thread_id', $thread->id)->count(),
            'token_estimate' => (int) AiMessage::query()->where('thread_id', $thread->id)->sum('token_estimate'),
            'metadata' => array_merge([
                'created_by' => 'ai_session_manager',
                'surface' => data_get($options, 'payload.app_surface'),
                'workflow_mode' => data_get($options, 'payload.atlas_workflow_mode'),
            ], $metadata),
        ]);
    }

    private function touchSession(AiSession $session, ?string $provider, array $options): AiSession
    {
        $messageCount = AiMessage::query()->where('thread_id', $session->thread_id)->count();
        $tokenEstimate = (int) AiMessage::query()->where('thread_id', $session->thread_id)->sum('token_estimate');

        $session->update([
            'provider_primary' => $session->provider_primary ?: $provider,
            'provider_last' => $provider ?: $session->provider_last,
            'message_count' => $messageCount,
            'token_estimate' => $tokenEstimate,
            'metadata' => array_merge($session->metadata ?? [], [
                'last_seen_at' => now()->toJSON(),
                'last_workflow_mode' => data_get($options, 'payload.atlas_workflow_mode'),
            ]),
        ]);

        return $session->refresh();
    }

    private function explicitSession(AiThread $thread, array $options): ?AiSession
    {
        $sessionId = $this->explicitSessionId($options);
        if (! $sessionId) {
            return null;
        }

        $session = AiSession::query()
            ->where('thread_id', $thread->id)
            ->whereKey($sessionId)
            ->lockForUpdate()
            ->first();

        if (! $session) {
            throw new RuntimeException('AI session does not belong to the resolved thread.');
        }

        return $session;
    }

    private function explicitSessionId(array $options): ?string
    {
        if (($options['new_thread'] ?? false) === true) {
            return null;
        }

        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $sessionId = $options['session_id'] ?? data_get($payload, 'session_id') ?? data_get($payload, 'conversation_context.session_id');

        return is_string($sessionId) && trim($sessionId) !== '' ? trim($sessionId) : null;
    }

    private function pauseOtherActiveSessions(AiThread $thread, AiSession $except): void
    {
        AiSession::query()
            ->where('thread_id', $thread->id)
            ->where('status', 'active')
            ->where('id', '!=', $except->id)
            ->lockForUpdate()
            ->get()
            ->each(function (AiSession $session): void {
                $session->update([
                    'status' => 'paused',
                    'ended_at' => now(),
                    'metadata' => array_merge($session->metadata ?? [], [
                        'paused_by' => 'ai_session_manager',
                        'pause_reason' => 'explicit_session_resume',
                    ]),
                ]);
            });
    }

    private function pauseSession(AiSession $session, string $reason): void
    {
        $session->update([
            'status' => 'paused',
            'ended_at' => now(),
            'message_count' => AiMessage::query()->where('thread_id', $session->thread_id)->count(),
            'token_estimate' => (int) AiMessage::query()->where('thread_id', $session->thread_id)->sum('token_estimate'),
            'metadata' => array_merge($session->metadata ?? [], [
                'paused_at' => now()->toJSON(),
                'pause_reason' => $reason,
            ]),
        ]);
    }

    private function isIdleExpired(AiSession $session): bool
    {
        $threshold = (int) config('atlas.ai.session_idle_minutes', 360);
        if ($threshold <= 0) {
            return false;
        }

        return $this->idleAgeMinutes($session) >= $threshold;
    }

    private function idleAgeMinutes(AiSession $session): int
    {
        $lastActivity = $session->updated_at ?? $session->started_at;

        return $lastActivity ? max(0, (int) $lastActivity->diffInMinutes(now(), true)) : 0;
    }

    private function purposeFromInput(string $input): string
    {
        $text = preg_replace('/\s+/', ' ', trim($input)) ?: 'Sessao Atlas';

        return Str::limit($text, 180, '');
    }
}

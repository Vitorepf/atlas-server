<?php

declare(strict_types=1);

namespace App\Services\Ai\RuntimeBoundary\Contracts;

use App\Services\Ai\RuntimeBoundary\FutureRuntimeInvocationContract;

/**
 * Voice Realtime — PHP-side runtime invocation contract.
 *
 * Block: voice_realtime · Runtime: livekit_agents_python
 * Per `atlas-ai-runtime-language-boundaries.md` and Voice Realtime doc.
 */
final class VoiceRealtimeContract extends FutureRuntimeInvocationContract
{
    protected function blockId(): string
    {
        return 'voice_realtime';
    }

    protected function targetRuntime(): string
    {
        return 'livekit_agents_python';
    }

    protected function blockValidation(array $payload): array
    {
        $errors = [];

        $room = $payload['room'] ?? null;
        if (! is_string($room) || ! str_starts_with($room, 'atlas-voice-')) {
            $errors[] = 'room must be string with prefix atlas-voice- (canonical scoping)';
        }

        $sessionLease = $payload['session_lease'] ?? null;
        if (! is_array($sessionLease)) {
            $errors[] = 'session_lease envelope required';
        } else {
            if (($sessionLease['required_room_prefix'] ?? null) !== 'atlas-voice-') {
                $errors[] = 'session_lease.required_room_prefix must be "atlas-voice-"';
            }
            if (($sessionLease['participant_namespace_source'] ?? null) !== 'client_surface') {
                $errors[] = 'session_lease.participant_namespace_source must be "client_surface"';
            }
        }

        if (($payload['runtime_failed_callback_required'] ?? null) !== true) {
            $errors[] = 'runtime_failed_callback_required must be true (fail-closed canon)';
        }

        if (($payload['client_surface'] ?? null) === null) {
            $errors[] = 'client_surface required (mobile|desktop|app)';
        }

        return $errors;
    }
}

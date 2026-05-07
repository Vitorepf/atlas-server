<?php

namespace App\Services\Ai\Voice;

final class AtlasVoiceEclipseGuard
{
    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function evaluate(array $payload): array
    {
        $reasons = [];

        if ((bool) ($payload['eclipse_active'] ?? false)) {
            $reasons[] = 'explicit_eclipse_active';
        }

        if ((bool) data_get($payload, 'privacy.private_meeting', false)) {
            $reasons[] = 'private_meeting';
        }

        if ((bool) data_get($payload, 'privacy.microphone_disabled', false)) {
            $reasons[] = 'microphone_disabled_by_policy';
        }

        $privacyClass = (string) ($payload['privacy_class'] ?? data_get($payload, 'privacy.class', 'p3_audio'));
        if (in_array($privacyClass, ['p4_secret', 'secret'], true) && ! (bool) ($payload['explicit_operator_consent'] ?? false)) {
            $reasons[] = 'secret_audio_requires_explicit_consent';
        }

        return [
            'schema_version' => 'atlas.voice.eclipse.v1',
            'active' => $reasons !== [],
            'reasons' => $reasons,
            'privacy_class' => $privacyClass,
            'requires_wake_word_local' => true,
            'raw_audio_persistence_allowed' => false,
        ];
    }
}

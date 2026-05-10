<?php

namespace Tests\Unit\Ai\Voice;

use App\Services\Ai\Voice\AtlasVoiceEclipseGuard;
use Tests\TestCase;

final class AtlasVoiceEclipseGuardTest extends TestCase
{
    public function test_secret_audio_requires_explicit_operator_consent(): void
    {
        $payload = app(AtlasVoiceEclipseGuard::class)->evaluate([
            'privacy_class' => 'p4_secret',
        ]);

        $this->assertSame('atlas.voice.eclipse.v1', $payload['schema_version']);
        $this->assertTrue($payload['active']);
        $this->assertSame('p4_secret', $payload['privacy_class']);
        $this->assertContains('secret_audio_requires_explicit_consent', $payload['reasons']);
        $this->assertFalse($payload['raw_audio_persistence_allowed']);
        $this->assertTrue($payload['requires_wake_word_local']);
    }

    public function test_explicit_consent_lifts_secret_audio_eclipse_without_allowing_raw_audio_persistence(): void
    {
        $payload = app(AtlasVoiceEclipseGuard::class)->evaluate([
            'privacy' => ['class' => 'secret'],
            'explicit_operator_consent' => true,
        ]);

        $this->assertFalse($payload['active']);
        $this->assertSame([], $payload['reasons']);
        $this->assertSame('secret', $payload['privacy_class']);
        $this->assertFalse($payload['raw_audio_persistence_allowed']);
        $this->assertTrue($payload['requires_wake_word_local']);
    }

    public function test_private_meeting_and_microphone_policy_fail_closed(): void
    {
        $payload = app(AtlasVoiceEclipseGuard::class)->evaluate([
            'privacy' => [
                'private_meeting' => true,
                'microphone_disabled' => true,
            ],
        ]);

        $this->assertTrue($payload['active']);
        $this->assertContains('private_meeting', $payload['reasons']);
        $this->assertContains('microphone_disabled_by_policy', $payload['reasons']);
        $this->assertFalse($payload['raw_audio_persistence_allowed']);
    }
}

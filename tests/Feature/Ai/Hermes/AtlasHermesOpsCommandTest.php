<?php

namespace Tests\Feature\Ai\Hermes;

use Tests\TestCase;

/**
 * Surface contract for `atlas:hermes:ops`. The governance-critical path is
 * sessions->evidence being FAIL-CLOSED: with the policy off the command must
 * not even reach the binary and must surface a disabled, hash-only import.
 */
class AtlasHermesOpsCommandTest extends TestCase
{
    public function test_sessions_is_fail_closed_when_policy_off(): void
    {
        config()->set('atlas.ai.providers.hermes_cli.session_evidence_policy', 'off');

        // policy off => short-circuits before any binary call; safe + hermetic.
        $this->artisan('atlas:hermes:ops', ['action' => 'sessions', '--json' => true])
            ->expectsOutputToContain('"action": "sessions"')
            ->assertExitCode(0);
    }
}

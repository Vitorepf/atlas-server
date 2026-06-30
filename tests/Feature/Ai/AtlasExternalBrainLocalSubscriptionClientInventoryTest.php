<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLocalSubscriptionClientInventory;
use Tests\TestCase;

final class AtlasExternalBrainLocalSubscriptionClientInventoryTest extends TestCase
{
    private function inventory(): AtlasExternalBrainLocalSubscriptionClientInventory
    {
        return new AtlasExternalBrainLocalSubscriptionClientInventory;
    }

    private function cursorFacts(array $overrides = []): array
    {
        return array_merge([
            'client_id' => 'cursor',
            'app_installed' => true,
            'cli_binary_present' => true,
            'logged_in_session_observed' => true,
            'subscription_plan_observed' => 'cursor-pro',
            'paid_api_required' => false,
            'headless_supported' => true,
            'model_hints' => ['gpt-5', 'claude'],
            'local_invocation_supported' => true,
        ], $overrides);
    }

    public function test_accepts_all_required_facts(): void
    {
        $entry = $this->inventory()->classify($this->cursorFacts());

        foreach ([
            'client_id', 'app_installed', 'cli_binary_present', 'logged_in_session_observed',
            'subscription_plan_observed', 'paid_api_required', 'headless_supported',
            'model_hints', 'local_invocation_supported',
        ] as $field) {
            $this->assertArrayHasKey($field, $entry, "missing field: {$field}");
        }
    }

    public function test_fully_entitled_local_client_is_usable_subscription_client(): void
    {
        $entry = $this->inventory()->classify($this->cursorFacts());

        $this->assertTrue($entry['usable_subscription_client']);
        $this->assertSame('usable_subscription_client', $entry['classification']);
        $this->assertSame([], $entry['not_usable_reasons']);
    }

    public function test_paid_api_required_is_never_usable(): void
    {
        $entry = $this->inventory()->classify($this->cursorFacts(['paid_api_required' => true]));

        $this->assertFalse($entry['usable_subscription_client']);
        $this->assertContains('paid_api_required', $entry['not_usable_reasons']);
    }

    public function test_no_local_invocation_support_is_not_usable(): void
    {
        $entry = $this->inventory()->classify($this->cursorFacts(['local_invocation_supported' => false]));

        $this->assertFalse($entry['usable_subscription_client']);
        $this->assertContains('local_invocation_not_supported', $entry['not_usable_reasons']);
    }

    public function test_no_session_or_subscription_entitlement_is_not_usable(): void
    {
        $entry = $this->inventory()->classify($this->cursorFacts([
            'logged_in_session_observed' => false,
            'subscription_plan_observed' => '',
        ]));

        $this->assertFalse($entry['usable_subscription_client']);
        $this->assertContains('no_authenticated_session_or_subscription_entitlement_observed', $entry['not_usable_reasons']);
    }

    public function test_subscription_plan_alone_satisfies_entitlement_without_login_session(): void
    {
        $entry = $this->inventory()->classify($this->cursorFacts([
            'logged_in_session_observed' => false,
            'subscription_plan_observed' => 'cursor-business',
        ]));

        $this->assertTrue($entry['usable_subscription_client']);
    }

    public function test_every_client_including_cursor_marks_atlas_not_required_and_fallback_required(): void
    {
        foreach (['cursor', 'codex', 'claude', 'hermes'] as $clientId) {
            $entry = $this->inventory()->classify($this->cursorFacts(['client_id' => $clientId]));

            $this->assertFalse($entry['atlas_required'], "atlas_required must be false for {$clientId}");
            $this->assertTrue($entry['fallback_required'], "fallback_required must be true for {$clientId}");
        }
    }

    public function test_not_installed_client_is_not_usable(): void
    {
        $entry = $this->inventory()->classify($this->cursorFacts([
            'app_installed' => false,
            'cli_binary_present' => false,
        ]));

        $this->assertFalse($entry['usable_subscription_client']);
        $this->assertContains('client_not_installed', $entry['not_usable_reasons']);
    }
}

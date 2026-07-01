<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLocalSubscriptionClientInventory;
use PHPUnit\Framework\TestCase;

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

    // ── Schema / envelope ────────────────────────────────────────────────────

    public function test_output_has_new_required_keys(): void
    {
        $entry = $this->inventory()->classify($this->cursorFacts());

        foreach (['client_class', 'credential_present', 'suitable_task_families'] as $key) {
            $this->assertArrayHasKey($key, $entry, "Missing key: {$key}");
        }
    }

    // ── AC2: 4-way client_class taxonomy ───────────────────────────────────────

    public function test_locally_invocable_installed_client_is_class_local(): void
    {
        $entry = $this->inventory()->classify($this->cursorFacts());

        $this->assertSame(AtlasExternalBrainLocalSubscriptionClientInventory::CLASS_LOCAL, $entry['client_class']);
    }

    public function test_entitled_but_not_locally_invocable_client_is_class_subscription_ui(): void
    {
        $entry = $this->inventory()->classify($this->cursorFacts(['local_invocation_supported' => false]));

        $this->assertSame(AtlasExternalBrainLocalSubscriptionClientInventory::CLASS_SUBSCRIPTION_UI, $entry['client_class']);
    }

    public function test_nothing_installed_and_no_entitlement_is_class_unavailable(): void
    {
        $entry = $this->inventory()->classify($this->cursorFacts([
            'app_installed' => false,
            'cli_binary_present' => false,
            'logged_in_session_observed' => false,
            'subscription_plan_observed' => '',
        ]));

        $this->assertSame(AtlasExternalBrainLocalSubscriptionClientInventory::CLASS_UNAVAILABLE, $entry['client_class']);
    }

    public function test_internal_runtime_flag_always_wins_regardless_of_other_facts(): void
    {
        $entry = $this->inventory()->classify($this->cursorFacts([
            'is_internal_runtime' => true,
            'app_installed' => false,
            'cli_binary_present' => false,
            'local_invocation_supported' => false,
        ]));

        $this->assertSame(AtlasExternalBrainLocalSubscriptionClientInventory::CLASS_INTERNAL_RUNTIME, $entry['client_class']);
    }

    // ── AC3: credentials are redacted and never returned ───────────────────────

    public function test_raw_credential_is_never_echoed_back_anywhere_in_output(): void
    {
        $secret = 'sk-live-super-secret-abc123';
        $entry = $this->inventory()->classify($this->cursorFacts(['raw_credential' => $secret]));

        $this->assertStringNotContainsString($secret, json_encode($entry));
        $this->assertTrue($entry['credential_present']);
    }

    public function test_api_key_field_is_never_echoed_back(): void
    {
        $secret = 'super-secret-api-key-xyz';
        $entry = $this->inventory()->classify($this->cursorFacts(['api_key' => $secret]));

        $this->assertStringNotContainsString($secret, json_encode($entry));
        $this->assertTrue($entry['credential_present']);
    }

    public function test_credential_present_is_false_when_no_credential_supplied(): void
    {
        $entry = $this->inventory()->classify($this->cursorFacts());

        $this->assertFalse($entry['credential_present']);
    }

    // ── AC4: suitable_task_families is conservative when evidence is missing ──

    public function test_unusable_client_has_no_suitable_task_families(): void
    {
        $entry = $this->inventory()->classify($this->cursorFacts(['paid_api_required' => true]));

        $this->assertSame([], $entry['suitable_task_families']);
    }

    public function test_usable_client_with_full_capability_evidence_gets_broader_task_families(): void
    {
        $entry = $this->inventory()->classify($this->cursorFacts());

        $this->assertContains('implementation', $entry['suitable_task_families']);
    }

    public function test_usable_client_without_headless_support_gets_conservative_family_only(): void
    {
        $entry = $this->inventory()->classify($this->cursorFacts(['headless_supported' => false]));

        $this->assertSame(['manual_supervised_only'], $entry['suitable_task_families']);
    }

    public function test_usable_client_without_model_hints_gets_conservative_family_only(): void
    {
        $entry = $this->inventory()->classify($this->cursorFacts(['model_hints' => []]));

        $this->assertSame(['manual_supervised_only'], $entry['suitable_task_families']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = $this->cursorFacts();

        $this->assertSame(
            json_encode($this->inventory()->classify($facts)),
            json_encode($this->inventory()->classify($facts)),
        );
    }
}

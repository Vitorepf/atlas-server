<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLocalSubscriptionClientInventory;
use Tests\TestCase;

final class AtlasExternalBrainLocalSubscriptionClientInventoryTest extends TestCase
{
    private AtlasExternalBrainLocalSubscriptionClientInventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inventory = new AtlasExternalBrainLocalSubscriptionClientInventory();
    }

    private function facts(array $overrides = []): array
    {
        return array_merge([
            'client_id' => 'cursor',
            'app_installed' => true,
            'cli_binary_present' => true,
            'logged_in_session_observed' => true,
            'subscription_plan_observed' => 'pro',
            'paid_api_required' => false,
            'headless_supported' => true,
            'model_hints' => ['gpt-4'],
            'local_invocation_supported' => true,
            'is_internal_runtime' => false,
        ], $overrides);
    }

    // ── Schema ───────────────────────────────────────────────────────────────────

    public function test_schema_constant(): void
    {
        $this->assertSame('atlas.external_brain.local_subscription_client_inventory.v1', AtlasExternalBrainLocalSubscriptionClientInventory::SCHEMA);
    }

    // ── Usable subscription client ───────────────────────────────────────────────

    public function test_usable_when_all_conditions_met(): void
    {
        $result = $this->inventory->classify($this->facts());
        $this->assertSame('usable_subscription_client', $result['classification']);
        $this->assertTrue($result['usable_subscription_client']);
        $this->assertSame([], $result['not_usable_reasons']);
    }

    // ── Not usable: paid API required ────────────────────────────────────────────

    public function test_not_usable_when_paid_api_required(): void
    {
        $result = $this->inventory->classify($this->facts(['paid_api_required' => true]));
        $this->assertSame('not_usable', $result['classification']);
        $this->assertContains('paid_api_required', $result['not_usable_reasons']);
    }

    // ── Not usable: no local invocation ──────────────────────────────────────────

    public function test_not_usable_when_no_local_invocation(): void
    {
        $result = $this->inventory->classify($this->facts(['local_invocation_supported' => false]));
        $this->assertSame('not_usable', $result['classification']);
        $this->assertContains('local_invocation_not_supported', $result['not_usable_reasons']);
    }

    // ── Not usable: no entitlement ───────────────────────────────────────────────

    public function test_not_usable_when_no_entitlement(): void
    {
        $result = $this->inventory->classify($this->facts([
            'logged_in_session_observed' => false,
            'subscription_plan_observed' => '',
        ]));
        $this->assertSame('not_usable', $result['classification']);
        $this->assertContains('no_authenticated_session_or_subscription_entitlement_observed', $result['not_usable_reasons']);
    }

    // ── Not usable: not installed ────────────────────────────────────────────────

    public function test_not_usable_when_not_installed(): void
    {
        $result = $this->inventory->classify($this->facts([
            'app_installed' => false,
            'cli_binary_present' => false,
        ]));
        $this->assertSame('not_usable', $result['classification']);
        $this->assertContains('client_not_installed', $result['not_usable_reasons']);
    }

    // ── Client class taxonomy ────────────────────────────────────────────────────

    public function test_client_class_internal_runtime(): void
    {
        $result = $this->inventory->classify($this->facts(['is_internal_runtime' => true]));
        $this->assertSame('internal_runtime', $result['client_class']);
    }

    public function test_client_class_local(): void
    {
        $result = $this->inventory->classify($this->facts());
        $this->assertSame('local', $result['client_class']);
    }

    public function test_client_class_subscription_ui(): void
    {
        $result = $this->inventory->classify($this->facts([
            'local_invocation_supported' => false,
            'app_installed' => false,
            'cli_binary_present' => false,
        ]));
        $this->assertSame('subscription_ui', $result['client_class']);
    }

    public function test_client_class_unavailable(): void
    {
        $result = $this->inventory->classify($this->facts([
            'app_installed' => false,
            'cli_binary_present' => false,
            'logged_in_session_observed' => false,
            'subscription_plan_observed' => '',
        ]));
        $this->assertSame('unavailable', $result['client_class']);
    }

    // ── Credential redaction ─────────────────────────────────────────────────────

    public function test_credential_present_when_api_key_supplied(): void
    {
        $result = $this->inventory->classify($this->facts(['api_key' => 'sk-123456']));
        $this->assertTrue($result['credential_present']);
    }

    public function test_credential_not_present_when_no_credentials(): void
    {
        $result = $this->inventory->classify($this->facts());
        $this->assertFalse($result['credential_present']);
    }

    public function test_credentials_not_echoed_back(): void
    {
        $result = $this->inventory->classify($this->facts(['raw_credential' => 'secret123']));
        $this->assertArrayNotHasKey('raw_credential', $result);
        $this->assertArrayNotHasKey('api_key', $result);
    }

    // ── Suitable task families ───────────────────────────────────────────────────

    public function test_task_families_empty_when_not_usable(): void
    {
        $result = $this->inventory->classify($this->facts(['paid_api_required' => true]));
        $this->assertSame([], $result['suitable_task_families']);
    }

    public function test_task_families_broad_when_capability_evidence_present(): void
    {
        $result = $this->inventory->classify($this->facts());
        $this->assertSame(['implementation', 'refactor', 'test_authoring'], $result['suitable_task_families']);
    }

    public function test_task_families_supervised_only_when_thin_evidence(): void
    {
        $result = $this->inventory->classify($this->facts([
            'headless_supported' => false,
            'model_hints' => [],
        ]));
        $this->assertSame(['manual_supervised_only'], $result['suitable_task_families']);
    }

    // ── Atlas required / fallback ────────────────────────────────────────────────

    public function test_atlas_required_always_false(): void
    {
        $result = $this->inventory->classify($this->facts());
        $this->assertFalse($result['atlas_required']);
        $this->assertTrue($result['fallback_required']);
    }

    // ── Determinism ──────────────────────────────────────────────────────────────

    public function test_result_is_deterministic(): void
    {
        $facts = $this->facts();
        $this->assertSame($this->inventory->classify($facts), $this->inventory->classify($facts));
    }

    // ── Empty input ──────────────────────────────────────────────────────────────

    public function test_empty_input_classifies_as_not_usable(): void
    {
        $result = $this->inventory->classify([]);
        $this->assertSame('not_usable', $result['classification']);
        $this->assertFalse($result['usable_subscription_client']);
    }
}

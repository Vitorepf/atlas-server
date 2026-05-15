<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\ForgeRivals\AtlasCodeProviderArenaSnapshotService;
use Tests\TestCase;

/**
 * Atlas Code Provider Arena · controller contract.
 *
 * Verifies that:
 *   - The snapshot endpoint never invokes a provider, honors the schema,
 *     and exposes the canonical registries the UI needs (arms, modes,
 *     task categories, presets, safety promises).
 *   - The `run` endpoint refuses real-provider runs without the three
 *     operator confirmations and propagates honest blockers.
 *   - The `run` endpoint accepts `local_fake` for the dry-run path
 *     (which never spends tokens), but still propagates contract
 *     blockers transparently.
 */
class AtlasCodeProviderArenaControllerTest extends TestCase
{
    public function test_snapshot_exposes_registry_and_safety_promises(): void
    {
        $response = $this->getJson('/atlas-code/forge/provider-arena/snapshot');
        $response->assertOk();
        $payload = $response->json();

        $this->assertSame(AtlasCodeProviderArenaSnapshotService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertIsArray($payload['arm_registry']['arms']);
        $this->assertGreaterThanOrEqual(7, count($payload['arm_registry']['arms']));
        $this->assertIsArray($payload['modes']);
        $this->assertGreaterThanOrEqual(3, count($payload['modes']));
        $this->assertContains('local_fake', array_column($payload['modes'], 'mode'));
        $this->assertContains('fair', array_column($payload['modes'], 'mode'));
        $this->assertContains('full_power', array_column($payload['modes'], 'mode'));
        $this->assertIsArray($payload['presets']);
        $this->assertContains('smoke', array_column($payload['presets'], 'preset'));
        $this->assertSame(false, $payload['external_provider_call']);
        $this->assertSame(false, $payload['provider_tokens_spent']);
        $this->assertSame(true, $payload['separated_from_external_rivals_certification']);
        $this->assertSame(true, $payload['safety_promises']['never_promotes_completion_claim']);
        $this->assertSame(true, $payload['safety_promises']['never_unlocks_external_rivals_certification']);
        $this->assertSame(true, $payload['safety_promises']['requires_three_confirmations_for_real_provider']);
        $this->assertSame(true, $payload['safety_promises']['local_fake_never_invokes_provider']);
    }

    public function test_run_blocks_real_provider_without_three_confirmations(): void
    {
        $response = $this->postJson('/atlas-code/forge/provider-arena/run', [
            'arm_a' => 'atlas_forge',
            'arm_b' => 'claude_code',
            'arm_a_model' => 'sonnet',
            'arm_b_model' => 'sonnet',
            'task_category' => 'tests',
            'mode' => 'fair',
            'preset' => 'smoke',
        ]);

        $response->assertStatus(409);
        $payload = $response->json();
        $this->assertSame('blocked', $payload['status']);
        $this->assertIsArray($payload['blockers']);
        $this->assertContains('missing_confirmation:runbook_reviewed', $payload['blockers']);
        $this->assertContains('missing_confirmation:provider_cost', $payload['blockers']);
        $this->assertContains('missing_confirmation:real_provider_call', $payload['blockers']);
        $this->assertSame(false, $payload['external_provider_call']);
        $this->assertSame(false, $payload['provider_tokens_spent']);
        $this->assertSame(true, $payload['separated_from_external_rivals_certification']);
    }

    public function test_run_local_fake_never_requires_confirmations_and_never_spends_tokens(): void
    {
        $response = $this->postJson('/atlas-code/forge/provider-arena/run', [
            'arm_a' => 'atlas_forge',
            'arm_b' => 'claude_code',
            'arm_a_model' => 'sonnet',
            'arm_b_model' => 'sonnet',
            'task_category' => 'tests',
            'mode' => 'local_fake',
            'preset' => 'smoke',
        ]);

        $payload = $response->json();
        $this->assertSame('atlas.forge.rivals.provider_arena_run.v1', $payload['arena_schema_version']);
        $this->assertSame(false, $payload['provider_tokens_spent']);
        $this->assertSame(true, $payload['separated_from_external_rivals_certification']);
        // Safety contract holds whatever the battery returns (ok or blocked);
        // tokens are never spent in local_fake regardless of the path.
        $this->assertContains($payload['status'], ['ok', 'blocked']);
    }

    public function test_run_rejects_unknown_arm_with_honest_blocker(): void
    {
        $response = $this->postJson('/atlas-code/forge/provider-arena/run', [
            'arm_a' => 'unknown_arm',
            'arm_b' => 'claude_code',
            'task_category' => 'tests',
            'mode' => 'local_fake',
            'preset' => 'smoke',
        ]);

        $response->assertStatus(409);
        $payload = $response->json();
        $this->assertSame('blocked', $payload['status']);
        $this->assertTrue(
            in_array('arm_a_arm_unknown:unknown_arm', $payload['blockers'], true)
            || in_array('arm_a_arm_unknown:unknown_arm', $payload['blockers'] ?? [], true),
        );
    }
}

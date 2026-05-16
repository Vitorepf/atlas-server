<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev\Http;

use App\Http\Controllers\AtlasDev\Support\RunExecutor;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;

/**
 * HTTP parity for the canonical surfaces.
 *
 * Sibling tests already cover `atlas_desktop_ai` and `atlas_cli_dev` in
 * detail. This file proves that `atlas_app` and `atlas_api_interaction` flow
 * through the SAME controller with the SAME guards and produce the SAME
 * absence of path/token leaks — i.e. the core is genuinely
 * surface-agnostic.
 *
 * The four-surface flag matrix is also locked: only `atlas_desktop_ai`
 * requires the extra `desktop_enabled` knob; the other three ride on
 * `plan_enabled` + `run_enabled` only. Default-off safety is reasserted by
 * flipping the master flag and asserting 503 across every surface.
 */
final class PlanRunSurfaceParityTest extends AtlasDevHttpTestCase
{
    private const NON_DESKTOP_SURFACES = ['atlas_app', 'atlas_api_interaction'];

    private const ALL_SURFACES = [
        'atlas_desktop_ai',
        'atlas_cli_dev',
        'atlas_app',
        'atlas_api_interaction',
    ];

    private FakeRunExecutor $fakeExecutor;

    protected function setUp(): void
    {
        parent::setUp();
        // Plan layer hits the real orchestrator. Run-layer pipeline has its
        // own end-to-end coverage in PipelineRunExecutorHttpSmokeTest; here
        // we only need to exercise the controller-side guards, so we stub
        // the executor for a deterministic "passed" completion.
        $this->fakeExecutor = FakeRunExecutor::passing();
        $this->app->instance(RunExecutor::class, $this->fakeExecutor);
    }

    public function test_plan_run_cycle_succeeds_for_app_and_api_with_canonical_guards(): void
    {
        foreach (self::NON_DESKTOP_SURFACES as $surfaceId) {
            $plan = $this->planWithSurface($surfaceId);

            $this->assertSame('atlas_dev_fast_path', $plan['routing']['kind'], $surfaceId);
            $this->assertIsArray($plan['confirmation'], $surfaceId);
            $this->assertSame($surfaceId, $plan['surface_id'], $surfaceId);
            $this->assertNoPathLeaks($plan, $surfaceId);

            $response = $this->withHeaders($this->headers)
                ->postJson('/ai/interactions/atlas-dev/run', [
                    'run_id' => $plan['run_id'],
                    'task_contract_hash' => $plan['hashes']['task_contract'],
                    'confirmation_token' => $plan['confirmation']['token'],
                    'operator_confirmed' => true,
                ]);

            $response->assertStatus(200);
            $response->assertJsonPath('data.completion_state', 'passed');
            $this->assertNoPathLeaks((array) $response->json('data'), $surfaceId);

            // The plan-only artifacts were persisted under the same run id, so
            // a subsequent /show would resolve them without any surface-
            // specific branch in the core orchestrator.
            $storage = $this->app->make(ReceiptStorage::class);
            foreach ([
                ArtifactNames::OPERATION_ENVELOPE,
                ArtifactNames::COMPACT_SDD,
                ArtifactNames::TASK_CONTRACT,
                ArtifactNames::PROMPT_PROJECTION,
            ] as $artifact) {
                $this->assertTrue(
                    $storage->exists($plan['run_id'], $artifact),
                    "missing persisted {$artifact} for surface {$surfaceId}",
                );
            }
        }
    }

    public function test_run_rejects_when_operator_not_confirmed_for_app_and_api(): void
    {
        foreach (self::NON_DESKTOP_SURFACES as $surfaceId) {
            $plan = $this->planWithSurface($surfaceId);

            $this->withHeaders($this->headers)
                ->postJson('/ai/interactions/atlas-dev/run', [
                    'run_id' => $plan['run_id'],
                    'task_contract_hash' => $plan['hashes']['task_contract'],
                    'confirmation_token' => $plan['confirmation']['token'],
                    'operator_confirmed' => false,
                ])
                ->assertStatus(400)
                ->assertJsonPath('error.code', 'OPERATOR_NOT_CONFIRMED');
        }

        $this->assertCount(0, $this->fakeExecutor->calls);
    }

    public function test_run_rejects_when_confirmation_token_missing_for_app_and_api(): void
    {
        foreach (self::NON_DESKTOP_SURFACES as $surfaceId) {
            $plan = $this->planWithSurface($surfaceId);

            // Missing confirmation_token → FormRequest 422 BEFORE the
            // controller can resolve anything. Locks the contract for the
            // App/API entry points the same way it does for Desktop/CLI.
            $this->withHeaders($this->headers)
                ->postJson('/ai/interactions/atlas-dev/run', [
                    'run_id' => $plan['run_id'],
                    'task_contract_hash' => $plan['hashes']['task_contract'],
                    'operator_confirmed' => true,
                ])
                ->assertStatus(422);
        }
    }

    public function test_plan_read_only_intent_emits_no_token_for_app_and_api(): void
    {
        foreach (self::NON_DESKTOP_SURFACES as $surfaceId) {
            $payload = $this->defaultRepairPayload();
            $payload['surface_id'] = $surfaceId;
            $payload['raw_intent'] = 'explique como o FooService resolve workspace';

            $data = $this->postPlan($payload);
            $this->assertSame('read_only_answer', $data['routing']['kind'], $surfaceId);
            $this->assertNull($data['confirmation'], "{$surfaceId} read-only plan must not mint a token");
            $this->assertFalse(
                (bool) ($data['prompt_sendable'] ?? true),
                "{$surfaceId} read-only plan must mark prompt non-sendable",
            );
            $this->assertNoPathLeaks($data, $surfaceId);
        }
    }

    public function test_plan_forge_promotion_preview_emits_no_token_for_app_and_api(): void
    {
        foreach (self::NON_DESKTOP_SURFACES as $surfaceId) {
            $payload = $this->defaultRepairPayload();
            $payload['surface_id'] = $surfaceId;
            $payload['raw_intent'] = 'mexer no fluxo de billing em production e ajustar migration';

            $data = $this->postPlan($payload);
            $this->assertSame('forge_promotion_preview', $data['routing']['kind'], $surfaceId);
            $this->assertNull($data['confirmation'], "{$surfaceId} forge preview must not mint a token");
            $this->assertFalse(
                (bool) ($data['prompt_sendable'] ?? true),
                "{$surfaceId} forge preview must mark prompt non-sendable",
            );
            $this->assertNoPathLeaks($data, $surfaceId);
        }
    }

    public function test_plan_disabled_by_master_flag_returns_503_for_every_surface(): void
    {
        config()->set('atlas_dev.efficient.plan_enabled', false);

        foreach (self::ALL_SURFACES as $surfaceId) {
            $payload = $this->defaultRepairPayload();
            $payload['surface_id'] = $surfaceId;

            $this->withHeaders($this->headers)
                ->postJson('/ai/interactions/atlas-dev/plan', $payload)
                ->assertStatus(503)
                ->assertJsonPath('error.code', 'ATLAS_DEV_PLAN_DISABLED');
        }
    }

    public function test_run_disabled_by_run_flag_returns_503_for_app_and_api(): void
    {
        foreach (self::NON_DESKTOP_SURFACES as $surfaceId) {
            config()->set('atlas_dev.efficient.plan_enabled', true);
            config()->set('atlas_dev.efficient.run_enabled', true);
            $plan = $this->planWithSurface($surfaceId);

            config()->set('atlas_dev.efficient.run_enabled', false);

            $this->withHeaders($this->headers)
                ->postJson('/ai/interactions/atlas-dev/run', [
                    'run_id' => $plan['run_id'],
                    'task_contract_hash' => $plan['hashes']['task_contract'],
                    'confirmation_token' => $plan['confirmation']['token'],
                    'operator_confirmed' => true,
                ])
                ->assertStatus(503)
                ->assertJsonPath('error.code', 'ATLAS_DEV_RUN_DISABLED');
        }
    }

    public function test_only_desktop_has_extra_desktop_flag_gate(): void
    {
        // Desktop is the one surface that requires its own flag in addition
        // to plan_enabled. App and API must NOT be gated by desktop_enabled —
        // pin that explicitly so flag drift never silently locks them out.
        config()->set('atlas_dev.efficient.desktop_enabled', false);
        foreach (self::NON_DESKTOP_SURFACES as $surfaceId) {
            $payload = $this->defaultRepairPayload();
            $payload['surface_id'] = $surfaceId;

            $this->withHeaders($this->headers)
                ->postJson('/ai/interactions/atlas-dev/plan', $payload)
                ->assertStatus(200);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function planWithSurface(string $surfaceId): array
    {
        $payload = $this->defaultRepairPayload();
        $payload['surface_id'] = $surfaceId;

        return $this->postPlan($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertNoPathLeaks(array $payload, string $surfaceId): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $this->assertIsString($json);
        $this->assertStringNotContainsString(
            $this->tmpWorkspace,
            $json,
            "{$surfaceId}: response leaked workspace absolute path",
        );
        $this->assertStringNotContainsString(
            $this->tmpStorage,
            $json,
            "{$surfaceId}: response leaked storage absolute path",
        );
        $this->assertStringNotContainsString(
            '/Users/',
            $json,
            "{$surfaceId}: response leaked a /Users/* path",
        );
    }
}

<?php

namespace Tests\Feature\Ai\Programming\AtlasDev\Http;

use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliRequest;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliResponse;

final class PlanEndpointTest extends AtlasDevHttpTestCase
{
    public function test_plan_returns_200_with_run_id_and_routing(): void
    {
        $data = $this->postPlan($this->defaultRepairPayload());

        $this->assertArrayHasKey('run_id', $data);
        $this->assertStringStartsWith('dev-', (string) $data['run_id']);
        $this->assertArrayHasKey('routing', $data);
        $this->assertIsArray($data['routing']);
        $this->assertContains($data['routing']['kind'], [
            'atlas_dev_fast_path',
            'forge_promotion_preview',
            'read_only_answer',
            'blocked',
        ]);
        $this->assertArrayHasKey('hashes', $data);
        $this->assertArrayHasKey('task_contract', $data['hashes']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $data['hashes']['task_contract']);
        $this->assertArrayHasKey('senior_loop', $data);
        $this->assertSame('atlas.dev.senior_engineer_loop_audit.v1', $data['senior_loop']['schema_version']);
        $this->assertSame('passed', $data['senior_loop']['status']);
        $this->assertSame([], $data['senior_loop']['blockers']);
        $this->assertArrayHasKey('senior_engineer_loop_audit.json', $data['persisted_artifact_refs']);

        $storage = $this->app->make(ReceiptStorage::class);
        $audit = $storage->read((string) $data['run_id'], ArtifactNames::SENIOR_ENGINEER_LOOP_AUDIT);
        $this->assertSame($data['senior_loop']['audit_hash'], $audit['audit_hash'] ?? null);
    }

    public function test_plan_never_resolves_or_calls_claude_cli_gateway(): void
    {
        $resolved = false;
        $this->app->singleton(ClaudeCliGateway::class, function () use (&$resolved): ClaudeCliGateway {
            $resolved = true;

            return new class implements ClaudeCliGateway
            {
                public bool $called = false;

                public function dispatch(ClaudeCliRequest $request): ClaudeCliResponse
                {
                    $this->called = true;
                    throw new \RuntimeException('Plan must never dispatch ClaudeCliGateway.');
                }
            };
        });

        $this->postPlan($this->defaultRepairPayload());

        $this->assertFalse(
            $resolved,
            'Plan must never resolve or dispatch the claude_cli gateway.',
        );
    }

    public function test_plan_returns_single_use_confirmation_token_when_route_is_executable(): void
    {
        $data = $this->postPlan($this->defaultRepairPayload());

        if ($data['routing']['kind'] !== 'atlas_dev_fast_path') {
            $this->markTestSkipped('Fixture intent did not land on fast_path; token assertion N/A.');
        }

        $this->assertArrayHasKey('confirmation', $data);
        $this->assertIsArray($data['confirmation']);
        $this->assertIsString($data['confirmation']['token']);
        $this->assertGreaterThanOrEqual(32, strlen($data['confirmation']['token']));
        $this->assertSame($data['hashes']['task_contract'], $data['confirmation']['task_contract_hash']);
        $this->assertGreaterThan(time(), $data['confirmation']['expires_at']);
    }

    public function test_plan_omits_confirmation_token_for_read_only_routes(): void
    {
        $payload = $this->defaultRepairPayload();
        $payload['raw_intent'] = 'explique como o FooService resolve workspace';

        $data = $this->postPlan($payload);

        $this->assertSame('read_only_answer', $data['routing']['kind']);
        $this->assertNull($data['confirmation']);
    }

    public function test_plan_omits_confirmation_token_for_forge_preview(): void
    {
        $payload = $this->defaultRepairPayload();
        $payload['raw_intent'] = 'mexer no fluxo de billing em production e ajustar migration';

        $data = $this->postPlan($payload);

        $this->assertSame('forge_promotion_preview', $data['routing']['kind']);
        $this->assertNull($data['confirmation']);
    }

    public function test_plan_rejects_missing_required_fields_with_422(): void
    {
        $this->withHeaders($this->headers)
            ->postJson('/ai/interactions/atlas-dev/plan', [])
            ->assertStatus(422);
    }

    public function test_plan_rejects_without_atlas_token(): void
    {
        $this->postJson('/ai/interactions/atlas-dev/plan', $this->defaultRepairPayload())
            ->assertStatus(401);
    }

    public function test_plan_disabled_by_feature_flag_returns_503(): void
    {
        config()->set('atlas_dev.efficient.plan_enabled', false);

        $this->withHeaders($this->headers)
            ->postJson('/ai/interactions/atlas-dev/plan', $this->defaultRepairPayload())
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'ATLAS_DEV_PLAN_DISABLED');
    }

    public function test_plan_rejects_desktop_surface_when_desktop_flag_is_disabled(): void
    {
        config()->set('atlas_dev.efficient.plan_enabled', true);
        config()->set('atlas_dev.efficient.desktop_enabled', false);

        $payload = $this->defaultRepairPayload();
        $payload['surface_id'] = 'atlas_desktop_ai';

        $this->withHeaders($this->headers)
            ->postJson('/ai/interactions/atlas-dev/plan', $payload)
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'ATLAS_DEV_DESKTOP_DISABLED');
    }

    public function test_plan_allows_desktop_surface_when_desktop_flag_is_enabled(): void
    {
        config()->set('atlas_dev.efficient.plan_enabled', true);
        config()->set('atlas_dev.efficient.desktop_enabled', true);

        $payload = $this->defaultRepairPayload();
        $payload['surface_id'] = 'atlas_desktop_ai';

        $this->withHeaders($this->headers)
            ->postJson('/ai/interactions/atlas-dev/plan', $payload)
            ->assertStatus(200)
            ->assertJsonPath('data.surface_id', 'atlas_desktop_ai');
    }

    public function test_plan_resolves_desktop_workspace_slug_to_configured_workspace_path(): void
    {
        config()->set('atlas_dev.efficient.plan_enabled', true);
        config()->set('atlas_dev.efficient.desktop_enabled', true);
        config()->set('atlas_projects.profiles', [[
            'id' => 'atlas-test',
            'slug' => 'atlas-test',
            'name' => 'Atlas Test',
            'workspace_path' => $this->tmpWorkspace,
            'repo_root' => $this->tmpWorkspace,
        ]]);

        $payload = $this->defaultRepairPayload();
        $payload['surface_id'] = 'atlas_desktop_ai';
        $payload['workspace'] = 'atlas-test';

        $data = $this->postPlan($payload);

        $storage = $this->app->make(ReceiptStorage::class);
        $envelope = $storage->read((string) $data['run_id'], ArtifactNames::OPERATION_ENVELOPE);

        $this->assertSame(realpath($this->tmpWorkspace) ?: $this->tmpWorkspace, $envelope['workspace'] ?? null);
    }

    public function test_plan_rejects_desktop_workspace_slug_without_configured_path(): void
    {
        config()->set('atlas_dev.efficient.plan_enabled', true);
        config()->set('atlas_dev.efficient.desktop_enabled', true);
        config()->set('atlas_projects.profiles', [[
            'id' => 'missing-workspace',
            'slug' => 'missing-workspace',
            'name' => 'Missing Workspace',
            'workspace_path' => sys_get_temp_dir().'/atlas-dev-missing-'.bin2hex(random_bytes(4)),
            'repo_root' => '',
        ]]);

        $payload = $this->defaultRepairPayload();
        $payload['surface_id'] = 'atlas_desktop_ai';
        $payload['workspace'] = 'missing-workspace';

        $this->withHeaders($this->headers)
            ->postJson('/ai/interactions/atlas-dev/plan', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ATLAS_DEV_PLAN_FAILED');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev\Http;

final class RunIndexEndpointTest extends AtlasDevHttpTestCase
{
    public function test_index_requires_workspace_hash_or_thread_id_filter(): void
    {
        $this->withHeaders($this->headers)
            ->getJson('/ai/interactions/atlas-dev/runs')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ATLAS_DEV_RUN_INDEX_FILTER_REQUIRED');
    }

    public function test_index_lists_runs_by_workspace_hash_without_absolute_paths(): void
    {
        $first = $this->postPlan([
            ...$this->defaultRepairPayload(),
            'surface_context' => ['thread_id' => 'thread-a'],
        ]);
        $second = $this->postPlan([
            ...$this->defaultRepairPayload(),
            'raw_intent' => 'corrija o teste falhando novamente em tests/Unit/Services/Foo/FooServiceTest.php',
            'surface_context' => ['thread_id' => 'thread-b'],
        ]);

        $workspaceHash = (string) $first['workspace_hash'];
        $this->assertSame($workspaceHash, $second['workspace_hash']);

        $response = $this->withHeaders($this->headers)
            ->getJson('/ai/interactions/atlas-dev/runs?workspace_hash='.$workspaceHash.'&limit=10')
            ->assertStatus(200)
            ->assertJsonPath('data.workspace_hash', $workspaceHash)
            ->assertJsonPath('data.thread_id', null)
            ->assertJsonCount(2, 'data.items');

        $items = $response->json('data.items');
        $this->assertIsArray($items);
        $this->assertSame($second['run_id'], $items[0]['run_id']);
        $this->assertSame($first['run_id'], $items[1]['run_id']);
        $this->assertSame('atlas_cli_dev', $items[0]['surface_id']);
        $this->assertSame('repair', $items[0]['task_kind']);
        $this->assertSame('atlas_dev_fast_path', $items[0]['routing_decision']);

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString($this->tmpWorkspace, $body);
        $this->assertStringNotContainsString($this->tmpStorage, $body);
        $this->assertStringNotContainsString('/Users/', $body);
        $this->assertStringNotContainsString('/private/var/', $body);
    }

    public function test_index_response_reports_effective_clamped_limit(): void
    {
        config()->set('atlas_dev.run_index.list_max_limit', 2);
        $first = $this->postPlan([
            ...$this->defaultRepairPayload(),
            'surface_context' => ['thread_id' => 'thread-limit'],
        ]);
        $this->postPlan([
            ...$this->defaultRepairPayload(),
            'raw_intent' => 'corrija outro teste em tests/Unit/Services/Foo/FooServiceTest.php',
            'surface_context' => ['thread_id' => 'thread-limit'],
        ]);
        $this->postPlan([
            ...$this->defaultRepairPayload(),
            'raw_intent' => 'corrija o terceiro teste em tests/Unit/Services/Foo/FooServiceTest.php',
            'surface_context' => ['thread_id' => 'thread-limit'],
        ]);

        $this->withHeaders($this->headers)
            ->getJson('/ai/interactions/atlas-dev/runs?workspace_hash='.$first['workspace_hash'].'&limit=999')
            ->assertStatus(200)
            ->assertJsonPath('data.limit', 2)
            ->assertJsonCount(2, 'data.items');
    }

    public function test_index_can_filter_workspace_runs_by_thread_id(): void
    {
        $first = $this->postPlan([
            ...$this->defaultRepairPayload(),
            'surface_context' => ['thread_id' => 'thread-only'],
        ]);
        $this->postPlan([
            ...$this->defaultRepairPayload(),
            'raw_intent' => 'corrija outro teste falhando em tests/Unit/Services/Foo/FooServiceTest.php',
            'surface_context' => ['thread_id' => 'thread-other'],
        ]);

        $response = $this->withHeaders($this->headers)
            ->getJson('/ai/interactions/atlas-dev/runs?workspace_hash='.$first['workspace_hash'].'&thread_id=thread-only')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.items');

        $this->assertSame($first['run_id'], $response->json('data.items.0.run_id'));
        $this->assertSame('thread-only', $response->json('data.items.0.thread_id'));
    }

    public function test_index_lists_by_thread_id_without_workspace_hash(): void
    {
        $plan = $this->postPlan([
            ...$this->defaultRepairPayload(),
            'surface_context' => ['thread_id' => 'thread-resume'],
        ]);

        $response = $this->withHeaders($this->headers)
            ->getJson('/ai/interactions/atlas-dev/runs?thread_id=thread-resume')
            ->assertStatus(200)
            ->assertJsonPath('data.workspace_hash', null)
            ->assertJsonPath('data.thread_id', 'thread-resume')
            ->assertJsonCount(1, 'data.items');

        $this->assertSame($plan['run_id'], $response->json('data.items.0.run_id'));
    }

    public function test_index_disabled_by_plan_feature_flag(): void
    {
        config()->set('atlas_dev.efficient.plan_enabled', false);

        $this->withHeaders($this->headers)
            ->getJson('/ai/interactions/atlas-dev/runs?thread_id=thread-x')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'ATLAS_DEV_PLAN_DISABLED');
    }
}

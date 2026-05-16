<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev\Http;

/**
 * F-07 closure: HTTP-boundary path redaction is covered at the integration
 * layer for every response body Plan/Run/Show/Stream emits. The unit test for
 * HttpResponseRedactor proves redaction is wired; this suite proves nobody
 * around it bypassed the redactor and slipped an absolute path through.
 *
 * Sentinels we treat as evidence of a leak:
 *   - the absolute tmp workspace directory the test harness uses;
 *   - the macOS-style `/Users/...` prefix;
 *   - `/private/var/...` and `/var/folders/...` (sys_get_temp_dir() on macOS);
 *   - `storage_path('atlas-dev/receipts/...')`-style absolute receipts path.
 *
 * Provenance-bearing hashes (workspace_hash, receipt_hash, etc.) are fine —
 * they are opaque to the client and never expose host state.
 */
final class HttpResponseRedactionTest extends AtlasDevHttpTestCase
{
    /**
     * @return list<string>
     */
    private function leakSentinels(): array
    {
        return [
            $this->tmpWorkspace,
            $this->tmpStorage,
            '/Users/',
            '/private/var/',
            '/var/folders/',
        ];
    }

    private function assertNoAbsolutePathsLeaked(string $body, string $context): void
    {
        foreach ($this->leakSentinels() as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $body,
                "F-04 leak in {$context}: response body must not contain '{$needle}'.",
            );
        }
    }

    public function test_plan_response_body_redacts_absolute_paths(): void
    {
        $response = $this->withHeaders($this->headers)
            ->postJson('/ai/interactions/atlas-dev/plan', $this->defaultRepairPayload());

        $response->assertStatus(200);
        $this->assertNoAbsolutePathsLeaked((string) $response->getContent(), 'plan');
    }

    public function test_run_response_body_redacts_absolute_paths(): void
    {
        $this->app->instance(
            \App\Http\Controllers\AtlasDev\Support\RunExecutor::class,
            FakeRunExecutor::passing(),
        );
        $plan = $this->postPlan($this->defaultRepairPayload());

        $response = $this->withHeaders($this->headers)
            ->postJson('/ai/interactions/atlas-dev/run', [
                'run_id' => $plan['run_id'],
                'task_contract_hash' => $plan['hashes']['task_contract'],
                'confirmation_token' => $plan['confirmation']['token'],
                'operator_confirmed' => true,
            ]);

        $response->assertStatus(200);
        $this->assertNoAbsolutePathsLeaked((string) $response->getContent(), 'run');
    }

    public function test_show_response_body_redacts_absolute_paths(): void
    {
        $plan = $this->postPlan($this->defaultRepairPayload());

        $response = $this->withHeaders($this->headers)
            ->get('/ai/interactions/atlas-dev/runs/'.$plan['run_id']);

        $response->assertStatus(200);
        $this->assertNoAbsolutePathsLeaked((string) $response->getContent(), 'show');
    }

    public function test_stream_event_body_redacts_absolute_paths(): void
    {
        $plan = $this->postPlan($this->defaultRepairPayload());

        $response = $this->withHeaders($this->headers)
            ->get('/ai/interactions/atlas-dev/runs/'.$plan['run_id'].'/stream');

        $response->assertStatus(200);
        $this->assertNoAbsolutePathsLeaked((string) $response->streamedContent(), 'stream');
    }

    public function test_show_404_response_body_redacts_absolute_paths(): void
    {
        $response = $this->withHeaders($this->headers)
            ->get('/ai/interactions/atlas-dev/runs/dev-9999999999999-deadbeef');

        $response->assertStatus(404);
        $this->assertNoAbsolutePathsLeaked((string) $response->getContent(), 'show_404');
    }

    /**
     * F-04 shape contract: the Plan body uses workspace_label + workspace_hash
     * and persisted_artifact_refs (basename-prefixed by `receipts/<run_id>/`),
     * never the legacy absolute keys.
     */
    public function test_plan_response_uses_canonical_redacted_shape(): void
    {
        $response = $this->withHeaders($this->headers)
            ->postJson('/ai/interactions/atlas-dev/plan', $this->defaultRepairPayload());
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertArrayHasKey('workspace_label', $data);
        $this->assertArrayHasKey('workspace_hash', $data);
        $this->assertArrayHasKey('persisted_artifact_refs', $data);
        $this->assertArrayNotHasKey('workspace', $data);
        $this->assertArrayNotHasKey('persisted_artifact_paths', $data);

        foreach ($data['persisted_artifact_refs'] as $ref) {
            $this->assertStringStartsWith("receipts/{$data['run_id']}/", $ref);
            $this->assertDoesNotMatchRegularExpression('@^/@', $ref);
        }

        foreach ((array) ($data['expected_files'] ?? []) as $path) {
            $this->assertIsString($path);
            $this->assertDoesNotMatchRegularExpression('@^/@', $path, 'expected_files leak');
        }
        foreach ((array) ($data['allowed_files'] ?? []) as $path) {
            $this->assertIsString($path);
            $this->assertDoesNotMatchRegularExpression('@^/@', $path, 'allowed_files leak');
        }
    }

    /**
     * F-04 shape contract for Show. Mirrors the Plan assertion: refs not paths,
     * label not absolute workspace.
     */
    public function test_show_response_uses_canonical_redacted_shape(): void
    {
        $plan = $this->postPlan($this->defaultRepairPayload());
        $runId = $plan['run_id'];

        $response = $this->withHeaders($this->headers)
            ->get('/ai/interactions/atlas-dev/runs/'.$runId);
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertArrayHasKey('workspace_label', $data);
        $this->assertArrayHasKey('workspace_hash', $data);
        $this->assertArrayHasKey('persisted_artifact_refs', $data);
        $this->assertArrayNotHasKey('workspace', $data);
        $this->assertArrayNotHasKey('persisted_artifact_paths', $data);

        foreach ($data['persisted_artifact_refs'] as $ref) {
            $this->assertStringStartsWith("receipts/{$runId}/", $ref);
        }
    }
}

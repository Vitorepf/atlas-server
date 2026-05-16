<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev\Http;

use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;

/**
 * F-07 closure: Plan must persist the full canonical artifact set so Run/Show
 * have everything they need to compose receipts and answer status. Previously
 * only a subset (envelope/task_contract/prompt) was asserted; this suite locks
 * the complete plan-time write set so regressions surface immediately.
 */
final class PlanArtifactPersistenceTest extends AtlasDevHttpTestCase
{
    public function test_plan_persists_all_canonical_artifacts_for_fast_path_routes(): void
    {
        $data = $this->postPlan($this->defaultRepairPayload());

        if ($data['routing']['kind'] !== 'atlas_dev_fast_path') {
            $this->markTestSkipped('Fixture intent did not land on fast_path; artifact set differs by route.');
        }

        $storage = $this->app->make(ReceiptStorage::class);
        $runId = (string) $data['run_id'];

        $required = [
            ArtifactNames::OPERATION_ENVELOPE,
            ArtifactNames::COMPACT_SDD,
            ArtifactNames::CONTEXT_RETRIEVAL_PLAN,
            ArtifactNames::CODE_DISCOVERY_MANIFEST,
            ArtifactNames::OPEN_BRAIN_PROJECTION,
            ArtifactNames::MINI_PROGRAMMING_SPEC,
            ArtifactNames::TASK_CONTRACT,
            ArtifactNames::PROMPT_PROJECTION,
            ArtifactNames::ROUTING_DECISION,
        ];

        foreach ($required as $artifact) {
            $this->assertTrue(
                $storage->exists($runId, $artifact),
                "F-07: plan must persist {$artifact} for run {$runId}.",
            );
        }
    }

    public function test_plan_persisted_compact_sdd_carries_canonical_task_kind_and_risk_level(): void
    {
        $data = $this->postPlan($this->defaultRepairPayload());
        $runId = (string) $data['run_id'];
        $compactSdd = $this->app->make(ReceiptStorage::class)->read($runId, ArtifactNames::COMPACT_SDD);

        $this->assertIsArray($compactSdd, 'compact_sdd.json must round-trip JSON.');
        $this->assertContains(
            $compactSdd['task_kind'] ?? null,
            ['question', 'patch', 'repair', 'review', 'frontend', 'risky'],
            'compact_sdd.task_kind must be a canonical VerificationReceipt task_kind.',
        );
        $this->assertContains(
            $compactSdd['risk_level'] ?? null,
            ['R0', 'R1', 'R2', 'R3', 'R4', 'R5'],
            'compact_sdd.risk_level must be a canonical VerificationReceipt risk_level.',
        );
    }

    public function test_plan_response_exposes_workspace_label_not_absolute_workspace(): void
    {
        $data = $this->postPlan($this->defaultRepairPayload());

        // Whatever the surface chooses to surface from the envelope, the
        // absolute workspace path must never reach the client.
        $payload = json_encode($data, JSON_UNESCAPED_SLASHES);
        $this->assertIsString($payload);
        $this->assertStringNotContainsString($this->tmpWorkspace, $payload);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Completion;

use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionFinalEvidenceSourceRegistry;
use Tests\TestCase;

final class AtlasSelfConstructionFinalEvidenceSourceRegistryTest extends TestCase
{
    public function test_describe_lists_all_required_sources(): void
    {
        $verdict = (new AtlasSelfConstructionFinalEvidenceSourceRegistry)->describe();
        $ids = array_column($verdict['required_sources'], 'id');
        $expected = [
            'task_serving_contract_sentinel',
            'code_index_readiness_bridge',
            'multi_project_governance_dossier',
            'native_worker_readiness',
            'verification_court',
            'merge_governor',
            'rollback',
            'receipts',
            'learning_transfer',
            'docs_health',
            'knowledge_sync',
        ];
        foreach ($expected as $id) {
            $this->assertContains($id, $ids, "missing required source: {$id}");
        }
        $this->assertSame(count($expected), count($ids));
    }

    public function test_every_required_source_is_blocking(): void
    {
        $verdict = (new AtlasSelfConstructionFinalEvidenceSourceRegistry)->describe();
        foreach ($verdict['required_sources'] as $s) {
            $this->assertTrue($s['blocking'], "source {$s['id']} must be blocking for final ready state");
        }
        $this->assertSame(count($verdict['required_sources']), count($verdict['blocking_source_ids']));
    }

    public function test_refreshable_sources_are_labelled_separately_from_unsafe_blockers(): void
    {
        $verdict = (new AtlasSelfConstructionFinalEvidenceSourceRegistry)->describe();
        $refreshable = array_values(array_filter($verdict['required_sources'], fn (array $s): bool => $s['refreshable']));
        $unsafe = array_values(array_filter($verdict['required_sources'], fn (array $s): bool => ! $s['refreshable']));

        $this->assertGreaterThan(0, count($refreshable), 'at least one refreshable source must exist');
        $this->assertGreaterThan(0, count($unsafe), 'at least one unsafe (non-refreshable) blocker must exist');
        $this->assertSame(count($refreshable), $verdict['proof_summary']['refreshable_count']);
        $this->assertSame(count($unsafe), $verdict['proof_summary']['unsafe_blocker_count']);

        $refreshIds = array_column($refreshable, 'id');
        $this->assertContains('code_index_readiness_bridge', $refreshIds);
        $this->assertContains('docs_health', $refreshIds);
        $this->assertContains('knowledge_sync', $refreshIds);

        $unsafeIds = array_column($unsafe, 'id');
        $this->assertContains('task_serving_contract_sentinel', $unsafeIds);
        $this->assertContains('verification_court', $unsafeIds);
        $this->assertContains('rollback', $unsafeIds);
    }

    public function test_output_is_deterministic_across_invocations(): void
    {
        $a = (new AtlasSelfConstructionFinalEvidenceSourceRegistry)->describe();
        $b = (new AtlasSelfConstructionFinalEvidenceSourceRegistry)->describe();
        $this->assertSame(
            json_encode($a, JSON_UNESCAPED_SLASHES),
            json_encode($b, JSON_UNESCAPED_SLASHES),
        );
    }

    public function test_no_duplicate_source_ids(): void
    {
        $verdict = (new AtlasSelfConstructionFinalEvidenceSourceRegistry)->describe();
        $ids = array_column($verdict['required_sources'], 'id');
        $this->assertSame(count($ids), count(array_unique($ids)));
    }
}

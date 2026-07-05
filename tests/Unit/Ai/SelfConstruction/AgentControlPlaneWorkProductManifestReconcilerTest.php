<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneWorkProductManifestReconciler;
use Tests\TestCase;

final class AgentControlPlaneWorkProductManifestReconcilerTest extends TestCase
{
    private function reconcile(array $products, array $expected = []): array
    {
        return (new AgentControlPlaneWorkProductManifestReconciler)->reconcile($products, $expected);
    }

    public function test_blank_paths_are_ignored_but_counted_as_invalid(): void
    {
        $result = $this->reconcile(
            [['path' => ''], ['path' => 'valid.txt']],
            [['path' => 'valid/path.txt']],
        );

        $this->assertSame(1, $result['invalid_path_count']);
        $this->assertStringContainsString('invalid_path_present', json_encode($result['repair_hints']));
    }

    public function test_duplicate_observed_paths_do_not_inflate_count_and_are_surfaced(): void
    {
        $result = $this->reconcile(
            [
                ['path' => 'file.txt'],
                ['path' => 'file.txt'],
                ['path' => 'file.txt'],
            ],
            [['path' => 'file.txt']],
        );

        $this->assertSame(1, $result['observed_output_count']); // not inflated
        $this->assertSame(1, $result['duplicate_observed_path_count']);
        $this->assertContains('file.txt', $result['duplicate_observed_paths']);
    }

    public function test_missing_outputs_return_gaps_status_and_repair_next_action(): void
    {
        $result = $this->reconcile(
            [['path' => 'existing.txt']],
            [['path' => 'existing.txt'], ['path' => 'missing.txt']],
        );

        $this->assertSame('manifest_reconciliation_has_gaps', $result['status']);
        $this->assertSame('repair_manifest_before_collection', $result['next_action']);
        $this->assertContains('missing.txt', array_column($result['missing_outputs'], 'path'));
    }

    public function test_unexpected_outputs_return_gaps_status_and_repair_next_action(): void
    {
        $result = $this->reconcile(
            [['path' => 'expected.txt'], ['path' => 'unexpected.txt']],
            [['path' => 'expected.txt']],
        );

        $this->assertSame('manifest_reconciliation_has_gaps', $result['status']);
        $this->assertContains('unexpected.txt', array_column($result['unexpected_outputs'], 'path'));
    }

    public function test_clean_manifest_returns_clear_status_and_proceed_next_action(): void
    {
        $result = $this->reconcile(
            [['path' => 'a.txt'], ['path' => 'b.txt']],
            [['path' => 'a.txt'], ['path' => 'b.txt']],
        );

        $this->assertSame('manifest_reconciliation_clear', $result['status']);
        $this->assertSame('collection_may_proceed', $result['next_action']);
        $this->assertSame(0, $result['missing_count']);
        $this->assertSame(0, $result['unexpected_count']);
        $this->assertSame(0, $result['duplicate_observed_path_count']);
        $this->assertSame(0, $result['invalid_path_count']);
    }

    public function test_missing_output_emits_blocked_proof_status_and_repair_hint(): void
    {
        $result = $this->reconcile(
            [['path' => 'present.txt']],
            [['path' => 'present.txt'], ['path' => 'gone.txt']],
        );

        $this->assertSame('blocked', $result['proof_status']);
        $repairPaths = array_column($result['repair_hints'], 'path');
        $this->assertContains('gone.txt', $repairPaths);
    }

    public function test_unexpected_output_emits_blocked_proof_status_and_repair_hint(): void
    {
        $result = $this->reconcile(
            [['path' => 'ok.txt'], ['path' => 'extra.txt']],
            [['path' => 'ok.txt']],
        );

        $this->assertSame('blocked', $result['proof_status']);
        $repairPaths = array_column($result['repair_hints'], 'path');
        $this->assertContains('extra.txt', $repairPaths);
    }

    public function test_duplicate_paths_emit_blocked_proof_status_and_repair_hint(): void
    {
        $result = $this->reconcile(
            [['path' => 'dup.txt'], ['path' => 'dup.txt']],
            [['path' => 'dup.txt']],
        );

        $this->assertSame('blocked', $result['proof_status']);
        $this->assertStringContainsString('deduplicate_work_product:dup.txt', json_encode($result['repair_hints']));
    }

    public function test_invalid_paths_emit_blocked_proof_status_and_repair_hint(): void
    {
        $result = $this->reconcile(
            [['path' => 'valid.txt'], ['path' => '']],
            [['path' => 'valid.txt']],
        );

        $this->assertSame('blocked', $result['proof_status']);
        $this->assertStringContainsString('invalid_path_present', json_encode($result['repair_hints']));
    }

    public function test_manifest_reconciliation_hash_changes_when_repair_hints_change(): void
    {
        $products = [['path' => 'a.txt']];

        $hashMissing = (new AgentControlPlaneWorkProductManifestReconciler)->reconcile(
            $products,
            [['path' => 'a.txt'], ['path' => 'b.txt']],
        )['manifest_reconciliation_hash'];

        $hashClean = (new AgentControlPlaneWorkProductManifestReconciler)->reconcile(
            $products,
            [['path' => 'a.txt']],
        )['manifest_reconciliation_hash'];

        $this->assertNotSame($hashMissing, $hashClean);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hashMissing);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hashClean);
    }
}

<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneAutomaticWorkProductCollectionCertificationService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneWorkProductCandidateNormalizer;
use App\Services\Ai\SelfConstruction\AgentControlPlaneWorkProductCollectionReceiptPlanner;
use App\Services\Ai\SelfConstruction\AgentControlPlaneWorkProductManifestReconciler;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AgentControlPlaneAutomaticWorkProductCollectionRuntimeTest extends TestCase
{
    public function test_certification_is_available_for_safe_manifest(): void
    {
        $cert = (new AgentControlPlaneAutomaticWorkProductCollectionCertificationService)->certify();

        $this->assertSame('atlas.self_construction.agent_control_plane_automatic_work_product_collection_runtime.v1', $cert['schema_version']);
        $this->assertSame('available', $cert['status']);
        $this->assertSame(0, $cert['violation_count']);
        $this->assertTrue($cert['invariants_all_true']);
        $this->assertFalse($cert['collection_allowed']);
        $this->assertFalse($cert['workspace_scan_allowed']);
        $this->assertFalse($cert['work_product_write_allowed']);
        $this->assertFalse($cert['provider_call_allowed']);
        $this->assertFalse($cert['token_spend_allowed']);
        $this->assertFalse($cert['dispatch_allowed']);
        $this->assertFalse($cert['self_programming_allowed']);
        $this->assertTrue($cert['runtime_safety']['runtime_safety_all_false']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $cert['certification_hash']);
    }

    public function test_certification_hash_is_deterministic_for_same_input(): void
    {
        $service = new AgentControlPlaneAutomaticWorkProductCollectionCertificationService;
        $options = ['work_product_candidates' => $this->safeCandidates(), 'expected_outputs' => [['path' => 'app/Foo.php']]];

        $first = $service->certify($options);
        $second = $service->certify($options);

        $this->assertSame($first['certification_hash'], $second['certification_hash']);
        $this->assertSame($first['work_product_normalization']['normalized_work_products_hash'], $second['work_product_normalization']['normalized_work_products_hash']);
    }

    public function test_normalizer_blocks_path_traversal_and_absolute_paths(): void
    {
        $result = (new AgentControlPlaneWorkProductCandidateNormalizer)->normalize([
            array_merge($this->safeCandidates()[0], ['path' => '../secret.php']),
            array_merge($this->safeCandidates()[0], ['artifact_id' => 'artifact-2', 'path' => '/tmp/secret.php']),
        ]);

        $codes = array_column($result['violations'], 'code');
        $this->assertSame('normalization_blocked', $result['status']);
        $this->assertContains('path_traversal_not_allowed', $codes);
        $this->assertContains('absolute_path_not_allowed', $codes);
    }

    public function test_normalizer_blocks_forbidden_segments_duplicate_ids_and_missing_hash(): void
    {
        $result = (new AgentControlPlaneWorkProductCandidateNormalizer)->normalize([
            array_merge($this->safeCandidates()[0], ['path' => 'vendor/pkg/file.php', 'artifact_hash' => '']),
            array_merge($this->safeCandidates()[0], ['path' => 'app/Other.php']),
        ]);

        $codes = array_column($result['violations'], 'code');
        $this->assertContains('forbidden_path_segment', $codes);
        $this->assertContains('missing_artifact_hash', $codes);
        $this->assertContains('duplicate_artifact_id', $codes);
    }

    public function test_certification_blocks_workspace_scan_write_and_completion_claim_requests(): void
    {
        $cert = (new AgentControlPlaneAutomaticWorkProductCollectionCertificationService)->certify([
            'work_product_candidates' => [array_merge($this->safeCandidates()[0], [
                'workspace_scan_requested' => true,
                'write_requested' => true,
                'completion_claim_requested' => true,
            ])],
            'expected_outputs' => [['path' => 'app/Foo.php']],
        ]);

        $codes = array_column($cert['violations'], 'code');
        $this->assertSame('blocked', $cert['status']);
        $this->assertContains('workspace_scan_requested', $codes);
        $this->assertContains('write_requested', $codes);
        $this->assertContains('completion_claim_requested', $codes);
    }

    public function test_receipt_planner_is_non_persistent(): void
    {
        $normalized = (new AgentControlPlaneWorkProductCandidateNormalizer)->normalize($this->safeCandidates());
        $plan = (new AgentControlPlaneWorkProductCollectionReceiptPlanner)->plan($normalized['normalized_work_products']);

        $this->assertSame('work_product_collection_receipt_plan_ready', $plan['status']);
        $this->assertSame(1, $plan['receipt_count']);
        $this->assertFalse($plan['ledger_write_allowed']);
        $this->assertFalse($plan['work_product_write_allowed']);
        $this->assertFalse($plan['receipt_persistence_allowed']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $plan['work_product_collection_receipt_plan_hash']);
    }

    public function test_reconciler_reports_missing_and_unexpected_outputs_without_collection(): void
    {
        $normalized = (new AgentControlPlaneWorkProductCandidateNormalizer)->normalize($this->safeCandidates());
        $result = (new AgentControlPlaneWorkProductManifestReconciler)->reconcile($normalized['normalized_work_products'], [
            ['path' => 'app/Foo.php'],
            ['path' => 'app/Missing.php'],
        ]);

        $this->assertSame('manifest_reconciliation_has_gaps', $result['status']);
        $this->assertSame(1, $result['missing_count']);
        $this->assertSame(0, $result['unexpected_count']);
        $this->assertFalse($result['collection_allowed']);
        $this->assertFalse($result['workspace_scan_allowed']);
        $this->assertFalse($result['work_product_write_allowed']);
    }

    public function test_certification_rejects_runtime_enabling_flags(): void
    {
        $cert = (new AgentControlPlaneAutomaticWorkProductCollectionCertificationService)->certify([
            'work_product_candidates' => $this->safeCandidates(),
            'expected_outputs' => [['path' => 'app/Foo.php']],
            'collection_allowed' => true,
            'workspace_scan_allowed' => true,
            'dispatch_allowed' => true,
        ]);

        $this->assertSame('blocked', $cert['status']);
        $this->assertGreaterThanOrEqual(3, count(array_filter($cert['violations'], static fn (array $v): bool => ($v['code'] ?? '') === 'runtime_flag_true')));
    }

    public function test_command_exposes_automatic_work_product_collection_runtime_quartet(): void
    {
        foreach ([
            '--agent-control-plane-automatic-work-product-collection-runtime-contract' => 'atlas.self_construction_agent_control_plane_automatic_work_product_collection_runtime_contract.v1',
            '--agent-control-plane-automatic-work-product-collection-runtime-preflight' => 'atlas.self_construction_agent_control_plane_automatic_work_product_collection_runtime_preflight.v1',
            '--agent-control-plane-automatic-work-product-collection-runtime-implementation-packet' => 'atlas.self_construction_agent_control_plane_automatic_work_product_collection_runtime_implementation_packet.v1',
            '--agent-control-plane-automatic-work-product-collection-runtime-status' => 'atlas.self_construction_agent_control_plane_automatic_work_product_collection_runtime_status.v1',
        ] as $flag => $schema) {
            $exit = Artisan::call('atlas:ai:self-construction', [$flag => true, '--json' => true]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exit);
            $this->assertSame($schema, $payload['schema_version']);
            $this->assertFalse($payload['execution_allowed']);
            $this->assertFalse($payload['dispatch_allowed']);
            $this->assertFalse($payload['ledger_write_allowed']);
            $this->assertFalse($payload['runtime_write_allowed']);
        }
    }

    /** @return list<array<string, mixed>> */
    private function safeCandidates(): array
    {
        return [[
            'artifact_id' => 'artifact-a',
            'path' => 'app/Foo.php',
            'artifact_hash' => hash('sha256', 'artifact-a'),
            'source' => 'manual_manifest',
        ]];
    }
}

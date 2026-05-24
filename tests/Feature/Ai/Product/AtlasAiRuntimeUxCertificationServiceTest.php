<?php

namespace Tests\Feature\Ai\Product;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Product\AtlasAiRuntimeUxCertificationService;
use App\Services\Ai\RuntimeReadiness\AtlasAiRuntimeReadinessService;
use Tests\TestCase;

/**
 * Atlas AI · Runtime UX Certification feature test.
 *
 * Guards: envelope canon stable, all 8 UX checks pass on current tree,
 * hash deterministic, and the underlying readiness endpoint exposes
 * `ux_bundle` outside the deterministic certification_hash.
 */
class AtlasAiRuntimeUxCertificationServiceTest extends TestCase
{
    public function test_envelope_shape_is_stable(): void
    {
        $report = app(AtlasAiRuntimeUxCertificationService::class)->certify();

        $this->assertSame(
            AtlasAiRuntimeUxCertificationService::SCHEMA_VERSION,
            $report['schema_version'],
        );
        $this->assertContains($report['status'], ['ready', 'partial', 'blocked']);
        $this->assertArrayHasKey('checks', $report);
        $this->assertArrayHasKey('certification_hash', $report);
        $this->assertSame(64, strlen((string) $report['certification_hash']));
        $this->assertSame(8, count($report['checks']));
        $this->assertFalse($report['writes']);
        $this->assertSame('runtime_ux_layer_only', $report['claims']['scope']);
    }

    public function test_certification_hash_is_deterministic(): void
    {
        $service = app(AtlasAiRuntimeUxCertificationService::class);
        $a = $service->certify();
        $b = $service->certify();
        $this->assertSame($a['certification_hash'], $b['certification_hash']);
    }

    public function test_all_canonical_checks_pass_on_current_tree(): void
    {
        $report = app(AtlasAiRuntimeUxCertificationService::class)->certify();

        $byId = [];
        foreach ($report['checks'] as $check) {
            $byId[$check['id']] = $check;
        }

        $expected = [
            'backend_ux_bundle',
            'assisted_execution_operational_ux',
            'desktop_view_model_extended',
            'desktop_pill_exists_and_wired',
            'mobile_view_model_extended',
            'mobile_context_sheet_wired',
            'no_raw_json_leaked_to_ui',
            'tests_cover_ux_bundle',
        ];
        foreach ($expected as $id) {
            $this->assertArrayHasKey($id, $byId, "missing check `{$id}`");
            $this->assertSame(
                'passed',
                $byId[$id]['status'],
                "check `{$id}` failed — evidence: ".json_encode($byId[$id]['evidence'] ?? []),
            );
        }
        $this->assertSame('ready', $report['status']);
    }

    public function test_runtime_readiness_endpoint_emits_ux_bundle_outside_hash(): void
    {
        $payload = app(AtlasAiRuntimeReadinessService::class)->report();

        $this->assertArrayHasKey('ux_bundle', $payload);
        $this->assertSame('atlas.ai.runtime_readiness.ux_bundle.v1', $payload['ux_bundle']['schema_version']);
        $this->assertArrayHasKey('active_mission', $payload['ux_bundle']);
        $this->assertArrayHasKey('pending_approvals_count', $payload['ux_bundle']);
        $this->assertArrayHasKey('latest_handoff', $payload['ux_bundle']);
        $this->assertArrayHasKey('assisted_execution', $payload['ux_bundle']);
        $this->assertSame(
            'atlas.ai.assisted_execution.operational_ux.v1',
            $payload['ux_bundle']['assisted_execution']['schema_version'],
        );
        $this->assertArrayHasKey('doctrine_gate_status', $payload['ux_bundle']['assisted_execution']);
        $this->assertArrayHasKey('selected_drivers', $payload['ux_bundle']['assisted_execution']);
        $this->assertArrayHasKey('context_memory_status', $payload['ux_bundle']['assisted_execution']);
        $this->assertArrayHasKey('areg_path', $payload['ux_bundle']['assisted_execution']);
        $this->assertArrayHasKey('aemor_feedback_status', $payload['ux_bundle']['assisted_execution']);

        // Recompute hash WITHOUT ux_bundle — must equal certification_hash.
        $forHash = $payload;
        unset($forHash['certification_hash'], $forHash['generated_at'], $forHash['ux_bundle']);
        $recomputed = MissionCanonicalHash::sha256($forHash);
        $this->assertSame(
            $payload['certification_hash'],
            $recomputed,
            'ux_bundle must NOT be part of the deterministic certification_hash',
        );
    }

    public function test_http_endpoint_returns_ux_bundle(): void
    {
        $token = 'test-token-with-enough-length-123';
        config()->set('atlas.token', $token);

        $response = $this->withHeaders(['X-Atlas-Token' => $token])
            ->getJson('/atlas/ai/runtime-readiness');

        $response->assertOk();
        $response->assertJsonStructure([
            'schema_version',
            'status',
            'certification_hash',
            'ux_bundle' => [
                'schema_version',
                'active_mission',
                'pending_approvals_count',
                'latest_handoff',
                'assisted_execution',
            ],
        ]);
    }
}

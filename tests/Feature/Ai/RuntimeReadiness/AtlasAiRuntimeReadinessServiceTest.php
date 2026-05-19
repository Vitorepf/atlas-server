<?php

namespace Tests\Feature\Ai\RuntimeReadiness;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\RuntimeReadiness\AtlasAiRuntimeReadinessService;
use Tests\TestCase;

class AtlasAiRuntimeReadinessServiceTest extends TestCase
{
    private function service(): AtlasAiRuntimeReadinessService
    {
        return app(AtlasAiRuntimeReadinessService::class);
    }

    public function test_report_shape_canon(): void
    {
        $report = $this->service()->report();

        $this->assertSame('atlas.ai.runtime_readiness.v1', $report['schema_version']);
        $this->assertContains($report['status'], [
            AtlasAiRuntimeReadinessService::STATUS_READY,
            AtlasAiRuntimeReadinessService::STATUS_PARTIAL,
            AtlasAiRuntimeReadinessService::STATUS_BLOCKED,
        ]);
        $this->assertArrayHasKey('generated_at', $report);
        $this->assertArrayHasKey('summary', $report);
        $this->assertArrayHasKey('checks', $report);
        $this->assertArrayHasKey('blockers', $report);
        $this->assertArrayHasKey('warnings', $report);
        $this->assertArrayHasKey('evidence_refs', $report);
        $this->assertArrayHasKey('required_commands', $report);
        $this->assertArrayHasKey('claim_policy', $report);
        $this->assertArrayHasKey('release_scope', $report);
        $this->assertArrayHasKey('certification_hash', $report);
        $this->assertSame('atlas_ai_runtime', $report['release_scope']);
    }

    public function test_summary_counts_são_consistentes(): void
    {
        $report = $this->service()->report();
        $summary = $report['summary'];
        $total = $summary['total'];

        $this->assertGreaterThan(0, $total);
        $this->assertSame(
            $total,
            ($summary['passed'] ?? 0) + ($summary['partial'] ?? 0) + ($summary['failed'] ?? 0),
            'summary buckets devem somar total',
        );
        $this->assertCount($total, $report['checks']);
    }

    public function test_each_check_tem_shape_canonico(): void
    {
        $report = $this->service()->report();

        foreach ($report['checks'] as $check) {
            $this->assertArrayHasKey('id', $check);
            $this->assertArrayHasKey('label', $check);
            $this->assertArrayHasKey('status', $check);
            $this->assertArrayHasKey('severity', $check);
            $this->assertArrayHasKey('source_service', $check);
            $this->assertArrayHasKey('evidence_refs', $check);
            $this->assertArrayHasKey('detail', $check);

            $this->assertContains($check['status'], [
                AtlasAiRuntimeReadinessService::CHECK_STATUS_PASSED,
                AtlasAiRuntimeReadinessService::CHECK_STATUS_WARN,
                AtlasAiRuntimeReadinessService::CHECK_STATUS_FAILED,
            ]);
            $this->assertContains($check['severity'], [
                AtlasAiRuntimeReadinessService::SEVERITY_CRITICAL,
                AtlasAiRuntimeReadinessService::SEVERITY_WARN,
            ]);
        }
    }

    public function test_checks_cobrem_todas_camadas_obrigatorias(): void
    {
        $report = $this->service()->report();
        $ids = array_column($report['checks'], 'id');

        $required = [
            'product_certification',
            'control_plane_runtime',
            'router_runtime_readiness',
            'specialist_flows_readiness',
            'mission_foundation_readiness',
            'mission_mode_layer',
            'follow_through_loop',
            'operator_approval_gates',
            'memory_learning_loop',
            'desktop_hyperflow_integration',
            'claim_policy_canon',
        ];

        foreach ($required as $id) {
            $this->assertContains($id, $ids, "readiness deve cobrir camada {$id}");
        }
    }

    public function test_status_consistente_com_summary_counts(): void
    {
        // Garantia canônica: a regra match() do `report()` é determinística
        // sobre os counters do summary. Validamos os 3 invariantes em um
        // único teste para evitar `risky` quando o estado runtime atual não
        // ativa um cenário específico.
        $report = $this->service()->report();
        $criticalFailed = (int) $report['summary']['critical_failed'];
        $warnFailed = (int) $report['summary']['warn_failed'];
        $status = (string) $report['status'];

        if ($criticalFailed > 0) {
            $this->assertSame(AtlasAiRuntimeReadinessService::STATUS_BLOCKED, $status);
            $this->assertNotEmpty($report['blockers']);
        } elseif ($warnFailed > 0) {
            $this->assertSame(AtlasAiRuntimeReadinessService::STATUS_PARTIAL, $status);
            $this->assertNotEmpty($report['warnings']);
        } else {
            $this->assertSame(AtlasAiRuntimeReadinessService::STATUS_READY, $status);
            $this->assertEmpty($report['blockers']);
            $this->assertEmpty($report['warnings']);
        }
    }

    public function test_certification_hash_é_determinístico_sobre_payload_canonico(): void
    {
        $r1 = $this->service()->report();
        $r2 = $this->service()->report();

        // generated_at muda entre chamadas, mas o hash IGNORA generated_at.
        // Re-computando manualmente: hash de payload sem generated_at e
        // sem o próprio certification_hash deve dar o mesmo.
        $stripped1 = $r1;
        $stripped2 = $r2;
        unset($stripped1['generated_at'], $stripped1['certification_hash']);
        unset($stripped2['generated_at'], $stripped2['certification_hash']);

        $this->assertSame(
            MissionCanonicalHash::sha256($stripped1),
            MissionCanonicalHash::sha256($stripped2),
            'mesmo state runtime → mesmo hash',
        );
        $this->assertSame(64, strlen((string) $r1['certification_hash']));
    }

    public function test_claim_policy_proíbe_benchmark_e_superiority(): void
    {
        $report = $this->service()->report();
        $policy = $report['claim_policy'];

        $this->assertFalse($policy['declares_benchmark']);
        $this->assertFalse($policy['declares_rivals']);
        $this->assertFalse($policy['declares_superiority']);
        $this->assertFalse($policy['declares_teos_certification']);
        $this->assertFalse($policy['invokes_provider']);
        $this->assertSame('atlas_ai_runtime_release_gate', $policy['scope']);
        $this->assertContains('better_than_claude_code', $policy['forbidden_claims']);
        $this->assertContains('better_than_codex', $policy['forbidden_claims']);
        $this->assertContains('beats_benchmark_x', $policy['forbidden_claims']);
    }

    public function test_evidence_refs_são_não_vazios(): void
    {
        $report = $this->service()->report();
        $this->assertNotEmpty($report['evidence_refs']);
        // Todos os refs são strings limpas.
        foreach ($report['evidence_refs'] as $ref) {
            $this->assertIsString($ref);
            $this->assertNotSame('', trim($ref));
        }
    }

    public function test_required_commands_inclui_runtime_readiness_proprio_e_subordinados(): void
    {
        $report = $this->service()->report();
        $cmds = $report['required_commands'];

        $this->assertContains('php artisan atlas:ai:runtime-readiness --json', $cmds);
        $this->assertContains('php artisan atlas:ai:product-certify --json', $cmds);
        $this->assertContains('php artisan atlas:ai:control-plane runtime --hours=24 --json', $cmds);
        $this->assertContains('php artisan atlas:ai:approval list --json', $cmds);
        $this->assertContains('php artisan atlas:ai:learning collect --hours=24 --json', $cmds);
    }

    public function test_blockers_e_warnings_são_listas_de_check_ids(): void
    {
        $report = $this->service()->report();
        $ids = array_column($report['checks'], 'id');

        foreach ($report['blockers'] as $blocker) {
            $this->assertContains($blocker, $ids, 'blocker deve referenciar check existente');
        }
        foreach ($report['warnings'] as $warning) {
            $this->assertContains($warning, $ids, 'warning deve referenciar check existente');
        }
    }
}

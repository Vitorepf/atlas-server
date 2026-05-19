<?php

namespace Tests\Feature\Ai\RuntimeReleaseGate;

use App\Services\Ai\RuntimeReadiness\AtlasAiRuntimeReadinessService;
use App\Services\Ai\RuntimeReleaseGate\AtlasAiRuntimeReleaseGateService;
use Tests\TestCase;

/**
 * Stub readiness service that produces deterministic reports for each
 * macro-level scenario the release gate must distinguish.
 */
class AtlasAiRuntimeReleaseGateServiceTest extends TestCase
{
    private function bindStub(string $upstreamStatus, array $checks = [], array $blockers = [], array $warnings = [], array $extra = []): void
    {
        $this->app->bind(
            AtlasAiRuntimeReadinessService::class,
            fn () => new StubReadinessService($upstreamStatus, $checks, $blockers, $warnings, $extra),
        );
    }

    private function gate(): AtlasAiRuntimeReleaseGateService
    {
        return $this->app->make(AtlasAiRuntimeReleaseGateService::class);
    }

    public function test_ready_when_upstream_ready_and_no_blockers_warnings(): void
    {
        $checks = [
            $this->passedCheck('product_certification'),
            $this->passedCheck('control_plane_runtime'),
            $this->passedCheck('router_runtime'),
            $this->passedCheck('specialist_flows'),
            $this->passedCheck('mission_mode'),
            $this->passedCheck('follow_through_loop'),
            $this->passedCheck('operator_approval_gates'),
            $this->passedCheck('memory_learning_loop'),
            $this->passedCheck('desktop_hyperflow_integration'),
            $this->passedCheck('claim_policy'),
        ];
        $this->bindStub(AtlasAiRuntimeReadinessService::STATUS_READY, $checks);

        $report = $this->gate()->report();

        $this->assertSame('atlas.ai.runtime_release_gate.v1', $report['schema_version']);
        $this->assertSame('ready', $report['status']);
        $this->assertSame('atlas_ai_hyperflow_runtime_principal', $report['macro']);
        $this->assertSame([], $report['blockers']);
        $this->assertSame([], $report['warnings']);
        $this->assertSame('atlas_teos_i2_macro', $report['next_macro_recommendation']['next_macro']);
        $this->assertContains(
            'do_not_run_external_rivals_inside_this_macro',
            $report['next_macro_recommendation']['preconditions'],
        );
    }

    public function test_partial_when_follow_through_missing_marked_warn(): void
    {
        $checks = [
            $this->passedCheck('product_certification'),
            $this->warnCheck('follow_through_loop', 'service class missing'),
        ];
        $this->bindStub(
            AtlasAiRuntimeReadinessService::STATUS_PARTIAL,
            $checks,
            blockers: [],
            warnings: ['follow_through_loop'],
        );

        $report = $this->gate()->report();

        $this->assertSame('partial', $report['status']);
        $this->assertContains('follow_through_loop', $report['warnings']);
        $this->assertSame('stay_in_atlas_ai_hyperflow_runtime_principal', $report['next_macro_recommendation']['next_macro']);
        $this->assertContains(
            'open_teos_i2_before_closing_warnings',
            $report['next_macro_recommendation']['forbidden_jumps'],
        );
    }

    public function test_partial_when_approval_gates_missing_marked_warn(): void
    {
        $this->bindStub(
            AtlasAiRuntimeReadinessService::STATUS_PARTIAL,
            checks: [$this->warnCheck('operator_approval_gates', 'table missing')],
            warnings: ['operator_approval_gates'],
        );

        $report = $this->gate()->report();

        $this->assertSame('partial', $report['status']);
        $this->assertContains('operator_approval_gates', $report['warnings']);
    }

    public function test_partial_when_learning_loop_missing_marked_warn(): void
    {
        $this->bindStub(
            AtlasAiRuntimeReadinessService::STATUS_PARTIAL,
            checks: [$this->warnCheck('memory_learning_loop', 'signals table missing')],
            warnings: ['memory_learning_loop'],
        );

        $report = $this->gate()->report();

        $this->assertSame('partial', $report['status']);
        $this->assertContains('memory_learning_loop', $report['warnings']);
    }

    public function test_partial_when_desktop_ux_cert_missing_marked_warn(): void
    {
        $this->bindStub(
            AtlasAiRuntimeReadinessService::STATUS_PARTIAL,
            checks: [$this->warnCheck('desktop_hyperflow_integration', 'cert blocked')],
            warnings: ['desktop_hyperflow_integration'],
        );

        $report = $this->gate()->report();

        $this->assertSame('partial', $report['status']);
        $this->assertContains('desktop_hyperflow_integration', $report['warnings']);
    }

    public function test_blocked_when_product_cert_blocked(): void
    {
        $this->bindStub(
            AtlasAiRuntimeReadinessService::STATUS_BLOCKED,
            checks: [$this->failedCheck('product_certification', 'critical', 'mobile_canon_import missing')],
            blockers: ['product_certification'],
        );

        $report = $this->gate()->report();

        $this->assertSame('blocked', $report['status']);
        $this->assertContains('product_certification', $report['blockers']);
        $this->assertSame('stay_in_atlas_ai_hyperflow_runtime_principal', $report['next_macro_recommendation']['next_macro']);
        $this->assertContains('product_certification', $report['next_macro_recommendation']['blockers']);
        $this->assertContains(
            'open_teos_i2_with_blocked_macro',
            $report['next_macro_recommendation']['forbidden_jumps'],
        );
    }

    public function test_unknown_upstream_status_fails_safe_to_blocked(): void
    {
        $this->bindStub('mystery_value');

        $report = $this->gate()->report();

        $this->assertSame('blocked', $report['status']);
    }

    public function test_required_commands_include_release_gate_first(): void
    {
        $this->bindStub(AtlasAiRuntimeReadinessService::STATUS_READY);
        $report = $this->gate()->report();

        $this->assertNotEmpty($report['required_commands']);
        $this->assertSame(
            'php artisan atlas:ai:runtime-release-gate --json',
            $report['required_commands'][0],
        );
    }

    public function test_evidence_refs_are_string_list(): void
    {
        $this->bindStub(
            AtlasAiRuntimeReadinessService::STATUS_READY,
            extra: ['evidence_refs' => ['evidence-1', 'evidence-2', 'evidence-1']],
        );

        $report = $this->gate()->report();
        $this->assertSame(['evidence-1', 'evidence-2'], $report['evidence_refs']);
    }

    public function test_claim_policy_blocks_superiority_and_benchmark_claims(): void
    {
        $this->bindStub(AtlasAiRuntimeReadinessService::STATUS_READY);

        $report = $this->gate()->report();
        $claim = $report['claim_policy'];

        $this->assertFalse($claim['declares_benchmark']);
        $this->assertFalse($claim['declares_rivals']);
        $this->assertFalse($claim['declares_superiority']);
        $this->assertFalse($claim['declares_teos_certification']);
        $this->assertFalse($claim['invokes_provider']);
        foreach ([
            'better_than_claude_code',
            'better_than_codex',
            'better_than_cursor',
            'beats_benchmark_x',
            'wins_arena_y',
            'teos_certified_unless_explicitly_proven',
            'external_rivals_certified',
            'production_grade_unless_evidence_proven',
        ] as $forbidden) {
            $this->assertContains($forbidden, $claim['forbidden_claims']);
        }
    }

    public function test_claim_policy_cannot_be_loosened_by_upstream(): void
    {
        // Stub tries to declare benchmark=true; macro must override boolean
        // fences are strictly tighter — true wins, false stays false.
        $this->bindStub(
            AtlasAiRuntimeReadinessService::STATUS_READY,
            extra: ['claim_policy' => [
                'declares_benchmark' => true,        // honest about a leak — macro propagates true
                'declares_superiority' => false,     // macro keeps false (cannot loosen)
                'scope' => 'attempt_to_override',    // macro keeps its own scope
                'forbidden_claims' => ['extra_one'], // macro merges
            ]],
        );

        $report = $this->gate()->report();
        $claim = $report['claim_policy'];

        $this->assertTrue($claim['declares_benchmark'], 'macro accepts honest true from upstream');
        $this->assertFalse($claim['declares_superiority']);
        $this->assertSame('atlas_ai_hyperflow_runtime_principal_release_gate', $claim['scope']);
        $this->assertContains('extra_one', $claim['forbidden_claims']);
        $this->assertContains('teos_certified_unless_explicitly_proven', $claim['forbidden_claims']);
    }

    public function test_certification_hash_is_deterministic(): void
    {
        $this->bindStub(AtlasAiRuntimeReadinessService::STATUS_READY);

        $a = $this->gate()->report();
        $b = $this->gate()->report();

        $this->assertSame(64, strlen($a['certification_hash']));
        $this->assertSame(
            $a['certification_hash'],
            $b['certification_hash'],
            'hash must be deterministic for identical canonical inputs (generated_at excluded)',
        );
    }

    public function test_certification_hash_changes_when_status_changes(): void
    {
        $this->bindStub(AtlasAiRuntimeReadinessService::STATUS_READY);
        $ready = $this->gate()->report();

        $this->app->forgetInstance(AtlasAiRuntimeReleaseGateService::class);
        $this->bindStub(
            AtlasAiRuntimeReadinessService::STATUS_BLOCKED,
            checks: [$this->failedCheck('product_certification', 'critical', 'broken')],
            blockers: ['product_certification'],
        );
        $blocked = $this->gate()->report();

        $this->assertNotSame($ready['certification_hash'], $blocked['certification_hash']);
    }

    public function test_upstream_readiness_block_is_surfaced(): void
    {
        $this->bindStub(AtlasAiRuntimeReadinessService::STATUS_READY);
        $report = $this->gate()->report();

        $this->assertSame('atlas.ai.runtime_readiness.v1', $report['upstream_readiness']['schema_version']);
        $this->assertSame('ready', $report['upstream_readiness']['status']);
        $this->assertNotEmpty($report['upstream_readiness']['certification_hash']);
    }

    /**
     * @return array<string,mixed>
     */
    private function passedCheck(string $id): array
    {
        return [
            'id' => $id,
            'label' => "check {$id}",
            'status' => 'passed',
            'severity' => 'critical',
            'source_service' => 'StubService',
            'evidence_refs' => ["php artisan atlas:ai:stub:{$id} --json"],
            'detail' => [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function warnCheck(string $id, string $reason): array
    {
        return [
            'id' => $id,
            'label' => "check {$id}",
            'status' => 'warn',
            'severity' => 'warn',
            'source_service' => 'StubService',
            'evidence_refs' => [],
            'detail' => ['reason' => $reason],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function failedCheck(string $id, string $severity, string $reason): array
    {
        return [
            'id' => $id,
            'label' => "check {$id}",
            'status' => 'failed',
            'severity' => $severity,
            'source_service' => 'StubService',
            'evidence_refs' => [],
            'detail' => ['reason' => $reason],
        ];
    }
}

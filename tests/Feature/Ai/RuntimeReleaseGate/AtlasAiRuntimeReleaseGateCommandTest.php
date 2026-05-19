<?php

namespace Tests\Feature\Ai\RuntimeReleaseGate;

use App\Services\Ai\RuntimeReadiness\AtlasAiRuntimeReadinessService;
use App\Services\Ai\RuntimeReleaseGate\AtlasAiRuntimeReleaseGateService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasAiRuntimeReleaseGateCommandTest extends TestCase
{
    private function bindStub(string $upstreamStatus, array $checks = [], array $blockers = [], array $warnings = []): void
    {
        $this->app->bind(
            AtlasAiRuntimeReadinessService::class,
            fn () => new StubReadinessService($upstreamStatus, $checks, $blockers, $warnings),
        );
    }

    private function runJson(array $params): array
    {
        $exit = Artisan::call('atlas:ai:runtime-release-gate', $params);
        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);

        return ['exit' => $exit, 'payload' => $payload];
    }

    public function test_command_returns_canonical_shape_when_ready(): void
    {
        $this->bindStub(AtlasAiRuntimeReadinessService::STATUS_READY);

        $result = $this->runJson(['--json' => true]);

        $this->assertSame(0, $result['exit']);
        $payload = $result['payload'];
        $this->assertTrue($payload['ok']);
        $this->assertSame('runtime-release-gate', $payload['action']);
        $this->assertFalse($payload['strict']);

        $report = $payload['report'];
        $this->assertSame('atlas.ai.runtime_release_gate.v1', $report['schema_version']);
        $this->assertSame('ready', $report['status']);
        $this->assertSame('atlas_ai_hyperflow_runtime_principal', $report['macro']);
        foreach (['summary', 'checks', 'blockers', 'warnings', 'evidence_refs', 'required_commands', 'claim_policy', 'next_macro_recommendation', 'certification_hash'] as $key) {
            $this->assertArrayHasKey($key, $report, "report must expose [{$key}]");
        }
    }

    public function test_strict_exits_3_when_partial(): void
    {
        $this->bindStub(
            AtlasAiRuntimeReadinessService::STATUS_PARTIAL,
            checks: [['id' => 'memory_learning_loop', 'label' => 'l', 'status' => 'warn', 'severity' => 'warn', 'source_service' => 'S', 'evidence_refs' => [], 'detail' => []]],
            warnings: ['memory_learning_loop'],
        );

        $result = $this->runJson(['--strict' => true, '--json' => true]);

        $this->assertSame(3, $result['exit']);
        $this->assertFalse($result['payload']['ok']);
        $this->assertTrue($result['payload']['strict']);
        $this->assertSame('partial', $result['payload']['report']['status']);
    }

    public function test_strict_exits_3_when_blocked(): void
    {
        $this->bindStub(
            AtlasAiRuntimeReadinessService::STATUS_BLOCKED,
            checks: [['id' => 'product_certification', 'label' => 'l', 'status' => 'failed', 'severity' => 'critical', 'source_service' => 'S', 'evidence_refs' => [], 'detail' => []]],
            blockers: ['product_certification'],
        );

        $result = $this->runJson(['--strict' => true, '--json' => true]);

        $this->assertSame(3, $result['exit']);
        $this->assertSame('blocked', $result['payload']['report']['status']);
    }

    public function test_strict_exits_0_when_ready(): void
    {
        $this->bindStub(AtlasAiRuntimeReadinessService::STATUS_READY);

        $result = $this->runJson(['--strict' => true, '--json' => true]);

        $this->assertSame(0, $result['exit']);
        $this->assertTrue($result['payload']['ok']);
    }

    public function test_command_propagates_blockers_and_warnings(): void
    {
        $this->bindStub(
            AtlasAiRuntimeReadinessService::STATUS_BLOCKED,
            checks: [['id' => 'product_certification', 'label' => 'l', 'status' => 'failed', 'severity' => 'critical', 'source_service' => 'S', 'evidence_refs' => [], 'detail' => []]],
            blockers: ['product_certification'],
            warnings: ['operator_approval_gates'],
        );

        $result = $this->runJson(['--json' => true]);
        $report = $result['payload']['report'];
        $this->assertContains('product_certification', $report['blockers']);
        $this->assertContains('operator_approval_gates', $report['warnings']);
    }

    public function test_command_uses_macro_id_in_next_recommendation(): void
    {
        $this->bindStub(AtlasAiRuntimeReadinessService::STATUS_READY);

        $result = $this->runJson(['--json' => true]);
        $report = $result['payload']['report'];

        $this->assertSame(AtlasAiRuntimeReleaseGateService::MACRO_ID, $report['macro']);
        $this->assertSame('atlas_teos_i2_macro', $report['next_macro_recommendation']['next_macro']);
    }

    public function test_command_works_with_real_readiness_service_smoke(): void
    {
        // Smoke: no stub. Real service runs. Status may be ready/partial/blocked
        // depending on env. Just assert the macro shape is honored.
        $result = $this->runJson(['--json' => true]);

        $report = $result['payload']['report'];
        $this->assertSame('atlas.ai.runtime_release_gate.v1', $report['schema_version']);
        $this->assertSame('atlas_ai_hyperflow_runtime_principal', $report['macro']);
        $this->assertContains($report['status'], ['ready', 'partial', 'blocked']);
        $this->assertNotEmpty($report['certification_hash']);
    }
}

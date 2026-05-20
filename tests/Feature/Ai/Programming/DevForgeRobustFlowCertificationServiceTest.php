<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\LongHorizon\AtlasTeosFinalCertificationService;
use App\Services\Ai\Programming\DevForgeRobustFlowCertificationService;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

final class DevForgeRobustFlowCertificationServiceTest extends TestCase
{
    public function test_certifies_dev_forge_are_wired_to_robust_local_flow(): void
    {
        $this->bindTeosFinalCertification(AtlasTeosFinalCertificationService::STATUS_READY);

        $payload = app(DevForgeRobustFlowCertificationService::class)->certify();

        $this->assertSame(DevForgeRobustFlowCertificationService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(DevForgeRobustFlowCertificationService::STATUS_PASSED, $payload['status']);
        $this->assertSame(7, $payload['summary']['total']);
        $this->assertSame(7, $payload['summary']['pass']);
        $this->assertSame(0, $payload['summary']['fail']);
        $this->assertFalse($payload['writes']);
        $this->assertTrue($payload['claim_policy']['benchmark_not_run']);
        $this->assertFalse($payload['claim_policy']['rivals_compared']);
        $this->assertFalse($payload['claim_policy']['provider_calls_made']);
        $this->assertFalse($payload['claim_policy']['allows_external_superiority_claim']);

        $checks = collect($payload['checks'])->keyBy('id');
        foreach ([
            'dev_fast_path_mandatory_rag',
            'dev_continuation_pack_surface',
            'forge_continuation_pack_surface',
            'dev_to_forge_escalation_contract',
            'programming_console_real_teos',
            'teos_final_certification',
            'no_external_execution_policy',
        ] as $checkId) {
            $this->assertSame('pass', $checks[$checkId]['status'] ?? null, $checkId);
        }
    }

    public function test_certification_hash_is_deterministic_ignoring_generated_at(): void
    {
        $this->bindTeosFinalCertification(AtlasTeosFinalCertificationService::STATUS_READY);

        $a = app(DevForgeRobustFlowCertificationService::class)->certify();
        $b = app(DevForgeRobustFlowCertificationService::class)->certify();

        $this->assertSame($a['certification_hash'], $b['certification_hash']);
    }

    public function test_command_emits_json_and_strict_passes(): void
    {
        $this->bindTeosFinalCertification(AtlasTeosFinalCertificationService::STATUS_READY);

        $exit = Artisan::call('atlas:programming:dev-forge-flow-certify', [
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true);
        $this->assertSame(DevForgeRobustFlowCertificationService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(DevForgeRobustFlowCertificationService::STATUS_PASSED, $payload['status']);
        $this->assertTrue($payload['claim_policy']['benchmark_not_run']);
    }

    public function test_blocks_when_teos_final_certification_is_not_ready(): void
    {
        $this->bindTeosFinalCertification(AtlasTeosFinalCertificationService::STATUS_BLOCKED);

        $payload = app(DevForgeRobustFlowCertificationService::class)->certify();

        $this->assertSame(DevForgeRobustFlowCertificationService::STATUS_BLOCKED, $payload['status']);
        $this->assertSame('fail', collect($payload['checks'])->firstWhere('id', 'teos_final_certification')['status'] ?? null);
    }

    private function bindTeosFinalCertification(string $status): void
    {
        /** @var AtlasTeosFinalCertificationService&MockInterface $mock */
        $mock = Mockery::mock(AtlasTeosFinalCertificationService::class);
        $mock->shouldReceive('certify')->andReturn([
            'status' => $status,
            'certification_hash' => 'sha256:test-teos-final',
            'summary' => [
                'total' => 6,
                'pass' => $status === AtlasTeosFinalCertificationService::STATUS_READY ? 6 : 5,
                'warn' => 0,
                'fail' => $status === AtlasTeosFinalCertificationService::STATUS_READY ? 0 : 1,
            ],
            'blockers' => $status === AtlasTeosFinalCertificationService::STATUS_READY ? [] : [['id' => 'fake_blocker']],
            'warnings' => [],
        ]);

        $this->instance(AtlasTeosFinalCertificationService::class, $mock);
    }
}

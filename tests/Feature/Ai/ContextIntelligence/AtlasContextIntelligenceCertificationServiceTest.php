<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\ContextIntelligence;

use App\Services\Ai\ContextIntelligence\AtlasContextIntelligenceCertificationService;
use App\Services\Ai\LongHorizon\AtlasTeosFinalCertificationService;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

final class AtlasContextIntelligenceCertificationServiceTest extends TestCase
{
    public function test_certification_passes_with_teos_and_dev_forge_ready(): void
    {
        $this->bindTeosFinal(AtlasTeosFinalCertificationService::STATUS_READY);

        $payload = app(AtlasContextIntelligenceCertificationService::class)->certify();

        $this->assertSame('atlas.context_intelligence.certification.v1', $payload['schema_version']);
        $this->assertSame('passed', $payload['status']);
        $this->assertSame(12, $payload['summary']['total']);
        $this->assertSame(12, $payload['summary']['pass']);
        $this->assertFalse($payload['writes']);
        $this->assertFalse($payload['claim_policy']['provider_calls_made']);
        $this->assertFalse($payload['claim_policy']['rivals_compared']);
        $this->assertTrue($payload['claim_policy']['benchmark_not_run']);
    }

    public function test_certification_blocks_when_teos_is_not_ready(): void
    {
        $this->bindTeosFinal(AtlasTeosFinalCertificationService::STATUS_BLOCKED);

        $payload = app(AtlasContextIntelligenceCertificationService::class)->certify();

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('fail', collect($payload['checks'])->firstWhere('id', 'teos_final_certification')['status'] ?? null);
    }

    public function test_command_emits_json_and_strict_passes(): void
    {
        $this->bindTeosFinal(AtlasTeosFinalCertificationService::STATUS_READY);

        $exit = Artisan::call('atlas:context-intelligence:certify', [
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true);
        $this->assertSame('atlas.context_intelligence.certification.v1', $payload['schema_version']);
        $this->assertSame('passed', $payload['status']);
    }

    private function bindTeosFinal(string $status): void
    {
        /** @var AtlasTeosFinalCertificationService&MockInterface $mock */
        $mock = Mockery::mock(AtlasTeosFinalCertificationService::class);
        $mock->shouldReceive('certify')->andReturn([
            'status' => $status,
            'certification_hash' => 'sha256:teos',
            'summary' => ['total' => 6, 'pass' => $status === AtlasTeosFinalCertificationService::STATUS_READY ? 6 : 5, 'warn' => 0, 'fail' => $status === AtlasTeosFinalCertificationService::STATUS_READY ? 0 : 1],
            'blockers' => $status === AtlasTeosFinalCertificationService::STATUS_READY ? [] : [['id' => 'fake']],
        ]);
        $this->instance(AtlasTeosFinalCertificationService::class, $mock);
    }
}

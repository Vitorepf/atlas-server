<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\PersistentContext;

use App\Services\Ai\PersistentContext\AtlasPersistentContextCertificationService;
use App\Services\Ai\PersistentContext\AtlasPersistentContextRuntimeService;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

final class AtlasPersistentContextCertificationServiceTest extends TestCase
{
    public function test_certification_passes_when_apcr_wiring_is_present(): void
    {
        $this->bindRuntimeSmoke();

        $payload = app(AtlasPersistentContextCertificationService::class)->certify();

        $this->assertSame(AtlasPersistentContextCertificationService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(AtlasPersistentContextCertificationService::STATUS_PASSED, $payload['status']);
        $this->assertSame(14, $payload['summary']['total']);
        $this->assertSame(14, $payload['summary']['pass']);
        $this->assertSame(0, $payload['summary']['fail']);
        $this->assertFalse($payload['writes']);
        $this->assertFalse($payload['claim_policy']['provider_calls_made']);
        $this->assertFalse($payload['claim_policy']['rivals_compared']);
        $this->assertTrue($payload['claim_policy']['benchmark_not_run']);
        $this->assertIsString($payload['certification_hash']);
    }

    public function test_command_emits_json_and_strict_passes(): void
    {
        $this->bindRuntimeSmoke();

        $exit = Artisan::call('atlas:persistent-context:certify', [
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true);
        $this->assertSame(AtlasPersistentContextCertificationService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(AtlasPersistentContextCertificationService::STATUS_PASSED, $payload['status']);
    }

    private function bindRuntimeSmoke(): void
    {
        /** @var AtlasPersistentContextRuntimeService&MockInterface $mock */
        $mock = Mockery::mock(AtlasPersistentContextRuntimeService::class);
        $mock->shouldReceive('build')->andReturn([
            'schema_version' => AtlasPersistentContextRuntimeService::SCHEMA_VERSION,
            'status' => AtlasPersistentContextRuntimeService::STATUS_READY,
            'persistent_context_hash' => 'sha256:apcr',
            'sufficiency' => ['status' => 'sufficient'],
            'must_know_ledger' => ['items' => [['id' => 'mk1']]],
            'provider_handoff' => ['execution_allowed' => true],
            'claim_policy' => [
                'provider_calls_made' => false,
                'benchmark_not_run' => true,
                'rivals_compared' => false,
            ],
        ]);
        $this->instance(AtlasPersistentContextRuntimeService::class, $mock);
    }
}

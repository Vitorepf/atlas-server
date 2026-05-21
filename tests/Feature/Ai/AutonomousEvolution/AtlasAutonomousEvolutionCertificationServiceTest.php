<?php

namespace Tests\Feature\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasAutonomousEvolutionCertificationService;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesAaelTables;
use Tests\TestCase;

class AtlasAutonomousEvolutionCertificationServiceTest extends TestCase
{
    use CreatesAaelTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAaelTables();
    }

    protected function tearDown(): void
    {
        $this->dropAaelTables();

        parent::tearDown();
    }

    public function test_certification_passes_all_checks(): void
    {
        $payload = app(AtlasAutonomousEvolutionCertificationService::class)->certify();

        $this->assertSame(AtlasAutonomousEvolutionCertificationService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('passed', $payload['status']);
        $this->assertSame(0, data_get($payload, 'summary.fail'));
        $this->assertNotEmpty($payload['certification_hash']);
    }

    public function test_certify_command_supports_strict_json(): void
    {
        $exit = Artisan::call('atlas:aael:certify', ['--json' => true, '--strict' => true]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('passed', $payload['status']);
        $this->assertSame(0, data_get($payload, 'summary.fail'));
    }
}

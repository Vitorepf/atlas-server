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

    /**
     * Pins the exact `integration_wiring` check output. Before the refactor this
     * shape was produced by a hand-copied 17-line clone of `fileCheck()`; after
     * the refactor it is produced by delegating to `fileCheck()`. The delegation
     * is behavior-preserving iff this byte-exact array is unchanged. Unlike the
     * full `certification_hash` (which is non-deterministic because the runtime
     * smoke checks embed per-run cycle hashes), this single file-based check is
     * deterministic, so it is a valid pin for the refactor.
     */
    public function test_integration_wiring_check_has_canonical_file_check_shape(): void
    {
        $payload = app(AtlasAutonomousEvolutionCertificationService::class)->certify();

        $integrationWiring = collect($payload['checks'])->firstWhere('id', 'integration_wiring');

        $this->assertSame([
            'id' => 'integration_wiring',
            'status' => 'pass',
            'evidence' => ['app/Services/Ai/AutonomousEvolution/AtlasAutonomousEvolutionLoopService.php'],
            'missing' => [],
        ], $integrationWiring);
    }

    /**
     * Proves there is a single source of truth for the file-token-check contract:
     * the `integration_wiring` check is byte-identical to what the canonical
     * `fileCheck()` helper produces for the same id, path and tokens. If a future
     * change to the token-check contract (e.g. case-insensitive or regex tokens)
     * is made in `fileCheck()` but the integration check has drifted back into a
     * private clone, these two arrays diverge and this test fails.
     */
    public function test_integration_wiring_matches_generic_file_check_with_no_drift(): void
    {
        $service = app(AtlasAutonomousEvolutionCertificationService::class);

        $payload = $service->certify();
        $integrationWiring = collect($payload['checks'])->firstWhere('id', 'integration_wiring');

        $reflection = new \ReflectionMethod($service, 'fileCheck');
        $reflection->setAccessible(true);
        $canonical = $reflection->invoke(
            $service,
            'integration_wiring',
            'app/Services/Ai/AutonomousEvolution/AtlasAutonomousEvolutionLoopService.php',
            ['AtlasAutonomousWorkExecutionService', 'AtlasIntelligenceFactoryRuntimeService'],
        );

        $this->assertSame($canonical, $integrationWiring);
    }
}

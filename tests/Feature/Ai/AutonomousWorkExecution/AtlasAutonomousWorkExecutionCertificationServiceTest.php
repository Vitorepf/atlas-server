<?php

namespace Tests\Feature\Ai\AutonomousWorkExecution;

use App\Services\Ai\AutonomousWorkExecution\AtlasAutonomousWorkExecutionCertificationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAemorTables;
use Tests\Concerns\CreatesAgenticWorkcellTables;
use Tests\Concerns\CreatesAweosTables;
use Tests\Concerns\CreatesPersistentContextTables;
use Tests\Concerns\CreatesRuntimeEfficiencyTables;
use Tests\TestCase;

class AtlasAutonomousWorkExecutionCertificationServiceTest extends TestCase
{
    use CreatesAemorTables;
    use CreatesAgenticWorkcellTables;
    use CreatesAweosTables;
    use CreatesPersistentContextTables;
    use CreatesRuntimeEfficiencyTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPersistentContextTables();
        $this->createRuntimeEfficiencyTables();
        $this->createAgenticWorkcellTables();
        $this->createAemorTables(false);
        $this->createAweosTables();
    }

    protected function tearDown(): void
    {
        $this->dropAweosTables();
        $this->dropAemorTables();
        $this->dropAgenticWorkcellTables();
        $this->dropRuntimeEfficiencyTables();
        $this->dropPersistentContextTables();

        parent::tearDown();
    }

    public function test_certification_passes_with_all_canonical_artifacts(): void
    {
        $payload = app(AtlasAutonomousWorkExecutionCertificationService::class)->certify();

        $this->assertSame('atlas.aweos.certification.v1', $payload['schema_version']);
        $this->assertSame('passed', $payload['status']);
        $this->assertSame(12, $payload['summary']['total']);
        $this->assertSame(12, $payload['summary']['pass']);
        $this->assertSame([], $payload['blockers']);
        $this->assertFalse(data_get($payload, 'claim_policy.provider_invoked_directly'));
        $this->assertTrue(data_get($payload, 'claim_policy.completion_requires_certified_outcome'));
    }

    public function test_certification_smokes_do_not_pollute_aweos_tables(): void
    {
        $before = [
            'executions' => DB::table('atlas_aweos_executions')->count(),
            'events' => DB::table('atlas_aweos_events')->count(),
            'outcomes' => DB::table('atlas_aweos_certified_outcomes')->count(),
        ];

        $payload = app(AtlasAutonomousWorkExecutionCertificationService::class)->certify();

        $this->assertSame('passed', $payload['status']);
        $this->assertSame($before['executions'], DB::table('atlas_aweos_executions')->count());
        $this->assertSame($before['events'], DB::table('atlas_aweos_events')->count());
        $this->assertSame($before['outcomes'], DB::table('atlas_aweos_certified_outcomes')->count());
    }

    public function test_commands_run_control_plane_and_certify_json(): void
    {
        Artisan::call('atlas:aweos', [
            'action' => 'run',
            '--objective' => 'AWEOS command smoke',
            '--domain' => 'research',
            '--evidence' => ['test:command'],
            '--json' => true,
        ]);
        $run = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.aweos.execution.v1', $run['schema_version']);

        Artisan::call('atlas:aweos', ['action' => 'control-plane', '--json' => true]);
        $controlPlane = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.aweos.control_plane.v1', $controlPlane['schema_version']);
        $this->assertGreaterThanOrEqual(1, data_get($controlPlane, 'summary.executions_total'));

        $exit = Artisan::call('atlas:aweos:certify', ['--json' => true, '--strict' => true]);
        $cert = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $exit);
        $this->assertSame('passed', $cert['status']);
    }
}

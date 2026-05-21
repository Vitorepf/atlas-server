<?php

namespace Tests\Feature\Ai\VerifiedExecution;

use App\Services\Ai\VerifiedExecution\AtlasVerifiedExecutionCertificationService;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesAverTables;
use Tests\TestCase;

class AtlasVerifiedExecutionCertificationServiceTest extends TestCase
{
    use CreatesAverTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAverTables();
    }

    protected function tearDown(): void
    {
        $this->dropAverTables();

        parent::tearDown();
    }

    public function test_certification_passes_all_aver_checks(): void
    {
        $payload = app(AtlasVerifiedExecutionCertificationService::class)->certify();

        $this->assertSame(AtlasVerifiedExecutionCertificationService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('passed', $payload['status']);
        $this->assertSame(13, $payload['summary']['total']);
        $this->assertSame(13, $payload['summary']['pass']);
        $this->assertSame(0, $payload['summary']['fail']);
        $this->assertNotEmpty($payload['certification_hash']);
        $this->assertTrue(data_get($payload, 'claim_policy.destructive_commands_blocked'));
    }

    public function test_certification_smokes_do_not_pollute_aver_tables(): void
    {
        $before = [
            'executions' => $this->tableCount('atlas_aver_executions'),
            'commands' => $this->tableCount('atlas_aver_command_ledgers'),
            'certifications' => $this->tableCount('atlas_aver_certified_executions'),
        ];

        app(AtlasVerifiedExecutionCertificationService::class)->certify();

        $this->assertSame($before['executions'], $this->tableCount('atlas_aver_executions'));
        $this->assertSame($before['commands'], $this->tableCount('atlas_aver_command_ledgers'));
        $this->assertSame($before['certifications'], $this->tableCount('atlas_aver_certified_executions'));
    }

    public function test_certify_command_supports_json_and_strict(): void
    {
        $exit = Artisan::call('atlas:aver:certify', ['--json' => true, '--strict' => true]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('passed', $payload['status']);
        $this->assertSame('atlas.aver.certification.v1', $payload['schema_version']);
    }

    private function tableCount(string $table): int
    {
        return (int) \DB::table($table)->count();
    }
}

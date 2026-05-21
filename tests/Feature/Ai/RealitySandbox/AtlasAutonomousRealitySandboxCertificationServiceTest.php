<?php

namespace Tests\Feature\Ai\RealitySandbox;

use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesAarsTables;
use Tests\TestCase;

class AtlasAutonomousRealitySandboxCertificationServiceTest extends TestCase
{
    use CreatesAarsTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAarsTables();
    }

    protected function tearDown(): void
    {
        $this->dropAarsTables();

        parent::tearDown();
    }

    public function test_certify_command_passes_with_all_artifacts(): void
    {
        $exit = Artisan::call('atlas:aars:certify', ['--json' => true, '--strict' => true]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.aars.certification.v1', $payload['schema_version']);
        $this->assertSame('passed', $payload['status']);
        $this->assertSame(12, $payload['summary']['total']);
    }
}

<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasAiArchitectureValidateCommandTest extends TestCase
{
    public function test_command_validates_architecture_contracts_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:architecture-validate', [
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertTrue(data_get($payload, 'capabilities.valid'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'capabilities.count'));
        $this->assertTrue(data_get($payload, 'orchestrators.valid'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'orchestrators.count'));
        $this->assertTrue(data_get($payload, 'domains.valid'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'domains.domain_count'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'domains.flow_count'));
        $this->assertGreaterThanOrEqual(3, data_get($payload, 'onboarding.ready_domains'));
        $this->assertSame(0, data_get($payload, 'onboarding.executable_incomplete_domains'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'onboarding.domain_count'));
    }
}

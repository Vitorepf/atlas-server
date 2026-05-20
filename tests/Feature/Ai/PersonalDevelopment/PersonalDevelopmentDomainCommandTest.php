<?php

namespace Tests\Feature\Ai\PersonalDevelopment;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PersonalDevelopmentDomainCommandTest extends TestCase
{
    public function test_readiness_smoke_and_control_plane_commands_return_json(): void
    {
        foreach (['readiness', 'smoke', 'control-plane'] as $action) {
            Artisan::call('atlas:ai:personal-development-domain', [
                'positional' => $action,
                '--json' => true,
            ]);

            $payload = json_decode(Artisan::output(), true);

            $this->assertIsArray($payload);
            $this->assertTrue($payload['ok']);
            $this->assertSame('personal_development', $payload['domain']);
        }
    }
}

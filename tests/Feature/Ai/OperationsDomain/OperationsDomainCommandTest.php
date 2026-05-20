<?php

namespace Tests\Feature\Ai\OperationsDomain;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class OperationsDomainCommandTest extends TestCase
{
    public function test_readiness_smoke_and_control_plane_commands_return_json(): void
    {
        foreach (['readiness', 'smoke', 'control-plane'] as $action) {
            Artisan::call('atlas:ai:operations-domain', [
                'positional' => $action,
                '--json' => true,
            ]);

            $payload = json_decode(Artisan::output(), true);

            $this->assertIsArray($payload);
            $this->assertTrue($payload['ok']);
            $this->assertSame('operations', $payload['domain']);
        }
    }
}

<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignRuntimeService;
use Tests\TestCase;

class AtlasFrontendDesignRuntimeCommandTest extends TestCase
{
    public function test_frontend_plan_command_emits_runtime_contract(): void
    {
        $this->artisan('atlas:frontend:plan', [
            '--task' => 'frontend SaaS multiempresa com live variants e design system',
            '--json' => true,
        ])->assertExitCode(0);
    }

    public function test_frontend_certify_command_is_ready_and_honest_about_world_best_claim(): void
    {
        $this->artisan('atlas:frontend:certify', [
            '--json' => true,
            '--strict' => true,
        ])->assertExitCode(0);

        $payload = app(AtlasFrontendDesignRuntimeService::class)->certify();

        $this->assertSame('ready', $payload['status']);
        $this->assertTrue((bool) data_get($payload, 'market_claim_policy.may_claim_more_complete_than_impeccable'));
        $this->assertFalse((bool) data_get($payload, 'market_claim_policy.may_claim_world_best_frontend_system'));
    }
}

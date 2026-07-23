<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasAaeosAcosSimplifyCycleCommandTest extends TestCase
{
    public function test_plan_emits_json_with_elite_contract_and_steps(): void
    {
        $exit = Artisan::call('atlas:acos:simplify-cycle', [
            'action' => 'plan',
            '--json' => true,
            '--dry-run' => '1',
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('atlas.aaeos_acos.simplify_cycle.cli.v1', $payload['schema_version']);
        $this->assertSame('aaeos_acos', $payload['plan']['scope']);
        $this->assertTrue($payload['plan']['elite_contract']['anti_proxy']);
        $this->assertFalse($payload['schedule']['enabled']);
        $this->assertSame('aaeos_acos', $payload['campaign']['lane_scope']);
    }

    public function test_prompts_action_lists_external_worker_steps(): void
    {
        $exit = Artisan::call('atlas:acos:simplify-cycle', [
            'action' => 'prompts',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $roles = array_column($payload['prompts'] ?? [], 'role');
        $this->assertContains('external_brain_loop', $roles);
        $this->assertContains('external_muscle_loop', $roles);
    }
}

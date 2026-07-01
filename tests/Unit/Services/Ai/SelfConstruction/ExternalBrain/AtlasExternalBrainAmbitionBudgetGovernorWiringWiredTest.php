<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasExternalBrainAmbitionBudgetGovernorWiringWiredTest extends TestCase
{
    public function test_queue_pressure_command_includes_ambition_budget_section(): void
    {
        Artisan::call('atlas:external-brain:queue-pressure', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('ambition_budget', $payload);
        self::assertSame('atlas.external_brain.ambition_budget_governor.v1', $payload['ambition_budget']['schema']);
    }

    public function test_low_queue_health_selects_self_heal_mode(): void
    {
        Artisan::call('atlas:external-brain:queue-pressure', [
            '--queue-health' => '0.2',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('self_heal', $payload['ambition_budget']['selected_mode']);
    }

    public function test_high_stale_backlog_selects_consolidation_mode(): void
    {
        Artisan::call('atlas:external-brain:queue-pressure', [
            '--queue-health' => '1.0',
            '--stale-backlog-ratio' => '0.9',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('consolidation', $payload['ambition_budget']['selected_mode']);
    }

    public function test_ambition_budget_total_flows_into_remaining_budget(): void
    {
        Artisan::call('atlas:external-brain:queue-pressure', [
            '--ambition-budget-total' => '42',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(42, $payload['ambition_budget']['remaining_budget']);
    }
}

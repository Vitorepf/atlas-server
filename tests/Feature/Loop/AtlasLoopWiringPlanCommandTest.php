<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the multi-site wiring planner is live at the operator surface and emits deterministic facts: when
 * armed, a consumer whose source references the primitive's class is `wired`, one that does not is
 * `intended`; flag-OFF yields an empty plan (byte-identical no-op).
 */
final class AtlasLoopWiringPlanCommandTest extends TestCase
{
    private const PRIMITIVE = [
        'primitive_id' => 'wiring_plan_command',
        // the class name 'AtlasLoopWiringPlanCommand' appears in its own source ⇒ that consumer is wired
        'file_path' => 'app/Console/Commands/AtlasLoopWiringPlanCommand.php',
        'intended_consumer_paths' => [
            'app/Console/Commands/AtlasLoopWiringPlanCommand.php', // references the class ⇒ wired
            'composer.json', // does not ⇒ intended
        ],
    ];

    public function test_flag_off_yields_empty_plan(): void
    {
        config(['atlas.loop.multi_site_wiring_planner_enabled' => false]);

        $exit = Artisan::call('atlas:loop:wiring-plan', [
            '--primitives' => json_encode([self::PRIMITIVE]),
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.multi_site_wiring_plan.v1', $decoded['schema']);
        $this->assertSame(0, $decoded['count']);
        $this->assertSame([], $decoded['records']);
    }

    public function test_armed_plan_marks_wired_and_intended(): void
    {
        config(['atlas.loop.multi_site_wiring_planner_enabled' => true]);

        $exit = Artisan::call('atlas:loop:wiring-plan', [
            '--primitives' => json_encode([self::PRIMITIVE]),
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame(2, $decoded['count']);

        $byConsumer = array_column($decoded['records'], 'status', 'consumer_path');
        $this->assertSame('wired', $byConsumer['app/Console/Commands/AtlasLoopWiringPlanCommand.php']);
        $this->assertSame('intended', $byConsumer['composer.json']);
    }
}

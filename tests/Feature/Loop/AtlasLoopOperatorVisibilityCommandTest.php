<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the operator-visibility composer is live at the operator surface and emits deterministic facts:
 * supplied per-section facts map to their status, absent sections read 'unknown', and the read-only role +
 * native autonomy owner are stamped. A missing --facts is a usage error.
 */
final class AtlasLoopOperatorVisibilityCommandTest extends TestCase
{
    public function test_requires_facts(): void
    {
        $exit = Artisan::call('atlas:loop:operator-visibility', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_composes_visibility_view_from_facts(): void
    {
        $facts = [
            'autonomy_mode' => ['mode' => 'atlas_native'],
            'control_plane' => ['ready' => true],
            'safety_stop' => ['action' => 'none'],
            // other sections intentionally absent ⇒ 'unknown'
        ];

        $exit = Artisan::call('atlas:loop:operator-visibility', [
            '--facts' => json_encode($facts),
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.operator_interface.visibility.v1', $decoded['schema']);
        $this->assertSame('read_only_with_emergency_stop', $decoded['interface_role']);
        $this->assertSame('atlas_native', $decoded['autonomy_owner']);
        $this->assertCount(11, $decoded['blocks']);

        $byLabel = array_column($decoded['blocks'], 'status', 'label');
        $this->assertSame('ready', $byLabel['control_plane']);
        $this->assertSame('atlas_native', $byLabel['autonomy_mode']);
        $this->assertSame('none', $byLabel['safety_stop']);
        $this->assertSame('unknown', $byLabel['worker_swarm']); // absent ⇒ unknown
    }
}

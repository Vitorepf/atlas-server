<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\SelfConstruction\Maestro\Pinning\AtlasMaestroTaskPinningPolicy;
use App\Services\Ai\SelfConstruction\Maestro\Pinning\AtlasMaestroTaskPinningRegistry;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the maestro task-pinning policy is live at the operator surface: an unpinned packet is allowed
 * (no_pin); a packet pinned to the same worker is allowed (pin_match); pinned to another worker is refused
 * (pinned_to_other_worker).
 */
final class AtlasLoopPinningDecideCommandTest extends TestCase
{
    private string $snapshot = '';

    private AtlasMaestroTaskPinningRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshot = sys_get_temp_dir().'/atlas-pins-'.bin2hex(random_bytes(5)).'.json';
        $this->registry = new AtlasMaestroTaskPinningRegistry($this->snapshot);
        $this->app->instance(AtlasMaestroTaskPinningPolicy::class, new AtlasMaestroTaskPinningPolicy($this->registry));
    }

    protected function tearDown(): void
    {
        @unlink($this->snapshot);
        parent::tearDown();
    }

    private function decide(string $packetId, string $workerId): array
    {
        $exit = Artisan::call('atlas:loop:pinning-decide', ['--packet-id' => $packetId, '--worker-id' => $workerId, '--json' => true]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_unpinned_packet_is_allowed(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->decide('pkt-fresh', 'wA');

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.pinning_decide.v1', $d['schema']);
        // structured decision keys present
        $this->assertArrayHasKey('decision', $d);
        $this->assertArrayHasKey('reason', $d);
        $this->assertArrayHasKey('pinned_worker_id', $d);
        $this->assertSame(AtlasMaestroTaskPinningPolicy::DECISION_ALLOW, $d['decision'], (string) json_encode($d));
        $this->assertSame(AtlasMaestroTaskPinningPolicy::REASON_NO_PIN, $d['reason']);
    }

    public function test_pin_match_is_allowed_and_conflict_is_refused(): void
    {
        $this->registry->pin('pkt-1', 'wA', 'operator');

        ['d' => $match] = $this->decide('pkt-1', 'wA');
        $this->assertSame(AtlasMaestroTaskPinningPolicy::DECISION_ALLOW, $match['decision'], (string) json_encode($match));
        $this->assertSame(AtlasMaestroTaskPinningPolicy::REASON_PIN_MATCH, $match['reason']);
        $this->assertSame('wA', $match['pinned_worker_id']);

        ['d' => $conflict] = $this->decide('pkt-1', 'wB');
        $this->assertSame(AtlasMaestroTaskPinningPolicy::DECISION_REFUSE, $conflict['decision'], (string) json_encode($conflict));
        $this->assertSame(AtlasMaestroTaskPinningPolicy::REASON_PIN_CONFLICT, $conflict['reason']);
    }

    public function test_missing_options_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:pinning-decide', ['--packet-id' => 'pkt-1', '--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}

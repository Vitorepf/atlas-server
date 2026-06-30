<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AtlasTaskCoordinationHealthService;
use App\Services\Ai\SelfConstruction\AtlasTaskServableHeartbeatService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasTaskServableHeartbeatTest extends TestCase
{
    private string $tempReceipt = '';

    /** @var list<string> */
    private array $artisanCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempReceipt = sys_get_temp_dir().'/atlas-hb-'.bin2hex(random_bytes(6)).'.json';
        $this->artisanCalls = [];
    }

    protected function tearDown(): void
    {
        @unlink($this->tempReceipt);
        parent::tearDown();
    }

    private function service(int $servable, int $claimable): AtlasTaskServableHeartbeatService
    {
        return new AtlasTaskServableHeartbeatService(
            receiptPath: $this->tempReceipt,
            nowIso: fn () => '2026-06-25T05:00:00Z',
            artisanCaller: function (string $cmd): int { $this->artisanCalls[] = $cmd; return 0; },
            snapshotProvider: fn (): array => ['servable_now' => $servable, 'claimable_depth' => $claimable],
        );
    }

    public function test_jammed_queue_fires_three_commands_in_order(): void
    {
        $expected = [
            'atlas:acp:reap-leases',
            'atlas:task:sweep-malformed',
            'atlas:task:repair-blocked',
            'atlas:task:retire',
        ];

        $envelope = $this->service(servable: 0, claimable: 3)->tick();
        $this->assertSame($expected, $this->artisanCalls);
        $this->assertSame('recovery_fired', $envelope['status']);
        $this->assertSame($expected, $envelope['actions_fired']);
        $this->assertTrue($envelope['ok']);
    }

    public function test_healthy_queue_fires_no_commands(): void
    {
        $envelope = $this->service(servable: 2, claimable: 5)->tick();
        $this->assertSame([], $this->artisanCalls);
        $this->assertSame('ok', $envelope['status']);
        $this->assertSame([], $envelope['actions_fired']);
    }

    public function test_empty_queue_fires_no_commands(): void
    {
        $envelope = $this->service(servable: 0, claimable: 0)->tick();
        $this->assertSame([], $this->artisanCalls);
        $this->assertSame('ok', $envelope['status']);
    }

    public function test_receipt_persisted_with_required_keys(): void
    {
        $this->service(servable: 0, claimable: 2)->tick();
        $this->assertFileExists($this->tempReceipt);
        $receipt = json_decode((string) file_get_contents($this->tempReceipt), true);
        foreach (['schema', 'status', 'timestamp', 'health_snapshot', 'actions_taken'] as $k) {
            $this->assertArrayHasKey($k, $receipt);
        }
        $this->assertSame('2026-06-25T05:00:00Z', $receipt['timestamp']);
    }

    public function test_envelope_json_shape_has_canonical_keys(): void
    {
        $envelope = $this->service(servable: 0, claimable: 1)->tick();
        foreach (['ok', 'servable_now', 'claimable_depth', 'actions_fired', 'receipt_path'] as $k) {
            $this->assertArrayHasKey($k, $envelope);
        }
    }

    public function test_cli_emits_canonical_json_envelope(): void
    {
        app()->instance(AtlasTaskServableHeartbeatService::class, $this->service(servable: 0, claimable: 1));
        $exit = Artisan::call('atlas:task:servable-heartbeat', ['--json' => true]);
        $this->assertSame(0, $exit);
        $decoded = json_decode(trim(Artisan::output()), true);
        $this->assertSame('recovery_fired', $decoded['status']);
        $this->assertTrue($decoded['ok']);
    }
}

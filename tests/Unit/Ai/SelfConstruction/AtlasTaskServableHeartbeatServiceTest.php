<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasTaskServableHeartbeatService;
use Tests\TestCase;

final class AtlasTaskServableHeartbeatServiceTest extends TestCase
{
    private function service(array $snapshot, array &$firedCommands, string $receiptPath): AtlasTaskServableHeartbeatService
    {
        return new AtlasTaskServableHeartbeatService(
            receiptPath: $receiptPath,
            nowIso: static fn (): string => '2026-06-30T00:00:00Z',
            artisanCaller: static function (string $cmd) use (&$firedCommands): int {
                $firedCommands[] = $cmd;

                return 0;
            },
            snapshotProvider: static fn (): array => $snapshot,
        );
    }

    public function test_no_recovery_when_not_jammed_despite_zero_servable_and_positive_claimable(): void
    {
        $receiptPath = storage_path('app/atlas/evidence/test-heartbeat-no-jam.json');
        @unlink($receiptPath);
        $fired = [];

        $service = $this->service([
            'servable_now' => 0,
            'claimable_depth' => 5,
            'health_flags' => ['serving_jammed' => false],
        ], $fired, $receiptPath);

        $result = $service->tick();

        $this->assertSame([], $fired);
        $this->assertSame([], $result['actions_fired']);
        $this->assertSame('ok', $result['status']);
        $this->assertFileExists($receiptPath);

        @unlink($receiptPath);
    }

    public function test_recovery_fires_when_serving_jammed_is_true(): void
    {
        $receiptPath = storage_path('app/atlas/evidence/test-heartbeat-jam.json');
        @unlink($receiptPath);
        $fired = [];

        $service = $this->service([
            'servable_now' => 0,
            'claimable_depth' => 5,
            'health_flags' => ['serving_jammed' => true],
        ], $fired, $receiptPath);

        $result = $service->tick();

        $this->assertSame(AtlasTaskServableHeartbeatService::RECOVERY_SEQUENCE, $fired);
        $this->assertSame(AtlasTaskServableHeartbeatService::RECOVERY_SEQUENCE, $result['actions_fired']);
        $this->assertSame('recovery_fired', $result['status']);

        @unlink($receiptPath);
    }
}

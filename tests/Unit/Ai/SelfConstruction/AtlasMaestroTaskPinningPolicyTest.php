<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\Pinning\AtlasMaestroTaskPinningPolicy;
use App\Services\Ai\SelfConstruction\Maestro\Pinning\AtlasMaestroTaskPinningRegistry;
use Tests\TestCase;

final class AtlasMaestroTaskPinningPolicyTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-pin-pol-'.bin2hex(random_bytes(6));
        @mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->root.'/*') as $f) {
            @unlink((string) $f);
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    private function policy(): array
    {
        $reg = new AtlasMaestroTaskPinningRegistry($this->root.'/pins.json', fn () => '2026-06-25T05:00:00Z');

        return [$reg, new AtlasMaestroTaskPinningPolicy($reg)];
    }

    public function test_no_pin_allows_any_worker(): void
    {
        [, $p] = $this->policy();
        $d = $p->decide('pkt', 'w1');
        $this->assertSame(AtlasMaestroTaskPinningPolicy::DECISION_ALLOW, $d['decision']);
        $this->assertSame(AtlasMaestroTaskPinningPolicy::REASON_NO_PIN, $d['reason']);
        $this->assertNull($d['pinned_worker_id']);
    }

    public function test_matching_pin_allows(): void
    {
        [$reg, $p] = $this->policy();
        $reg->pin('pkt', 'w1', 'r');
        $d = $p->decide('pkt', 'w1');
        $this->assertSame(AtlasMaestroTaskPinningPolicy::DECISION_ALLOW, $d['decision']);
        $this->assertSame(AtlasMaestroTaskPinningPolicy::REASON_PIN_MATCH, $d['reason']);
        $this->assertSame('w1', $d['pinned_worker_id']);
    }

    public function test_conflicting_pin_refuses_with_canonical_reason(): void
    {
        [$reg, $p] = $this->policy();
        $reg->pin('pkt', 'w1', 'r');
        $d = $p->decide('pkt', 'w2');
        $this->assertSame(AtlasMaestroTaskPinningPolicy::DECISION_REFUSE, $d['decision']);
        $this->assertSame('pinned_to_other_worker', $d['reason']);
        $this->assertSame('w1', $d['pinned_worker_id']);
    }

    public function test_malformed_worker_id_refuses(): void
    {
        [, $p] = $this->policy();
        $d = $p->decide('pkt', '   ');
        $this->assertSame(AtlasMaestroTaskPinningPolicy::DECISION_REFUSE, $d['decision']);
        $this->assertSame(AtlasMaestroTaskPinningPolicy::REASON_INVALID_WORKER, $d['reason']);
    }

    private function injectExpiredAt(string $pinsPath, string $taskPacketId, string $expiredAt): void
    {
        $state = json_decode((string) file_get_contents($pinsPath), true);
        $state[$taskPacketId]['expired_at'] = $expiredAt;
        ksort($state[$taskPacketId]);
        file_put_contents($pinsPath, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function test_expired_pin_allows_worker_with_expiry_reason(): void
    {
        [$reg, ] = $this->policy();
        $reg->pin('pkt', 'w1', 'r');
        $this->injectExpiredAt($this->root.'/pins.json', 'pkt', '2026-06-25T04:00:00Z');

        $policy = new AtlasMaestroTaskPinningPolicy($reg, fn () => '2026-06-25T05:00:00Z');
        $d = $policy->decide('pkt', 'w2');

        $this->assertSame(AtlasMaestroTaskPinningPolicy::DECISION_ALLOW, $d['decision']);
        $this->assertSame(AtlasMaestroTaskPinningPolicy::REASON_PIN_EXPIRED, $d['reason']);
    }

    public function test_non_expired_pin_still_refuses_different_worker(): void
    {
        [$reg, ] = $this->policy();
        $reg->pin('pkt', 'w1', 'r');
        $this->injectExpiredAt($this->root.'/pins.json', 'pkt', '2026-06-25T06:00:00Z');

        $policy = new AtlasMaestroTaskPinningPolicy($reg, fn () => '2026-06-25T05:00:00Z');
        $d = $policy->decide('pkt', 'w2');

        $this->assertSame(AtlasMaestroTaskPinningPolicy::DECISION_REFUSE, $d['decision']);
        $this->assertSame(AtlasMaestroTaskPinningPolicy::REASON_PIN_CONFLICT, $d['reason']);
    }

    public function test_invalid_blank_worker_id_refuses_before_pin_lookup(): void
    {
        [$reg, ] = $this->policy();
        $reg->pin('pkt', 'w1', 'r');
        $this->injectExpiredAt($this->root.'/pins.json', 'pkt', '2026-06-25T04:00:00Z');

        $policy = new AtlasMaestroTaskPinningPolicy($reg, fn () => '2026-06-25T05:00:00Z');
        $d = $policy->decide('pkt', '');

        $this->assertSame(AtlasMaestroTaskPinningPolicy::DECISION_REFUSE, $d['decision']);
        $this->assertSame(AtlasMaestroTaskPinningPolicy::REASON_INVALID_WORKER, $d['reason']);
    }
}

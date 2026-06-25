<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Trinity\MaestroToLoop;

use App\Services\Ai\AutonomousEvolution\Trinity\MaestroToLoop\AtlasLoopMaestroFuelGateInjector;
use Tests\TestCase;

/**
 * Proves the Trinity Maestro→Loop fuel gate: with the flag ON and partially-consumed outcomes the gate
 * BLOCKS origination and surfaces un_consumed_packet_ids (origination spy receives ZERO calls); with all
 * outcomes consumed the gate delegates to origination EXACTLY ONCE; with the flag OFF the gate is
 * byte-identically permissive and reads NO receipt source.
 */
final class AtlasLoopMaestroFuelGateInjectorTest extends TestCase
{
    private function injector(array $closed, array $digest, array $fuel, array $mine, callable $originationSpy, bool $flagOn): AtlasLoopMaestroFuelGateInjector
    {
        config(['atlas.loop.trinity.maestro_to_loop.enabled' => $flagOn]);

        return new AtlasLoopMaestroFuelGateInjector(
            closedOutcomesSource: static fn (): iterable => $closed,
            digestReceiptPresent: static fn (string $id): bool => in_array($id, $digest, true),
            fuelReceiptPresent: static fn (string $id): bool => in_array($id, $fuel, true),
            mineReceiptPresent: static fn (string $id): bool => in_array($id, $mine, true),
            originationProducer: $originationSpy,
        );
    }

    public function test_blocks_origination_when_one_of_two_outcomes_is_unconsumed(): void
    {
        $origCalls = 0;
        $spy = static function () use (&$origCalls): void { $origCalls++; };

        $gate = $this->injector(
            closed: ['pkt-a', 'pkt-b'],
            digest: ['pkt-a', 'pkt-b'],   // both digested
            fuel: ['pkt-a'],              // ONLY pkt-a converted
            mine: ['pkt-a', 'pkt-b'],     // both mined
            originationSpy: $spy,
            flagOn: true,
        );

        $result = $gate->ensureFuelConsumedBeforeOrigination();

        $this->assertTrue($result['blocked']);
        $this->assertSame(['pkt-b'], $result['un_consumed_packet_ids']);
        $this->assertSame(0, $origCalls, 'origination producer must NEVER be invoked when blocked');
    }

    public function test_admits_and_delegates_exactly_once_when_all_outcomes_are_consumed(): void
    {
        $origCalls = 0;
        $spy = static function () use (&$origCalls): void { $origCalls++; };

        $gate = $this->injector(
            closed: ['pkt-a', 'pkt-b', 'pkt-c'],
            digest: ['pkt-a', 'pkt-b', 'pkt-c'],
            fuel: ['pkt-a', 'pkt-b', 'pkt-c'],
            mine: ['pkt-a', 'pkt-b', 'pkt-c'],
            originationSpy: $spy,
            flagOn: true,
        );

        $result = $gate->ensureFuelConsumedBeforeOrigination();

        $this->assertFalse($result['blocked']);
        $this->assertSame(3, $result['consumed']);
        $this->assertSame(1, $origCalls, 'origination producer invoked exactly once on admit');
    }

    public function test_flag_off_returns_unblocked_zero_and_reads_no_receipts(): void
    {
        $closedCalls = 0;
        $digestCalls = 0;
        $origCalls = 0;
        $spy = static function () use (&$origCalls): void { $origCalls++; };

        config(['atlas.loop.trinity.maestro_to_loop.enabled' => false]);

        $gate = new AtlasLoopMaestroFuelGateInjector(
            closedOutcomesSource: static function () use (&$closedCalls): iterable { $closedCalls++; return []; },
            digestReceiptPresent: static function (string $id) use (&$digestCalls): bool { $digestCalls++; return true; },
            fuelReceiptPresent: static fn (string $id): bool => true,
            mineReceiptPresent: static fn (string $id): bool => true,
            originationProducer: $spy,
        );

        $result = $gate->ensureFuelConsumedBeforeOrigination();

        $this->assertSame(['blocked' => false, 'consumed' => 0], $result);
        $this->assertSame(0, $closedCalls, 'flag OFF must NEVER read the closed outcomes source');
        $this->assertSame(0, $digestCalls, 'flag OFF must NEVER read receipts');
        $this->assertSame(0, $origCalls, 'flag OFF is a no-op — origination NOT delegated either');
    }

    public function test_empty_closed_outcomes_is_unblocked_and_consumed_zero_and_still_delegates(): void
    {
        $origCalls = 0;
        $spy = static function () use (&$origCalls): void { $origCalls++; };

        $gate = $this->injector(
            closed: [],
            digest: [],
            fuel: [],
            mine: [],
            originationSpy: $spy,
            flagOn: true,
        );

        $result = $gate->ensureFuelConsumedBeforeOrigination();

        $this->assertFalse($result['blocked']);
        $this->assertSame(0, $result['consumed']);
        $this->assertSame(1, $origCalls, 'no outcomes to consume ⇒ origination still runs');
    }

    public function test_unconsumed_packet_ids_are_sorted_byte_stably(): void
    {
        $gate = $this->injector(
            closed: ['z-pkt', 'a-pkt', 'm-pkt'],
            digest: [],
            fuel: [],
            mine: [],
            originationSpy: static fn () => null,
            flagOn: true,
        );

        $result = $gate->ensureFuelConsumedBeforeOrigination();
        $this->assertTrue($result['blocked']);
        $this->assertSame(['a-pkt', 'm-pkt', 'z-pkt'], $result['un_consumed_packet_ids']);
    }
}

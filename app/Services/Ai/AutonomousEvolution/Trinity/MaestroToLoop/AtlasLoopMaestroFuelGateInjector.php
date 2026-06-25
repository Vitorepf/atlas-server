<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Trinity\MaestroToLoop;

use Throwable;

/**
 * TRINITY MAESTRO→LOOP — FUEL GATE INJECTOR. The chokepoint that wires the Trinity edge to the existing
 * origination machinery: before {@see \App\Services\Ai\AutonomousEvolution\AtlasLoopOriginationProducer} or
 * {@see \App\Services\Ai\AutonomousEvolution\AtlasLoopBacklogAutoFeederService} enqueues a new wave, the gate
 * verifies every Maestro outcome closed since the last origination tick has been DIGESTED + CONVERTED + MINED.
 *
 * Any un-consumed outcome blocks the next origination tick. This is the structural fix for
 * `loop-material-fuel-gap` — origination cannot starve while real outcomes go un-digested.
 *
 * FLAG-GATED: `atlas.loop.trinity.maestro_to_loop.enabled` default false ⇒ the gate returns
 * `{blocked:false, consumed:0}` byte-identically and reads NO receipt source (master-OFF default preserved).
 */
final class AtlasLoopMaestroFuelGateInjector
{
    /** @var callable():iterable<string> source of closed-since-last-tick Maestro outcome packet_ids */
    private $closedOutcomesSource;

    /** @var callable(string):bool true iff a digest receipt exists for the given packet_id */
    private $digestReceiptPresent;

    /** @var callable(string):bool true iff a fuel-conversion receipt exists for the given packet_id */
    private $fuelReceiptPresent;

    /** @var callable(string):bool true iff a mining receipt exists for the given packet_id */
    private $mineReceiptPresent;

    /** @var callable():void the next origination step invoked ONLY when the gate allows */
    private $originationProducer;

    /**
     * @param  callable():iterable<string>  $closedOutcomesSource
     * @param  callable(string):bool        $digestReceiptPresent
     * @param  callable(string):bool        $fuelReceiptPresent
     * @param  callable(string):bool        $mineReceiptPresent
     * @param  callable():void              $originationProducer
     */
    public function __construct(
        callable $closedOutcomesSource,
        callable $digestReceiptPresent,
        callable $fuelReceiptPresent,
        callable $mineReceiptPresent,
        callable $originationProducer,
    ) {
        $this->closedOutcomesSource = $closedOutcomesSource;
        $this->digestReceiptPresent = $digestReceiptPresent;
        $this->fuelReceiptPresent = $fuelReceiptPresent;
        $this->mineReceiptPresent = $mineReceiptPresent;
        $this->originationProducer = $originationProducer;
    }

    /**
     * @return array{blocked:bool, consumed?:int, un_consumed_packet_ids?:list<string>}
     */
    public function ensureFuelConsumedBeforeOrigination(): array
    {
        if (! $this->flagOn()) {
            return ['blocked' => false, 'consumed' => 0];
        }

        $unConsumed = [];
        $consumed = 0;
        foreach (($this->closedOutcomesSource)() as $packetId) {
            $packetId = (string) $packetId;
            if ($packetId === '') {
                continue;
            }
            $isConsumed = ($this->digestReceiptPresent)($packetId) && ($this->fuelReceiptPresent)($packetId) && ($this->mineReceiptPresent)($packetId);
            if ($isConsumed) {
                $consumed++;
            } else {
                $unConsumed[] = $packetId;
            }
        }

        if ($unConsumed !== []) {
            sort($unConsumed, SORT_STRING);

            return ['blocked' => true, 'un_consumed_packet_ids' => $unConsumed];
        }

        // All consumed ⇒ delegate to the next origination step exactly once.
        ($this->originationProducer)();

        return ['blocked' => false, 'consumed' => $consumed];
    }

    private function flagOn(): bool
    {
        if (! function_exists('config')) {
            return false;
        }
        try {
            return (bool) config('atlas.loop.trinity.maestro_to_loop.enabled', false);
        } catch (Throwable) {
            return false;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets;

/**
 * The Cortex-grounding inputs the proposer consumes — REAL symbols + REAL files in scope, sourced from
 * {@see \App\Services\Ai\AutonomousEvolution\AtlasLoopComprehensionOriginator} in production. The proposer
 * anchors the emitted packet objective to one of these symbols so the resulting packet is grounded, not
 * hallucinated.
 *
 * No relevance scoring lives here — the snapshot is a FACT bag, the proposer chooses an anchor deterministically.
 */
final class CortexGroundingSnapshot
{
    /**
     * @param  list<string>  $symbols  FQCNs (or short symbol names) reachable in the current Cortex view
     * @param  list<string>  $files    workspace-relative paths reachable in the current Cortex view
     * @param  string        $cortexId opaque id of the Cortex view (e.g. comprehension run id) for receipts
     */
    public function __construct(
        public readonly array $symbols,
        public readonly array $files,
        public readonly string $cortexId,
    ) {
    }
}

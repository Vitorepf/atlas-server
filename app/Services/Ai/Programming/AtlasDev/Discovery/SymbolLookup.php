<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Discovery;

/**
 * Optional adapter the {@see CodeDiscoveryEngine} may consult for symbol→file
 * hints. Implementations must be advisory only: every hit must still pass
 * `is_file()` confirmation inside the engine.
 *
 * Keeping this as an interface lets Discovery stay decoupled from
 * Code Intelligence (which depends on DB tables that aren't available in
 * unit tests). Pipeline-time wiring may inject a real implementation;
 * tests inject in-memory fakes.
 */
interface SymbolLookup
{
    /**
     * Return zero or more candidate hits for `symbol` rooted at `workspace`.
     *
     * @return list<array{path:string,reason?:string,confidence?:float}>
     */
    public function find(string $workspace, string $symbol): array;
}

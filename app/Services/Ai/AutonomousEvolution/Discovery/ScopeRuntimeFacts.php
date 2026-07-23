<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * Provider-free runtime facts consumed by the live scope-comprehension keep-list.
 */
interface ScopeRuntimeFacts
{
    public function hasGateBlock(string $relPath): bool;

    public function lastMergeClean(string $relPath): bool;
}

/**
 * Optional observed test-presence fact. Kept separate so implementations of the
 * base contract remain compatible and callers can degrade through instanceof.
 */
interface ScopeRuntimeFactsWithTestPresence
{
    public function hasTest(string $relPath): bool;
}

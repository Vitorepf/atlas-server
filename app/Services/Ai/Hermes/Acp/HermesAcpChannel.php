<?php

namespace App\Services\Ai\Hermes\Acp;

/**
 * The raw line I/O contract a `hermes acp` session is driven over.
 *
 * {@see HermesAcpTransport} is the real proc_open implementation; tests inject a
 * fake so {@see AtlasHermesAcpRuntime} orchestration + permission governance can
 * be proven WITHOUT spawning Hermes or calling a model (the real round-trip is
 * proven by a live spike instead).
 */
interface HermesAcpChannel
{
    public function start(): void;

    public function writeLine(string $line): void;

    /** Read one newline-delimited line, waiting up to $budget seconds; null on timeout/EOF. */
    public function readLine(float $budget): ?string;

    public function drainStderr(): string;

    public function isRunning(): bool;

    public function stop(): void;
}

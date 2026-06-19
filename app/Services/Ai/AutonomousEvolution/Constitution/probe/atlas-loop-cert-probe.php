<?php

declare(strict_types=1);

/**
 * LOOP-OS · FASE 3 · SLICE 4.5 — the FROZEN cert-probe entrypoint (pétreo / FORBIDDEN under Constitution/).
 *
 * Driven as a SUBPROCESS by {@see \App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopBatteryRunner}.
 * It is the candidate-bytes anchor: it proves the tree being scored carries the CANDIDATE's bytes (not the
 * live judge) by recomputing the cert-chain closure Merkle over the worktree and comparing it to the root the
 * runner minted after applying the candidate diff. A mismatch ⇒ exit 66 (bytes_not_proven) — the §3.4 R3
 * "symlink loaded the LIVE judge" trap is caught here, loudly, never silently green.
 *
 * It resolves the (FROZEN) closure walker from its OWN repo location so the closure PATHS are stable, then
 * hashes the WORKTREE's bytes at those paths — so a candidate edit to any delegate moves the root. Exit codes
 * follow §10.5; the judge-execution battery legs (∀ bad REFUTED / ∀ good CERTIFIED / ∀ robust HELD) layer on
 * top of this same entrypoint. Trusts NOTHING but the exit code.
 *
 * Usage: php atlas-loop-cert-probe.php <worktreeRoot> <expectedClosureMerkle>
 */

use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopCertChainClosure;

const ATLAS_PROBE_EXIT_PASS = 0;
const ATLAS_PROBE_EXIT_BYTES_NOT_PROVEN = 66;
const ATLAS_PROBE_EXIT_CRASHED = 64;

$worktree = isset($argv[1]) ? rtrim((string) $argv[1], '/') : '';
$expectedMerkle = isset($argv[2]) ? (string) $argv[2] : '';

if ($worktree === '' || ! is_dir($worktree)) {
    fwrite(STDERR, "probe: no worktree\n");
    exit(ATLAS_PROBE_EXIT_BYTES_NOT_PROVEN);
}

// Boot the autoloader from THIS file's frozen repo location (6 levels up: probe → Constitution → … → root),
// so the closure walker (itself FROZEN) is the trusted one — the candidate cannot supply its own walker.
$repoRoot = dirname(__DIR__, 6);
$autoload = $repoRoot.'/vendor/autoload.php';
if (! is_file($autoload)) {
    fwrite(STDERR, "probe: autoload missing\n");
    exit(ATLAS_PROBE_EXIT_CRASHED);
}
require $autoload;

try {
    $actualMerkle = (new AtlasLoopCertChainClosure())->merkleRoot($worktree);
} catch (\Throwable $e) {
    fwrite(STDERR, 'probe: crashed: '.$e->getMessage()."\n");
    exit(ATLAS_PROBE_EXIT_CRASHED);
}

// CANDIDATE-BYTES PROOF: the tree we scored must be the exact tree the runner minted the expected root from.
// If they differ, the worktree did not carry the candidate's bytes (symlinked live judge, dropped hunk, …).
if ($expectedMerkle !== '' && ! hash_equals($expectedMerkle, $actualMerkle)) {
    fwrite(STDERR, 'probe: bytes_not_proven expected='.$expectedMerkle.' actual='.$actualMerkle."\n");
    exit(ATLAS_PROBE_EXIT_BYTES_NOT_PROVEN);
}

// Advisory: echo the proven closure Merkle (the runner logs it; the verdict is the exit code only).
fwrite(STDOUT, $actualMerkle."\n");
exit(ATLAS_PROBE_EXIT_PASS);

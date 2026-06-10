<?php

declare(strict_types=1);

namespace App\Services\Ai\Obra;

/**
 * AOBG N3.F2 — the GOVERNED per-node gate seam (fail-closed).
 *
 * The {@see AtlasObraExecutor} certifies each node TWICE: the delivery already proved
 * the change certified (php -l / self-test — that certification IS the materializer's
 * gate credential), and then — when a gate is injected — THIS seam runs ON TOP as a
 * stricter per-step check (a real Forge certification / contract gate). A gate that
 * does NOT pass (or throws) HALTS the obra: no further nodes run and the partial
 * branch is marked not-certified. This is the "a bad step must not silently pass"
 * floor — fail-CLOSED on certification, by contrast with the fail-OPEN brain.
 *
 * It is OPTIONAL by injection (default null ⇒ the delivery's certification alone
 * gates), so the executor stays runnable with the proven minimal floor while a
 * caller can tighten governance without the executor knowing which Forge gate ran.
 */
interface ObraNodeGate
{
    /**
     * Certify ONE applied obra step. Return {passed:bool, reason?:string}. A false
     * (or a thrown exception, which the executor treats as a failure) HALTS the obra.
     *
     * @param  array<string,mixed>  $node  the node row {id, seq, title, request, target_area, brain_refs, ...}
     * @param  array<string,mixed>  $context  {delivered, apply, worktree} — the delivery
     *                          result, the apply result (commit + files_changed), and the
     *                          obra worktree path (so a gate may run a focused check on the
     *                          accumulated state in the worktree)
     * @return array{passed:bool,reason?:string}
     */
    public function certify(array $node, array $context = []): array;
}

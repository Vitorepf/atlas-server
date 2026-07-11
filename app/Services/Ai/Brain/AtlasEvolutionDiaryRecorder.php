<?php

declare(strict_types=1);

namespace App\Services\Ai\Brain;

use Throwable;

/**
 * DIARIO-3 (Carta Regra 3) — the typed API every autonomous act calls to write
 * its labelled Evolution-Diary entry IN THE SAME ACT. This is the wiring that
 * turns "approval before" into "documentation after": G0 auto-promotes a memory,
 * a scoped commit auto-merges on the local main, auto-construction integrates a
 * new organ (requires_human_approval flipped false), an automation graduates or
 * is retired — each emits an entry here, and each entry is reversible.
 *
 * Only safe under SIS8 ({@see AtlasMemoryJournal}) + the scoped committer:
 * git revert reverses a merge; replay-sem-a-entrada reverses a memory promotion.
 * Every method fail-opens — a diary fault must never break the act it documents.
 */
class AtlasEvolutionDiaryRecorder
{
    public function __construct(
        private readonly ?AtlasEvolutionDiary $diary = null,
        private readonly ?AtlasMemoryJournal $journal = null,
    ) {}

    /** Auto-merge on the local main → a `merge` entry, reversible by git revert. */
    public function merged(string $commitSha, string $oQue, string $porQue, ?string $evidencia = null): void
    {
        $this->safe(fn () => $this->diary()->record('merge', $oQue, $porQue, $evidencia, $commitSha !== '' ? $commitSha : null));
    }

    /**
     * G0 auto-promotes a memory → a `promocao-memoria` entry, reversible by
     * replay-sem-a-entrada (the journal seq of the promoting mutation).
     */
    public function memoryPromoted(string $memoryId, string $oQue, string $porQue, ?string $evidencia = null): void
    {
        $this->safe(function () use ($memoryId, $oQue, $porQue, $evidencia): void {
            $seq = $this->journalSeqFor($memoryId);
            $this->diary()->record('promocao-memoria', $oQue, $porQue, $evidencia, $seq !== null ? 'memory:'.$seq : null);
        });
    }

    /**
     * OUTC-01(d): compounding memory auto-promote → labelled Diary entry with explicit
     * reverse handle for the weekly digest / operator review-after.
     */
    public function compoundingMemoryPromoted(
        string $memoryId,
        string $oQue,
        string $porQue,
        ?string $evidencia = null,
        ?string $reverseHandle = null,
    ): void {
        $this->safe(fn () => $this->diary()->record(
            'promocao-memoria',
            $oQue,
            $porQue,
            $evidencia,
            $reverseHandle,
        ));
    }

    /** Auto-construction integrates a new organ (requires_human_approval=false) → `orgao-novo`. */
    public function newOrgan(string $oQue, string $porQue, ?string $evidencia = null, ?string $idReversao = null): void
    {
        $this->safe(fn () => $this->diary()->record('orgao-novo', $oQue, $porQue, $evidencia, $idReversao));
    }

    /** An automation graduates from observe to enforce → `graduacao`. */
    public function graduated(string $oQue, string $porQue, ?string $evidencia = null, ?string $idReversao = null): void
    {
        $this->safe(fn () => $this->diary()->record('graduacao', $oQue, $porQue, $evidencia, $idReversao));
    }

    /** An automation or organ is written off → `aposentadoria`. */
    public function retired(string $oQue, string $porQue, ?string $evidencia = null, ?string $idReversao = null): void
    {
        $this->safe(fn () => $this->diary()->record('aposentadoria', $oQue, $porQue, $evidencia, $idReversao));
    }

    private function diary(): AtlasEvolutionDiary
    {
        return $this->diary ?? app(AtlasEvolutionDiary::class);
    }

    private function journal(): AtlasMemoryJournal
    {
        return $this->journal ?? app(AtlasMemoryJournal::class);
    }

    private function journalSeqFor(string $memoryId): ?int
    {
        $seq = null;
        foreach ($this->journal()->read() as $record) {
            if ((string) ($record['id'] ?? '') === $memoryId && isset($record['seq'])) {
                $seq = (int) $record['seq']; // last write for this id wins
            }
        }

        return $seq;
    }

    private function safe(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }
}

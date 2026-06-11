<?php

declare(strict_types=1);

namespace App\Services\Ai\Analysis;

/**
 * G6 — one seat of the cross-domain analysis judge panel.
 *
 * Each implementation is a deterministic LENS (no provider, no LLM): it looks
 * at one structured analysis payload from a single angle and either accepts or
 * refutes it. The panel composes several lenses under default-refute + strict
 * majority (mirrors the Frontier judge-panel pattern without modifying it).
 */
interface AnalysisJudgePort
{
    /**
     * @param  array<string,mixed>  $analysis
     * @return array{verdict:'accept'|'refute', reasons:list<string>, lens:string}
     */
    public function judge(array $analysis): array;
}

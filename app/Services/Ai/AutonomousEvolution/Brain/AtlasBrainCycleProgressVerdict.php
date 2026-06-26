<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * S7 KEYSTONE — the anti-Goodhart backstop for a brain cycle. A cycle counts as REAL progress ONLY when ALL
 * of these hold (each measured by a non-gameable side effect or organ verdict, never by the model self-scoring):
 *
 *   - a doc section was actually written on disk         (ctx.doc_written)
 *   - a packet was actually enqueued into the serving queue (ctx.enqueued)
 *   - the evolution-level classifier did NOT class it as a proxy (ctx.classifier_class !== 'rejected_proxy')
 *   - the seed-quality gate ADMITTED it — no BRAIN_FATAL_ADVISORY (ctx.seed_gate_admit)
 *   - at least one grounded citation backs it             (ctx.grounded_citations non-empty)
 *
 * This sits BEYOND the keyword proxy list {@see AtlasBrainSeedQualityGate}: a paraphrased proxy packet that
 * slips the substring screen still scores ZERO progress here unless it produced real doc + real enqueue + a
 * grounded citation. Pure: no provider, no DB, no mutation. Deterministic.
 */
final class AtlasBrainCycleProgressVerdict
{
    public const SCHEMA = 'atlas.brain.cycle_progress_verdict.v1';

    /**
     * @param  array<string,mixed>  $ctx
     * @return array{counts_as_progress:bool, reasons:list<string>}
     */
    public function verdict(array $ctx): array
    {
        $reasons = [];

        if (! (bool) ($ctx['doc_written'] ?? false)) {
            $reasons[] = 'no_doc_section_written';
        }
        if (! (bool) ($ctx['enqueued'] ?? false)) {
            $reasons[] = 'no_packet_enqueued';
        }
        if ((string) ($ctx['classifier_class'] ?? '') === 'rejected_proxy') {
            $reasons[] = 'classifier_rejected_proxy';
        }
        if (! (bool) ($ctx['seed_gate_admit'] ?? false)) {
            $reasons[] = 'seed_gate_blocked';
        }
        if ($this->groundedCitations($ctx) === []) {
            $reasons[] = 'no_grounded_citation';
        }

        return [
            'counts_as_progress' => $reasons === [],
            'reasons' => $reasons,
        ];
    }

    /**
     * @param  array<string,mixed>  $ctx
     * @return list<string>
     */
    private function groundedCitations(array $ctx): array
    {
        $citations = $ctx['grounded_citations'] ?? [];
        if (! is_array($citations)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $c): string => trim((string) $c), $citations),
            static fn (string $c): bool => $c !== '',
        ));
    }
}

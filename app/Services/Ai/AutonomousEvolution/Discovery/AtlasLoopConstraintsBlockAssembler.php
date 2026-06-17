<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Models\AtlasLoopTarget;
use Throwable;

/**
 * ARBOR-GRAFT CB1 — the constraints-block: Arbor's highest-value compounding lever. Before ideating it
 * feeds the model PRUNED LESSONS (failed directions + the anti-re-tread directive), VALIDATED FINDINGS
 * (what already works — build on it, don't re-derive) and a TREE SHAPE census (breadth vs depth). Purely
 * deterministic text assembled from EXISTING corpora, none of which gate.
 *
 * FLOOR-SAFE: CONTEXT ONLY — appended to the generation prompt, never to a certifier. A worse draft simply
 * fails the unchanged out-of-process gate. Provenance is kept DISTINCT: an operator steering note (S1) is
 * tagged operator_note and is NEVER laundered into a machine-certified VALIDATED FINDING. Wired away from
 * every gate by AtlasLoopAdvisoryFirewallTest.
 */
final class AtlasLoopConstraintsBlockAssembler
{
    private const ANTI_RETREAD = 'Do NOT re-propose any idea that shares the same hidden assumption or '
        .'mechanism-class as a pruned lesson without explicitly explaining how it gets around that lesson.';

    /**
     * Pure text composition from already-gathered data. Empty corpora => empty block (no crash).
     *
     * @param  list<string>  $prunedLessons      machine-resolved failed directions
     * @param  list<string>  $validatedFindings  machine-CERTIFIED outcomes only
     * @param  array<string,int>  $treeShape      status => count census
     */
    public static function assemble(array $prunedLessons, array $validatedFindings, array $treeShape, ?string $operatorNote = null): string
    {
        $lines = [];

        if ($treeShape !== []) {
            $parts = [];
            foreach ($treeShape as $status => $count) {
                $parts[] = "{$count} {$status}";
            }
            $lines[] = '## TREE SHAPE';
            $lines[] = implode(' | ', $parts);
            $lines[] = '';
        }

        if ($prunedLessons !== []) {
            $lines[] = '## PRUNED LESSONS ('.count($prunedLessons).' — these directions FAILED. '.self::ANTI_RETREAD.')';
            foreach ($prunedLessons as $lesson) {
                $lines[] = '- '.self::oneLine($lesson);
            }
            $lines[] = '';
        }

        if ($validatedFindings !== []) {
            $lines[] = '## VALIDATED FINDINGS ('.count($validatedFindings).' — machine-certified; build on them, do not re-derive.)';
            foreach ($validatedFindings as $finding) {
                $lines[] = '- '.self::oneLine($finding);
            }
            $lines[] = '';
        }

        if ($operatorNote !== null && trim($operatorNote) !== '') {
            // Operator-supplied steering — provenance kept DISTINCT from machine-certified findings; advisory.
            $lines[] = '## OPERATOR NOTE (provenance: operator_note — operator-supplied steering, NOT a certified finding)';
            $lines[] = self::oneLine($operatorNote);
            $lines[] = '';
        }

        return $lines === [] ? '' : rtrim(implode("\n", $lines));
    }

    private static function oneLine(string $text): string
    {
        $t = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return strlen($t) <= 240 ? $t : substr($t, 0, 237).'...';
    }

    /**
     * Thin DB wrapper: gather pruned lessons + tree shape from the campaign's tree; validated findings and
     * the operator note are supplied by the caller (the refiller has them). Fail-open to an empty block.
     *
     * @param  list<string>  $validatedFindings
     */
    public function build(string $campaignId, array $validatedFindings = [], ?string $operatorNote = null): string
    {
        try {
            $rows = AtlasLoopTarget::query()
                ->where('campaign_id', $campaignId)
                ->whereNotNull('tree_status')
                ->get(['tree_status', 'node_insight']);

            $pruned = [];
            $shape = [];
            foreach ($rows as $r) {
                $status = (string) $r->tree_status;
                $shape[$status] = ($shape[$status] ?? 0) + 1;
                if ($status === AtlasLoopIdeaTreeAccessor::STATUS_PRUNED) {
                    $insight = is_array($r->node_insight) ? $r->node_insight : [];
                    foreach ((array) ($insight['pruned_lessons'] ?? []) as $lesson) {
                        $pruned[] = (string) $lesson;
                    }
                }
            }

            return self::assemble($pruned, $validatedFindings, $shape, $operatorNote);
        } catch (Throwable) {
            return '';
        }
    }
}

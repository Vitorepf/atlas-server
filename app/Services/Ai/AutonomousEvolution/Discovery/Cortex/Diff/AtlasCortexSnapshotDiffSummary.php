<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Diff;

/**
 * FACT-only digest over the diff produced by AtlasCortexSnapshotDiffEngine.
 *
 * INVARIANTS:
 *   - Emits ONLY counts per category. NO aggregate scalar (no total_changes,
 *     no severity, no weight, no improvement_score, no importance). Collapsing
 *     into one number would reincarnate the cyclomatic-proxy trap the
 *     comprehension model defends against.
 *   - Reads the diff verbatim. Structurally-empty diff ⇒ every count is 0 and
 *     nonEmptyCategories is the empty list.
 *   - Prose section deltas live ONLY under output['prose'] with
 *     provenance=writable_untrusted_prose; structural categories are never
 *     mixed with prose facts.
 */
final class AtlasCortexSnapshotDiffSummary
{
    public const PROVENANCE_PROSE = 'writable_untrusted_prose';

    /** @var list<string> ordered fixed category names — alpha order ⇒ deterministic. */
    private const CATEGORIES = [
        'clone_clusters_appeared',
        'clone_clusters_dissolved',
        'clone_clusters_shape_changed',
        'doc_stated_gaps_closed',
        'doc_stated_gaps_opened',
        'edges_added',
        'edges_removed',
        'forbidden_added',
        'forbidden_removed',
        'inventory_added',
        'inventory_removed',
        'inventory_shape_changed',
        'orphans_appeared',
        'orphans_resolved',
    ];

    /**
     * @param  array<string,mixed>  $diff structural diff array; tested fields:
     *   - from_snapshot_id / to_snapshot_id
     *   - inventory: {added, removed, shape_changed}
     *   - orphans:   {appeared, resolved}
     *   - edges:     {added, removed}
     *   - clone_clusters: {appeared, dissolved, shape_changed}
     *   - forbidden: {added, removed}
     *   - doc_stated_gaps: {opened, closed}
     *   - prose:     {added, removed, changed}  (optional — segregated)
     * @return array<string,mixed>
     */
    public function summarize(array $diff): array
    {
        $counts = [
            'inventory_added' => $this->countOf($diff, ['inventory', 'added']),
            'inventory_removed' => $this->countOf($diff, ['inventory', 'removed']),
            'inventory_shape_changed' => $this->countOf($diff, ['inventory', 'shape_changed']),
            'orphans_appeared' => $this->countOf($diff, ['orphans', 'appeared']),
            'orphans_resolved' => $this->countOf($diff, ['orphans', 'resolved']),
            'edges_added' => $this->countOf($diff, ['edges', 'added']),
            'edges_removed' => $this->countOf($diff, ['edges', 'removed']),
            'clone_clusters_appeared' => $this->countOf($diff, ['clone_clusters', 'appeared']),
            'clone_clusters_dissolved' => $this->countOf($diff, ['clone_clusters', 'dissolved']),
            'clone_clusters_shape_changed' => $this->countOf($diff, ['clone_clusters', 'shape_changed']),
            'forbidden_added' => $this->countOf($diff, ['forbidden', 'added']),
            'forbidden_removed' => $this->countOf($diff, ['forbidden', 'removed']),
            'doc_stated_gaps_opened' => $this->countOf($diff, ['doc_stated_gaps', 'opened']),
            'doc_stated_gaps_closed' => $this->countOf($diff, ['doc_stated_gaps', 'closed']),
        ];

        $nonEmpty = [];
        foreach (self::CATEGORIES as $category) {
            if (($counts[$category] ?? 0) > 0) {
                $nonEmpty[] = $category;
            }
        }
        sort($nonEmpty, SORT_STRING);

        $prose = is_array($diff['prose'] ?? null) ? $diff['prose'] : [];

        return [
            'from_snapshot_id' => (string) ($diff['from_snapshot_id'] ?? ''),
            'to_snapshot_id' => (string) ($diff['to_snapshot_id'] ?? ''),
            'inventory_added' => $counts['inventory_added'],
            'inventory_removed' => $counts['inventory_removed'],
            'inventory_shape_changed' => $counts['inventory_shape_changed'],
            'orphans_appeared' => $counts['orphans_appeared'],
            'orphans_resolved' => $counts['orphans_resolved'],
            'edges_added' => $counts['edges_added'],
            'edges_removed' => $counts['edges_removed'],
            'clone_clusters_appeared' => $counts['clone_clusters_appeared'],
            'clone_clusters_dissolved' => $counts['clone_clusters_dissolved'],
            'clone_clusters_shape_changed' => $counts['clone_clusters_shape_changed'],
            'forbidden_added' => $counts['forbidden_added'],
            'forbidden_removed' => $counts['forbidden_removed'],
            'doc_stated_gaps_opened' => $counts['doc_stated_gaps_opened'],
            'doc_stated_gaps_closed' => $counts['doc_stated_gaps_closed'],
            'nonEmptyCategories' => $nonEmpty,
            'prose' => [
                'provenance' => self::PROVENANCE_PROSE,
                'added' => $this->countOf(['root' => $prose], ['root', 'added']),
                'removed' => $this->countOf(['root' => $prose], ['root', 'removed']),
                'changed' => $this->countOf(['root' => $prose], ['root', 'changed']),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $bag
     * @param  list<string>  $path
     */
    private function countOf(array $bag, array $path): int
    {
        $cursor = $bag;
        foreach ($path as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return 0;
            }
            $cursor = $cursor[$segment];
        }
        if (is_int($cursor)) {
            return max(0, $cursor);
        }
        if (is_array($cursor)) {
            return count($cursor);
        }

        return 0;
    }
}

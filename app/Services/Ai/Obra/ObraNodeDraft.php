<?php

declare(strict_types=1);

namespace App\Services\Ai\Obra;

/**
 * AOBG N3.F1 — a single DECOMPOSED step, before it is validated + persisted.
 *
 * A {@see ObraDecomposer} returns a list of these drafts (the raw, un-anchored,
 * un-sequenced steps). The {@see AtlasObraPlanService} then:
 *   - validates the DAG they form (no cycles, deps resolve, topo order exists),
 *   - anchors each to the brain (cites the AURG / context-pack refs for the step),
 *   - assigns the topological seq, and persists it as an `atlas_obra_nodes` row.
 *
 * `key` is the decomposer's LOCAL handle for the step — depends_on entries name
 * OTHER drafts by their `key`. The service maps keys → stable node ids after the
 * topological sort, so the decomposer never has to know the final id scheme.
 *
 * PRIVACY: every field is a provider-safe LABEL (a step request / a file hint),
 * never source and never a raw secret — the decomposer that builds these is
 * responsible for keeping them provider-safe (the deterministic one is by
 * construction; the provider-backed one redacts).
 */
final class ObraNodeDraft
{
    /**
     * @param  string  $key  the decomposer's local handle (referenced by depends_on)
     * @param  string  $title  short human label
     * @param  string  $request  the natural-language step the executor delivers
     * @param  ?string  $targetArea  file/dir/module hint (biases brain + code recall)
     * @param  list<string>  $dependsOn  keys of OTHER drafts this step depends on
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly string $request,
        public readonly ?string $targetArea = null,
        public readonly array $dependsOn = [],
    ) {}

    /**
     * Build a draft from a loose array (the provider-decoder / fixture shape):
     * {key, title, request, target_area?, depends_on?:list<string>}. Tolerant of
     * missing optional fields; falls back the title to a trimmed request.
     *
     * @param  array<string,mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $key = trim((string) ($row['key'] ?? ''));
        $request = trim((string) ($row['request'] ?? ''));
        $title = trim((string) ($row['title'] ?? ''));
        if ($title === '') {
            $title = mb_substr($request, 0, 80);
        }

        $targetArea = isset($row['target_area']) ? trim((string) $row['target_area']) : '';

        $dependsOn = [];
        foreach ((array) ($row['depends_on'] ?? []) as $dep) {
            $dep = trim((string) $dep);
            if ($dep !== '') {
                $dependsOn[] = $dep;
            }
        }

        return new self(
            key: $key,
            title: $title,
            request: $request,
            targetArea: $targetArea !== '' ? $targetArea : null,
            dependsOn: array_values(array_unique($dependsOn)),
        );
    }
}

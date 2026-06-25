<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Cortex;

/**
 * Composes existing-source inventory + freshness + risk gaps + queue state + task coverage + evidence
 * refs into ONE deterministic Self-Construction WORLD SNAPSHOT.
 *
 * SEPARATION OF POWERS: the output is OBSERVATION FACTS only. NO priority, scheduling, merge or
 * learning decision. Strategy / Control Plane / Merge Governor / Learning Transfer use the snapshot;
 * this composer does NOT decide anything.
 *
 * INPUT (each section optional, missing ones surface as 'missing_section:<name>' blocker):
 *   { inventory:{...}, freshness:{...}, risk_gaps:{gaps:[...]}, queue_state:{...},
 *     task_coverage:{...}, evidence_refs:{...} }
 *
 * OUTPUT:
 *   { schema, status ∈ {ready,blocked}, blockers:list<string>, snapshot:array<string,mixed>, snapshot_hash:string }
 *
 * INVARIANTS:
 *   - DETERMINISTIC snapshot_hash (sha256 over canonical-ksort encoded snapshot).
 *   - Identical input ⇒ byte-identical envelope.
 *   - NO scalar score / rank.
 */
final class AtlasSelfConstructionCortexSnapshotComposer
{
    public const SCHEMA = 'atlas.cortex.world_snapshot.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

    public const REQUIRED_SECTIONS = ['inventory', 'freshness', 'risk_gaps', 'queue_state', 'task_coverage', 'evidence_refs'];

    /**
     * @param  array<string,array<string,mixed>>  $sections
     * @return array{schema:string, status:string, blockers:list<string>, snapshot:array<string,array<string,mixed>>, snapshot_hash:string}
     */
    public function compose(array $sections): array
    {
        $blockers = [];
        $snapshot = [];
        foreach (self::REQUIRED_SECTIONS as $name) {
            if (! isset($sections[$name]) || ! is_array($sections[$name])) {
                $blockers[] = 'missing_section:'.$name;
                $snapshot[$name] = null;

                continue;
            }
            $snapshot[$name] = $sections[$name];
        }

        $status = $blockers === [] ? self::STATUS_READY : self::STATUS_BLOCKED;
        sort($blockers, SORT_STRING);

        $canonical = $snapshot;
        ksort($canonical);
        $hash = hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return [
            'schema' => self::SCHEMA,
            'status' => $status,
            'blockers' => $blockers,
            'snapshot' => $snapshot,
            'snapshot_hash' => $hash,
        ];
    }
}

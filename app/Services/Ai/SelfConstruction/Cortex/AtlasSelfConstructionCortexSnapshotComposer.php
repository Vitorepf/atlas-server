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

        // Extract stale/missing sources from the freshness section for originator decisions.
        $staleSources   = [];
        $missingSources = [];
        if (is_array($snapshot['freshness'] ?? null)) {
            foreach ((array) ($snapshot['freshness']['rows'] ?? []) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $sourceId  = (string) ($row['source_id'] ?? '');
                $readiness = (string) ($row['readiness'] ?? '');
                if ($readiness === 'stale') {
                    $staleSources[] = $sourceId;
                } elseif ($readiness === 'missing') {
                    $missingSources[] = $sourceId;
                }
            }
        }

        // Extract a compact queue summary so originators don't need to drill into the section.
        $queueSummary = ['pending' => null, 'locked' => null, 'dry' => null];
        if (is_array($snapshot['queue_state'] ?? null)) {
            $qs = $snapshot['queue_state'];
            $queueSummary['pending'] = isset($qs['pending_count']) ? (int) $qs['pending_count'] : (isset($qs['pending']) ? (int) $qs['pending'] : null);
            $queueSummary['locked']  = isset($qs['locked_count'])  ? (int) $qs['locked_count']  : (isset($qs['locked'])  ? (int) $qs['locked']  : null);
            $queueSummary['dry']     = isset($qs['dry']) ? (bool) $qs['dry'] : null;
        }

        $canonical = $snapshot;
        ksort($canonical);
        $hash = hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return [
            'schema'          => self::SCHEMA,
            'status'          => $status,
            'blockers'        => $blockers,
            'snapshot'        => $snapshot,
            'snapshot_hash'   => $hash,
            'stale_sources'   => $staleSources,
            'missing_sources' => $missingSources,
            'queue_summary'   => $queueSummary,
        ];
    }
}

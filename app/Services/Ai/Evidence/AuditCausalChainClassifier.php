<?php

declare(strict_types=1);

namespace App\Services\Ai\Evidence;

/**
 * Classifies the causal integrity of an ordered audit event timeline.
 *
 * Each event may carry a uuid, a correlation_id and a causation_id (TEOS-I1
 * timeline fields, mirrored from AuditEventService::record). A single forward
 * pass builds a seen-set of every event's uuid and correlation_id, adding them
 * AFTER the event has been evaluated. An event whose non-empty causation_id is
 * not already present in that prior seen-set (an event strictly earlier in the
 * timeline) is a dangling link. Self-referential causation (causation_id equal
 * to the event's own uuid) is therefore dangling, because the own id has not
 * been folded into the seen-set yet at evaluation time.
 *
 * Pure: zero constructor dependencies, no I/O, no clock, no randomness. Every
 * returned field is computed from the supplied events via the ordered rules.
 */
final class AuditCausalChainClassifier
{
    private const STATUS_EMPTY = 'empty';

    private const STATUS_DANGLING = 'dangling_causation';

    private const STATUS_ORPHAN_ROOT = 'orphan_root';

    private const STATUS_CAUSAL = 'causal';

    /**
     * @param  array<int,mixed>  $events
     * @return array{status: string, first_dangling_index: int|null, seen_ids: int, reason: string}
     */
    public function classify(array $events): array
    {
        // Rule (1): an empty timeline has no causal structure to evaluate.
        if ($events === []) {
            return [
                'status' => self::STATUS_EMPTY,
                'first_dangling_index' => null,
                'seen_ids' => 0,
                'reason' => 'no_events',
            ];
        }

        /** @var array<string,true> $seen */
        $seen = [];
        $hasLink = false;
        $index = 0;

        foreach ($events as $event) {
            $uuid = $this->normalize($this->field($event, 'uuid'));
            $correlationId = $this->normalize($this->field($event, 'correlation_id'));
            $causationId = $this->normalize($this->field($event, 'causation_id'));

            // Rule (3): an empty/absent causation_id is a legal root and is
            // skipped. Rules (2) and (5): a non-empty causation_id must resolve
            // to a strictly-earlier event already in the prior seen-set; the
            // event's own uuid is not yet present, so self-reference dangles.
            if ($causationId !== null) {
                $hasLink = true;

                if (! isset($seen[$causationId])) {
                    return [
                        'status' => self::STATUS_DANGLING,
                        'first_dangling_index' => $index,
                        'seen_ids' => count($seen),
                        'reason' => 'causation_id_unresolved_at_index_'.$index,
                    ];
                }
            }

            // Fold this event's identifiers into the seen-set AFTER evaluation.
            if ($uuid !== null) {
                $seen[$uuid] = true;
            }
            if ($correlationId !== null) {
                $seen[$correlationId] = true;
            }

            $index++;
        }

        // Rule (4): no event carried any causation_id -> every event is a root.
        if (! $hasLink) {
            return [
                'status' => self::STATUS_ORPHAN_ROOT,
                'first_dangling_index' => null,
                'seen_ids' => count($seen),
                'reason' => 'no_causation_links',
            ];
        }

        // Rule (6): at least one link existed and every causation_id resolved
        // to a strictly-earlier identifier.
        return [
            'status' => self::STATUS_CAUSAL,
            'first_dangling_index' => null,
            'seen_ids' => count($seen),
            'reason' => 'all_links_resolved',
        ];
    }

    private function field(mixed $event, string $key): mixed
    {
        return is_array($event) ? ($event[$key] ?? null) : null;
    }

    private function normalize(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\AuditTrail;

use Generator;

/**
 * Joins every existing Loop sub-ledger (AtlasLoopAttemptLedger, AtlasLoopImpactReceiptService,
 * AtlasLoopProjectionOutcomeLedger, AtlasLoopAtomParaphraseAudit, plus cycle git contract and
 * auto-merge receipts) into ONE ordered FACTS-only audit timeline.
 *
 * Contract:
 *   - Registry-driven: adding a new source ⇒ register(name, source). compose() is never edited.
 *   - Each source MUST expose iterate(TimelineWindow $w): iterable<array> yielding rows of the form
 *       {ts_utc:string, kind:string, refs?:list<string>, facts?:array<string,mixed>, event_id?:string}.
 *   - Global ordering is (ts_utc ASC, event_id ASC). Composer assigns a stable event_id of the form
 *     "<source>:<row_index>:<ts_utc>" when the row omits one — never invents the ts_utc.
 *   - PURE JOIN: the row's facts are passed through byte-identical (no canonicalization, no scoring,
 *     no summarization, no key reordering).
 *   - A degenerate TimelineWindow short-circuits: no source is touched.
 */
final class AtlasLoopAuditTrailComposer
{
    /** @var array<string, callable(TimelineWindow):iterable<array<string,mixed>>> */
    private array $registry = [];

    /**
     * @param  callable(TimelineWindow):iterable<array<string,mixed>>  $source
     */
    public function register(string $sourceName, callable $source): void
    {
        $this->registry[$sourceName] = $source;
    }

    /**
     * @return list<string>
     */
    public function registeredSources(): array
    {
        $names = array_keys($this->registry);
        sort($names);

        return array_values($names);
    }

    /**
     * @return Generator<int, AuditEvent>
     */
    public function compose(TimelineWindow $window): Generator
    {
        if ($window->isDegenerate()) {
            return;
        }

        /** @var list<array{ts:string, eid:string, ev:AuditEvent}> $buf */
        $buf = [];

        foreach ($this->registry as $sourceName => $source) {
            $rowIndex = 0;
            foreach ($source($window) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $tsUtc = (string) ($row['ts_utc'] ?? '');
                if ($tsUtc === '' || ! $window->contains($tsUtc)) {
                    continue;
                }
                $eventId = (string) ($row['event_id'] ?? '');
                if ($eventId === '') {
                    $eventId = $sourceName.':'.$rowIndex.':'.$tsUtc;
                }
                $event = new AuditEvent(
                    event_id: $eventId,
                    ts_utc: $tsUtc,
                    source_ledger: $sourceName,
                    kind: (string) ($row['kind'] ?? ''),
                    refs: array_values((array) ($row['refs'] ?? [])),
                    facts: (array) ($row['facts'] ?? []),
                );
                $buf[] = ['ts' => $tsUtc, 'eid' => $eventId, 'ev' => $event];
                $rowIndex++;
            }
        }

        usort($buf, static function (array $a, array $b): int {
            return strcmp($a['ts'], $b['ts']) ?: strcmp($a['eid'], $b['eid']);
        });

        foreach ($buf as $row) {
            yield $row['ev'];
        }
    }
}

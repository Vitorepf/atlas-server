<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council;

/**
 * CORTEX COUNCIL TRIANGULATOR — composes a {@see CouncilReport} from the lens observations gathered for ONE
 * {@see CortexSubject}. The composition is purely structural:
 *
 *   - RAW FACTS: every lens's facts are kept verbatim, keyed by lens_id (never collapsed to a number).
 *   - AGREEMENTS: facts whose canonical signature is asserted by ≥2 DISTINCT lens ids.
 *   - DISAGREEMENTS: every lens-emitted disagreement_signal (lifted to a first-class FACT), plus cross-lens
 *     fact-vs-fact conflicts when two lenses emit facts with the same {kind, target} but a different
 *     discriminator value (e.g. covered vs uncovered for the same line).
 *
 * NO weighted vote, NO scalar verdict, NO majority rule, NO winner picking. The downstream consumer reads
 * the report and acts on the facts; the triangulator never picks sides.
 */
final class AtlasCortexCouncilTriangulator
{
    /**
     * @param  list<LensObservation>  $observations  lens observations for ONE subject
     */
    public function triangulate(array $observations): CouncilReport
    {
        if ($observations === []) {
            return new CouncilReport('', [], [], [], []);
        }

        // All observations should share the same subject_id; we surface the first one.
        $subjectId = (string) $observations[0]->subjectId;

        $rawFactsByLens = [];
        $participating = [];
        $disagreements = [];

        // Index: signature_hash => list<{lens_id, fact}>  (for agreement detection)
        $bySignature = [];
        // Index: kind+target => list<{lens_id, fact, discriminator}> (for cross-lens conflict detection)
        $byKindTarget = [];

        foreach ($observations as $obs) {
            $lensId = (string) $obs->lensId;
            $participating[] = $lensId;

            $rawFactsByLens[$lensId] = $this->factsList($obs);
            foreach ($this->factsList($obs) as $fact) {
                $hash = self::signatureHash($fact);
                $bySignature[$hash] ??= [];
                $bySignature[$hash][] = ['lens_id' => $lensId, 'fact' => $fact];

                $kindTarget = $this->kindTargetKey($fact);
                if ($kindTarget !== null) {
                    $byKindTarget[$kindTarget] ??= [];
                    $byKindTarget[$kindTarget][] = ['lens_id' => $lensId, 'fact' => $fact, 'discriminator' => $this->discriminator($fact)];
                }
            }

            foreach ($obs->disagreementSignals as $signal) {
                $disagreements[] = [
                    'kind' => 'lens_disagreement_signal',
                    'signal' => (string) $signal,
                    'lens_id' => $lensId,
                ];
            }
        }

        // AGREEMENTS — same signature asserted by ≥2 distinct lens ids.
        $agreements = [];
        foreach ($bySignature as $group) {
            $lensIds = array_values(array_unique(array_column($group, 'lens_id')));
            if (count($lensIds) >= 2) {
                sort($lensIds, SORT_STRING);
                $agreements[] = [
                    'fact' => $group[0]['fact'],
                    'supporting_lens_ids' => $lensIds,
                ];
            }
        }
        usort($agreements, static fn (array $x, array $y): int => self::signatureHash($x['fact']) <=> self::signatureHash($y['fact']));

        // CROSS-LENS CONFLICTS — same kind+target, different discriminator, asserted by ≥2 distinct lens ids.
        foreach ($byKindTarget as $kindTarget => $group) {
            $byDiscriminator = [];
            foreach ($group as $entry) {
                $d = (string) $entry['discriminator'];
                $byDiscriminator[$d] ??= [];
                $byDiscriminator[$d][] = $entry['lens_id'];
            }
            $distinctLensIds = array_values(array_unique(array_column($group, 'lens_id')));
            if (count($byDiscriminator) >= 2 && count($distinctLensIds) >= 2) {
                // Multiple discriminator values for the same kind+target ⇒ a real conflict.
                ksort($byDiscriminator);
                $disagreements[] = [
                    'kind' => 'cross_lens_fact_conflict',
                    'signal' => $kindTarget.' has conflicting values: '.implode(' vs ', array_keys($byDiscriminator)),
                    'lens_id' => null,
                    'fact' => ['kind_target' => $kindTarget, 'discriminators' => array_keys($byDiscriminator)],
                ];
            }
        }

        usort($disagreements, static fn (array $x, array $y): int => [(string) $x['kind'], (string) $x['signal']] <=> [(string) $y['kind'], (string) $y['signal']]);

        $participating = array_values(array_unique($participating));
        sort($participating, SORT_STRING);

        return new CouncilReport($subjectId, $rawFactsByLens, $agreements, $disagreements, $participating);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function factsList(LensObservation $obs): array
    {
        $bag = $obs->facts;
        // The lens may shape facts in several ways: a flat list, or a wrapper like {facts: [...]} or
        // {entries: [...]}. We flatten the common cases so the triangulator can iterate uniformly.
        if (isset($bag['facts']) && is_array($bag['facts'])) {
            return array_values($this->onlyAssocItems($bag['facts']));
        }
        if (isset($bag['entries']) && is_array($bag['entries'])) {
            return array_values($this->onlyAssocItems($bag['entries']));
        }
        if (array_is_list($bag)) {
            return array_values($this->onlyAssocItems($bag));
        }
        // Take any top-level list-of-assocs values (e.g. {methods: [...]}) and concatenate.
        $out = [];
        foreach ($bag as $v) {
            if (is_array($v) && array_is_list($v)) {
                foreach ($this->onlyAssocItems($v) as $item) {
                    $out[] = $item;
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<int,mixed>  $items
     * @return list<array<string,mixed>>
     */
    private function onlyAssocItems(array $items): array
    {
        $out = [];
        foreach ($items as $i) {
            if (is_array($i) && ! array_is_list($i)) {
                $out[] = $i;
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $fact
     */
    private function kindTargetKey(array $fact): ?string
    {
        $kind = (string) ($fact['kind'] ?? '');
        if ($kind === '') {
            return null;
        }
        // Target = method name OR file path OR (line+file) when available.
        $target = $fact['method'] ?? ($fact['file'] ?? ($fact['symbol'] ?? null));
        if ($target === null) {
            return null;
        }

        return $kind.'@'.((string) $target);
    }

    /**
     * @param  array<string,mixed>  $fact
     */
    private function discriminator(array $fact): string
    {
        // Heuristic: the most stable discriminator is the fact's count/value/signal field if present;
        // otherwise the kind itself is the discriminator (so cross-kind conflict on the same target counts).
        foreach (['signal', 'value', 'count', 'covered'] as $k) {
            if (array_key_exists($k, $fact)) {
                return $k.':'.json_encode($fact[$k], JSON_UNESCAPED_SLASHES);
            }
        }

        return 'kind:'.(string) ($fact['kind'] ?? '');
    }

    /**
     * @param  array<string,mixed>  $fact
     */
    private static function signatureHash(array $fact): string
    {
        return hash('sha256', (string) json_encode(self::canonicalize($fact), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'canonicalize'], $value);
        }
        ksort($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::canonicalize($v);
        }

        return $out;
    }
}

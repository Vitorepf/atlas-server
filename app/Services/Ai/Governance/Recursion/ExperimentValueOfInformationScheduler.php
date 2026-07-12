<?php

declare(strict_types=1);

namespace App\Services\Ai\Governance\Recursion;

/**
 * REC-02 — Scheduler de experimentos por valor-de-informação (o dono do relógio).
 *
 * The recursion's scarcest resource is CLOCK: observation windows. This service
 * ranks pending REC-01 hypotheses by a deterministic VOI score and publishes an
 * ELEV-26s-compatible agenda. It PROPOSES, never STARTS — the flip belongs to
 * the operator (charter 06/07 + A4).
 *
 * Frontier plan §3042-3046 hard rules baked in mechanically:
 *   - VOI = expected_gain × interval_width / (window_days × cost) — pure fn of
 *     inputs; a hypothesis without a falsifier (already refused by REC-01) is
 *     inelegible; a hypothesis whose components are unmeasurable goes to the
 *     BOTTOM of the ranking with `basis=unmeasurable_component` and NEVER
 *     silently drops out (dropping evidence is the "92" of scheduling).
 *   - Independent families are RUN IN PARALLEL — the emitted agenda groups
 *     independent hypotheses into overlapping windows.
 *   - Same-family hypotheses NEVER overlap — 1 flip per family per window
 *     (attribution invariant); the 2nd of the family serialises behind the 1st.
 *   - Agenda publishes critical path with declared days remaining. Empty
 *     agenda ⇒ `status=insufficient_signal` (never a fabricated ETA).
 *
 * READ-ONLY. Zero I/O. Provider-safe by construction: takes scalar inputs from
 * callers (DAG entries from MULTX-09; validated hypotheses from REC-01;
 * declared cost/complexity from ELEV-27). Never touches the network, never
 * writes to any ledger.
 */
final class ExperimentValueOfInformationScheduler
{
    public const SCHEMA_VERSION = 'atlas.acos.rec_scheduler.v1';

    /**
     * A hypothesis with an unmeasurable component is ranked LAST, never
     * silently pruned. Everything with `_unmeasurable_` prefix on `basis`
     * lives here so the report layer can render them tail-of-queue.
     */
    public const BASIS_UNMEASURABLE = 'unmeasurable_component';

    public const BASIS_INELIGIBLE_NO_FALSIFIER = 'ineligible_no_falsifier';

    public const BASIS_MEASURED = 'measured';

    /**
     * Rank a set of hypothesis proposals by VOI and return the agenda.
     *
     * @param  list<array<string,mixed>>  $proposals  each item: {
     *   hypothesis_id:string, family:string, hypothesis:array (REC-01 payload),
     *   interval_width_current: float|null (MULTK-01-style CI width, larger
     *     ⇒ more uncertainty ⇒ more information to extract; null ⇒
     *     unmeasurable),
     *   expected_gain: float|null (declared magnitude the flip is predicted
     *     to move; null ⇒ unmeasurable),
     *   window_days: int|null (days the observation window occupies; null ⇒
     *     unmeasurable),
     *   cost_units: float|null (ELEV-27 declared cost; null ⇒ unmeasurable),
     * }
     * @return array<string,mixed> schema-shaped agenda payload
     */
    public function schedule(array $proposals): array
    {
        $ranked = [];
        $unmeasurable = [];
        $ineligible = [];

        foreach ($proposals as $proposal) {
            $normalized = $this->normalize($proposal);
            if ($normalized === null) {
                continue;
            }
            $classification = $this->classify($normalized);
            $entry = [
                'hypothesis_id' => $normalized['hypothesis_id'],
                'family' => $normalized['family'],
                'basis' => $classification['basis'],
                'reason' => $classification['reason'] ?? null,
                'voi' => $classification['voi'],
                'components' => $classification['components'],
            ];
            if ($classification['basis'] === self::BASIS_INELIGIBLE_NO_FALSIFIER) {
                $ineligible[] = $entry;

                continue;
            }
            if ($classification['basis'] === self::BASIS_UNMEASURABLE) {
                $unmeasurable[] = $entry;

                continue;
            }
            $ranked[] = $entry;
        }

        usort($ranked, static function (array $a, array $b): int {
            $cmp = ($b['voi'] <=> $a['voi']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp((string) $a['hypothesis_id'], (string) $b['hypothesis_id']);
        });
        usort($unmeasurable, static fn (array $a, array $b): int => strcmp((string) $a['hypothesis_id'], (string) $b['hypothesis_id']));
        usort($ineligible, static fn (array $a, array $b): int => strcmp((string) $a['hypothesis_id'], (string) $b['hypothesis_id']));

        $agenda = $this->composeAgenda($ranked);
        $criticalPath = $this->criticalPath($agenda);

        $status = $ranked === [] ? 'insufficient_signal' : 'ok';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'source' => [
                'read_only' => true,
                'starts_windows' => false,
                'proposes_only' => true,
            ],
            'ranked' => $ranked,
            'unmeasurable' => $unmeasurable,
            'ineligible' => $ineligible,
            'agenda' => $agenda,
            'critical_path' => $criticalPath,
        ];
    }

    /**
     * @param  array<string,mixed>  $proposal
     * @return array<string,mixed>|null
     */
    private function normalize(array $proposal): ?array
    {
        $id = $proposal['hypothesis_id'] ?? null;
        $family = $proposal['family'] ?? null;
        if (! is_string($id) || $id === '' || ! is_string($family) || $family === '') {
            return null;
        }

        return [
            'hypothesis_id' => $id,
            'family' => $family,
            'hypothesis' => is_array($proposal['hypothesis'] ?? null) ? $proposal['hypothesis'] : [],
            'interval_width_current' => $this->numericOrNull($proposal['interval_width_current'] ?? null),
            'expected_gain' => $this->numericOrNull($proposal['expected_gain'] ?? null),
            'window_days' => $this->intPositiveOrNull($proposal['window_days'] ?? null),
            'cost_units' => $this->positiveOrNull($proposal['cost_units'] ?? null),
        ];
    }

    /**
     * @param  array<string,mixed>  $normalized
     * @return array{basis:string, voi:float|null, reason?:string, components:array<string,mixed>}
     */
    private function classify(array $normalized): array
    {
        $hypothesis = $normalized['hypothesis'];
        $errors = $hypothesis === [] ? [['field' => '_root', 'code' => 'empty']] : HypothesisV1::validate($hypothesis);
        $falsifierMissing = array_filter(
            $errors,
            static fn (array $err): bool => ($err['field'] ?? null) === 'falsifier'
                || ($err['field'] ?? null) === '_root'
        );
        if ($falsifierMissing !== []) {
            return [
                'basis' => self::BASIS_INELIGIBLE_NO_FALSIFIER,
                'reason' => 'hypothesis missing a valid falsifier',
                'voi' => null,
                'components' => [],
            ];
        }

        $width = $normalized['interval_width_current'];
        $gain = $normalized['expected_gain'];
        $days = $normalized['window_days'];
        $cost = $normalized['cost_units'];

        $components = [
            'interval_width_current' => $this->componentBasis($width),
            'expected_gain' => $this->componentBasis($gain),
            'window_days' => $this->componentBasis($days),
            'cost_units' => $this->componentBasis($cost),
        ];

        if ($width === null || $gain === null || $days === null || $cost === null) {
            return [
                'basis' => self::BASIS_UNMEASURABLE,
                'reason' => 'one or more VOI components missing',
                'voi' => null,
                'components' => $components,
            ];
        }

        $denominator = ($days * $cost);
        if ($denominator <= 0.0) {
            return [
                'basis' => self::BASIS_UNMEASURABLE,
                'reason' => 'non-positive denominator (window_days × cost_units)',
                'voi' => null,
                'components' => $components,
            ];
        }

        $voi = ($gain * $width) / $denominator;

        return [
            'basis' => self::BASIS_MEASURED,
            'voi' => $voi,
            'components' => $components,
        ];
    }

    /**
     * Same-family entries serialise; independent families run in parallel.
     *
     * @param  list<array<string,mixed>>  $ranked  already VOI-desc sorted
     * @return list<array<string,mixed>>
     */
    private function composeAgenda(array $ranked): array
    {
        $familySlot = [];
        $agenda = [];
        foreach ($ranked as $entry) {
            $family = (string) $entry['family'];
            $slot = $familySlot[$family] ?? 0;
            $agenda[] = [
                'hypothesis_id' => $entry['hypothesis_id'],
                'family' => $family,
                'family_slot' => $slot,
                'starts_after_family_slot' => $slot === 0 ? null : $slot - 1,
                'voi' => $entry['voi'],
                'parallel_with' => [],
            ];
            $familySlot[$family] = $slot + 1;
        }
        for ($i = 0; $i < count($agenda); $i++) {
            $peers = [];
            for ($j = 0; $j < count($agenda); $j++) {
                if ($i === $j) {
                    continue;
                }
                if ($agenda[$j]['family'] !== $agenda[$i]['family']
                    && $agenda[$j]['family_slot'] === $agenda[$i]['family_slot']
                ) {
                    $peers[] = $agenda[$j]['hypothesis_id'];
                }
            }
            sort($peers);
            $agenda[$i]['parallel_with'] = $peers;
        }

        return $agenda;
    }

    /**
     * @param  list<array<string,mixed>>  $agenda
     * @return list<string>  hypothesis ids on the highest-VOI serialised chain
     */
    private function criticalPath(array $agenda): array
    {
        $chains = [];
        foreach ($agenda as $item) {
            $family = (string) $item['family'];
            $chains[$family] = $chains[$family] ?? ['ids' => [], 'voi_sum' => 0.0];
            $chains[$family]['ids'][] = $item['hypothesis_id'];
            $chains[$family]['voi_sum'] += (float) $item['voi'];
        }
        if ($chains === []) {
            return [];
        }
        uasort($chains, static fn (array $a, array $b): int => $b['voi_sum'] <=> $a['voi_sum']);
        $winner = array_values($chains)[0];

        return $winner['ids'];
    }

    private function componentBasis(?float $value): array
    {
        if ($value === null) {
            return ['value' => null, 'basis' => 'unmeasurable'];
        }

        return ['value' => $value, 'basis' => 'measured'];
    }

    private function numericOrNull(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function positiveOrNull(mixed $value): ?float
    {
        $numeric = $this->numericOrNull($value);
        if ($numeric === null || $numeric <= 0.0) {
            return null;
        }

        return $numeric;
    }

    private function intPositiveOrNull(mixed $value): ?float
    {
        $numeric = $this->numericOrNull($value);
        if ($numeric === null) {
            return null;
        }
        if ((int) $numeric <= 0) {
            return null;
        }

        return (float) (int) $numeric;
    }
}

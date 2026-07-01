<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Normalizes gap facts across the eight core Autonomous areas — external_brain, task_fabric,
 * maestro, queue, outcome_learning, simplification, control_plane, prompt_contract — into one
 * gap list, instead of each subsystem reporting health in its own shape and an operator arguing
 * from scattered command outputs.
 *
 * Input shape:
 *   { areas: {
 *       <area_key>?: array{connected_consumers?: list<string>, report_only?: bool},
 *   } }
 *
 * An area only counts as connected when it names at least one non-blank connected consumer AND
 * is not marked report_only — a subsystem that only emits a report about itself, with nothing
 * downstream actually consuming its output, is still a detached organ. Every disconnected area
 * produces a normalized gap row: area, missing_link, severity, evidence, proposed_closure_task.
 * no_gap is true only when every required area is connected; empty or report-only input always
 * yields no_gap=false with every area named in detached_areas.
 *
 * Pure: no I/O, no provider calls, deterministic — callers supply the area facts.
 */
final class AtlasExternalBrainGapInventory
{
    public const SCHEMA = 'atlas.self_construction.external_brain.gap_inventory.v1';

    /** @var list<string> */
    private const REQUIRED_AREAS = [
        'external_brain',
        'task_fabric',
        'maestro',
        'queue',
        'outcome_learning',
        'simplification',
        'control_plane',
        'prompt_contract',
    ];

    /**
     * @param  array{areas?: array<string, array<string,mixed>>}  $facts
     * @return array<string,mixed>
     */
    public function inventory(array $facts): array
    {
        $areas = is_array($facts['areas'] ?? null) ? $facts['areas'] : [];

        $gaps = [];

        foreach (self::REQUIRED_AREAS as $area) {
            $entry = is_array($areas[$area] ?? null) ? $areas[$area] : null;

            if ($entry === null) {
                $gaps[] = [
                    'area' => $area,
                    'missing_link' => "{$area}_no_consumer",
                    'severity' => 'high',
                    'evidence' => 'no area data supplied',
                    'proposed_closure_task' => "wire_{$area}_into_autonomous_decision_loop",
                ];

                continue;
            }

            $reportOnly = (bool) ($entry['report_only'] ?? false);
            $consumers = array_values(array_filter(
                array_map('strval', (array) ($entry['connected_consumers'] ?? [])),
                static fn (string $c): bool => trim($c) !== '',
            ));

            if ($consumers !== [] && ! $reportOnly) {
                continue;
            }

            $gaps[] = [
                'area' => $area,
                'missing_link' => $reportOnly ? "{$area}_report_only_no_consumer" : "{$area}_no_consumer",
                'severity' => $reportOnly ? 'medium' : 'high',
                'evidence' => $reportOnly
                    ? 'area is report-only; no connected consumer'
                    : 'area supplied no connected consumers',
                'proposed_closure_task' => "wire_{$area}_into_autonomous_decision_loop",
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'no_gap' => $gaps === [],
            'gaps' => $gaps,
            'areas_scanned' => count(self::REQUIRED_AREAS),
            'detached_areas' => array_column($gaps, 'area'),
        ];
    }
}

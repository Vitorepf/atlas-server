<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Pure injector that turns outcome-derived constraints into Task Fabric admission
 * constraints so completed, give_back, blocked and quarantined results change the
 * next emitted packet shape instead of becoming inert telemetry.
 *
 * Constraint mapping:
 *   completed   → preserve (keep the proven approach)
 *   give_back   → reshape (change the packet shape)
 *   blocked     → repair_first (fix dependencies before re-emitting)
 *   quarantined → do_not_requeue (permanently exclude)
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasTaskFabricOutcomeConstraintInjector
{
    public const SCHEMA = 'atlas.task_fabric.outcome_constraint_injector.v1';

    public const CONSTRAINT_PRESERVE = 'preserve';
    public const CONSTRAINT_RESHAPE = 'reshape';
    public const CONSTRAINT_REPAIR_FIRST = 'repair_first';
    public const CONSTRAINT_DO_NOT_REQUEUE = 'do_not_requeue';

    /**
     * @param  array<int, array<string, mixed>>  $outcomes
     * @return array<string, mixed>
     */
    public function inject(array $outcomes): array
    {
        $constraints = [];

        foreach ($outcomes as $outcome) {
            if (! is_array($outcome)) {
                continue;
            }

            $result = (string) ($outcome['result'] ?? '');
            $targetFamily = (string) ($outcome['target_family'] ?? '');
            $reason = (string) ($outcome['reason'] ?? '');
            $taskId = (string) ($outcome['task_id'] ?? '');

            if ($targetFamily === '') {
                continue;
            }

            $constraint = match ($result) {
                'completed', 'success', 'delivered' => [
                    'constraint' => self::CONSTRAINT_PRESERVE,
                    'target_family' => $targetFamily,
                    'reason' => 'proven_approach_preserve_pattern',
                    'source_task_id' => $taskId,
                ],
                'give_back' => [
                    'constraint' => self::CONSTRAINT_RESHAPE,
                    'target_family' => $targetFamily,
                    'reason' => $reason !== '' ? $reason : 'give_back_requires_packet_reshape',
                    'source_task_id' => $taskId,
                ],
                'blocked' => [
                    'constraint' => self::CONSTRAINT_REPAIR_FIRST,
                    'target_family' => $targetFamily,
                    'reason' => $reason !== '' ? $reason : 'blocked_requires_dependency_repair',
                    'source_task_id' => $taskId,
                ],
                'quarantined' => [
                    'constraint' => self::CONSTRAINT_DO_NOT_REQUEUE,
                    'target_family' => $targetFamily,
                    'reason' => $reason !== '' ? $reason : 'quarantined_do_not_requeue',
                    'source_task_id' => $taskId,
                ],
                default => null,
            };

            if ($constraint !== null) {
                $constraints[] = $constraint;
            }
        }

        // Deduplicate by constraint + target_family + reason.
        $seen = [];
        $deduped = [];
        foreach ($constraints as $c) {
            $key = $c['constraint'].':'.$c['target_family'].':'.$c['reason'];
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $deduped[] = $c;
            }
        }

        // Sort deterministically.
        usort($deduped, static function (array $a, array $b): int {
            return strcmp($a['constraint'], $b['constraint'])
                ?: strcmp($a['target_family'], $b['target_family'])
                ?: strcmp($a['reason'], $b['reason']);
        });

        $preserve = array_values(array_filter($deduped, static fn (array $c): bool => $c['constraint'] === self::CONSTRAINT_PRESERVE));
        $reshape = array_values(array_filter($deduped, static fn (array $c): bool => $c['constraint'] === self::CONSTRAINT_RESHAPE));
        $repairFirst = array_values(array_filter($deduped, static fn (array $c): bool => $c['constraint'] === self::CONSTRAINT_REPAIR_FIRST));
        $doNotRequeue = array_values(array_filter($deduped, static fn (array $c): bool => $c['constraint'] === self::CONSTRAINT_DO_NOT_REQUEUE));

        return [
            'schema_version' => self::SCHEMA,
            'constraints' => $deduped,
            'preserve_constraints' => $preserve,
            'reshape_constraints' => $reshape,
            'repair_first_constraints' => $repairFirst,
            'do_not_requeue_constraints' => $doNotRequeue,
            'total_constraints' => count($deduped),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure pre-seed regression sentinel. Prevents a future brain batch from recreating the
 * detached-organ gap: a batch that adds only standalone detector/report organs — with no
 * bridge, control-plane, readiness, or outcome-learning consumer anywhere in the batch — is
 * flagged detached_organ_risk before it is allowed to seed.
 *
 * CONSUMER KINDS (any one present anywhere in the batch clears the flag):
 *   bridge | control_plane | readiness | outcome_learning
 *
 * STANDALONE-RISK KINDS (cannot change autonomous behavior on their own):
 *   detector | report
 *
 * A batch with zero organs is vacuously safe (nothing to seed, nothing detached).
 * Any organ kind outside the known consumer kinds counts toward standalone risk, so an
 * unrecognised future kind never silently satisfies the gate.
 *
 * Pure: no I/O, no provider calls.
 */
final class AtlasExternalBrainDetachedOrganRegressionSentinel
{
    public const SCHEMA = 'atlas.external_brain.detached_organ_regression_sentinel.v1';

    public const CONSUMER_KINDS = ['bridge', 'control_plane', 'readiness', 'outcome_learning'];

    public const STANDALONE_KINDS = ['detector', 'report'];

    /**
     * @param  array{organs?: list<array<string,mixed>>}  $batch
     * @return array{schema:string, detached_organ_risk:bool, flagged_organs:list<string>, missing_consumer_types:list<string>, repair_guidance:string|null}
     */
    public function scan(array $batch): array
    {
        $organs = is_array($batch['organs'] ?? null) ? $batch['organs'] : [];

        if ($organs === []) {
            return [
                'schema'                 => self::SCHEMA,
                'detached_organ_risk'    => false,
                'flagged_organs'         => [],
                'missing_consumer_types' => [],
                'repair_guidance'        => null,
            ];
        }

        $organIds = [];
        $hasConsumer = false;

        foreach ($organs as $organ) {
            if (! is_array($organ)) {
                continue;
            }
            $organIds[] = (string) ($organ['organ_id'] ?? '');
            $kind = strtolower(trim((string) ($organ['kind'] ?? '')));
            if (in_array($kind, self::CONSUMER_KINDS, true)) {
                $hasConsumer = true;
            }
        }

        if ($hasConsumer) {
            return [
                'schema'                 => self::SCHEMA,
                'detached_organ_risk'    => false,
                'flagged_organs'         => [],
                'missing_consumer_types' => [],
                'repair_guidance'        => null,
            ];
        }

        $flaggedOrgans = array_values(array_filter($organIds, static fn (string $id): bool => $id !== ''));
        sort($flaggedOrgans, SORT_STRING);

        return [
            'schema'                 => self::SCHEMA,
            'detached_organ_risk'    => true,
            'flagged_organs'         => $flaggedOrgans,
            'missing_consumer_types' => self::CONSUMER_KINDS,
            'repair_guidance'        => 'Wire at least one of the following consumers before seeding: '
                .implode(', ', self::CONSUMER_KINDS).' — organs '.implode(', ', $flaggedOrgans)
                .' currently have no control-plane, bridge, readiness, or outcome-learning consumer.',
        ];
    }
}

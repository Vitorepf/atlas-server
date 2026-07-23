<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Duplicate-capability invariant detector: groups organs that share purpose, consumers,
 * or overlapping IO contracts, and proposes ONE deep-module seam per group instead of
 * leaving many small wrappers around the same responsibility.
 *
 * Grouping signals (any shared key merges organs into one group, same union pattern as
 * {@see AtlasExternalBrainOrganSprawlReductionPlanner::buildCapabilityGroups()}):
 *   - shared_purpose:   organ['purpose'] or organ['capability_labels']
 *   - shared_consumers: organ['consumer_ids']
 *   - shared_io:        organ['inputs'] / organ['outputs']
 *
 * SAFETY: an organ flagged behavior_unique=true is NEVER folded into a seam even when it
 * shares signals with other organs — it is excluded and reported under needs_review so a
 * human/gate confirms before any consolidation touches it. An organ that shares no signal
 * with any other organ (a true singleton) is preserved as-is, never forced into a seam.
 *
 * Pure PHP, deterministic, no file mutations, no I/O.
 */
final class AtlasExternalBrainDeepModuleFinder
{
    public const SCHEMA = 'atlas.external_brain.deep_module_finder.v1';

    /**
     * @param  array{organs?: list<array<string,mixed>>}  $input
     * @return array{schema:string, seams:list<array<string,mixed>>, preserved:list<string>, needs_review:list<array<string,mixed>>}
     */
    public function find(array $input): array
    {
        $organs = (array) ($input['organs'] ?? []);

        $keyToOrgans = []; // key => [organ_id, ...]
        $keyToSignal = []; // key => signal name
        $organIndex  = []; // organ_id => raw organ record

        foreach ($organs as $organ) {
            $id = (string) ($organ['organ_id'] ?? 'unknown');
            $organIndex[$id] = $organ;

            $purposes = $this->purposeLabels($organ);
            foreach ($purposes as $purpose) {
                $key = 'purpose:'.$purpose;
                $keyToOrgans[$key][] = $id;
                $keyToSignal[$key]   = 'shared_purpose';
            }
            foreach ((array) ($organ['consumer_ids'] ?? []) as $consumer) {
                $key = 'consumer:'.(string) $consumer;
                $keyToOrgans[$key][] = $id;
                $keyToSignal[$key]   = 'shared_consumers';
            }
            foreach ((array) ($organ['inputs'] ?? []) as $input_) {
                $key = 'input:'.(string) $input_;
                $keyToOrgans[$key][] = $id;
                $keyToSignal[$key]   = 'shared_io_contracts';
            }
            foreach ((array) ($organ['outputs'] ?? []) as $output) {
                $key = 'output:'.(string) $output;
                $keyToOrgans[$key][] = $id;
                $keyToSignal[$key]   = 'shared_io_contracts';
            }
        }

        // Union organs that share at least one key, regardless of signal type.
        $groups   = []; // groupKey => ['organ_ids'=>[], 'purposes'=>[], 'consumers'=>[], 'io_contracts'=>[], 'signals'=>[]]
        $assigned = []; // organ_id => groupKey

        foreach ($keyToOrgans as $key => $ids) {
            $ids = array_values(array_unique($ids));
            if (count($ids) < 2) {
                continue;
            }

            $groupKey = null;
            foreach ($ids as $oid) {
                if (isset($assigned[$oid])) {
                    $groupKey = $assigned[$oid];
                    break;
                }
            }
            if ($groupKey === null) {
                $groupKey = implode('|', $ids);
                $groups[$groupKey] = ['organ_ids' => [], 'purposes' => [], 'consumers' => [], 'io_contracts' => [], 'signals' => []];
            }

            foreach ($ids as $oid) {
                if (! in_array($oid, $groups[$groupKey]['organ_ids'], true)) {
                    $groups[$groupKey]['organ_ids'][] = $oid;
                    $assigned[$oid] = $groupKey;
                }
            }

            $signal = $keyToSignal[$key];
            if (! in_array($signal, $groups[$groupKey]['signals'], true)) {
                $groups[$groupKey]['signals'][] = $signal;
            }

            if ($signal === 'shared_purpose') {
                $label = substr($key, strlen('purpose:'));
                if (! in_array($label, $groups[$groupKey]['purposes'], true)) {
                    $groups[$groupKey]['purposes'][] = $label;
                }
            } elseif ($signal === 'shared_consumers') {
                $consumer = substr($key, strlen('consumer:'));
                if (! in_array($consumer, $groups[$groupKey]['consumers'], true)) {
                    $groups[$groupKey]['consumers'][] = $consumer;
                }
            } else {
                $io = str_starts_with($key, 'input:') ? substr($key, strlen('input:')) : substr($key, strlen('output:'));
                if (! in_array($io, $groups[$groupKey]['io_contracts'], true)) {
                    $groups[$groupKey]['io_contracts'][] = $io;
                }
            }
        }

        $seams       = [];
        $needsReview = [];
        $groupedOrganIds = [];

        foreach ($groups as $group) {
            $mergeable = [];
            foreach ($group['organ_ids'] as $oid) {
                $groupedOrganIds[$oid] = true;

                if ($this->isBehaviorUnique($organIndex[$oid] ?? [])) {
                    $needsReview[] = [
                        'organ_id' => $oid,
                        'reason'   => 'behavior_unique_excluded_from_seam',
                        'shared_with' => array_values(array_diff($group['organ_ids'], [$oid])),
                    ];

                    continue;
                }

                $mergeable[] = $oid;
            }

            // Fewer than two mergeable organs remain (the rest were behavior-unique) —
            // nothing left to blindly merge into a seam.
            if (count($mergeable) < 2) {
                continue;
            }

            sort($mergeable, SORT_STRING);
            $proposedName = $group['purposes'] !== []
                ? 'DeepModule:'.implode('+', $group['purposes'])
                : 'DeepModule:'.implode('+', $mergeable);

            $seams[] = [
                'seam_id'               => implode('|', $mergeable),
                'organ_ids'             => $mergeable,
                'shared_purpose'        => $group['purposes'],
                'shared_consumers'      => $group['consumers'],
                'shared_io_contracts'   => $group['io_contracts'],
                'overlap_signals'       => $group['signals'],
                'proposed_deep_module'  => $proposedName,
                'rationale'             => 'Organs share '.implode(', ', $group['signals']).' — collapse into one deep module instead of N thin wrappers.',
            ];
        }

        $preserved = [];
        foreach ($organIndex as $id => $organ) {
            if (isset($groupedOrganIds[$id])) {
                continue; // either seamed or already reported under needs_review
            }
            $preserved[] = $id;
        }

        sort($preserved, SORT_STRING);

        return [
            'schema'       => self::SCHEMA,
            'seams'        => $seams,
            'preserved'    => $preserved,
            'needs_review' => $needsReview,
        ];
    }

    /** @return list<string> */
    private function purposeLabels(array $organ): array
    {
        if (isset($organ['purpose']) && (string) $organ['purpose'] !== '') {
            return [(string) $organ['purpose']];
        }

        return array_values(array_map('strval', (array) ($organ['capability_labels'] ?? [])));
    }

    private function isBehaviorUnique(array $organ): bool
    {
        return (bool) ($organ['behavior_unique'] ?? false);
    }
}

<?php

namespace App\Services\Ai\Product;

use App\Models\AtlasProductDeliveryOutcomeMemory;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\AiStringListNormalizer;
use App\Services\Ai\Support\DatabaseTableAvailability;

class AtlasProductDeliveryDoctrineFitnessService
{
    public const SCHEMA_VERSION = 'atlas.product_delivery.doctrine_fitness.v1';

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function evaluate(array $options = []): array
    {
        $limit = max(1, min((int) ($options['limit'] ?? 100), 500));
        $records = $this->records($limit);
        $routeFitness = $this->routeFitness($records);
        $lensFitness = $this->lensFitness($records);
        $evidenceFitness = $this->evidenceFitness($records);
        $repairFitness = $this->repairFitness($records);
        $policyProposals = $this->policyProposals($routeFitness, $lensFitness, $evidenceFitness, $repairFitness);

        return $this->payload(
            status: $records === [] ? 'watch' : 'ready',
            routeFitness: $routeFitness,
            lensFitness: $lensFitness,
            evidenceFitness: $evidenceFitness,
            repairFitness: $repairFitness,
            blockers: $this->blockers($records, $routeFitness, $lensFitness),
            policyProposals: $policyProposals,
            sampleSize: count($records),
        );
    }

    /**
     * Backward-compatible alias for older callers.
     *
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function analyze(array $options = []): array
    {
        return $this->evaluate($options);
    }

    /**
     * @return list<AtlasProductDeliveryOutcomeMemory>
     */
    private function records(int $limit): array
    {
        if (! DatabaseTableAvailability::has('atlas_product_delivery_outcome_memories')) {
            return [];
        }

        return AtlasProductDeliveryOutcomeMemory::query()
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * @param  list<AtlasProductDeliveryOutcomeMemory>  $records
     * @return list<array<string,mixed>>
     */
    private function routeFitness(array $records): array
    {
        $groups = [];
        foreach ($records as $record) {
            $route = $this->key((string) $record->route);
            $groups[$route] ??= $this->baseGroup($route);
            $this->countOutcome($groups[$route], $record);
            foreach (AiStringListNormalizer::trimmedScalarValues($record->evidence_kinds) as $kind) {
                $groups[$route]['evidence_kinds'][$kind] = true;
            }
            foreach (AiStringListNormalizer::trimmedScalarValues($record->required_repairs) as $repair) {
                $groups[$route]['required_repairs'][$repair] = true;
            }
        }

        $items = [];
        foreach ($groups as $route => $group) {
            $total = max((int) $group['total'], 1);
            $repairPressure = ((int) $group['blocked'] + (int) $group['needs_repair'] + (int) $group['human_review_required']) / $total;
            $items[] = [
                'route' => $route,
                'id' => $route,
                'total' => $total,
                'ready' => (int) $group['ready'],
                'blocked' => (int) $group['blocked'],
                'needs_repair' => (int) $group['needs_repair'],
                'human_review_required' => (int) $group['human_review_required'],
                'repair_pressure' => round($repairPressure, 4),
                'fitness_score' => $this->fitnessScore($group),
                'evidence_kinds' => array_values(array_keys($group['evidence_kinds'])),
                'required_repairs' => array_values(array_keys($group['required_repairs'])),
            ];
        }

        usort($items, static fn (array $a, array $b): int => ($b['total'] <=> $a['total']) ?: strcmp((string) $a['route'], (string) $b['route']));

        return $items;
    }

    /**
     * @param  list<AtlasProductDeliveryOutcomeMemory>  $records
     * @return list<array<string,mixed>>
     */
    private function lensFitness(array $records): array
    {
        $groups = [];
        foreach ($records as $record) {
            foreach ($this->lenses($record) as $lens) {
                $groups[$lens] ??= $this->baseGroup($lens);
                $this->countOutcome($groups[$lens], $record);
            }
        }

        $items = [];
        foreach ($groups as $lens => $group) {
            $items[] = [
                'lens' => $lens,
                'id' => $lens,
                'total' => (int) $group['total'],
                'ready' => (int) $group['ready'],
                'blocked' => (int) $group['blocked'],
                'needs_repair' => (int) $group['needs_repair'],
                'human_review_required' => (int) $group['human_review_required'],
                'fitness_score' => $this->fitnessScore($group),
            ];
        }

        usort($items, static fn (array $a, array $b): int => ($b['total'] <=> $a['total']) ?: strcmp((string) $a['lens'], (string) $b['lens']));

        return $items;
    }

    /**
     * @param  list<AtlasProductDeliveryOutcomeMemory>  $records
     * @return list<array<string,mixed>>
     */
    private function evidenceFitness(array $records): array
    {
        $groups = [];
        foreach ($records as $record) {
            foreach (AiStringListNormalizer::trimmedScalarValues($record->evidence_kinds) as $kind) {
                $kind = $this->key($kind);
                $groups[$kind] ??= [
                    'evidence_kind' => $kind,
                    'total' => 0,
                    'ready' => 0,
                    'blocked' => 0,
                    'needs_repair' => 0,
                    'routes' => [],
                ];
                $groups[$kind]['total']++;
                $this->countSimpleOutcome($groups[$kind], $record);
                $groups[$kind]['routes'][(string) $record->route] = true;
            }
        }

        $items = [];
        foreach ($groups as $group) {
            $total = max((int) $group['total'], 1);
            $items[] = [
                'evidence_kind' => $group['evidence_kind'],
                'total' => $total,
                'ready' => (int) $group['ready'],
                'blocked' => (int) $group['blocked'],
                'needs_repair' => (int) $group['needs_repair'],
                'routes' => array_values(array_keys($group['routes'])),
                'fitness_score' => round(((int) $group['ready'] / $total) - (((int) $group['blocked'] + (int) $group['needs_repair']) / $total * 0.25), 4),
            ];
        }

        usort($items, static fn (array $a, array $b): int => ($b['total'] <=> $a['total']) ?: strcmp((string) $a['evidence_kind'], (string) $b['evidence_kind']));

        return $items;
    }

    /**
     * @param  list<AtlasProductDeliveryOutcomeMemory>  $records
     * @return list<array<string,mixed>>
     */
    private function repairFitness(array $records): array
    {
        $groups = [];
        foreach ($records as $record) {
            foreach (AiStringListNormalizer::trimmedScalarValues($record->required_repairs) as $repair) {
                $repair = $this->key($repair);
                $groups[$repair] ??= [
                    'repair' => $repair,
                    'total' => 0,
                    'routes' => [],
                    'human_review_required' => 0,
                ];
                $groups[$repair]['total']++;
                $groups[$repair]['routes'][(string) $record->route] = true;
                if ((bool) $record->human_review_required) {
                    $groups[$repair]['human_review_required']++;
                }
            }
        }

        $items = [];
        foreach ($groups as $group) {
            $total = max((int) $group['total'], 1);
            $items[] = [
                'repair' => $group['repair'],
                'total' => $total,
                'routes' => array_values(array_keys($group['routes'])),
                'human_review_pressure' => round((int) $group['human_review_required'] / $total, 4),
                'fitness_score' => round(max(0.0, 1.0 - ((int) $group['human_review_required'] / $total * 0.35)), 4),
            ];
        }

        usort($items, static fn (array $a, array $b): int => ($b['total'] <=> $a['total']) ?: strcmp((string) $a['repair'], (string) $b['repair']));

        return $items;
    }

    /**
     * @param  list<array<string,mixed>>  $routeFitness
     * @param  list<array<string,mixed>>  $lensFitness
     * @param  list<array<string,mixed>>  $evidenceFitness
     * @param  list<array<string,mixed>>  $repairFitness
     * @return list<array<string,mixed>>
     */
    private function policyProposals(array $routeFitness, array $lensFitness, array $evidenceFitness, array $repairFitness): array
    {
        $proposals = [];
        foreach (array_merge($routeFitness, $lensFitness, $evidenceFitness, $repairFitness) as $item) {
            if ((float) ($item['fitness_score'] ?? 0.0) >= 0.55 || (int) ($item['total'] ?? 0) === 0) {
                continue;
            }

            $target = (string) ($item['route'] ?? $item['lens'] ?? $item['evidence_kind'] ?? $item['repair'] ?? 'unknown');
            $proposals[] = [
                'id' => 'tighten_doctrine_for_'.$this->slug($target),
                'target' => $target,
                'recommendation' => 'require stronger evidence and review before increasing autonomy',
                'reason' => 'fitness_score_below_threshold',
                'auto_apply' => false,
            ];
        }

        return $proposals;
    }

    /**
     * @param  list<AtlasProductDeliveryOutcomeMemory>  $records
     * @param  list<array<string,mixed>>  $routeFitness
     * @param  list<array<string,mixed>>  $lensFitness
     * @return list<array<string,mixed>>
     */
    private function blockers(array $records, array $routeFitness, array $lensFitness): array
    {
        $blockers = [];
        if ($records === []) {
            $blockers[] = ['id' => 'no_outcome_memory_records', 'severity' => 'warn'];
        }
        if ($lensFitness === [] || array_column($lensFitness, 'lens') === ['unknown_lens']) {
            $blockers[] = ['id' => 'missing_lens_attribution', 'severity' => 'warn'];
        }
        if ($routeFitness === []) {
            $blockers[] = ['id' => 'missing_route_attribution', 'severity' => 'warn'];
        }

        return $blockers;
    }

    /**
     * @param  list<array<string,mixed>>  $routeFitness
     * @param  list<array<string,mixed>>  $lensFitness
     * @param  list<array<string,mixed>>  $evidenceFitness
     * @param  list<array<string,mixed>>  $repairFitness
     * @param  list<array<string,mixed>>  $blockers
     * @param  list<array<string,mixed>>  $policyProposals
     * @return array<string,mixed>
     */
    private function payload(
        string $status,
        array $routeFitness,
        array $lensFitness,
        array $evidenceFitness,
        array $repairFitness,
        array $blockers,
        array $policyProposals,
        int $sampleSize,
    ): array {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'provider_free_read_only',
            'sample_size' => $sampleSize,
            'record_count' => $sampleSize,
            'route_fitness' => $routeFitness,
            'lens_fitness' => $lensFitness,
            'evidence_fitness' => $evidenceFitness,
            'repair_fitness' => $repairFitness,
            'policy_proposals' => $policyProposals,
            'blockers' => $blockers,
            'false_learning_guard' => [
                'min_sample_size_for_enforcement' => 10,
                'current_sample_size' => $sampleSize,
                'enforcement_allowed' => $sampleSize >= 10,
                'proposal_only_when_under_sample_floor' => $sampleSize < 10,
            ],
            'claim_policy' => [
                'provider_invoked' => false,
                'writes' => false,
                'auto_policy_change' => false,
                'fitness_is_not_causality_proof' => true,
                'fitness_is_not_policy_mutation' => true,
                'human_review_required_for_policy_change' => true,
            ],
        ];
        $payload['fitness_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function baseGroup(string $id): array
    {
        return [
            'id' => $id,
            'total' => 0,
            'ready' => 0,
            'blocked' => 0,
            'needs_repair' => 0,
            'human_review_required' => 0,
            'evidence_kinds' => [],
            'required_repairs' => [],
        ];
    }

    /**
     * @param  array<string,mixed>  $group
     */
    private function countOutcome(array &$group, AtlasProductDeliveryOutcomeMemory $record): void
    {
        $group['total']++;
        $this->countSimpleOutcome($group, $record);
        if ((bool) $record->human_review_required) {
            $group['human_review_required']++;
        }
    }

    /**
     * @param  array<string,mixed>  $group
     */
    private function countSimpleOutcome(array &$group, AtlasProductDeliveryOutcomeMemory $record): void
    {
        $status = (string) $record->outcome_status;
        if ($status === 'ready') {
            $group['ready']++;
        } elseif ($status === 'blocked') {
            $group['blocked']++;
        } else {
            $group['needs_repair']++;
        }
    }

    /**
     * @param  array<string,mixed>  $group
     */
    private function fitnessScore(array $group): float
    {
        $total = max((int) $group['total'], 1);

        return round(max(0.0, min(1.0,
            ((int) $group['ready'] / $total)
            - (((int) $group['blocked'] + (int) $group['needs_repair']) / $total * 0.35)
            - ((int) $group['human_review_required'] / $total * 0.1)
        )), 4);
    }

    /**
     * @return list<string>
     */
    private function lenses(AtlasProductDeliveryOutcomeMemory $record): array
    {
        $lenses = AiStringListNormalizer::trimmedScalarValues(data_get($record->delivery_summary, 'required_lenses', []));

        return $lenses === [] ? ['unknown_lens'] : $lenses;
    }

    private function key(string $value): string
    {
        return trim($value) !== '' ? trim($value) : 'unknown';
    }

    private function slug(string $value): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '_', strtolower($value)) ?: 'unknown';

        return trim($slug, '_') ?: 'unknown';
    }
}

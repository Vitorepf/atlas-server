<?php

namespace App\Services\Ai\Aaeos;

/**
 * Runtime for the AAEOS Department Contract — the registry the canonical doc
 * names (AtlasAaeosDepartmentRegistryService) to enforce that every department
 * fills the canonical atlas.aaeos.department.v1 schema before it "exists". Pure
 * validation: required fields present, maturity level in range, escalation_to
 * points to a known department (not self), and no escalation cycles across the
 * registry.
 *
 * @see docs/engineering-knowledge-base/atlas-agentic-engineering-os-department-contract.md
 */
class AtlasAaeosDepartmentRegistryService
{
    public const SCHEMA = 'atlas.aaeos.department.v1';

    /**
     * The 12 mandatory fields — a department missing any is a blocker.
     *
     * @var array<int,string>
     */
    private const REQUIRED_FIELDS = [
        'id', 'human_name', 'scope', 'triggers', 'inputs', 'outputs',
        'gates', 'allowed_actions', 'forbidden_actions', 'escalation_to',
        'evidence_required', 'maturity_level',
    ];

    /**
     * @var array<int,string>
     */
    private const VALID_MATURITY = ['L0', 'L1', 'L2', 'L3', 'L4', 'L5', 'L6', 'L7'];

    /**
     * The 11 canonical AAEOS departments.
     *
     * @var array<int,string>
     */
    public const CANONICAL_DEPARTMENTS = [
        'product', 'architect', 'research', 'dev', 'debug', 'review',
        'qa', 'security', 'forge', 'delivery', 'memory',
    ];

    /**
     * Validate a single department contract against the canonical schema.
     *
     * @param  array<string,mixed>  $contract
     * @param  array<int,string>  $knownDepartmentIds  ids that escalation_to may target
     * @return array<string,mixed>
     */
    public function validateDepartment(array $contract, array $knownDepartmentIds = self::CANONICAL_DEPARTMENTS): array
    {
        $blockers = [];

        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $contract) || $contract[$field] === '' || $contract[$field] === []) {
                $blockers[] = "missing required field [{$field}]";
            }
        }

        $id = strtolower(trim((string) ($contract['id'] ?? '')));
        $maturity = strtoupper(trim((string) ($contract['maturity_level'] ?? '')));
        if ($maturity !== '' && ! in_array($maturity, self::VALID_MATURITY, true)) {
            $blockers[] = "maturity_level [{$maturity}] is not in L0..L7";
        }

        $escalation = strtolower(trim((string) ($contract['escalation_to'] ?? '')));
        if ($escalation !== '' && $escalation !== 'operator') {
            if ($escalation === $id) {
                $blockers[] = 'escalation_to must not point to the department itself';
            } elseif (! in_array($escalation, array_map('strtolower', $knownDepartmentIds), true)) {
                $blockers[] = "escalation_to [{$escalation}] is not a known department";
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'id' => $id,
            'valid' => $blockers === [],
            'blockers' => $blockers,
        ];
    }

    /**
     * Validate a whole registry: each contract, unique ids, and no escalation
     * cycles (A -> B -> A).
     *
     * @param  array<int,array<string,mixed>>  $departments
     * @return array<string,mixed>
     */
    public function validateRegistry(array $departments): array
    {
        $ids = [];
        foreach ($departments as $dept) {
            $id = strtolower(trim((string) ($dept['id'] ?? '')));
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        $knownIds = array_values(array_unique(array_merge(self::CANONICAL_DEPARTMENTS, $ids)));

        $results = [];
        $duplicateIds = array_values(array_unique(array_diff_assoc($ids, array_unique($ids))));
        $escalationMap = [];

        foreach ($departments as $dept) {
            $result = $this->validateDepartment($dept, $knownIds);
            $results[] = $result;
            $id = $result['id'];
            $escalation = strtolower(trim((string) ($dept['escalation_to'] ?? '')));
            if ($id !== '' && $escalation !== '' && $escalation !== 'operator') {
                $escalationMap[$id] = $escalation;
            }
        }

        $cycles = $this->detectEscalationCycles($escalationMap);
        $allValid = $duplicateIds === [] && $cycles === []
            && collect($results)->every(fn (array $r): bool => $r['valid'] === true);

        return [
            'schema_version' => self::SCHEMA,
            'valid' => $allValid,
            'department_count' => count($departments),
            'duplicate_ids' => $duplicateIds,
            'escalation_cycles' => $cycles,
            'departments' => $results,
        ];
    }

    /**
     * @param  array<string,string>  $escalationMap  id => escalation_to
     * @return array<int,array<int,string>>
     */
    private function detectEscalationCycles(array $escalationMap): array
    {
        $cycles = [];
        foreach (array_keys($escalationMap) as $start) {
            $seen = [];
            $node = $start;
            while (isset($escalationMap[$node]) && ! in_array($node, $seen, true)) {
                $seen[] = $node;
                $node = $escalationMap[$node];
            }
            if (isset($escalationMap[$node]) && in_array($node, $seen, true)) {
                $cycleStart = array_search($node, $seen, true);
                $cycle = array_slice($seen, (int) $cycleStart);
                sort($cycle);
                if (! in_array($cycle, $cycles, true)) {
                    $cycles[] = $cycle;
                }
            }
        }

        return $cycles;
    }
}

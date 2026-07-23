<?php

namespace App\Services\Ai\AgenticEngineeringOs\Maturity;

use App\Services\Ai\Support\AiStringListNormalizer;
use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Runtime for the AAEOS Department Contract — the registry the canonical doc
 * names (AtlasDepartmentRegistryService) to enforce that every department
 * fills the canonical atlas.aaeos.department.v1 schema before it "exists". Pure
 * validation: required fields present, maturity level in range, escalation_to
 * points to a known department (not self), and no escalation cycles across the
 * registry.
 *
 * @see docs/engineering-knowledge-base/atlas-agentic-engineering-os-department-contract.md
 */
class AtlasDepartmentRegistryService
{
    public const SCHEMA = 'atlas.aaeos.department.v1';

    public const FIELD_VALID = 'valid';
    public const FIELD_ESCALATION_TO = 'escalation_to';
    public const FIELD_BLOCKERS = 'blockers';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_DEPARTMENT_COUNT = 'department_count';
    public const FIELD_ID = 'id';
    public const FIELD_MATURITY_LEVEL = 'maturity_level';
    public const FIELD_DEPARTMENTS = 'departments';
    public const FIELD_DUPLICATE_IDS = 'duplicate_ids';
    public const FIELD_ESCALATION_CYCLES = 'escalation_cycles';
    public const FIELD_OPERATOR = 'operator';
    public const FIELD_ALLOWED_ACTIONS = 'allowed_actions';
    public const FIELD_ARCHITECT = 'architect';
    public const FIELD_DEBUG = 'debug';
    public const FIELD_DELIVERY = 'delivery';
    public const FIELD_EVIDENCE_REQUIRED = 'evidence_required';
    public const FIELD_FORBIDDEN_ACTIONS = 'forbidden_actions';
    public const FIELD_HUMAN_NAME = 'human_name';
    public const FIELD_MEMORY = 'memory';
    public const FIELD_GATES = 'gates';
    public const FIELD_INPUTS = 'inputs';
    public const FIELD_OUTPUTS = 'outputs';
    public const FIELD_RESEARCH = 'research';
    public const FIELD_DEV = 'dev';
    public const FIELD_REVIEW = 'review';
    public const FIELD_SCOPE = 'scope';
    public const FIELD_SECURITY = 'security';
    public const FIELD_FORGE = 'forge';
    public const FIELD_STRTOLOWER = 'strtolower';
    public const FIELD_TRIGGERS = 'triggers';
    public const FIELD_PRODUCT = 'product';
    public const FIELD_QA = 'qa';
    public const FIELD_ESCALATION_TO_MUST_NOT_POINT_TO_THE_DEPARTMENT_ITSELF = 'escalation_to must not point to the department itself';

    /**
     * The 12 mandatory fields — a department missing any is a blocker.
     *
     * @var array<int,string>
     */
    public const REQUIRED_FIELDS = [
        self::FIELD_ID, self::FIELD_HUMAN_NAME, self::FIELD_SCOPE, self::FIELD_TRIGGERS, self::FIELD_INPUTS, self::FIELD_OUTPUTS,
        self::FIELD_GATES, self::FIELD_ALLOWED_ACTIONS, self::FIELD_FORBIDDEN_ACTIONS, self::FIELD_ESCALATION_TO,
        self::FIELD_EVIDENCE_REQUIRED, self::FIELD_MATURITY_LEVEL,
    ];

    /**
     * @var array<int,string>
     */
    public const VALID_MATURITY = ['L0', 'L1', 'L2', 'L3', 'L4', 'L5', 'L6', 'L7'];

    /**
     * The 11 canonical AAEOS departments.
     *
     * @var array<int,string>
     */
    public const CANONICAL_DEPARTMENTS = [
        self::FIELD_PRODUCT, self::FIELD_ARCHITECT, self::FIELD_RESEARCH, self::FIELD_DEV, self::FIELD_DEBUG, self::FIELD_REVIEW,
        self::FIELD_QA, self::FIELD_SECURITY, self::FIELD_FORGE, self::FIELD_DELIVERY, self::FIELD_MEMORY,
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

        $id = $this->departmentId($contract[self::FIELD_ID] ?? '');
        $maturity = $this->maturityLevel($contract[self::FIELD_MATURITY_LEVEL] ?? '');
        if ($maturity !== '' && ! in_array($maturity, self::VALID_MATURITY, true)) {
            $blockers[] = "maturity_level [{$maturity}] is not in L0..L7";
        }

        $escalation = $this->departmentId($contract[self::FIELD_ESCALATION_TO] ?? '');
        if ($escalation !== '' && $escalation !== self::FIELD_OPERATOR) {
            if ($escalation === $id) {
                $blockers[] = self::FIELD_ESCALATION_TO_MUST_NOT_POINT_TO_THE_DEPARTMENT_ITSELF;
            } elseif (! in_array($escalation, array_map(self::FIELD_STRTOLOWER, $knownDepartmentIds), true)) {
                $blockers[] = "escalation_to [{$escalation}] is not a known department";
            }
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA,
            self::FIELD_ID => $id,
            self::FIELD_VALID => $blockers === [],
            self::FIELD_BLOCKERS => $blockers,
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
        $ids = $this->departmentIds($departments);
        $knownIds = AiStringListNormalizer::uniqueStrings(array_merge(self::CANONICAL_DEPARTMENTS, $ids));

        $results = [];
        $duplicateIds = $this->duplicateStrings($ids);
        $escalationMap = [];

        foreach ($departments as $dept) {
            $result = $this->validateDepartment($dept, $knownIds);
            $results[] = $result;
            $id = $result[self::FIELD_ID];
            $escalation = $this->departmentId($dept[self::FIELD_ESCALATION_TO] ?? '');
            if ($id !== '' && $escalation !== '' && $escalation !== self::FIELD_OPERATOR) {
                $escalationMap[$id] = $escalation;
            }
        }

        $cycles = $this->detectEscalationCycles($escalationMap);
        $allValid = $duplicateIds === [] && $cycles === []
            && collect($results)->every(fn (array $r): bool => $r[self::FIELD_VALID] === true);

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA,
            self::FIELD_VALID => $allValid,
            self::FIELD_DEPARTMENT_COUNT => count($departments),
            self::FIELD_DUPLICATE_IDS => $duplicateIds,
            self::FIELD_ESCALATION_CYCLES => $cycles,
            self::FIELD_DEPARTMENTS => $results,
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

    /**
     * @param  array<int,array<string,mixed>>  $departments
     * @return array<int,string>
     */
    private function departmentIds(array $departments): array
    {
        $ids = [];
        foreach ($departments as $dept) {
            $id = $this->departmentId($dept[self::FIELD_ID] ?? '');
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param  array<int,string>  $values
     * @return array<int,string>
     */
    private function duplicateStrings(array $values): array
    {
        return AiStringListNormalizer::uniqueStrings(array_diff_assoc($values, array_unique($values)));
    }

    private function departmentId(mixed $value): string
    {
        return AiValueNormalizer::lowerTrimmedString($value);
    }

    private function maturityLevel(mixed $value): string
    {
        return AiValueNormalizer::upperTrimmedString($value);
    }
}

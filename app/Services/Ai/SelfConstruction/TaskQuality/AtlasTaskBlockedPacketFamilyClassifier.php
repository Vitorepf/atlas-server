<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQuality;

/**
 * Pure, facts-only triage primitive for blocked queue packets.
 *
 * classifyOne() maps a single raw packet array to a deterministic family record. classifyBatch() applies
 * it over a list. No queue writes, shell calls, git calls, provider calls, or status mutations — the
 * classifier is a pure function: same input → same output, always.
 *
 * Families (in priority order — first matching rule wins):
 *   - dormant_cli_arm_proxy              : quality_facts.dormant_cli_arm_proxy === true
 *   - test_only_microtask_requires_contract : quality_facts.test_only_has_contract === true
 *   - repeated_give_back                 : give_back_count >= GIVE_BACK_THRESHOLD (default 8)
 *   - duplicate_already_done             : quality_facts.already_done === true or status 'completed'
 *   - respec_candidate                   : any blocking_deficiency is present
 *   - unknown                            : none of the above matched
 */
final class AtlasTaskBlockedPacketFamilyClassifier
{
    public const SCHEMA = 'atlas.task_quality.blocked_packet_family.v1';

    public const FAMILY_DORMANT_CLI_ARM_PROXY = 'dormant_cli_arm_proxy';

    public const FAMILY_TEST_ONLY_MICROTASK = 'test_only_microtask_requires_contract';

    public const FAMILY_REPEATED_GIVE_BACK = 'repeated_give_back';

    public const FAMILY_DUPLICATE_ALREADY_DONE = 'duplicate_already_done';

    public const FAMILY_RESPEC_CANDIDATE = 'respec_candidate';

    public const FAMILY_MISSING_IMPL_FILE = 'missing_impl_file';

    public const FAMILY_FORBIDDEN_TARGET = 'forbidden_target';

    public const FAMILY_CONTRADICTORY_ACCEPTANCE = 'contradictory_acceptance';

    public const FAMILY_SCHEMA_MISMATCH = 'schema_mismatch';

    public const FAMILY_UNKNOWN = 'unknown';

    private const GIVE_BACK_THRESHOLD = 8;

    /**
     * @param  array<string,mixed>  $packet  a raw task packet row
     * @return array{schema_version:string, task_packet_id:string, family:string, recommended_action:string, rationale:string}
     */
    public function classifyOne(array $packet): array
    {
        $id = (string) ($packet['task_packet_id'] ?? ($packet['id'] ?? ''));
        $facts = is_array($packet['packet_quality']['facts'] ?? null)
            ? (array) $packet['packet_quality']['facts']
            : (is_array($packet['quality_facts'] ?? null) ? (array) $packet['quality_facts'] : []);
        $blockingDeficiencies = is_array($packet['packet_quality']['blocking_deficiencies'] ?? null)
            ? (array) $packet['packet_quality']['blocking_deficiencies']
            : (is_array($packet['blocking_deficiencies'] ?? null) ? (array) $packet['blocking_deficiencies'] : []);
        $giveBackCount = max(0, (int) ($packet['give_back_count'] ?? 0));
        $hasPriorSuccess = (bool) ($packet['has_prior_success'] ?? false);
        $status = trim((string) ($packet['status'] ?? ''));
        $blockingStr = implode(' ', array_map('strval', $blockingDeficiencies));
        $blockingStrLower = strtolower($blockingStr);

        // ── New families: detect from blocking_deficiencies content ──────────
        if (str_contains($blockingStrLower, 'forbidden_self_target') || str_contains($blockingStrLower, 'forbidden_target') || str_contains($blockingStrLower, 'property_gated')) {
            return $this->record($id, self::FAMILY_FORBIDDEN_TARGET, 'retire', 'unrepairable',
                'blocking_deficiencies contain forbidden_target/property_gated: cannot be repaired by file edits',
                ['remove_forbidden_target_from_scope'], 'high');
        }
        if (str_contains($blockingStrLower, 'contradictory_acceptance') || str_contains($blockingStrLower, 'contradictory')) {
            return $this->record($id, self::FAMILY_CONTRADICTORY_ACCEPTANCE, 'retire', 'unrepairable',
                'blocking_deficiencies contain contradictory_acceptance: criteria contradict each other',
                ['rewrite_acceptance_criteria'], 'high');
        }
        if (str_contains($blockingStrLower, 'schema') && (str_contains($blockingStrLower, 'mismatch') || str_contains($blockingStrLower, 'invalid'))) {
            return $this->record($id, self::FAMILY_SCHEMA_MISMATCH, 'respec', 'repairable',
                'blocking_deficiencies contain schema_mismatch: packet schema does not match expected version',
                ['align_packet_schema'], 'medium');
        }

        if (($facts['dormant_cli_arm_proxy'] ?? false) === true) {
            return $this->record($id, self::FAMILY_DORMANT_CLI_ARM_PROXY, 'retire_or_wire', 'conditional',
                'quality_facts.dormant_cli_arm_proxy=true: the allowed_files target is a CLI arm that is dormant and cannot be wired without external scaffolding',
                ['wire_cli_arm_or_retire'], 'medium');
        }

        if (($facts['test_only_has_contract'] ?? false) === true) {
            return $this->record($id, self::FAMILY_TEST_ONLY_MICROTASK, 'add_implementation_scaffold', 'repairable',
                'quality_facts.test_only_has_contract=true: tests exist as a contract spec only; the implementation class is missing or empty and cannot be proved green by a muscle worker alone',
                ['add_implementation_file', 'add_runnable_acceptance'], 'medium');
        }

        if ($giveBackCount >= self::GIVE_BACK_THRESHOLD) {
            $action = $hasPriorSuccess ? 'respec' : 'retire';
            $repair = $hasPriorSuccess ? 'repairable' : 'unrepairable';
            $confidence = $hasPriorSuccess ? 'medium' : 'high';
            $rationale = "give_back_count={$giveBackCount} >= threshold ".self::GIVE_BACK_THRESHOLD.': this packet has been repeatedly given back';
            if ($hasPriorSuccess) {
                $rationale .= ' but has prior success — respec candidate';
            } else {
                $rationale .= ' with no prior success — quarantine/retire candidate';
            }
            return $this->record($id, self::FAMILY_REPEATED_GIVE_BACK, $action, $repair, $rationale,
                $hasPriorSuccess ? ['respec_with_proven_leverage'] : ['retire'], $confidence);
        }

        if (($facts['already_done'] ?? false) === true || $status === 'completed') {
            return $this->record($id, self::FAMILY_DUPLICATE_ALREADY_DONE, 'retire', 'unrepairable',
                'quality_facts.already_done=true or status=completed: the deliverable was resolved by a prior task; this packet is a duplicate and can be safely retired',
                ['retire'], 'high');
        }

        // missing_impl_file: detect from deficiencies
        if (str_contains($blockingStrLower, 'missing_impl') || ($facts['impl_file_missing'] ?? false) === true) {
            return $this->record($id, self::FAMILY_MISSING_IMPL_FILE, 'respec', 'repairable',
                'implementation file is missing from allowed_files',
                ['add_implementation_file'], 'medium');
        }

        if ($blockingDeficiencies !== []) {
            $listed = implode(', ', array_slice(array_map('strval', $blockingDeficiencies), 0, 3));
            return $this->record($id, self::FAMILY_RESPEC_CANDIDATE, 'respec', 'repairable',
                "blocking_deficiencies present: [{$listed}] — packet cannot be grinded until its scope or acceptance criteria are corrected",
                ['respec_scope_and_acceptance'], 'medium');
        }

        return $this->record($id, self::FAMILY_UNKNOWN, 'manual_review', 'unknown',
            'no deterministic family rule matched; manual review required to determine why this packet is blocked',
            ['manual_review'], 'low');
    }

    /**
     * @param  list<array<string,mixed>>  $packets
     * @return list<array{schema_version:string, task_packet_id:string, family:string, recommended_action:string, rationale:string}>
     */
    public function classifyBatch(array $packets): array
    {
        return array_values(array_map(fn (array $p): array => $this->classifyOne($p), $packets));
    }

    /**
     * @param  list<string>  $requiredRespecFields
     * @return array{schema_version:string, task_packet_id:string, family:string, recommended_action:string, repairability:string, rationale:string, required_respec_fields:list<string>, confidence:string}
     */
    private function record(string $id, string $family, string $action, string $repairability, string $rationale, array $requiredRespecFields, string $confidence): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'task_packet_id' => $id,
            'family' => $family,
            'recommended_action' => $action,
            'repairability' => $repairability,
            'rationale' => $rationale,
            'required_respec_fields' => array_values($requiredRespecFields),
            'confidence' => $confidence,
        ];
    }
}

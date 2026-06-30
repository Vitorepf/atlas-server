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
        $status = trim((string) ($packet['status'] ?? ''));

        if (($facts['dormant_cli_arm_proxy'] ?? false) === true) {
            return $this->record($id, self::FAMILY_DORMANT_CLI_ARM_PROXY, 'retire_or_wire',
                'quality_facts.dormant_cli_arm_proxy=true: the allowed_files target is a CLI arm that is dormant and cannot be wired without external scaffolding');
        }

        if (($facts['test_only_has_contract'] ?? false) === true) {
            return $this->record($id, self::FAMILY_TEST_ONLY_MICROTASK, 'add_implementation_scaffold',
                'quality_facts.test_only_has_contract=true: tests exist as a contract spec only; the implementation class is missing or empty and cannot be proved green by a muscle worker alone');
        }

        if ($giveBackCount >= self::GIVE_BACK_THRESHOLD) {
            return $this->record($id, self::FAMILY_REPEATED_GIVE_BACK, 'respec_or_retire',
                "give_back_count={$giveBackCount} >= threshold ".self::GIVE_BACK_THRESHOLD.': this packet has been repeatedly given back, indicating a systematic blocker not resolvable by retrying the same spec');
        }

        if (($facts['already_done'] ?? false) === true || $status === 'completed') {
            return $this->record($id, self::FAMILY_DUPLICATE_ALREADY_DONE, 'retire',
                'quality_facts.already_done=true or status=completed: the deliverable was resolved by a prior task; this packet is a duplicate and can be safely retired');
        }

        if ($blockingDeficiencies !== []) {
            $listed = implode(', ', array_slice(array_map('strval', $blockingDeficiencies), 0, 3));
            return $this->record($id, self::FAMILY_RESPEC_CANDIDATE, 'respec',
                "blocking_deficiencies present: [{$listed}] — packet cannot be grinded until its scope or acceptance criteria are corrected");
        }

        return $this->record($id, self::FAMILY_UNKNOWN, 'manual_review',
            'no deterministic family rule matched; manual review required to determine why this packet is blocked');
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
     * @return array{schema_version:string, task_packet_id:string, family:string, recommended_action:string, rationale:string}
     */
    private function record(string $id, string $family, string $action, string $rationale): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'task_packet_id' => $id,
            'family' => $family,
            'recommended_action' => $action,
            'rationale' => $rationale,
        ];
    }
}

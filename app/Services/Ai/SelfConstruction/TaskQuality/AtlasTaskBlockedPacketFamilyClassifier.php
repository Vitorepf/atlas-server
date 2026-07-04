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

    public const FAMILY_MISSING_SCOPE_FIELDS = 'missing_scope_fields';

    public const FAMILY_FORBIDDEN_TARGET_SUSPECT = 'forbidden_target_suspect';

    public const FAMILY_DUPLICATE_OR_STALE_BRAIN_PACKET = 'duplicate_or_stale_brain_packet';

    public const FAMILY_CODEX_META_SCHEMA_MIGRATION = 'codex_meta_schema_migration';

    public const FAMILY_CODEX_META_RECEIPT_VERIFIER = 'codex_meta_receipt_verifier';

    public const FAMILY_CODEX_META_AUTOPOIETIC_CONSTITUTION = 'codex_meta_autopoietic_constitution';

    public const FAMILY_CODEX_META_HARD_CASE_DATASET = 'codex_meta_hard_case_dataset';

    public const FAMILY_CODEX_META_BACKUP_SNAPSHOT = 'codex_meta_backup_snapshot';

    public const FAMILY_CODEX_META_PROCESS_ZOMBIE = 'codex_meta_process_zombie';

    public const FAMILY_UNKNOWN = 'unknown';

    /**
     * codex-meta- blocked packet id/objective slug → concrete family, in priority order
     * (first matching marker set wins). Each maps to respec/retire/manual_review with its
     * own rationale + repair hints, instead of collapsing into FAMILY_UNKNOWN.
     *
     * @var array<string, array{markers: list<string>, action: string, repairability: string, confidence: string, rationale: string, hints: list<string>}>
     */
    private const CODEX_META_SLUG_FAMILIES = [
        self::FAMILY_CODEX_META_SCHEMA_MIGRATION => [
            'markers' => ['schema-migration', 'schema_migration', 'migrate-schema'],
            'action' => 'respec',
            'repairability' => 'repairable',
            'confidence' => 'medium',
            'rationale' => "id/objective slug indicates a schema migration task: the target schema/contract version must be pinned and the migration plan respecified before this packet can be grinded",
            'hints' => ['pin_target_schema_version', 'attach_migration_plan'],
        ],
        self::FAMILY_CODEX_META_RECEIPT_VERIFIER => [
            'markers' => ['receipt-verifier', 'receipt_verifier', 'verifier', 'receipt-verification'],
            'action' => 'respec',
            'repairability' => 'repairable',
            'confidence' => 'medium',
            'rationale' => 'id/objective slug indicates a receipt/verifier task: the receipt contract and the verification command must be made explicit before this packet can be grinded',
            'hints' => ['attach_receipt_contract', 'attach_runnable_verification_command'],
        ],
        self::FAMILY_CODEX_META_AUTOPOIETIC_CONSTITUTION => [
            'markers' => ['autopoietic', 'autopoiesis', 'constitution'],
            'action' => 'manual_review',
            'repairability' => 'conditional',
            'confidence' => 'medium',
            'rationale' => 'id/objective slug touches the autopoietic constitution layer: changes here are self-referential and require explicit human review before respec or retire',
            'hints' => ['operator_review_constitution_change'],
        ],
        self::FAMILY_CODEX_META_HARD_CASE_DATASET => [
            'markers' => ['hard-case', 'hard_case', 'hardcase-dataset', 'dataset'],
            'action' => 'respec',
            'repairability' => 'repairable',
            'confidence' => 'medium',
            'rationale' => 'id/objective slug indicates a hard-case dataset task: the dataset source and the expected fixture shape must be respecified before this packet can be grinded',
            'hints' => ['attach_dataset_source', 'attach_expected_fixture_shape'],
        ],
        self::FAMILY_CODEX_META_BACKUP_SNAPSHOT => [
            'markers' => ['backup-snapshot', 'backup_snapshot', 'snapshot-backup', 'backup', 'snapshot'],
            'action' => 'respec',
            'repairability' => 'repairable',
            'confidence' => 'medium',
            'rationale' => 'id/objective slug indicates a backup/snapshot task: the snapshot target and retention policy must be respecified before this packet can be grinded',
            'hints' => ['attach_snapshot_target', 'attach_retention_policy'],
        ],
        self::FAMILY_CODEX_META_PROCESS_ZOMBIE => [
            'markers' => ['zombie-process', 'zombie_process', 'zombie', 'process-leak', 'stale-process'],
            'action' => 'respec',
            'repairability' => 'repairable',
            'confidence' => 'medium',
            'rationale' => 'id/objective slug indicates a zombie/stale-process detection task: the reclaim/cleanup contract must be made explicit before this packet can be grinded',
            'hints' => ['attach_reclaim_contract'],
        ],
    ];

    private const GIVE_BACK_THRESHOLD = 8;

    /** Path-fragment markers for FORBIDDEN_AXES (pétreo): soft id/objective signal only —
     * weaker confidence than the hard forbidden_target rule above, which requires an explicit
     * blocking_deficiency citing it.
     */
    private const FORBIDDEN_AXIS_MARKERS = [
        'selfimprovement/', 'self_improvement/', 'programming/', 'atlascode',
        'routes/api.php', 'atlas-desktop/', 'forge/', 'rivals/', 'cartografia/', 'voice/',
    ];

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
        $objective = strtolower(trim((string) ($packet['objective'] ?? '')));
        $idLower = strtolower($id);
        $missingFields = is_array($packet['missing_fields'] ?? null) ? array_values(array_map('strval', $packet['missing_fields'])) : [];

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

        // ── New real-queue families: classify from id/objective/missing-field signal
        //    instead of collapsing into unknown (AC1).

        if ($missingFields !== []
            || str_contains($blockingStrLower, 'missing_scope')
            || str_contains($blockingStrLower, 'scope_in')
            || str_contains($blockingStrLower, 'allowed_files_empty')) {
            $listed = $missingFields !== [] ? implode(', ', array_slice($missingFields, 0, 5)) : 'scope_in/allowed_files';
            return $this->record($id, self::FAMILY_MISSING_SCOPE_FIELDS, 'respec', 'repairable',
                "scope fields are missing: [{$listed}] — packet cannot be grinded until scope_in/allowed_files are populated",
                ['populate_scope_in', 'populate_allowed_files'], 'medium');
        }

        $idAndObjective = $idLower.' '.$objective;
        foreach (self::FORBIDDEN_AXIS_MARKERS as $marker) {
            if (str_contains($idAndObjective, $marker)) {
                return $this->record($id, self::FAMILY_FORBIDDEN_TARGET_SUSPECT, 'manual_review', 'conditional',
                    "task_packet_id/objective mention a forbidden-axis path fragment ('{$marker}') without an explicit blocking_deficiency citing it — needs human confirmation before retire or repair",
                    ['confirm_forbidden_axis_then_retire_or_clear'], 'medium');
            }
        }

        $looksLikeBrainSeedId = (bool) preg_match('/^brain:[a-z0-9_]+:[a-z]+:/i', $id);
        $staleSignal = str_contains($objective, 'duplicate') || str_contains($objective, 'stale')
            || str_contains($objective, 'already implemented') || str_contains($objective, 'already resolved');
        if ($looksLikeBrainSeedId || (str_starts_with($idLower, 'codex-meta-') && $staleSignal)) {
            return $this->record($id, self::FAMILY_DUPLICATE_OR_STALE_BRAIN_PACKET, 'retire', 'unrepairable',
                $looksLikeBrainSeedId
                    ? "task_packet_id matches a brain-seed enumeration pattern ('{$id}'): these are ephemeral auto-generated ids that go stale quickly"
                    : 'objective signals duplicate/stale work already resolved by a prior task',
                ['retire'], $looksLikeBrainSeedId ? 'medium' : 'high');
        }

        // ── codex-meta- slug families: concrete, repairable categories instead of
        //    collapsing all unmatched codex-meta- packets into FAMILY_UNKNOWN (AC1).
        if (str_starts_with($idLower, 'codex-meta-')) {
            foreach (self::CODEX_META_SLUG_FAMILIES as $family => $spec) {
                foreach ($spec['markers'] as $marker) {
                    if (str_contains($idAndObjective, $marker)) {
                        return $this->record($id, $family, $spec['action'], $spec['repairability'],
                            $spec['rationale'], $spec['hints'], $spec['confidence']);
                    }
                }
            }
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
            'insufficient_signal: no deterministic family rule matched; manual review required to determine why this packet is blocked',
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
     * @return array{schema_version:string, task_packet_id:string, family:string, recommended_action:string, repairability:string, rationale:string, required_respec_fields:list<string>, confidence:string, repair_family:string, root_cause:string, repair_route:string, worker_safe:bool, non_worker_reason:?string}
     */
    private function record(string $id, string $family, string $action, string $repairability, string $rationale, array $requiredRespecFields, string $confidence): array
    {
        $isWorkerSafe = match (true) {
            $action === 'manual_review' => false,
            $repairability === 'conditional' || $repairability === 'unknown' => false,
            in_array($family, [
                self::FAMILY_FORBIDDEN_TARGET,
                self::FAMILY_CONTRADICTORY_ACCEPTANCE,
                self::FAMILY_FORBIDDEN_TARGET_SUSPECT,
            ], true) => false,
            $repairability === 'unrepairable' && $action === 'retire' => true, // retire is a safe autonomous op for non-sensitive families
            default => true,
        };
        $nonWorkerReason = $isWorkerSafe
            ? null
            : match ($family) {
                self::FAMILY_UNKNOWN => 'manual_review_required',
                self::FAMILY_FORBIDDEN_TARGET => 'forbidden_target_cannot_be_fixed_by_file_edits',
                self::FAMILY_CONTRADICTORY_ACCEPTANCE => 'contradictory_acceptance_needs_rewrite',
                self::FAMILY_DORMANT_CLI_ARM_PROXY => 'dormant_cli_arm_needs_operator_wiring',
                self::FAMILY_FORBIDDEN_TARGET_SUSPECT => 'forbidden_axis_needs_human_confirmation',
                self::FAMILY_CODEX_META_AUTOPOIETIC_CONSTITUTION => 'autopoietic_constitution_requires_human_review',
                default => 'manual_intervention_required',
            };

        return [
            'schema_version' => self::SCHEMA,
            'task_packet_id' => $id,
            'family' => $family,
            'repair_family' => $family,
            'recommended_action' => $action,
            'repair_route' => $action,
            'repairability' => $repairability,
            'rationale' => $rationale,
            'root_cause' => $rationale,
            'required_respec_fields' => array_values($requiredRespecFields),
            'confidence' => $confidence,
            'worker_safe' => $isWorkerSafe,
            'non_worker_reason' => $nonWorkerReason,
        ];
    }
}

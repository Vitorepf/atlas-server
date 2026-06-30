<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQuality;

/**
 * Pure, deterministic explainer for blocked queue packets that {@see AtlasTaskBlockedPacketFamilyClassifier}
 * left as `family=unknown`. Instead of an opaque "manual review required" bucket, this inspects the raw
 * packet shape and emits the STRONGEST likely blocker family plus the missing signals that point to it,
 * so a respec/retire decision can be made without a human reading the packet first.
 *
 * INPUT: a raw task packet array (same shape as {@see AtlasTaskPacketQualityInspector::inspect} input,
 * optionally also carrying give_back_count / has_prior_success / status / packet_quality facts).
 *
 * FAMILIES (priority order — first match wins):
 *   forbidden_target                  — allowed_files hit a pétreo forbidden/property-gated target
 *   missing_allowed_files             — allowed_files is empty
 *   missing_acceptance                — acceptance_criteria is empty
 *   missing_required_evidence         — required_evidence is empty
 *   duplicate_or_already_done_suspect — high give_back_count + prior success, or status=completed
 *   contradictory_acceptance_suspect  — packet_quality.deficiencies names a contradiction
 *   insufficient_metadata             — fallback: objective too short/empty, nothing else matched
 *
 * OUTPUT per packet: { packet_id, likely_family, confidence, missing_signals, respec_hint, safe_next_action }
 *
 * Pure: only reads the packet array — no queue mutation, DB write, provider call, network, file write,
 * or git command.
 */
final class AtlasTaskBlockedUnknownFamilyExplainer
{
    public const SCHEMA = 'atlas.task_quality.blocked_unknown_family_explainer.v1';

    public const FAMILY_FORBIDDEN_TARGET = 'forbidden_target';

    public const FAMILY_MISSING_ALLOWED_FILES = 'missing_allowed_files';

    public const FAMILY_MISSING_ACCEPTANCE = 'missing_acceptance';

    public const FAMILY_MISSING_REQUIRED_EVIDENCE = 'missing_required_evidence';

    public const FAMILY_DUPLICATE_OR_ALREADY_DONE_SUSPECT = 'duplicate_or_already_done_suspect';

    public const FAMILY_CONTRADICTORY_ACCEPTANCE_SUSPECT = 'contradictory_acceptance_suspect';

    public const FAMILY_INSUFFICIENT_METADATA = 'insufficient_metadata';

    public const ACTION_RESPEC = 'respec';

    public const ACTION_RETIRE = 'retire';

    public const ACTION_MANUAL_REVIEW = 'manual_review';

    private const GIVE_BACK_SUSPECT_THRESHOLD = 5;

    private const RESPEC_HINTS = [
        self::FAMILY_FORBIDDEN_TARGET => 'remove the forbidden/property-gated target from allowed_files before re-serving',
        self::FAMILY_MISSING_ALLOWED_FILES => 'add at least one concrete implementation/test file to allowed_files',
        self::FAMILY_MISSING_ACCEPTANCE => 'add a runnable acceptance criterion naming the changed file',
        self::FAMILY_MISSING_REQUIRED_EVIDENCE => 'add required_evidence (e.g. tests_or_gates_result) so completion can be proven',
        self::FAMILY_DUPLICATE_OR_ALREADY_DONE_SUSPECT => 'verify the deliverable was not already shipped by a prior task before re-queuing',
        self::FAMILY_CONTRADICTORY_ACCEPTANCE_SUSPECT => 'rewrite acceptance_criteria so they no longer contradict each other',
        self::FAMILY_INSUFFICIENT_METADATA => 'expand the objective with a concrete target and rationale',
    ];

    private const SAFE_NEXT_ACTION = [
        self::FAMILY_FORBIDDEN_TARGET => self::ACTION_RETIRE,
        self::FAMILY_MISSING_ALLOWED_FILES => self::ACTION_RESPEC,
        self::FAMILY_MISSING_ACCEPTANCE => self::ACTION_RESPEC,
        self::FAMILY_MISSING_REQUIRED_EVIDENCE => self::ACTION_RESPEC,
        self::FAMILY_DUPLICATE_OR_ALREADY_DONE_SUSPECT => self::ACTION_RETIRE,
        self::FAMILY_CONTRADICTORY_ACCEPTANCE_SUSPECT => self::ACTION_RETIRE,
        self::FAMILY_INSUFFICIENT_METADATA => self::ACTION_MANUAL_REVIEW,
    ];

    /**
     * @param  array<string,mixed>  $packet
     * @return array{packet_id:string, likely_family:string, confidence:string, missing_signals:list<string>, respec_hint:string, safe_next_action:string}
     */
    public function explain(array $packet): array
    {
        $id = (string) ($packet['task_packet_id'] ?? ($packet['id'] ?? ''));
        $objective = trim((string) ($packet['objective'] ?? ''));
        $allowedFiles = array_values(array_map('strval', (array) ($packet['allowed_files'] ?? [])));
        $acceptance = (array) ($packet['acceptance_criteria'] ?? []);
        $requiredEvidence = (array) ($packet['required_evidence'] ?? []);
        $giveBackCount = max(0, (int) ($packet['give_back_count'] ?? 0));
        $hasPriorSuccess = (bool) ($packet['has_prior_success'] ?? false);
        $status = trim((string) ($packet['status'] ?? ''));

        $facts = is_array($packet['packet_quality']['facts'] ?? null) ? (array) $packet['packet_quality']['facts'] : [];
        $forbiddenTargets = array_values(array_map('strval', (array) ($facts['forbidden_self_targets'] ?? [])));
        $propertyGatedTargets = array_values(array_map('strval', (array) ($facts['property_gated_targets'] ?? [])));
        $deficiencies = array_values(array_map('strval', (array) ($packet['packet_quality']['deficiencies'] ?? [])));

        $missingSignals = [];

        // ── forbidden_target ──────────────────────────────────────────────────
        $hitForbidden = $forbiddenTargets !== [] && array_intersect($allowedFiles, $forbiddenTargets) !== [];
        $hitPropertyGatedNoReceipt = $propertyGatedTargets !== []
            && array_intersect($allowedFiles, $propertyGatedTargets) !== []
            && ! in_array('constitution_gate_receipt', $requiredEvidence, true);
        if ($hitForbidden || $hitPropertyGatedNoReceipt) {
            $missingSignals[] = $hitForbidden ? 'allowed_files_hit_forbidden_self_target' : 'property_gated_target_missing_constitution_receipt';

            return $this->result($id, self::FAMILY_FORBIDDEN_TARGET, $missingSignals);
        }

        // ── missing_allowed_files ────────────────────────────────────────────
        if ($allowedFiles === []) {
            $missingSignals[] = 'allowed_files_empty';

            return $this->result($id, self::FAMILY_MISSING_ALLOWED_FILES, $missingSignals);
        }

        // ── missing_acceptance ───────────────────────────────────────────────
        if ($acceptance === []) {
            $missingSignals[] = 'acceptance_criteria_empty';

            return $this->result($id, self::FAMILY_MISSING_ACCEPTANCE, $missingSignals);
        }

        // ── missing_required_evidence ────────────────────────────────────────
        if ($requiredEvidence === []) {
            $missingSignals[] = 'required_evidence_empty';

            return $this->result($id, self::FAMILY_MISSING_REQUIRED_EVIDENCE, $missingSignals);
        }

        // ── duplicate_or_already_done_suspect ────────────────────────────────
        $duplicateSuspect = ($giveBackCount >= self::GIVE_BACK_SUSPECT_THRESHOLD && $hasPriorSuccess) || $status === 'completed';
        if ($duplicateSuspect) {
            if ($status === 'completed') {
                $missingSignals[] = 'status_completed';
            }
            if ($giveBackCount >= self::GIVE_BACK_SUSPECT_THRESHOLD && $hasPriorSuccess) {
                $missingSignals[] = sprintf('give_back_count=%d_with_prior_success', $giveBackCount);
            }

            return $this->result($id, self::FAMILY_DUPLICATE_OR_ALREADY_DONE_SUSPECT, $missingSignals);
        }

        // ── contradictory_acceptance_suspect ─────────────────────────────────
        $contradictoryHit = array_values(array_filter($deficiencies, static fn (string $d): bool => str_contains(strtolower($d), 'contradict')));
        if ($contradictoryHit !== []) {
            $missingSignals = array_map(static fn (string $d): string => 'packet_quality_flag:'.$d, $contradictoryHit);

            return $this->result($id, self::FAMILY_CONTRADICTORY_ACCEPTANCE_SUSPECT, $missingSignals);
        }

        // ── insufficient_metadata (fallback) ─────────────────────────────────
        if ($objective === '') {
            $missingSignals[] = 'objective_empty';
        } elseif (mb_strlen($objective) < 20) {
            $missingSignals[] = 'objective_too_short';
        } else {
            $missingSignals[] = 'no_deterministic_signal_matched';
        }

        return $this->result($id, self::FAMILY_INSUFFICIENT_METADATA, $missingSignals);
    }

    /**
     * @param  list<array<string,mixed>>  $packets
     * @return list<array<string,mixed>>
     */
    public function explainBatch(array $packets): array
    {
        return array_values(array_map(fn (array $p): array => $this->explain($p), $packets));
    }

    /**
     * @param  list<string>  $missingSignals
     * @return array{packet_id:string, likely_family:string, confidence:string, missing_signals:list<string>, respec_hint:string, safe_next_action:string}
     */
    private function result(string $id, string $family, array $missingSignals): array
    {
        $confidence = match (true) {
            count($missingSignals) >= 2 => 'high',
            count($missingSignals) === 1 && $family !== self::FAMILY_INSUFFICIENT_METADATA => 'medium',
            default => 'low',
        };

        return [
            'packet_id' => $id,
            'likely_family' => $family,
            'confidence' => $confidence,
            'missing_signals' => array_values($missingSignals),
            'respec_hint' => self::RESPEC_HINTS[$family],
            'safe_next_action' => self::SAFE_NEXT_ACTION[$family],
        ];
    }
}

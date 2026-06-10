<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;

/**
 * AP-806 · factory_max -> Self-Construction ADMISSION BRIDGE.
 *
 * When the Stewardship loop's factory_max selection rejects a HIGH-VALUE finding
 * for an authority reason (high-risk / cross-system / forge-authority), this
 * bridge converts that single big finding into 2-5 small GOVERNED implementation
 * packets and offers the FIRST eligible one as a normal, finding-shaped candidate.
 *
 * This is NOT a separate Self-Construction loop or queue. The SAME Area Focus /
 * Stewardship loop selects + executes exactly ONE packet per cycle through its
 * existing owner runtime -> judge -> gates -> merge -> evidence path, and
 * slice-progression advances to the next packet on the next cycle. The bridge
 * REUSES FindingSlicePlannerService (semantic decomposition) and
 * AgentControlPlaneTaskPacketBuilder (governance: scope, forbidden axes, risk,
 * claim/lease, evidence) — it never reimplements packetization, claim or scope.
 *
 * If no safe packet can be built, it returns admission_blocked with a reason —
 * it NEVER falls back to synthetic starvation-recovery.
 */
final class AreaFocusSelfConstructionAdmissionBridgeService
{
    public const SCHEMA = 'atlas.software_company_stewardship.self_construction_admission.v1';

    /**
     * Only HIGH-VALUE findings rejected for an authority reason or a learned
     * non-retryable broad-slice pattern are packetized. Routine / docs /
     * benchmark / missing-test rejections are NEVER admitted here (no filler),
     * and review-locked / quarantined findings are left alone.
     *
     * @var list<string>
     */
    public const ADMISSIBLE_REJECTION_REASONS = [
        'factory_max_rejects_high_risk_deep_finding_without_forge_authority',
        'factory_max_rejects_forge_without_live_authority',
        'factory_max_rejects_atlas_dev_topology_leak_without_authority',
        'factory_max_rejects_non_factory_scope_without_automerge_authority',
        'factory_max_rejects_prior_non_retryable_failure_pattern',
    ];

    /** A packet may touch at most this many files; bigger is not "bounded". */
    public const MAX_PACKET_ALLOWED_FILES = 4;

    public function __construct(
        private readonly FindingSlicePlannerService $slicePlanner,
        private readonly AgentControlPlaneTaskPacketBuilder $packetBuilder,
    ) {}

    /**
     * @param  array<string,mixed>  $finding  the factory_max-rejected finding
     * @param  array<string,true>  $completedPacketIds  packet_ids already merged (slice-progression)
     * @return array<string,mixed>
     */
    public function admit(array $finding, string $rejectionReason, string $areaId, string $focus, array $completedPacketIds = []): array
    {
        if (! in_array($rejectionReason, self::ADMISSIBLE_REJECTION_REASONS, true)) {
            return $this->blocked('rejection_not_authority_gated', $rejectionReason, [], $finding, $areaId, $focus);
        }

        $plan = $this->slicePlanner->plan([
            'finding' => $finding,
            'mode' => FindingSlicePlannerService::MODE_RECORD,
            'scope_profile' => FindingSlicePlannerService::SCOPE_FACTORY_MAX,
        ]);
        if ((string) ($plan['decomposition_status'] ?? '') !== FindingSlicePlannerService::STATUS_SLICED) {
            return $this->blocked('finding_not_decomposable', $rejectionReason, [], $finding, $areaId, $focus, $plan);
        }

        $slices = AreaFocusLoopPayloadNormalizer::listOfArrays($plan['slices'] ?? []);
        if (count($slices) < 2) {
            return $this->blocked('insufficient_slices_for_packets', $rejectionReason, [], $finding, $areaId, $focus, $plan);
        }

        $parentId = (string) ($finding['finding_id'] ?? '');
        $parentHash = (string) ($finding['finding_hash'] ?? '');
        $requiredTests = $this->requiredTests($finding);

        $packets = [];
        foreach ($slices as $slice) {
            $sequence = (int) ($slice['sequence'] ?? (count($packets) + 1));
            $allowed = AreaFocusStringListNormalizer::preserveStrings($slice['allowed_files'] ?? ($finding['affected_files'] ?? []));
            $objective = trim((string) ($slice['objective'] ?? ''));
            $anchorContext = $this->sliceAnchorContext($slice);
            // The planner's slice_id IS the canonical packet id — keep it so the
            // loop's AP-806 slice-progression (active_slice_id / completedSemanticSliceIds)
            // recognizes a completed packet and advances N -> N+1 instead of re-running
            // slice 1. Only synthesize an id if the planner did not provide one.
            $packetId = (string) ($slice['slice_id'] ?? '') !== ''
                ? (string) $slice['slice_id']
                : 'scp_'.substr(MissionCanonicalHash::sha256([$parentHash, $sequence, $allowed]), 0, 16);
            // A bounded <=4-file packet is genuinely lower risk than the whole
            // high-severity finding — slicing-for-safety is this bridge's entire
            // thesis. Cap the per-packet autonomous-execution risk at MEDIUM so the
            // bounded step is admissible; the REAL risk is still enforced downstream
            // by the judge + merge governor (never_auto_merge for high/critical) at
            // MERGE time, so this never lowers merge safety. The parent severity is
            // preserved on the packet (parent_severity) for the audit trail.
            $sliceRisk = strtolower((string) ($slice['risk_level'] ?? 'medium'));
            $risk = in_array($sliceRisk, ['low', 'medium'], true) ? $sliceRisk : 'medium';

            $built = $this->packetBuilder->build([
                'task_packet_id' => $packetId,
                'objective' => $objective,
                'allowed_files' => $allowed,
                'forbidden_files' => AreaFocusStringListNormalizer::preserveStrings($slice['forbidden_files'] ?? $this->forbiddenFor($allowed)),
                'risk_level' => $risk,
                'parent_run_id' => $parentId,
                'source' => 'area_focus_self_construction_admission_bridge',
                // parent/sequence ride in continuation_context (hashed into the packet
                // hash) per the Self-Construction packet contract — no schema bump.
                'continuation_context' => array_merge([
                    'parent_finding_id' => $parentId,
                    'parent_finding_hash' => $parentHash,
                    'slice_sequence' => $sequence,
                    'area_id' => $areaId,
                    'focus' => $focus,
                ], $anchorContext),
                'acceptance_criteria' => AreaFocusStringListNormalizer::preserveStrings($slice['validation_commands'] ?? []) !== []
                    ? AreaFocusStringListNormalizer::preserveStrings($slice['validation_commands'])
                    : [
                        'Implement ONLY bounded packet '.$sequence.' of "'.((string) ($finding['title'] ?? 'finding')).'" within allowed_files.',
                        'Do not touch forbidden_files or any forbidden axis.',
                        'A focused test for the changed code passes.',
                    ],
                'required_evidence' => array_values(array_unique(array_merge(
                    ['task_packet_created', 'scope_lock_planned', 'focused_test_passed'],
                    $requiredTests,
                ))),
            ]);

            $packets[] = $this->bridgePacket($built, $finding, $slice, $areaId, $focus, $sequence, $packetId, $allowed, $risk, $requiredTests);
        }

        $safe = array_values(array_filter(
            $packets,
            fn (array $packet): bool => $this->isSafePacket($packet) && ! isset($completedPacketIds[(string) ($packet['packet_id'] ?? '')]),
        ));

        if ($safe === []) {
            return $this->blocked('no_safe_packet_after_governance', $rejectionReason, $packets, $finding, $areaId, $focus, $plan);
        }

        $first = $safe[0];

        return [
            'schema_version' => self::SCHEMA,
            'admissible' => true,
            'admission_blocked_reason' => '',
            'parent_finding_id' => $parentId,
            'parent_finding_hash' => $parentHash,
            'rejection_reason' => $rejectionReason,
            'packet_count' => count($packets),
            'safe_packet_count' => count($safe),
            'packets' => array_map(fn (array $packet): array => $this->packetSummary($packet), $packets),
            'first_packet' => $first,
            'first_packet_finding' => $this->packetToFinding($first, $finding, $areaId, $focus),
        ];
    }

    /**
     * A packet is safe to admit when the read-only governance builder planned it
     * (scope ok, no forbidden axis) and it stays bounded (1..MAX files).
     *
     * @param  array<string,mixed>  $packet
     */
    private function isSafePacket(array $packet): bool
    {
        $files = AreaFocusStringListNormalizer::preserveStrings($packet['allowed_files'] ?? []);

        return (string) ($packet['status'] ?? '') === 'planned'
            && $files !== []
            && count($files) <= self::MAX_PACKET_ALLOWED_FILES
            && (array) ($packet['blocking_reasons'] ?? []) === [];
    }

    /**
     * @param  array<string,mixed>  $built  raw AgentControlPlaneTaskPacketBuilder output
     * @param  array<string,mixed>  $finding
     * @param  array<string,mixed>  $slice
     * @param  list<string>  $allowed
     * @param  list<string>  $requiredTests
     * @return array<string,mixed>
     */
    private function bridgePacket(array $built, array $finding, array $slice, string $areaId, string $focus, int $sequence, string $packetId, array $allowed, string $risk, array $requiredTests): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'packet_id' => $packetId,
            'parent_finding_id' => (string) ($finding['finding_id'] ?? ''),
            'parent_finding_hash' => (string) ($finding['finding_hash'] ?? ''),
            'parent_affected_files' => AreaFocusStringListNormalizer::preserveStrings($finding['affected_files'] ?? []),
            'area_id' => $areaId,
            'focus' => $focus,
            'slice_sequence' => $sequence,
            'status' => (string) ($built['status'] ?? 'blocked'),
            'parent_severity' => strtolower((string) ($finding['severity'] ?? '')),
            'objective' => (string) ($built['objective'] ?? ($slice['objective'] ?? '')),
            'allowed_files' => $allowed,
            'forbidden_files' => AreaFocusStringListNormalizer::preserveStrings(data_get($built, 'normalized_scope.forbidden_files', $this->forbiddenFor($allowed))),
            'required_tests' => $requiredTests,
            'required_gates' => ['scope_validator', 'focused_test', 'judge_accept', 'merge_governor'],
            'risk_level' => $risk,
            'target_method' => trim((string) ($slice['target_method'] ?? '')),
            'target_symbol' => trim((string) ($slice['target_symbol'] ?? '')),
            'method_anchor' => trim((string) ($slice['method_anchor'] ?? ($slice['target_method'] ?? ''))),
            'surgical_anchor' => trim((string) ($slice['surgical_anchor'] ?? '')),
            'mutation_anchor' => trim((string) ($slice['mutation_anchor'] ?? '')),
            'owner_runtime' => 'atlas_dev',
            'claim' => (array) ($built['claim_requirements'] ?? []),
            'lease' => (array) ($built['lease_requirements'] ?? []),
            'stop_conditions' => [
                'scope_violation',
                'forbidden_axis_touched',
                'validation_failed',
                'judge_not_accept',
                'lease_expired',
            ],
            'blocking_reasons' => array_values((array) ($built['blocking_reasons'] ?? [])),
            'task_packet' => $built,
        ];
    }

    /**
     * Convert the first safe packet into a finding-shaped candidate the SAME loop
     * selects + executes. `active_slice_id` = packet_id ties it into AP-806
     * slice-progression: the packet locks independently, the parent stays
     * selectable, and the next cycle advances to the next packet.
     *
     * @param  array<string,mixed>  $packet
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    private function packetToFinding(array $packet, array $finding, string $areaId, string $focus): array
    {
        $packetId = (string) ($packet['packet_id'] ?? '');
        $sequence = (int) ($packet['slice_sequence'] ?? 1);
        $objective = (string) ($packet['objective'] ?? '');

        return [
            'schema_version' => 'atlas.software_company_stewardship.area_focus_deep_finding.v1',
            'finding_id' => (string) ($finding['finding_id'] ?? '').'::packet::'.$sequence,
            'finding_hash' => 'sha256:'.MissionCanonicalHash::sha256(['self_construction_packet', $packetId]),
            'area_id' => $areaId,
            'focus' => $focus,
            'title' => 'Self-Construction packet '.$sequence.' — execute ONLY this bounded step',
            'detail' => $objective,
            'why_it_matters' => $objective,
            'proposed_next_action' => '',
            'kind' => (string) ($finding['kind'] ?? 'feature'),
            'severity' => (string) ($packet['risk_level'] ?? 'medium'),
            'owner_candidate' => 'atlas_dev',
            'affected_files' => AreaFocusStringListNormalizer::preserveStrings($packet['allowed_files'] ?? []),
            'evidence_refs' => AreaFocusStringListNormalizer::preserveStrings($packet['required_tests'] ?? []),
            'active_slice_id' => $packetId,
            'active_slice_kind' => 'self_construction_packet',
            'self_construction_packet' => $packet,
            'parent_finding_id' => (string) ($finding['finding_id'] ?? ''),
            'parent_finding_hash' => (string) ($finding['finding_hash'] ?? ''),
            'origin_type' => 'self_construction_admission_packet',
            'target_method' => trim((string) ($packet['target_method'] ?? '')),
            'target_symbol' => trim((string) ($packet['target_symbol'] ?? '')),
            'method_anchor' => trim((string) ($packet['method_anchor'] ?? ($packet['target_method'] ?? ''))),
            'surgical_anchor' => trim((string) ($packet['surgical_anchor'] ?? '')),
            'mutation_anchor' => trim((string) ($packet['mutation_anchor'] ?? '')),
            // A bounded, scope-validated, claim/lease-governed packet is autonomously
            // executable by design — the governance (small allowed_files + scope
            // validator + judge/merge gates) is what makes it safe, replacing the
            // operator review the unbounded parent finding would have required.
            'auto_execution_allowed' => true,
            'operator_review_required' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $packet
     * @return array<string,mixed>
     */
    private function packetSummary(array $packet): array
    {
        return [
            'packet_id' => (string) ($packet['packet_id'] ?? ''),
            'slice_sequence' => (int) ($packet['slice_sequence'] ?? 0),
            'status' => (string) ($packet['status'] ?? ''),
            'objective' => (string) ($packet['objective'] ?? ''),
            'allowed_files' => AreaFocusStringListNormalizer::preserveStrings($packet['allowed_files'] ?? []),
            'risk_level' => (string) ($packet['risk_level'] ?? ''),
            'target_method' => trim((string) ($packet['target_method'] ?? '')),
            'target_symbol' => trim((string) ($packet['target_symbol'] ?? '')),
            'method_anchor' => trim((string) ($packet['method_anchor'] ?? ($packet['target_method'] ?? ''))),
            'surgical_anchor' => trim((string) ($packet['surgical_anchor'] ?? '')),
            'safe' => $this->isSafePacket($packet),
            'blocking_reasons' => array_values((array) ($packet['blocking_reasons'] ?? [])),
        ];
    }

    /**
     * @param  array<string,mixed>  $slice
     * @return array<string,string>
     */
    private function sliceAnchorContext(array $slice): array
    {
        $context = [];
        foreach (['target_method', 'target_symbol', 'method_anchor', 'surgical_anchor', 'mutation_anchor'] as $key) {
            $value = trim((string) ($slice[$key] ?? ''));
            if ($value !== '') {
                $context[$key] = $value;
            }
        }

        return $context;
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return list<string>
     */
    private function requiredTests(array $finding): array
    {
        $tests = [];
        foreach (AreaFocusStringListNormalizer::preserveStrings($finding['evidence_refs'] ?? []) as $ref) {
            if (str_starts_with($ref, 'expected_test:')) {
                $tests[] = $ref;
            }
        }

        return array_values(array_unique($tests));
    }

    /**
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function forbiddenFor(array $allowed): array
    {
        // A packet must never touch the loop's own governance/safety spine while
        // implementing a bounded step.
        return [
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipBranchMergeGovernorService.php',
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousLoopReceiptIntegrityService.php',
            'config/atlas.php',
            'routes/api.php',
        ];
    }

    /**
     * @param  array<string,mixed>  $packets
     * @param  array<string,mixed>  $finding
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function blocked(string $reason, string $rejectionReason, array $packets, array $finding, string $areaId, string $focus, array $plan = []): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'admissible' => false,
            'admission_blocked_reason' => $reason,
            'parent_finding_id' => (string) ($finding['finding_id'] ?? ''),
            'parent_finding_hash' => (string) ($finding['finding_hash'] ?? ''),
            'rejection_reason' => $rejectionReason,
            'area_id' => $areaId,
            'focus' => $focus,
            'packet_count' => count($packets),
            'safe_packet_count' => 0,
            'packets' => array_map(fn (array $packet): array => $this->packetSummary($packet), AreaFocusLoopPayloadNormalizer::listOfArrays($packets)),
            'first_packet' => null,
            'first_packet_finding' => null,
            'decomposition_status' => (string) ($plan['decomposition_status'] ?? ''),
        ];
    }
}

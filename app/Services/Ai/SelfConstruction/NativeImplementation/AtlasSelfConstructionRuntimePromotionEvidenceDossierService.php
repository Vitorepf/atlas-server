<?php

namespace App\Services\Ai\SelfConstruction\NativeImplementation;

use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use Carbon\CarbonImmutable;
use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;

final class AtlasSelfConstructionRuntimePromotionEvidenceDossierService
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    public const SCHEMA_VERSION = 'atlas.self_construction.runtime_promotion_evidence_dossier.v1';

    public const MODE = 'read_only_runtime_promotion_evidence_dossier';

    public const EVIDENCE_SECTIONS_SCHEMA = 'atlas.self_construction.runtime_promotion_evidence_sections.v1';

    public const STATE_CURRENT = 'current';

    public const STATE_STALE = 'stale';

    public const STATE_WEAK = 'weak';

    public const STATE_MISSING = 'missing';

    public const VERDICT_COMPLETE = 'complete';

    public const VERDICT_INCOMPLETE = 'incomplete';

    /** @var list<string> */
    private const CRITICAL_SECTIONS = [
        'queue_health',
        'worker_outcome',
        'proof_receipts',
        'learning_sync',
        'rollback_plan',
        'runtime_soak',
    ];

    private const STALE_AFTER_SECONDS = 86400;

    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $readiness,
    ) {}

    /**
     * Builds the six-section runtime promotion evidence dossier: queue health, worker outcome,
     * proof receipts, learning sync, rollback plan, and runtime soak. Each section is
     * independently classified missing/stale/weak/current with an actionable repair hint, and
     * the overall verdict is complete ONLY when every critical section is current — a runtime
     * promotion is never claimed complete on partial or aging evidence.
     *
     * @param  array<string, mixed>  $facts  each key in CRITICAL_SECTIONS maps to that section's evidence array
     * @return array<string, mixed>
     */
    public function evidenceSections(array $facts): array
    {
        $sections = [];
        foreach (self::CRITICAL_SECTIONS as $sectionKey) {
            $sections[$sectionKey] = $this->evaluateSection($sectionKey, (array) ($facts[$sectionKey] ?? []));
        }

        $blockingSections = array_values(array_filter(
            self::CRITICAL_SECTIONS,
            static fn (string $key): bool => $sections[$key]['evidence_state'] !== self::STATE_CURRENT,
        ));

        $verdict = $blockingSections === [] ? self::VERDICT_COMPLETE : self::VERDICT_INCOMPLETE;

        $payload = [
            'schema_version' => self::EVIDENCE_SECTIONS_SCHEMA,
            'mode' => self::MODE,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'sections' => $sections,
            'blocking_sections' => $blockingSections,
            'verdict' => $verdict,
            'complete' => $verdict === self::VERDICT_COMPLETE,
        ];
        $payload['evidence_sections_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function evaluateSection(string $sectionKey, array $section): array
    {
        if ($section === []) {
            return [
                'section' => $sectionKey,
                'evidence_state' => self::STATE_MISSING,
                'repair_hint' => 'supply_'.$sectionKey.'_evidence',
                'facts' => [],
            ];
        }

        $ageSeconds = (int) ($section['observed_at_seconds_ago'] ?? 0);
        if ($ageSeconds > self::STALE_AFTER_SECONDS) {
            return [
                'section' => $sectionKey,
                'evidence_state' => self::STATE_STALE,
                'repair_hint' => 'refresh_'.$sectionKey.'_evidence',
                'facts' => $section,
            ];
        }

        $weakReason = $this->weakReason($sectionKey, $section);
        if ($weakReason !== null) {
            return [
                'section' => $sectionKey,
                'evidence_state' => self::STATE_WEAK,
                'repair_hint' => $weakReason,
                'facts' => $section,
            ];
        }

        return [
            'section' => $sectionKey,
            'evidence_state' => self::STATE_CURRENT,
            'repair_hint' => null,
            'facts' => $section,
        ];
    }

    /**
     * Section-specific weakness rules — a section can be present and fresh yet still fail to
     * prove the thing it claims to prove.
     *
     * @param  array<string, mixed>  $section
     */
    private function weakReason(string $sectionKey, array $section): ?string
    {
        return match ($sectionKey) {
            'queue_health' => (float) ($section['give_back_rate'] ?? 0.0) >= 0.3
                ? 'give_back_rate_too_high_stabilize_queue_before_promotion'
                : null,
            'worker_outcome' => (int) ($section['sample_count'] ?? 0) < 5
                ? 'insufficient_worker_outcome_sample_count'
                : ((float) ($section['success_rate'] ?? 0.0) < 0.8 ? 'worker_outcome_success_rate_below_promotion_floor' : null),
            'proof_receipts' => ((int) ($section['receipt_count'] ?? 0) === 0)
                ? 'no_proof_receipts_recorded'
                : (! (bool) ($section['all_verified'] ?? false) ? 'unverified_proof_receipts_present' : null),
            'learning_sync' => (int) ($section['lessons_admitted_count'] ?? 0) === 0
                ? 'no_lessons_admitted_since_last_sync'
                : null,
            'rollback_plan' => ! (bool) ($section['has_plan'] ?? false)
                ? 'no_rollback_plan_present'
                : (! (bool) ($section['tested'] ?? false) ? 'rollback_plan_present_but_untested' : null),
            'runtime_soak' => (int) ($section['soak_hours'] ?? 0) < 24
                ? 'runtime_soak_duration_below_24h_floor'
                : ((int) ($section['incident_count'] ?? 0) > 0 ? 'runtime_soak_reported_incidents_unresolved' : null),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    public function build(array $options = []): array
    {
        $matrix = (array) ($options['runtime_gap_matrix'] ?? (new AtlasSelfConstructionRuntimeGapMatrixService($this->readiness))->matrix([
            'runtime_promotion_receipt' => (array) ($options['runtime_promotion_receipt'] ?? []),
            'persist_runtime_promotion_receipt' => false,
        ]));
        $rows = (array) data_get($matrix, 'rows', []);
        $gapRows = array_values(array_filter($rows, static fn (array $row): bool => ! (bool) ($row['runtime_y'] ?? false)));
        $graduationHashes = [];
        foreach ($gapRows as $row) {
            $gapId = (string) ($row['gap_id'] ?? '');
            if ($gapId !== '') {
                $graduationHashes[$gapId] = (string) ($row['graduation_evidence_hash'] ?? '');
            }
        }
        $runtimePromotionMatrixHash = (string) data_get(
            $matrix,
            'expected_runtime_gap_matrix_hash_for_promotion_receipt',
            data_get($matrix, 'runtime_gap_matrix_hash', ''),
        );
        $runtimePromotionClosureBasisHash = (string) data_get($matrix, 'runtime_promotion_closure_basis_hash', '');

        $receiptPreimage = [
            'receipt_id' => 'operator-runtime-promotion-'.CarbonImmutable::now()->format('YmdHis'),
            'signed_by' => '<operator>',
            'reason' => 'Operator reviewed the current runtime graduation candidate hashes and approves runtime gap promotion without enabling execution directly.',
            'runtime_gap_matrix_hash' => $runtimePromotionMatrixHash,
            'runtime_promotion_basis_hash' => (string) data_get($matrix, 'runtime_promotion_basis_hash', ''),
            'runtime_promotion_closure_basis_hash' => $runtimePromotionClosureBasisHash,
            'promoted_gap_ids' => array_values(array_map(static fn (array $row): string => (string) ($row['gap_id'] ?? ''), $gapRows)),
            'graduation_evidence_hashes' => $graduationHashes,
            'receipt_hash' => '<operator_generated_64_hex_receipt_hash>',
            'runtime_promotion_approved' => true,
            'operator_reviewed_runtime_graduations' => true,
            'no_runtime_autopromotion_acknowledged' => true,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
        ];

        $allCandidatesReady = $gapRows !== [] && count(array_filter($gapRows, static fn (array $row): bool => (bool) ($row['runtime_y_candidate'] ?? false) === true)) === count($gapRows);
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $allCandidatesReady ? 'available' : 'blocked',
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'runtime_gap_matrix_snapshot' => [
                'status' => (string) data_get($matrix, 'status', ''),
                'runtime_gap_matrix_hash' => (string) data_get($matrix, 'runtime_gap_matrix_hash', ''),
                'expected_runtime_gap_matrix_hash_for_promotion_receipt' => $runtimePromotionMatrixHash,
                'runtime_promotion_basis_hash' => (string) data_get($matrix, 'runtime_promotion_basis_hash', ''),
                'runtime_promotion_closure_basis_hash' => $runtimePromotionClosureBasisHash,
                'runtime_gap_count' => count($gapRows),
                'runtime_y_candidate_count' => (int) data_get($matrix, 'runtime_y_candidate_count', 0),
                'runtime_enabled_count' => count(array_filter($rows, static fn (array $row): bool => (bool) ($row['runtime_enabled'] ?? false))),
                'blocked_gap_ids' => array_values(array_map(static fn (array $row): string => (string) ($row['gap_id'] ?? ''), $gapRows)),
            ],
            'graduation_candidate_evidence' => array_map(static fn (array $row): array => [
                'gap_id' => (string) ($row['gap_id'] ?? ''),
                'graduation_status' => (string) ($row['graduation_status'] ?? ''),
                'graduation_schema' => (string) ($row['graduation_schema'] ?? ''),
                'graduation_evidence_hash' => (string) ($row['graduation_evidence_hash'] ?? ''),
                'candidate_is_runtime_y' => (bool) ($row['runtime_y_candidate'] ?? false),
                'runtime_enabled' => false,
                'blockers_remaining' => (array) ($row['blockers'] ?? []),
                'why_human_promotion_receipt_is_required' => 'Runtime promotion affects completion eligibility and must be explicitly reviewed by the operator.',
            ], $gapRows),
            'promotion_receipt_preimage' => $receiptPreimage,
            'receipt_hash_instructions' => [
                'canonical_hash_service' => AtlasSelfConstructionCompletionEvidenceHashService::class,
                'method' => 'runtimePromotionReceiptHash',
                'operator_must_replace_placeholders' => true,
                'expected_hash_preview' => '',
                'receipt_hash_is_not_generated_for_placeholder_payload' => true,
            ],
            'promotion_safety_analysis' => [
                'no_runtime_autopromotion' => true,
                'runtime_enabled_count' => 0,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'adapter_execution_allowed' => false,
                'operator_signature_required' => true,
            ],
            'operator_checklist' => [
                'review_runtime_gap_matrix_hash',
                'review_runtime_promotion_basis_hash',
                'review_promoted_gap_ids',
                'review_graduation_evidence_hashes',
                'replace_placeholders',
                'compute_canonical_receipt_hash',
                'sign_runtime_promotion_receipt',
                'persist_runtime_promotion_receipt_through_verifier',
                'rerun_completion_audit',
                'refresh_terminal_loop_operational_proof',
                'rerun_completion_audit_with_terminal_loop_operational_proof',
            ],
            'terminal_loop_operational_proof_required_before_final_audit' => true,
            'terminal_loop_operational_proof_expected_binding_schema' => 'atlas.self_construction.agent_control_plane_terminal_loop_operational_proof_audit_binding_packet.v1',
            'terminal_loop_operational_proof_command' => 'php artisan atlas:ai:self-construction --agent-control-plane-terminal-loop-operational-proof-status --json',
            'completion_audit_with_terminal_loop_operational_proof_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json=@/path/to/terminal-loop-operational-proof-binding.json --json',
            'machine_verification' => [
                'status' => $allCandidatesReady ? 'available' : 'blocked',
                'dossier_hash' => '',
                'matrix_hash' => (string) data_get($matrix, 'runtime_gap_matrix_hash', ''),
                'expected_runtime_gap_matrix_hash_for_promotion_receipt' => $runtimePromotionMatrixHash,
                'basis_hash' => (string) data_get($matrix, 'runtime_promotion_basis_hash', ''),
                'closure_basis_hash' => $runtimePromotionClosureBasisHash,
                'candidate_count' => count(array_filter($gapRows, static fn (array $row): bool => (bool) ($row['runtime_y_candidate'] ?? false))),
                'gap_count' => count($gapRows),
                'runtime_y_candidate_count' => (int) data_get($matrix, 'runtime_y_candidate_count', 0),
                'runtime_enabled_count' => 0,
                'promotion_receipt_required' => count($gapRows) > 0,
                'safe_to_sign_when_operator_accepts' => $allCandidatesReady,
                'completion_claim_allowed' => false,
            ],
            'non_execution_guarantees' => [
                'runtime_promotion_evidence_dossier_does_not_persist_receipts',
                'runtime_promotion_evidence_dossier_does_not_enable_runtime',
                'runtime_promotion_evidence_dossier_does_not_start_codex',
                'runtime_promotion_evidence_dossier_does_not_call_provider',
                'runtime_promotion_evidence_dossier_does_not_dispatch_work',
                'runtime_promotion_evidence_dossier_does_not_spend_tokens',
            ],
        ];
        $payload['machine_verification']['dossier_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['machine_verification']['dossier_hash']);
        unset($payload['promotion_receipt_preimage']['receipt_id']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
}

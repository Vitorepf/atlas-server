<?php

namespace App\Services\Ai\SelfConstruction;


use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

/**
 * Atlas Self-Construction OS · Runtime Gap Matrix Audit (v1).
 *
 * Reads the existing `AtlasSelfConstructionRuntimeGapMatrixService::matrix()`
 * (which covers the four runtime-promotion gaps) plus the OS completion
 * audit (which surfaces the two human/provider gaps) and emits a single
 * auditable status payload that:
 *
 *   - enumerates every runtime gap that still keeps the OS from being
 *     declarable as complete;
 *   - classifies each gap into one of five canonical closure classes:
 *       1. `auto_closeable_by_existing_evidence`
 *       2. `closeable_by_safe_dry_run_evidence`
 *       3. `requires_operator_signed_receipt`
 *       4. `requires_real_provider_smoke`
 *       5. `must_remain_blocked`
 *   - declares, for each gap, the canonical command, acceptance criteria,
 *     expected evidence, receipts required, risks and absolute
 *     prohibitions;
 *   - emits a `runtime_gap_matrix_audit_hash` for replay/idempotency;
 *   - keeps every runtime safety flag false: never starts a process, never
 *     calls a provider, never spends tokens, never enables self-programming
 *     and never forges a human receipt.
 *
 * Schema: `atlas.self_construction.runtime_gap_matrix_audit.v1`.
 */
final class AtlasSelfConstructionRuntimeGapMatrixAuditService
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    public const SCHEMA_VERSION = 'atlas.self_construction.runtime_gap_matrix_audit.v1';

    public const MODE = 'read_only_runtime_gap_matrix_audit';

    /** @var list<string> Canonical closure classes. */
    public const CLOSURE_CLASSES = [
        'auto_closeable_by_existing_evidence',
        'closeable_by_safe_dry_run_evidence',
        'requires_operator_signed_receipt',
        'requires_real_provider_smoke',
        'must_remain_blocked',
    ];

    public const CLOSURE_AUTO = 'auto_closeable_by_existing_evidence';

    public const CLOSURE_SAFE_DRY_RUN = 'closeable_by_safe_dry_run_evidence';

    public const CLOSURE_OPERATOR_RECEIPT = 'requires_operator_signed_receipt';

    public const CLOSURE_REAL_PROVIDER_SMOKE = 'requires_real_provider_smoke';

    public const CLOSURE_MUST_REMAIN_BLOCKED = 'must_remain_blocked';

    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $readiness,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function audit(array $options = []): array
    {
        $matrix = (new AtlasSelfConstructionRuntimeGapMatrixService($this->readiness))->matrix();
        $completionAudit = (new AtlasSelfConstructionOsCompletionAuditService($this->readiness))->audit();

        $gaps = $this->buildGaps($matrix, $completionAudit);

        $byClass = [];
        foreach (self::CLOSURE_CLASSES as $class) {
            $byClass[$class] = [];
        }
        foreach ($gaps as $gap) {
            $byClass[$gap['closure_class']][] = $gap['gap_id'];
        }

        $autoCloseable = array_values(array_filter(
            $gaps,
            static fn (array $g): bool => $g['closure_class'] === self::CLOSURE_AUTO && (bool) ($g['auto_closable_locally'] ?? false),
        ));
        $stillOpen = array_values(array_filter(
            $gaps,
            static fn (array $g): bool => ($g['runtime_y'] ?? false) !== true,
        ));

        $completionAllowed = (bool) data_get($completionAudit, 'completion_allowed', false);
        $allRuntimeY = (bool) data_get($matrix, 'all_runtime_y', false);
        $humanReceipt = $this->humanReceiptPresent($completionAudit);
        $smokeGreen = $this->realProviderSmokeGreen($completionAudit);

        // OS-complete promotion is only allowed when every gap is closed by
        // its declared mechanism. We re-derive this honestly from the gaps —
        // never trust a single `passed` flag elsewhere.
        $osCompletePromotionAllowed = $allRuntimeY && $humanReceipt && $smokeGreen && $completionAllowed && $stillOpen === [];

        $blockers = [];
        if (! $allRuntimeY) {
            $blockers[] = 'runtime_gap_matrix_has_blocked_rows';
        }
        if (! $humanReceipt) {
            $blockers[] = 'human_signed_os_complete_receipt_missing';
        }
        if (! $smokeGreen) {
            $blockers[] = 'real_provider_smoke_not_green';
        }
        if (! $completionAllowed) {
            $blockers[] = 'completion_audit_did_not_allow_completion';
        }

        $status = $stillOpen === [] && $blockers === [] ? 'passed' : 'blocked';
        $implementationPacketCommandSurface = $this->implementationPacketCommandSurface($gaps);

        // Worker-safe closure classes only — never generate an impossible worker packet for a gap
        // that can only be closed by an operator-signed receipt or a real-provider smoke run.
        $workerSafeClasses = [self::CLOSURE_AUTO, self::CLOSURE_SAFE_DRY_RUN];
        $handoffClasses = [self::CLOSURE_OPERATOR_RECEIPT, self::CLOSURE_REAL_PROVIDER_SMOKE];
        $workerPacketCandidates = array_values(array_filter(
            $gaps,
            static fn (array $g): bool => in_array($g['closure_class'], $workerSafeClasses, true),
        ));
        $operatorOrProviderHandoffGaps = array_values(array_filter(
            $gaps,
            static fn (array $g): bool => in_array($g['closure_class'], $handoffClasses, true),
        ));

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'audited_at' => CarbonImmutable::now()->toIso8601String(),
            'os_complete_promotion_allowed' => $osCompletePromotionAllowed,
            'completion_allowed' => $completionAllowed,
            'completion_audit_status' => (string) data_get($completionAudit, 'status', 'unknown'),
            'runtime_gap_matrix_status' => (string) data_get($matrix, 'status', 'unknown'),
            'runtime_gap_matrix_hash' => (string) data_get($matrix, 'runtime_gap_matrix_hash', ''),
            'expected_runtime_gap_matrix_hash_for_promotion_receipt' => (string) data_get($matrix, 'expected_runtime_gap_matrix_hash_for_promotion_receipt', ''),
            'runtime_promotion_basis_hash' => (string) data_get($matrix, 'runtime_promotion_basis_hash', ''),
            'runtime_promotion_closure_basis_hash' => (string) data_get($matrix, 'runtime_promotion_closure_basis_hash', ''),
            'all_runtime_y' => $allRuntimeY,
            'runtime_gap_count' => (int) data_get($matrix, 'runtime_gap_count', 0),
            'runtime_y_count' => (int) data_get($matrix, 'runtime_y_count', 0),
            'runtime_y_candidate_count' => (int) data_get($matrix, 'runtime_y_candidate_count', 0),
            'blocked_gap_ids' => (array) data_get($matrix, 'blocked_gap_ids', []),
            'graduation_candidate_gap_ids' => (array) data_get($matrix, 'graduation_candidate_gap_ids', []),
            'current_required_operator_artifact' => $allRuntimeY ? 'real_provider_smoke' : 'runtime_promotion_receipt',
            'operator_next_action_command' => $allRuntimeY
                ? 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-draft-status --json'
                : 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-receipt-draft-status --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
            'operator_next_action_persist_command' => $allRuntimeY
                ? 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --persist-completion-evidence --json'
                : 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --persist-runtime-promotion-receipt --json',
            'human_signed_os_complete_receipt_present' => $humanReceipt,
            'real_provider_smoke_green' => $smokeGreen,
            'gap_count' => count($gaps),
            'still_open_count' => count($stillOpen),
            'auto_closeable_locally_count' => count($autoCloseable),
            'closure_class_index' => $byClass,
            'gaps' => $gaps,
            'worker_packet_candidates' => $workerPacketCandidates,
            'operator_or_provider_handoff_gaps' => $operatorOrProviderHandoffGaps,
            'implementation_packet_command_surface' => $implementationPacketCommandSurface,
            'blockers' => $blockers,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'completion_real_allowed' => false,
            'non_execution_guarantees' => [
                'runtime_gap_matrix_audit_does_not_start_codex',
                'runtime_gap_matrix_audit_does_not_call_codex_cli_or_app',
                'runtime_gap_matrix_audit_does_not_spawn_subprocess',
                'runtime_gap_matrix_audit_does_not_call_provider',
                'runtime_gap_matrix_audit_does_not_dispatch_work',
                'runtime_gap_matrix_audit_does_not_spend_tokens',
                'runtime_gap_matrix_audit_does_not_enable_self_programming',
                'runtime_gap_matrix_audit_does_not_write_ledger',
                'runtime_gap_matrix_audit_does_not_mutate_pointer',
                'runtime_gap_matrix_audit_does_not_forge_human_receipt',
                'runtime_gap_matrix_audit_does_not_mark_real_completion',
            ],
            'next_required_slice' => (string) data_get($completionAudit, 'next_required_slice', data_get($matrix, 'next_required_slice', '')),
            'note' => $osCompletePromotionAllowed
                ? 'All gaps closed — OS-complete promotion may be reviewed by the operator. Receipts must still be signed.'
                : 'Runtime Gap Matrix audit is honest: gaps still open are listed with closure_class, command, acceptance, evidence and receipts.',
        ];
        $payload['runtime_gap_matrix_audit_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /**
     * Builds the canonical 6-gap audit by composing matrix rows + completion
     * audit criteria.
     *
     * @param  array<string, mixed>  $matrix
     * @param  array<string, mixed>  $completionAudit
     * @return list<array<string, mixed>>
     */
    private function buildGaps(array $matrix, array $completionAudit): array
    {
        $rows = (array) data_get($matrix, 'rows', []);
        $gaps = [];
        foreach ($rows as $row) {
            $gaps[] = $this->annotateMatrixRow($row);
        }
        $gaps[] = $this->humanReceiptGap($completionAudit);
        $gaps[] = $this->realProviderSmokeGap($completionAudit);

        return $gaps;
    }

    /**
     * Wrap a runtime gap matrix row with the closure-class metadata and the
     * implementation packet block the operator needs to read.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function annotateMatrixRow(array $row): array
    {
        $gapId = (string) ($row['gap_id'] ?? '');
        $runtimeY = (bool) ($row['runtime_y'] ?? false);
        $candidate = (bool) ($row['runtime_y_candidate'] ?? false);
        $graduationStatus = (string) ($row['graduation_status'] ?? 'missing');
        $blockers = (array) ($row['blockers'] ?? []);

        // Closure-class decision tree (matrix-row gaps):
        //   - if already runtime_y                → auto_closeable_by_existing_evidence
        //   - else if has graduation candidate    → closeable_by_safe_dry_run_evidence
        //   - else                                → requires_operator_signed_receipt
        //     (operator must sign the promotion receipt that the matrix
        //     expects). The matrix never promotes by itself.
        $closureClass = match (true) {
            $runtimeY => self::CLOSURE_AUTO,
            $candidate => self::CLOSURE_SAFE_DRY_RUN,
            default => self::CLOSURE_OPERATOR_RECEIPT,
        };

        return [
            'gap_id' => $gapId,
            'origin' => 'runtime_gap_matrix_row',
            'runtime_y' => $runtimeY,
            'runtime_y_candidate' => $candidate,
            'graduation_status' => $graduationStatus,
            'graduation_evidence_hash' => (string) ($row['graduation_evidence_hash'] ?? ''),
            'required_promotion' => (string) ($row['required_promotion'] ?? ''),
            'blockers' => array_values(array_map('strval', $blockers)),
            'closure_class' => $closureClass,
            'auto_closable_locally' => $runtimeY,
            'implementation_packet' => $this->matrixRowImplementationPacket($gapId, $closureClass, $candidate),
        ];
    }

    /**
     * @param  array<string, mixed>  $completionAudit
     * @return array<string, mixed>
     */
    private function humanReceiptGap(array $completionAudit): array
    {
        $criterion = $this->criterion($completionAudit, 'human_signed_os_complete_receipt_present');
        $present = (bool) ($criterion['passed'] ?? false);

        return [
            'gap_id' => 'human_signed_os_complete_receipt_present',
            'origin' => 'completion_audit_criterion',
            'runtime_y' => $present,
            'runtime_y_candidate' => false,
            'graduation_status' => $present ? 'passed' : 'requires_operator_signed_receipt',
            'graduation_evidence_hash' => (string) data_get($criterion, 'evidence.receipt_hash', ''),
            'required_promotion' => 'human_signed_os_complete_receipt_persisted_and_verified',
            'blockers' => $present ? [] : ['operator_must_sign_os_complete_receipt'],
            'closure_class' => $present ? self::CLOSURE_AUTO : self::CLOSURE_OPERATOR_RECEIPT,
            'auto_closable_locally' => false,
            'implementation_packet' => $this->humanReceiptImplementationPacket($present),
        ];
    }

    /**
     * @param  array<string, mixed>  $completionAudit
     * @return array<string, mixed>
     */
    private function realProviderSmokeGap(array $completionAudit): array
    {
        $criterion = $this->criterion($completionAudit, 'end_to_end_real_provider_smoke_green');
        $green = (bool) ($criterion['passed'] ?? false);

        return [
            'gap_id' => 'end_to_end_real_provider_smoke_green',
            'origin' => 'completion_audit_criterion',
            'runtime_y' => $green,
            'runtime_y_candidate' => false,
            'graduation_status' => $green ? 'passed' : 'requires_real_provider_smoke',
            'graduation_evidence_hash' => (string) data_get($criterion, 'evidence.smoke_evidence_hash', ''),
            'required_promotion' => 'one_packet_claim_to_completion_real_provider_evidence',
            'blockers' => $green ? [] : ['real_provider_smoke_missing_or_not_green'],
            'closure_class' => $green ? self::CLOSURE_AUTO : self::CLOSURE_REAL_PROVIDER_SMOKE,
            'auto_closable_locally' => false,
            'implementation_packet' => $this->realProviderSmokeImplementationPacket($green),
        ];
    }

    /**
     * @param  array<string, mixed>  $completionAudit
     * @return array<string, mixed>|null
     */
    private function criterion(array $completionAudit, string $id): ?array
    {
        foreach ((array) data_get($completionAudit, 'criteria', []) as $criterion) {
            if (is_array($criterion) && (string) ($criterion['id'] ?? '') === $id) {
                return $criterion;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $completionAudit
     */
    private function humanReceiptPresent(array $completionAudit): bool
    {
        $criterion = $this->criterion($completionAudit, 'human_signed_os_complete_receipt_present');

        return (bool) ($criterion['passed'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $completionAudit
     */
    private function realProviderSmokeGreen(array $completionAudit): bool
    {
        $criterion = $this->criterion($completionAudit, 'end_to_end_real_provider_smoke_green');

        return (bool) ($criterion['passed'] ?? false);
    }

    /**
     * Implementation packet (commands, criteria, evidence, receipts, risks,
     * prohibitions) for a Runtime Gap Matrix row. The closure class drives
     * the variant: dry-run runs already exist (read-only); operator-receipt
     * gates need a signed receipt from the operator before the matrix admits
     * promotion.
     *
     * @return array<string, mixed>
     */
    private function matrixRowImplementationPacket(string $gapId, string $closureClass, bool $candidate): array
    {
        $base = [
            'gap_id' => $gapId,
            'closure_class' => $closureClass,
            'absolute_prohibitions' => [
                'do_not_start_codex',
                'do_not_call_codex_cli_or_app',
                'do_not_spawn_subprocess',
                'do_not_invoke_adapter',
                'do_not_call_provider',
                'do_not_dispatch_work',
                'do_not_spend_tokens',
                'do_not_enable_self_programming',
                'do_not_write_ledger',
                'do_not_mutate_pointer',
                'do_not_forge_human_receipt',
            ],
        ];

        if ($closureClass === self::CLOSURE_AUTO) {
            return $base + [
                'commands' => [
                    'php artisan atlas:ai:self-construction --atlas-self-construction-os-runtime-gap-matrix-audit-status --json',
                ],
                'acceptance_criteria' => [
                    'matrix row has runtime_y=true with cited evidence_hash',
                ],
                'expected_evidence' => [
                    'matrix.rows[gap_id='.$gapId.'].evidence_hash != ""',
                ],
                'receipts_required' => [],
                'risks' => ['none_at_this_stage_other_than_drift_if_underlying_status_regresses'],
            ];
        }

        if ($closureClass === self::CLOSURE_SAFE_DRY_RUN) {
            $statusFlag = $this->dryRunStatusFlagFor($gapId);

            return $base + [
                'commands' => array_filter([
                    $statusFlag === '' ? null : sprintf('php artisan atlas:ai:self-construction %s --json', $statusFlag),
                    'php artisan atlas:ai:self-construction --atlas-self-construction-os-runtime-gap-matrix-audit-status --json',
                ]),
                'acceptance_criteria' => [
                    'graduation_status=passed for gap_id='.$gapId,
                    'matrix row reports runtime_y_candidate=true',
                ],
                'expected_evidence' => [
                    'graduation_evidence_hash != "" for gap_id='.$gapId,
                ],
                'receipts_required' => [
                    'runtime_promotion_receipt (operator must sign through the existing runtime promotion receipt service)',
                ],
                'risks' => [
                    'dry-run candidate may not represent real provider behaviour — operator must sign promotion receipt before runtime_y is true',
                ],
            ];
        }

        // requires_operator_signed_receipt (matrix row but no candidate yet)
        return $base + [
            'commands' => [
                'php artisan atlas:ai:self-construction --atlas-self-construction-os-runtime-gap-matrix-audit-status --json',
                'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-receipt-draft-status --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
            ],
            'acceptance_criteria' => [
                'operator-signed runtime promotion receipt persisted',
                'matrix row admits runtime_y=true only after receipt verification',
            ],
            'expected_evidence' => [
                'runtime_promotion_receipt.status = passed',
                'graduation_evidence_hash != ""',
            ],
            'receipts_required' => [
                'operator_signed_runtime_promotion_receipt',
            ],
            'risks' => [
                'no graduation candidate yet — implementation must produce a safe dry-run candidate first',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function humanReceiptImplementationPacket(bool $present): array
    {
        $packet = [
            'gap_id' => 'human_signed_os_complete_receipt_present',
            'closure_class' => $present ? self::CLOSURE_AUTO : self::CLOSURE_OPERATOR_RECEIPT,
            'absolute_prohibitions' => [
                'do_not_forge_human_receipt',
                'do_not_synthesise_operator_signature',
                'do_not_mark_os_complete_without_human_receipt',
                'do_not_call_provider_or_dispatch_anything',
                'do_not_enable_self_programming',
            ],
        ];

        if ($present) {
            return $packet + [
                'commands' => ['php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json'],
                'acceptance_criteria' => ['criterion.human_signed_os_complete_receipt_present.passed = true'],
                'expected_evidence' => ['os_complete_receipt_hash != ""'],
                'receipts_required' => [],
                'risks' => ['none — receipt already present'],
            ];
        }

        return $packet + [
            'commands' => [
                'php artisan atlas:ai:self-construction --atlas-self-construction-human-completion-receipt-closure-execution-pack-status --json',
                'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-submission-readiness-status --json',
                '// then: operator must physically sign the OS-complete receipt and submit it via the documented channel',
            ],
            'acceptance_criteria' => [
                'operator-signed OS-complete receipt persisted',
                'receipt verification yields passed status',
            ],
            'expected_evidence' => [
                'human_completion_receipt_closure_execution_pack hash recorded',
                'os_complete_receipt persisted with operator signature',
            ],
            'receipts_required' => [
                'human_signed_os_complete_receipt',
            ],
            'risks' => [
                'this gap is human-gated; no automation can or should close it',
                'forging or simulating the receipt would invalidate the entire OS completion claim',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function realProviderSmokeImplementationPacket(bool $green): array
    {
        $packet = [
            'gap_id' => 'end_to_end_real_provider_smoke_green',
            'closure_class' => $green ? self::CLOSURE_AUTO : self::CLOSURE_REAL_PROVIDER_SMOKE,
            'absolute_prohibitions' => [
                'do_not_start_codex_without_signed_release_chain',
                'do_not_call_codex_cli_or_app_without_release_receipt',
                'do_not_spawn_subprocess_without_signed_supervised_start',
                'do_not_invoke_provider_outside_signed_release_chain',
                'do_not_mark_smoke_green_without_real_provider_evidence',
                'do_not_forge_smoke_evidence',
            ],
        ];

        if ($green) {
            return $packet + [
                'commands' => ['php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json'],
                'acceptance_criteria' => ['criterion.end_to_end_real_provider_smoke_green.passed = true'],
                'expected_evidence' => ['smoke_evidence_hash != ""'],
                'receipts_required' => [],
                'risks' => ['regression: any change to the provider chain must re-run the smoke'],
            ];
        }

        return $packet + [
            'commands' => [
                'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-receipt-draft-status --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
                'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-receipt-runbook-status --json',
                'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-runbook-status --json',
                '// then: operator must run the SIGNED release chain through the agent-control-plane gates,',
                '// from one-shot scheduler tick release receipt → process spawn enablement → final spawn executor → external runtime driver.',
                '// THIS AUDIT DOES NOT INITIATE ANY OF THOSE STEPS.',
            ],
            'acceptance_criteria' => [
                'one packet runs claim → completion through a real provider with end-to-end evidence',
                'smoke evidence is cited by the completion audit',
            ],
            'expected_evidence' => [
                'real_provider_smoke_evidence_hash recorded',
                'agent run reaches a terminal state via the signed release chain',
            ],
            'receipts_required' => [
                'signed_one_shot_scheduler_tick_release_receipt',
                'signed_codex_process_start_release_receipt',
                'signed_codex_process_spawn_enablement_receipt',
            ],
            'risks' => [
                'this gap requires a REAL provider call — only the operator may authorise it',
                'forging smoke evidence is a hard violation of the Self-Construction OS canon',
            ],
        ];
    }

    private function dryRunStatusFlagFor(string $gapId): string
    {
        return match ($gapId) {
            'adapter_execution_runtime' => '--agent-control-plane-adapter-execution-runtime-boundary-status',
            'automatic_cost_import_runtime' => '--agent-control-plane-automatic-cost-import-runtime-status',
            'automatic_work_product_collection_runtime' => '--agent-control-plane-automatic-work-product-collection-runtime-status',
            'automatic_dispatch_scheduler_codex_real_invoker_post_start_receipt_contract_runtime' => '--agent-control-plane-dispatch-scheduler-receipt-runtime-reentry-closure',
            default => '',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $gaps
     * @return array<string, mixed>
     */
    private function implementationPacketCommandSurface(array $gaps): array
    {
        $definition = Artisan::all()['atlas:ai:self-construction']->getDefinition();
        $deprecatedOptionAliases = [
            'runtime-gap-matrix',
            'runtime-promotion-receipt-draft',
            'runtime-promotion-receipt-runbook',
            'human-completion-receipt-closure-execution-pack',
            'operator-evidence-submission-readiness',
        ];
        $commands = [];
        $missingOptions = [];
        $legacyAliasHits = [];

        foreach ($gaps as $gap) {
            foreach ((array) data_get($gap, 'implementation_packet.commands', []) as $command) {
                $command = (string) $command;
                if (! str_starts_with($command, 'php artisan atlas:ai:self-construction ')) {
                    continue;
                }

                $options = $this->extractCommandOptions($command);
                $missing = [];
                $deprecatedAliases = [];
                foreach ($options as $option) {
                    if (! $definition->hasOption($option)) {
                        $missing[] = $option;
                        $missingOptions[] = $option;
                    }
                    if (in_array($option, $deprecatedOptionAliases, true)) {
                        $deprecatedAliases[] = $option;
                        $legacyAliasHits[] = $option;
                    }
                }

                $commands[] = [
                    'gap_id' => (string) ($gap['gap_id'] ?? ''),
                    'command' => $command,
                    'options' => $options,
                    'option_count' => count($options),
                    'all_options_available' => $missing === [],
                    'missing_options' => array_values(array_unique($missing)),
                    'legacy_aliases_detected' => array_values(array_unique($deprecatedAliases)),
                ];
            }
        }

        $missingOptions = array_values(array_unique($missingOptions));
        $legacyAliasHits = array_values(array_unique($legacyAliasHits));
        $surface = [
            'schema_version' => 'atlas.self_construction.runtime_gap_matrix_audit.command_surface.v1',
            'status' => $missingOptions === [] && $legacyAliasHits === [] ? 'available' : 'blocked',
            'all_commands_available' => $missingOptions === [],
            'legacy_alias_free' => $legacyAliasHits === [],
            'command_count' => count($commands),
            'missing_option_count' => count($missingOptions),
            'missing_options' => $missingOptions,
            'legacy_alias_count' => count($legacyAliasHits),
            'legacy_aliases_detected' => $legacyAliasHits,
            'commands' => $commands,
        ];
        $surface['command_surface_hash'] = $this->stableHash($surface);

        return $surface;
    }

    /**
     * @return list<string>
     */
    private function extractCommandOptions(string $command): array
    {
        preg_match_all('/(?:^|\s)--([A-Za-z0-9][A-Za-z0-9-]*)(?=\s|=|$)/', $command, $matches);

        return array_values(array_unique(array_map('strval', $matches[1] ?? [])));
    }

    private function stableHash(array $payload): string
    {
        unset($payload['audited_at'], $payload['runtime_gap_matrix_audit_hash'], $payload['command_surface_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

}

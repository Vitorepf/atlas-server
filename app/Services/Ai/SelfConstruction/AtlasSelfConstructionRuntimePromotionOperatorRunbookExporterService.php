<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * Runtime Promotion Operator Runbook Exporter v1.
 *
 * Generates an operator-legible Markdown runbook plus a machine-friendly JSON
 * summary describing how to close the `runtime_gap_matrix_all_runtime_y`
 * blocker. By default everything stays in memory; persistence is gated by the
 * explicit `persist_export=true` option and writes only to the local storage
 * disk under a sandboxed prefix. Never enables runtime, never persists the
 * runtime promotion receipt itself, never calls providers.
 */
final class AtlasSelfConstructionRuntimePromotionOperatorRunbookExporterService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.runtime_promotion_operator_runbook_exporter.v1';

    public const MODE = 'read_only_runtime_promotion_operator_runbook_exporter';

    private const STORAGE_DISK = 'local';

    private const STORAGE_PREFIX = 'atlas/self-construction/runtime-promotion/operator-runbook-exports';

    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $readiness,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function build(array $options = []): array
    {
        $persistExport = (bool) ($options['persist_export'] ?? false);

        $endgame = (new AtlasSelfConstructionRuntimePromotionEndgameService($this->readiness))->build([
            'signed_by' => (string) ($options['signed_by'] ?? ''),
            'reason' => (string) ($options['reason'] ?? ''),
            'runtime_promotion_receipt' => (array) ($options['runtime_promotion_receipt'] ?? []),
        ]);

        $machineSummary = $this->buildMachineSummary($endgame);
        $markdown = $this->buildMarkdown($endgame, $machineSummary);
        $markdownHash = hash('sha256', $markdown);
        $machineSummaryHash = hash('sha256', (string) json_encode($machineSummary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $exportPath = '';
        $persisted = false;
        if ($persistExport) {
            $timestamp = CarbonImmutable::now()->format('Ymd-His');
            $exportPath = self::STORAGE_PREFIX.'/runbook-'.$timestamp.'-'.substr($markdownHash, 0, 12).'.md';
            Storage::disk(self::STORAGE_DISK)->put($exportPath, $markdown);
            $persisted = true;
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $persisted ? 'exported' : 'available_in_memory_only',
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'persist_export_requested' => $persistExport,
            'persist' => $persisted,
            'export_path' => $exportPath,
            'storage_disk' => self::STORAGE_DISK,
            'storage_prefix' => self::STORAGE_PREFIX,
            'markdown' => $markdown,
            'markdown_hash' => $markdownHash,
            'machine_summary' => $machineSummary,
            'machine_summary_hash' => $machineSummaryHash,
            'endgame_status' => (string) data_get($endgame, 'status', 'unknown'),
            'endgame_hash' => (string) data_get($endgame, 'endgame_hash', ''),
            'completion_allowed' => false,
            'completion_claim_allowed' => false,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'non_execution_guarantees' => [
                'operator_runbook_exporter_does_not_enable_runtime',
                'operator_runbook_exporter_does_not_persist_receipt',
                'operator_runbook_exporter_does_not_sign_for_operator',
                'operator_runbook_exporter_does_not_call_provider',
                'operator_runbook_exporter_does_not_spend_tokens',
                'operator_runbook_exporter_does_not_dispatch',
                'operator_runbook_exporter_does_not_start_process',
                'operator_runbook_exporter_does_not_promote_completion',
                'operator_runbook_exporter_only_persists_when_persist_export_true',
            ],
        ];
        $payload['exporter_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @param array<string, mixed> $endgame */
    private function buildMachineSummary(array $endgame): array
    {
        return [
            'current_blockers' => (array) data_get($endgame, 'blocked_gap_ids', []),
            'runtime_gap_count' => (int) data_get($endgame, 'runtime_gap_count', 0),
            'current_hashes' => [
                'runtime_gap_matrix_hash' => (string) data_get($endgame, 'current_runtime_gap_matrix_hash', ''),
                'expected_runtime_gap_matrix_hash_for_promotion_receipt' => (string) data_get($endgame, 'expected_runtime_gap_matrix_hash_for_promotion_receipt', ''),
                'runtime_promotion_basis_hash' => (string) data_get($endgame, 'runtime_promotion_basis_hash', ''),
                'runtime_promotion_closure_basis_hash' => (string) data_get($endgame, 'runtime_promotion_closure_basis_hash', ''),
                'closure_pack_hash' => (string) data_get($endgame, 'closure_pack.closure_pack_hash', ''),
                'closure_pack_verification_hash' => (string) data_get($endgame, 'closure_pack_verification.verification_hash', ''),
                'receipt_template_hash' => (string) data_get($endgame, 'receipt_template.template_hash', ''),
            ],
            'graduation_evidence_hashes' => (array) data_get($endgame, 'graduation_evidence_hashes', []),
            'receipt_template_preimage' => (array) data_get($endgame, 'receipt_template.preimage', []),
            'commands' => [
                'compute_receipt_hash' => 'php artisan atlas:ai:self-construction --atlas-self-construction-completion-evidence-hash-composer-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --json',
                'pre_submission_verify' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-endgame-verifier-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --json',
                'persist_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --persist-runtime-promotion-receipt --json',
                'rerun_runtime_gap_matrix' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-gap-matrix --json',
                'rerun_completion_audit' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
                'inspect_endgame_status' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-endgame-status --json',
            ],
            'stop_conditions' => [
                'stop_if_runtime_enabled_flags_true_anywhere_in_payload',
                'stop_if_signed_by_is_placeholder_or_empty',
                'stop_if_receipt_hash_does_not_match_canonical_recomputation',
                'stop_if_runtime_gap_matrix_hash_drifted_since_signing',
                'stop_if_runtime_promotion_closure_basis_hash_drifted_since_signing',
                'stop_if_promoted_gap_ids_no_longer_match_blocked_gap_ids',
                'stop_if_any_required_acknowledgement_is_false_or_missing',
            ],
            'non_execution_guarantees' => (array) data_get($endgame, 'non_execution_guarantees', []),
            'completion_audit_status' => [
                'status' => (string) data_get($endgame, 'completion_audit_status.status', 'unknown'),
                'failed_criteria' => (array) data_get($endgame, 'completion_audit_status.failed_criteria', []),
                'runtime_gap_matrix_all_runtime_y_passed' => (bool) data_get($endgame, 'completion_audit_status.runtime_gap_matrix_all_runtime_y_passed', false),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $endgame
     * @param  array<string, mixed>  $machine
     */
    private function buildMarkdown(array $endgame, array $machine): string
    {
        $blockers = (array) data_get($machine, 'current_blockers', []);
        $hashes = (array) data_get($machine, 'current_hashes', []);
        $graduation = (array) data_get($machine, 'graduation_evidence_hashes', []);
        $template = (array) data_get($machine, 'receipt_template_preimage', []);
        $commands = (array) data_get($machine, 'commands', []);
        $stopConditions = (array) data_get($machine, 'stop_conditions', []);
        $guarantees = (array) data_get($machine, 'non_execution_guarantees', []);

        $lines = [];
        $lines[] = '# Runtime Promotion Operator Runbook v1';
        $lines[] = '';
        $lines[] = '> Read-only operator playbook for closing the Atlas Self-Construction OS blocker';
        $lines[] = '> `runtime_gap_matrix_all_runtime_y`. This runbook never persists a receipt, never';
        $lines[] = '> signs for the operator, never calls a provider, never spends tokens.';
        $lines[] = '';
        $lines[] = '## Endgame status';
        $lines[] = '';
        $lines[] = '- **endgame status**: `'.((string) data_get($endgame, 'status', 'unknown')).'`';
        $lines[] = '- **endgame_hash**: `'.((string) data_get($endgame, 'endgame_hash', '')).'`';
        $lines[] = '- **completion_audit status**: `'.((string) data_get($machine, 'completion_audit_status.status', 'unknown')).'`';
        $lines[] = '- **runtime_gap_matrix_all_runtime_y passed**: '.(((bool) data_get($machine, 'completion_audit_status.runtime_gap_matrix_all_runtime_y_passed', false)) ? '`true`' : '`false`');
        $lines[] = '';
        $lines[] = '## Current Blockers';
        $lines[] = '';
        if ($blockers === []) {
            $lines[] = '_No blocked runtime gaps detected in the current matrix. If the audit still fails on';
            $lines[] = '`runtime_gap_matrix_all_runtime_y`, capture a fresh replay snapshot and rerun the audit._';
        } else {
            foreach ($blockers as $gapId) {
                $lines[] = '- `'.$gapId.'` — graduation_evidence_hash `'.((string) ($graduation[$gapId] ?? '')).'`';
            }
        }
        $lines[] = '';
        $lines[] = '## Current Hashes';
        $lines[] = '';
        foreach ([
            'runtime_gap_matrix_hash',
            'expected_runtime_gap_matrix_hash_for_promotion_receipt',
            'runtime_promotion_basis_hash',
            'runtime_promotion_closure_basis_hash',
            'closure_pack_hash',
            'closure_pack_verification_hash',
            'receipt_template_hash',
        ] as $key) {
            $lines[] = '- `'.$key.'` = `'.((string) ($hashes[$key] ?? '')).'`';
        }
        $lines[] = '';
        $lines[] = '## Receipt Template';
        $lines[] = '';
        $lines[] = 'Replace every `<placeholder>` with the real value before computing the receipt hash.';
        $lines[] = '';
        $lines[] = '```json';
        $lines[] = (string) json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '## How To Compute Receipt Hash';
        $lines[] = '';
        $lines[] = '1. Fill the template above with real values (operator id, reason ≥ 32 chars, real receipt_id).';
        $lines[] = '2. Set `receipt_hash` to an empty string for the canonical computation.';
        $lines[] = '3. Run:';
        $lines[] = '';
        $lines[] = '   ```bash';
        $lines[] = '   '.((string) ($commands['compute_receipt_hash'] ?? '')).'';
        $lines[] = '   ```';
        $lines[] = '';
        $lines[] = '4. Copy the resulting hash back into the payload as `receipt_hash`.';
        $lines[] = '';
        $lines[] = '## How To Verify';
        $lines[] = '';
        $lines[] = 'Always run the Endgame Verifier first; it lists every violation per rule.';
        $lines[] = '';
        $lines[] = '```bash';
        $lines[] = (string) ($commands['pre_submission_verify'] ?? '');
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '## How To Persist';
        $lines[] = '';
        $lines[] = 'Persistence requires `--persist-runtime-promotion-receipt` AND the canonical verifier green.';
        $lines[] = '';
        $lines[] = '```bash';
        $lines[] = (string) ($commands['persist_receipt'] ?? '');
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '## How To Rerun Audit';
        $lines[] = '';
        $lines[] = '```bash';
        $lines[] = (string) ($commands['rerun_runtime_gap_matrix'] ?? '');
        $lines[] = (string) ($commands['rerun_completion_audit'] ?? '');
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '## Stop Conditions';
        $lines[] = '';
        foreach ($stopConditions as $stop) {
            $lines[] = '- `'.$stop.'`';
        }
        $lines[] = '';
        $lines[] = '## Non-Execution Guarantees';
        $lines[] = '';
        foreach ($guarantees as $guarantee) {
            $lines[] = '- `'.$guarantee.'`';
        }
        $lines[] = '';
        $lines[] = '_Generated by Atlas Self-Construction Runtime Promotion Operator Runbook Exporter v1._';

        return implode("\n", $lines)."\n";
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['exporter_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
    private function ksortRecursive(array $value): array
    {
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->ksortRecursive($entry);
            }
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }

        return $value;
    }
}

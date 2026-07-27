<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Support;

/**
 * Verifier context for the operator's final completion receipt.
 *
 * Pure projection: given the completion audit and its evidence, resolve the
 * hashes the operator signs against. It was duplicated byte-for-byte in the
 * human gate and the closure execution pack — two copies of the contract the
 * signature is bound to, which is the one place drift must not happen.
 */
final class CompletionVerifierContextSupport
{
    public static function verifierContext(array $completionAudit, array $completionEvidence): array
    {
        $criterion = function (string $id) use ($completionAudit): array {
            foreach ((array) data_get($completionAudit, 'criteria', []) as $row) {
                if ((string) ($row['id'] ?? '') === $id) {
                    return (array) $row;
                }
            }

            return [];
        };

        $runtime = $criterion('runtime_gap_matrix_all_runtime_y');
        $release = $criterion('release_dossier_green');
        $replay = $criterion('replay_diff_against_completion_snapshot_green');
        $smoke = $criterion('end_to_end_real_provider_smoke_green');
        $batch = $criterion('certification_status_batch_green');

        return [
            'completion_audit_hash' => (string) data_get($completionAudit, 'completion_audit_hash', ''),
            'release_dossier_hash' => (string) data_get($release, 'evidence.hash', ''),
            'replay_diff_hash' => (string) data_get($replay, 'evidence.diff_hash', ''),
            'runtime_gap_matrix_hash' => (string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_gap_matrix_hash', data_get($runtime, 'evidence.runtime_gap_matrix_hash', '')),
            'runtime_promotion_receipt_hash' => (string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_promotion_receipt.receipt_hash', ''),
            'real_provider_smoke_hash' => (string) data_get($completionEvidence, 'real_provider_smoke.smoke_hash', data_get($smoke, 'evidence.smoke_hash', '')),
            'certification_status_batch_hash' => (string) data_get($batch, 'evidence.hash', data_get($completionAudit, 'operator_action_packet.human_completion_receipt_template.certification_status_batch_hash', '')),
        ];
    }
}

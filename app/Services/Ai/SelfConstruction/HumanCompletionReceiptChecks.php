<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

/**
 * Validacoes compartilhadas do human-completion-receipt (prerequisitos, signer
 * placeholder, hash stale) — viviam clonadas entre o verifier de pre-submissao e o
 * de endgame (79 linhas no jscpd 05/07): correcao de validacao de receipt era
 * aplicada pela metade.
 */
trait HumanCompletionReceiptChecks
{
    private function failedPrerequisites(array $context): array
    {
        $failed = [];
        $prerequisites = (array) ($context['prerequisites'] ?? []);
        foreach ($prerequisites as $name => $row) {
            if (! is_array($row)) {
                continue;
            }
            if ((bool) ($row['green'] ?? false) !== true) {
                $failed[] = (string) $name;
            }
        }

        return $failed;
    }

    private function isPlaceholderSigner(string $signedBy): bool
    {
        $normalized = mb_strtolower(trim($signedBy));

        return in_array($normalized, self::PLACEHOLDER_SIGNERS, true);
    }

    private function staleHashCode(string $field): string
    {
        return match ($field) {
            'completion_audit_hash' => 'stale_completion_audit_hash',
            'release_dossier_hash' => 'stale_release_dossier_hash',
            'replay_diff_hash' => 'stale_replay_diff_hash',
            'runtime_gap_matrix_hash' => 'stale_runtime_gap_matrix_hash',
            'runtime_promotion_receipt_hash' => 'stale_runtime_promotion_receipt_hash',
            'real_provider_smoke_hash' => 'stale_real_provider_smoke_hash',
            'certification_status_batch_hash' => 'stale_certification_status_batch_hash',
            default => 'stale_evidence_hash',
        };
    }
}

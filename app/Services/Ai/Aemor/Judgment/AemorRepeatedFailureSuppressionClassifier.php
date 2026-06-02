<?php

declare(strict_types=1);

namespace App\Services\Ai\Aemor\Judgment;

final class AemorRepeatedFailureSuppressionClassifier
{
    private const SCHEMA_VERSION = 'atlas.aemor.repeated_failure_suppression.v1';

    /**
     * Pure mirror of AtlasAemorJudgmentService::repeatedFailureSuppression.
     *
     * The caller performs the AtlasAemorOutcome lookup/count and passes the
     * already-resolved failure signature plus its repeat count IN; this class
     * only applies the ordered banding rules.
     *
     * @return array{
     *     schema_version: string,
     *     status: string,
     *     repeat_count: int,
     *     failure_signature?: string,
     *     required_mitigation: list<string>
     * }
     */
    public function classify(?string $failureSignature, int $repeatCount): array
    {
        // R1: no usable signature short-circuits to a clean "clear" verdict.
        // The repeat count is ignored and no failure_signature key is emitted.
        if ($failureSignature === null || trim($failureSignature) === '') {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'clear',
                'repeat_count' => 0,
                'required_mitigation' => [],
            ];
        }

        // R2/R3/R4: band the repeat count into blocked / watch / clear.
        if ($repeatCount >= 3) {
            $status = 'blocked';
        } elseif ($repeatCount >= 2) {
            $status = 'watch';
        } else {
            $status = 'clear';
        }

        // R5: mitigation is required once the failure has repeated at least twice.
        $requiredMitigation = $repeatCount >= 2
            ? ['include_negative_knowledge_in_apcr', 'run_targeted_repair_test']
            : [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'repeat_count' => $repeatCount,
            'failure_signature' => trim($failureSignature),
            'required_mitigation' => $requiredMitigation,
        ];
    }
}

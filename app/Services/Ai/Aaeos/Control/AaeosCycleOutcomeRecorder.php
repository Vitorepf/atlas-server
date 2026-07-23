<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Throwable;

/**
 * Maps cycle outcomes to provider-safe learning candidates (never auto-promotes).
 */
final class AaeosCycleOutcomeRecorder
{
    public const SCHEMA = 'atlas.aaeos.learning_candidate.v1';

    public function __construct(
        private readonly ?AtlasEvidenceLedger $ledger = null,
    ) {}

    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    public function record(array $receipt): array
    {
        $status = (string) ($receipt['status'] ?? '');
        $verdict = (string) ($receipt['admission']['verdict'] ?? '');
        $mode = (string) ($receipt['mode']['mode'] ?? '');

        $shouldLearn = $status === 'halted'
            || $verdict === AaeosAdmissionVerdict::HALT_SOVEREIGN
            || $verdict === AaeosAdmissionVerdict::AUTO_NOTIFY;

        $candidate = [
            'schema' => self::SCHEMA,
            'status' => $shouldLearn ? 'pending_review' : 'not_applicable',
            'auto_promoted' => false,
            'mode' => $mode,
            'cycle_status' => $status,
            'admission' => $verdict,
            'summary' => $this->summary($receipt),
            'evidence_status' => 'skipped',
        ];

        if (! $shouldLearn) {
            return $candidate;
        }

        $ledger = $this->ledger;
        if ($ledger === null) {
            try {
                if (function_exists('app')) {
                    $ledger = app(AtlasEvidenceLedger::class);
                }
            } catch (Throwable) {
                return $candidate;
            }
        }

        if ($ledger === null) {
            return $candidate;
        }

        try {
            $event = $ledger->record(
                LedgerEventType::AaeosLearningCandidateRecorded,
                [
                    'schema' => self::SCHEMA,
                    'status' => 'pending_review',
                    'auto_promoted' => false,
                    'mode' => $mode,
                    'admission' => $verdict,
                    'summary' => $candidate['summary'],
                    'difficulty_level' => $receipt['difficulty']['level'] ?? null,
                ],
                [
                    'emitter_stage' => 'atlas.aaeos.learning',
                    'scope_type' => 'aaeos_learning',
                    'envelope_id' => 'aaeos-learn-'.substr(hash('sha256', (string) microtime(true)), 0, 16),
                ],
            );
            $candidate['evidence_status'] = $event === null ? 'skipped_table_missing' : 'recorded';
        } catch (Throwable) {
            $candidate['evidence_status'] = 'skipped_error';
        }

        return $candidate;
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function summary(array $receipt): string
    {
        $mode = (string) ($receipt['mode']['mode'] ?? 'unknown');
        $verdict = (string) ($receipt['admission']['verdict'] ?? 'unknown');
        $reasons = $receipt['admission']['reasons'] ?? [];
        $reason = is_array($reasons) ? implode(',', array_slice($reasons, 0, 3)) : '';

        return "AAEOS cycle mode={$mode} admission={$verdict} reasons={$reason}";
    }
}

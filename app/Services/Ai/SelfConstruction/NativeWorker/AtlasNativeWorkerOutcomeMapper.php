<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\NativeWorker;

/**
 * Honest, deterministic mapper from native-worker execution facts into a reportable Atlas
 * task outcome ('success' | 'give_back' | 'failed'). Pure — no queue mutation, no report write,
 * no provider call, no file I/O. NEVER converts a red verdict into success.
 *
 * Decision precedence:
 *   1. give_back when the envelope encodes an IMPOSSIBLE task (impossible_scope, missing
 *      implementation path, acceptance contradiction, dependency_missing, forbidden_file_required,
 *      non_atlas_native_dependency).
 *   2. failed when verification.passed is false OR command/patch results are red OR required
 *      evidence is incomplete.
 *   3. success only when verification.passed is true AND every required evidence ref is present
 *      AND every command/patch result is green AND no unresolved blockers remain.
 */
final class AtlasNativeWorkerOutcomeMapper
{
    public const SCHEMA = 'atlas.native_worker.outcome_mapper.v1';

    public const OUTCOME_SUCCESS = 'success';

    public const OUTCOME_GIVE_BACK = 'give_back';

    public const OUTCOME_FAILED = 'failed';

    /** @var list<string> */
    private const GIVE_BACK_FACTS = [
        'impossible_scope',
        'missing_implementation_path',
        'acceptance_contradiction',
        'dependency_missing',
        'forbidden_file_required',
        'non_atlas_native_dependency',
    ];

    /**
     * @param  array<string,mixed>  $envelope
     * @param  array<string,mixed>  $execution
     * @param  array<string,mixed>  $verification
     * @return array<string,mixed>
     */
    public function map(array $envelope, array $execution, array $verification): array
    {
        $giveBackReasons = $this->collectGiveBackReasons($envelope, $execution);
        if ($giveBackReasons !== []) {
            return $this->emit(self::OUTCOME_GIVE_BACK, $giveBackReasons[0], $giveBackReasons, $envelope, $execution, $verification);
        }

        $verificationPassed = (bool) ($verification['passed'] ?? false);
        $executionGreen = $this->executionIsGreen($execution);
        $evidenceComplete = $this->evidenceComplete($envelope, $execution, $verification);
        $unresolvedBlockers = $this->unresolvedBlockers($execution, $verification);

        if (! $verificationPassed) {
            return $this->emit(self::OUTCOME_FAILED, 'verification_failed', array_values(array_unique(array_merge(
                ['verification_failed'],
                array_values((array) ($verification['blockers'] ?? [])),
            ))), $envelope, $execution, $verification);
        }
        if (! $executionGreen) {
            return $this->emit(self::OUTCOME_FAILED, 'execution_red_results', array_values((array) ($execution['failed_results'] ?? ['execution_red_results'])), $envelope, $execution, $verification);
        }
        if (! $evidenceComplete) {
            return $this->emit(self::OUTCOME_FAILED, 'evidence_incomplete', ['evidence_incomplete'], $envelope, $execution, $verification);
        }
        if ($unresolvedBlockers !== []) {
            return $this->emit(self::OUTCOME_FAILED, 'unresolved_blockers', $unresolvedBlockers, $envelope, $execution, $verification);
        }

        return $this->emit(self::OUTCOME_SUCCESS, 'all_green', [], $envelope, $execution, $verification);
    }

    /**
     * @param  array<string,mixed>  $envelope
     * @param  array<string,mixed>  $execution
     * @return list<string>
     */
    private function collectGiveBackReasons(array $envelope, array $execution): array
    {
        $reasons = [];
        foreach (self::GIVE_BACK_FACTS as $key) {
            if ((bool) ($envelope[$key] ?? false) || (bool) ($execution[$key] ?? false)) {
                $reasons[] = $key;
            }
        }
        $execReasons = array_values(array_filter(
            array_map('strval', (array) ($execution['give_back_reasons'] ?? [])),
            static fn (string $s): bool => $s !== '',
        ));
        foreach ($execReasons as $r) {
            if (! in_array($r, $reasons, true)) {
                $reasons[] = $r;
            }
        }

        return $reasons;
    }

    /**
     * @param  array<string,mixed>  $execution
     */
    private function executionIsGreen(array $execution): bool
    {
        $command = (string) ($execution['command_status'] ?? '');
        $patch = (string) ($execution['patch_status'] ?? '');
        $results = array_values((array) ($execution['results'] ?? []));
        foreach ($results as $row) {
            if (is_array($row) && (string) ($row['status'] ?? '') !== 'green') {
                return false;
            }
        }

        return ($command === '' || $command === 'green') && ($patch === '' || $patch === 'green');
    }

    /**
     * @param  array<string,mixed>  $envelope
     * @param  array<string,mixed>  $execution
     * @param  array<string,mixed>  $verification
     */
    private function evidenceComplete(array $envelope, array $execution, array $verification): bool
    {
        $required = array_values(array_map('strval', (array) ($envelope['required_evidence'] ?? [])));
        if ($required === []) {
            // No required evidence declared ⇒ trust verification.passed.
            return true;
        }
        $refs = array_values(array_map('strval', (array) ($execution['evidence_refs'] ?? [])));
        $verificationRefs = array_values(array_map('strval', (array) ($verification['evidence_refs'] ?? [])));
        $all = array_unique(array_merge($refs, $verificationRefs));
        foreach ($required as $r) {
            if (! in_array($r, $all, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $execution
     * @param  array<string,mixed>  $verification
     * @return list<string>
     */
    private function unresolvedBlockers(array $execution, array $verification): array
    {
        $merged = array_merge(
            array_values((array) ($execution['blockers'] ?? [])),
            array_values((array) ($verification['blockers'] ?? [])),
        );

        return array_values(array_unique(array_map('strval', $merged)));
    }

    /**
     * @param  list<string>  $deficiencies
     * @param  array<string,mixed>  $envelope
     * @param  array<string,mixed>  $execution
     * @param  array<string,mixed>  $verification
     * @return array<string,mixed>
     */
    private function emit(string $outcome, string $reason, array $deficiencies, array $envelope, array $execution, array $verification): array
    {
        $required = array_values(array_map('strval', (array) ($envelope['required_evidence'] ?? [])));
        $observed = array_values(array_unique(array_map('strval', array_merge(
            (array) ($execution['evidence_refs'] ?? []),
            (array) ($verification['evidence_refs'] ?? []),
        ))));

        $envelopeOut = [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA,
            'report_outcome' => $outcome,
            'report_reason' => $reason,
            'evidence_refs' => [
                'required' => $required,
                'observed' => $observed,
            ],
            'blocking_deficiencies' => array_values($deficiencies),
        ];
        $envelopeOut['outcome_hash'] = $this->outcomeHash($envelopeOut);

        return $envelopeOut;
    }

    /**
     * @param  array<string,mixed>  $envelope
     */
    private function outcomeHash(array $envelope): string
    {
        unset($envelope['outcome_hash']);
        $canonical = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'outcome_'.substr(hash('sha256', (string) $canonical), 0, 32);
    }
}

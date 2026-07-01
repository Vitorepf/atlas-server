<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\VerificationCourt;

/**
 * Pure detector that blocks false-green replay outcomes which only claim exit
 * codes without binding command output hashes to planned command ids.
 *
 * A replay outcome with passed=true but missing output_hash for a planned
 * command yields a deterministic replay_output_hash_missing failure.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasVerificationCourtFalseGreenDetector
{
    public const SCHEMA = 'atlas.verification_court.false_green_detector.v1';

    public const VERDICT_PASSED = 'passed';
    public const VERDICT_FAILED = 'failed';
    public const VERDICT_BLOCKED = 'blocked';

    /**
     * @param  array{
     *   passed?:bool,
     *   planned_commands?:list<array{command_id?:string,output_hash?:?string}>,
     *   replay_results?:list<array{command_id?:string,exit_code?:int,output_hash?:?string,passed?:bool}>,
     * }  $outcome
     * @return array{
     *   schema:string,
     *   verdict:string,
     *   reasons:list<string>,
     * }
     */
    public function detect(array $outcome): array
    {
        $passed = (bool) ($outcome['passed'] ?? false);
        $planned = (array) ($outcome['planned_commands'] ?? []);
        $replays = (array) ($outcome['replay_results'] ?? []);

        // Build map of planned command_id → expected output_hash
        $plannedHashes = [];
        foreach ($planned as $cmd) {
            $cmdId = (string) ($cmd['command_id'] ?? '');
            if ($cmdId !== '') {
                $plannedHashes[$cmdId] = $cmd['output_hash'] ?? null;
            }
        }

        // Build map of replay command_id → output_hash
        $replayHashes = [];
        foreach ($replays as $replay) {
            $cmdId = (string) ($replay['command_id'] ?? '');
            if ($cmdId !== '') {
                $replayHashes[$cmdId] = $replay['output_hash'] ?? null;
            }
        }

        $reasons = [];

        // Check each planned command has a matching replay with output_hash
        foreach ($plannedHashes as $cmdId => $expectedHash) {
            $replayHash = $replayHashes[$cmdId] ?? null;

            if (! array_key_exists($cmdId, $replayHashes)) {
                $reasons[] = "replay_missing:{$cmdId}";
            } elseif ($replayHash === null || $replayHash === '') {
                $reasons[] = "replay_output_hash_missing:{$cmdId}";
            } elseif ($expectedHash !== null && $expectedHash !== '' && $replayHash !== $expectedHash) {
                $reasons[] = "replay_output_hash_mismatch:{$cmdId}";
            }
        }

        // If outcome claims passed but there are hash issues → fail
        if ($passed && count($reasons) > 0) {
            $hasHashMissing = count(array_filter($reasons, fn ($r) => str_contains($r, 'output_hash_missing'))) > 0;
            $hasHashMismatch = count(array_filter($reasons, fn ($r) => str_contains($r, 'output_hash_mismatch'))) > 0;

            if ($hasHashMismatch) {
                return $this->envelope(self::VERDICT_FAILED, $reasons);
            }

            return $this->envelope(self::VERDICT_BLOCKED, $reasons);
        }

        if (count($reasons) > 0) {
            return $this->envelope(self::VERDICT_BLOCKED, $reasons);
        }

        return $this->envelope($passed ? self::VERDICT_PASSED : self::VERDICT_FAILED, $reasons);
    }

    /** @param  list<string>  $reasons */
    private function envelope(string $verdict, array $reasons): array
    {
        sort($reasons, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'verdict' => $verdict,
            'reasons' => $reasons,
        ];
    }
}

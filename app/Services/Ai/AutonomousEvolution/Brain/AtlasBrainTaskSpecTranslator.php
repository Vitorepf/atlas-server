<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * Translates the output of {@see \App\Services\Ai\AutonomousEvolution\AtlasLoopOriginationPipeline::produce()}
 * into the EXACT seed-gov-lanes packet spec consumed by
 * {@see \App\Console\Commands\AtlasTaskSeedGovLanesCommand}.
 *
 * CRITICAL INVARIANT: allowed_files comes ONLY from target_path + obligation file
 * references — NEVER an invented path. If an obligation carries no file path, it
 * contributes nothing to allowed_files.
 *
 * Pure: no provider, no DB, no mutation. Deterministic.
 */
final class AtlasBrainTaskSpecTranslator
{
    public const SCHEMA_VERSION = 'atlas.brain.task_spec_translator.v1';

    /**
     * @param  array{objective:?string, target_path:?string, obligations:list<array<string,mixed>>, snapshot_id?:string}  $origination
     * @return array{task_packet_id:string, objective:string, allowed_files:list<string>, scope_in:list<string>,
     *               acceptance_criteria:list<string>, evidence_requirements:list<string>,
     *               depends_on:list<string>, wave:int, risk_level:string}
     */
    public function translate(array $origination): array
    {
        $objective = trim((string) ($origination['objective'] ?? ''));
        $targetPath = ltrim(trim((string) ($origination['target_path'] ?? '')), '/');
        $obligations = array_values(array_filter(
            (array) ($origination['obligations'] ?? []),
            static fn ($o): bool => is_array($o),
        ));
        $snapshotId = trim((string) ($origination['snapshot_id'] ?? ''));

        // allowed_files = target_path + every file named in obligations. NEVER invent a path.
        $allowedFiles = [];
        if ($targetPath !== '') {
            $allowedFiles[$targetPath] = true;
        }
        foreach ($obligations as $obligation) {
            foreach ($this->extractFilePaths($obligation) as $path) {
                $allowedFiles[$path] = true;
            }
        }
        $allowedFilesList = array_values(array_unique(array_keys($allowedFiles)));
        sort($allowedFilesList, SORT_STRING);

        // Derive acceptance_criteria + evidence_requirements from obligation kinds/assertions.
        [$acceptanceCriteria, $evidenceRequirements] = $this->deriveAcceptanceAndEvidence($obligations, $targetPath);

        // Deterministic task_packet_id = sha1(objective . '|' . snapshotId).
        $taskPacketId = 'brain:'.sha1($objective.'|'.$snapshotId);

        return [
            'task_packet_id' => $taskPacketId,
            'objective' => $objective,
            'allowed_files' => $allowedFilesList,
            'scope_in' => $allowedFilesList,
            'acceptance_criteria' => $acceptanceCriteria,
            'evidence_requirements' => $evidenceRequirements,
            'depends_on' => [],
            'wave' => 1,
            'risk_level' => 'medium',
        ];
    }

    /**
     * Extract file paths from a single obligation tuple. Looks for the common keys
     * an obligation carries a file reference in. Only strings that look like PHP
     * file paths (end with .php or .blade.php) are extracted — never invented.
     *
     * @param  array<string,mixed>  $obligation
     * @return list<string>
     */
    private function extractFilePaths(array $obligation): array
    {
        $paths = [];
        $keys = ['file', 'target_file', 'file_path', 'path', 'target_path', 'target_symbol'];
        foreach ($keys as $key) {
            $value = $obligation[$key] ?? null;
            if (is_string($value) && $this->looksLikeFilePath($value)) {
                $paths[ltrim(trim($value), '/')] = true;
            }
        }

        // Also check nested arrays (some obligations nest file refs).
        foreach ($obligation as $value) {
            if (is_array($value)) {
                foreach ($this->extractFilePaths($value) as $path) {
                    $paths[$path] = true;
                }
            }
        }

        return array_keys($paths);
    }

    private function looksLikeFilePath(string $value): bool
    {
        $value = trim($value);

        return $value !== '' && (str_ends_with($value, '.php') || str_ends_with($value, '.blade.php'));
    }

    /**
     * @param  list<array<string,mixed>>  $obligations
     * @return array{0:list<string>, 1:list<string>}
     */
    private function deriveAcceptanceAndEvidence(array $obligations, string $targetPath): array
    {
        $acceptance = [];
        $evidence = [];

        if ($targetPath !== '') {
            $acceptance[] = 'php -l '.$targetPath.' passes (no syntax error)';
            $evidence[] = 'tests_or_gates_result';
        }

        foreach ($obligations as $obligation) {
            $kind = trim((string) ($obligation['kind'] ?? ''));
            $assertionRef = trim((string) ($obligation['assertion_ref'] ?? ''));
            $targetSymbol = trim((string) ($obligation['target_symbol'] ?? ''));

            if ($assertionRef !== '') {
                $acceptance[] = $assertionRef;
                $evidence[] = 'obligation:'.$assertionRef;
            } elseif ($kind !== '' && $targetSymbol !== '') {
                $acceptance[] = $kind.' obligation on '.$targetSymbol.' is satisfied';
                $evidence[] = 'obligation:'.$kind.':'.$targetSymbol;
            }
        }

        $acceptance = array_values(array_unique($acceptance));
        $evidence = array_values(array_unique($evidence));

        if ($acceptance === []) {
            $acceptance[] = 'implementation is complete';
        }
        if ($evidence === []) {
            $evidence[] = 'tests_or_gates_result';
        }

        return [$acceptance, $evidence];
    }
}

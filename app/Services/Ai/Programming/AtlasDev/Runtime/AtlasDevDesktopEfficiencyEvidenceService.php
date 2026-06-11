<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Runtime;

use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\Support\JsonFileStore;
use Illuminate\Support\Facades\File;

final class AtlasDevDesktopEfficiencyEvidenceService
{
    public const CASES_SCHEMA_VERSION = 'atlas.dev.desktop_efficiency_cases.v1';

    public const EVIDENCE_SCHEMA_VERSION = 'atlas.dev.desktop_efficiency_evidence.v1';

    /** Seconds assigned to one manual operator step when computing effort. */
    private const MANUAL_STEP_SECONDS = 300.0;

    /** Seconds assigned to one provider call when computing effort. */
    private const PROVIDER_CALL_SECONDS = 30.0;

    private const REQUIRED_ENGINES = ['claude_code', 'codex'];

    private const REQUIRED_TASK_KINDS = ['patch', 'repair', 'review', 'frontend', 'question'];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function build(array $input, ?string $inputLabel = null): array
    {
        $cases = array_values((array) ($input['cases'] ?? []));
        $findings = [];
        $inputHash = hash('sha256', json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        $receiptsRoot = rtrim((string) config('atlas_dev.receipts_path', storage_path('atlas-dev/receipts')), DIRECTORY_SEPARATOR);

        if (($input['schema_version'] ?? null) !== self::CASES_SCHEMA_VERSION) {
            $findings[] = 'schema_version_invalid';
        }
        if (($input['measurement_mode'] ?? null) !== 'observed_operator_runs') {
            $findings[] = 'measurement_mode_invalid';
        }
        if (count($cases) < 5) {
            $findings[] = 'case_count_below_5';
        }

        $caseSummaries = [];
        $coveredTaskKinds = [];
        $atlasEffort = 0.0;
        $engineEfforts = array_fill_keys(self::REQUIRED_ENGINES, 0.0);

        foreach ($cases as $index => $case) {
            if (! is_array($case)) {
                $findings[] = 'case_'.$index.'_invalid';

                continue;
            }

            $caseId = AiValueNormalizer::trimmedStringOrNull($case['case_id'] ?? null) ?: 'case_'.$index;
            $taskKind = AiValueNormalizer::trimmedStringOrNull($case['task_kind'] ?? null);
            if ($taskKind !== null) {
                $coveredTaskKinds[] = $taskKind;
            }
            if ($taskKind === null || ! in_array($taskKind, self::REQUIRED_TASK_KINDS, true)) {
                $findings[] = $caseId.':task_kind_invalid';
            }
            $taskPrompt = AiValueNormalizer::trimmedStringOrNull($case['task_prompt'] ?? null);
            if ($taskPrompt === null) {
                $findings[] = $caseId.':task_prompt_missing';
            }
            $taskPromptHash = $taskPrompt !== null ? hash('sha256', $taskPrompt) : null;
            $declaredTaskPromptHash = AiValueNormalizer::trimmedStringOrNull($case['task_prompt_sha256'] ?? null);
            if ($declaredTaskPromptHash !== null && $declaredTaskPromptHash !== $taskPromptHash) {
                $findings[] = $caseId.':task_prompt_sha256_mismatch';
            }

            $atlas = $this->participantMetrics((array) ($case['atlas'] ?? []), $receiptsRoot, $caseId, (string) $taskKind, 'atlas', $taskPromptHash);
            if (! $atlas['valid']) {
                $findings[] = $caseId.':atlas_invalid';
            }
            if ($atlas['missing_evidence_refs'] !== []) {
                $findings[] = $caseId.':atlas_evidence_refs_missing';
            }
            if ($atlas['invalid_evidence_refs'] !== []) {
                $findings[] = $caseId.':atlas_evidence_refs_invalid';
            }
            if (! $atlas['passed']) {
                $findings[] = $caseId.':atlas_not_passed';
            }

            $engineMultipliers = [];
            $engineMetrics = [];
            foreach (self::REQUIRED_ENGINES as $engine) {
                $metrics = $this->participantMetrics((array) ($case[$engine] ?? []), $receiptsRoot, $caseId, (string) $taskKind, $engine, $taskPromptHash);
                $engineMetrics[$engine] = $metrics;
                if (! $metrics['valid']) {
                    $findings[] = $caseId.':'.$engine.'_invalid';
                }
                if ($metrics['missing_evidence_refs'] !== []) {
                    $findings[] = $caseId.':'.$engine.'_evidence_refs_missing';
                }
                if ($metrics['invalid_evidence_refs'] !== []) {
                    $findings[] = $caseId.':'.$engine.'_evidence_refs_invalid';
                }
                if (! $metrics['passed']) {
                    $findings[] = $caseId.':'.$engine.'_not_passed';
                }

                $engineEfforts[$engine] += $metrics['effort_seconds'];
                $engineMultipliers[$engine] = $atlas['effort_seconds'] > 0.0
                    ? round($metrics['effort_seconds'] / $atlas['effort_seconds'], 4)
                    : 0.0;
            }

            $atlasEffort += $atlas['effort_seconds'];
            $caseSummaries[] = [
                'case_id' => $caseId,
                'task_kind' => $taskKind,
                'task_prompt_sha256' => $taskPromptHash,
                'atlas_effort_seconds' => round($atlas['effort_seconds'], 2),
                'engine_multipliers' => $engineMultipliers,
                'evidence_refs' => [
                    'atlas' => $atlas['evidence_refs'],
                    'claude_code' => $engineMetrics['claude_code']['evidence_refs'] ?? [],
                    'codex' => $engineMetrics['codex']['evidence_refs'] ?? [],
                ],
                'missing_evidence_refs' => [
                    'atlas' => $atlas['missing_evidence_refs'],
                    'claude_code' => $engineMetrics['claude_code']['missing_evidence_refs'] ?? [],
                    'codex' => $engineMetrics['codex']['missing_evidence_refs'] ?? [],
                ],
                'invalid_evidence_refs' => [
                    'atlas' => $atlas['invalid_evidence_refs'],
                    'claude_code' => $engineMetrics['claude_code']['invalid_evidence_refs'] ?? [],
                    'codex' => $engineMetrics['codex']['invalid_evidence_refs'] ?? [],
                ],
            ];
        }

        $coveredTaskKinds = AtlasDevStringListNormalizer::uniqueTrimmedStrings($coveredTaskKinds);
        sort($coveredTaskKinds);
        $missingTaskKinds = array_values(array_diff(self::REQUIRED_TASK_KINDS, $coveredTaskKinds));
        if ($missingTaskKinds !== []) {
            $findings[] = 'required_task_kinds_missing:'.implode(',', $missingTaskKinds);
        }

        $aggregateMultipliers = [];
        foreach (self::REQUIRED_ENGINES as $engine) {
            $aggregateMultipliers[$engine] = $atlasEffort > 0.0
                ? round($engineEfforts[$engine] / $atlasEffort, 4)
                : 0.0;
        }

        $measuredMultiplier = $aggregateMultipliers === []
            ? 0.0
            : min(array_values($aggregateMultipliers));

        if ($measuredMultiplier < 10.0) {
            $findings[] = 'measured_multiplier_below_10';
        }

        $findings = AtlasDevStringListNormalizer::uniqueTrimmedStrings($findings);

        return [
            'schema_version' => self::EVIDENCE_SCHEMA_VERSION,
            'status' => $findings === [] ? 'passed' : 'blocked',
            'input_ref' => $inputLabel,
            'input_sha256' => $inputHash,
            'measured_multiplier' => round($measuredMultiplier, 4),
            'compared_against' => self::REQUIRED_ENGINES,
            'case_count' => count($cases),
            'measurement_mode' => $input['measurement_mode'] ?? null,
            'aggregate_multipliers' => $aggregateMultipliers,
            'evidence_quality' => [
                'required_task_kinds' => self::REQUIRED_TASK_KINDS,
                'covered_task_kinds' => $coveredTaskKinds,
                'missing_task_kinds' => $missingTaskKinds,
                'required_task_kinds_covered' => $missingTaskKinds === [],
                'participant_evidence_refs_required' => true,
            ],
            'effort_formula' => [
                'effort_seconds' => 'elapsed_seconds + manual_steps * manual_step_seconds + provider_calls * provider_call_seconds',
                'manual_step_seconds' => self::MANUAL_STEP_SECONDS,
                'provider_call_seconds' => self::PROVIDER_CALL_SECONDS,
                'measured_multiplier' => 'min(sum(engine_effort_seconds) / sum(atlas_effort_seconds)) across required engines',
            ],
            'cases' => $caseSummaries,
            'blocking_findings' => $findings,
        ];
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @return array{ref:string,latest_ref:string}
     */
    public function persist(array $evidence): array
    {
        $dir = rtrim((string) config('atlas_dev.receipts_path', storage_path('atlas-dev/receipts')), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'desktop_efficiency';
        File::ensureDirectoryExists($dir);

        $stamp = now()->format('YmdHis');
        $filename = 'efficiency-'.$stamp.'.json';
        $json = json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
        File::put($dir.DIRECTORY_SEPARATOR.$filename, $json);
        File::put($dir.DIRECTORY_SEPARATOR.'latest.json', $json);

        return [
            'ref' => 'desktop_efficiency/'.$filename,
            'latest_ref' => 'desktop_efficiency/latest.json',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function writeTemplate(string $path): array
    {
        $cases = array_map(fn (string $taskKind): array => $this->templateCase($taskKind), self::REQUIRED_TASK_KINDS);
        $template = [
            'schema_version' => self::CASES_SCHEMA_VERSION,
            'measurement_mode' => 'observed_operator_runs',
            'instructions' => [
                'Fill all elapsed_seconds/manual_steps/provider_calls from observed runs.',
                'Each evidence_refs entry must be relative to the Atlas Dev receipts root and the file must exist.',
                'Use source_write_commands entries to write each participant source evidence file without mistyping task_prompt_sha256.',
                'Run php artisan atlas:dev:desktop:efficiency-evidence --input='.$path.' --persist --json --strict after filling.',
            ],
            'cases' => $cases,
            'source_write_commands' => $this->sourceWriteCommands($cases),
        ];

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
        $sourceTemplateResult = $this->writeSourceTemplates($template);

        return [
            'schema_version' => self::EVIDENCE_SCHEMA_VERSION,
            'status' => 'blocked',
            'template_written' => true,
            'template_path_label' => basename($path),
            'source_template_count' => count($sourceTemplateResult['refs']),
            'source_template_refs' => $sourceTemplateResult['refs'],
            'source_template_written_count' => count($sourceTemplateResult['written_refs']),
            'source_template_refreshed_count' => count($sourceTemplateResult['refreshed_refs']),
            'source_template_refreshed_refs' => $sourceTemplateResult['refreshed_refs'],
            'source_template_preserved_count' => count($sourceTemplateResult['preserved_refs']),
            'source_template_preserved_refs' => $sourceTemplateResult['preserved_refs'],
            'case_count' => count(self::REQUIRED_TASK_KINDS),
            'required_task_kinds' => self::REQUIRED_TASK_KINDS,
            'blocking_findings' => ['template_requires_observed_operator_data'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function writeSourceEvidence(
        string $ref,
        string $caseId,
        string $taskKind,
        string $participant,
        string $taskPrompt,
        string $taskPromptSha256,
        ?float $elapsedSeconds,
        ?float $manualSteps,
        ?float $providerCalls,
        bool $verificationPassed,
        string $runRef = '',
        string $notes = '',
    ): array {
        $ref = trim($ref);
        $caseId = trim($caseId);
        $taskKind = trim($taskKind);
        $participant = trim($participant);
        $taskPrompt = trim($taskPrompt);
        $taskPromptSha256 = trim($taskPromptSha256);
        $runRef = trim($runRef);
        $notes = trim($notes);

        $blockers = [];
        if (! $this->validRelativeRef($ref) || ! str_starts_with($ref, 'desktop_efficiency/source/') || ! str_ends_with($ref, '.json')) {
            $blockers[] = 'source_ref_invalid';
        }
        if ($caseId === '') {
            $blockers[] = 'case_id_missing';
        }
        if (! in_array($taskKind, self::REQUIRED_TASK_KINDS, true)) {
            $blockers[] = 'task_kind_invalid';
        }
        if (! in_array($participant, ['atlas', ...self::REQUIRED_ENGINES], true)) {
            $blockers[] = 'participant_invalid';
        }
        if ($taskPromptSha256 === '' || preg_match('/^[a-f0-9]{64}$/', $taskPromptSha256) !== 1) {
            $blockers[] = 'task_prompt_sha256_invalid';
        }
        if ($taskPrompt === '') {
            $blockers[] = 'task_prompt_missing';
        } elseif (hash('sha256', $taskPrompt) !== $taskPromptSha256) {
            $blockers[] = 'task_prompt_sha256_mismatch';
        }
        if ($elapsedSeconds === null || $elapsedSeconds < 0.0) {
            $blockers[] = 'elapsed_seconds_invalid';
        }
        if ($manualSteps === null || $manualSteps < 0.0) {
            $blockers[] = 'manual_steps_invalid';
        }
        if ($providerCalls === null || $providerCalls < 0.0) {
            $blockers[] = 'provider_calls_invalid';
        }
        $receiptsRoot = rtrim((string) config('atlas_dev.receipts_path', storage_path('atlas-dev/receipts')), DIRECTORY_SEPARATOR);
        if ($runRef === '') {
            $blockers[] = 'run_ref_missing';
        } elseif (! $this->validRelativeRef($runRef)) {
            $blockers[] = 'run_ref_invalid';
        } elseif ($runRef === $ref) {
            $blockers[] = 'run_ref_self_reference';
        } elseif (! is_file($receiptsRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $runRef))) {
            $blockers[] = 'run_ref_not_found';
        } elseif (! $this->rawRunRefMatches($receiptsRoot, $runRef, $caseId, $taskKind, $participant)) {
            $blockers[] = 'run_ref_invalid_payload';
        }

        $payload = [
            'schema_version' => 'atlas.dev.desktop_efficiency_source.v1',
            'status' => $blockers === [] && $verificationPassed ? 'passed' : 'blocked',
            'case_id' => $caseId !== '' ? $caseId : null,
            'task_kind' => $taskKind !== '' ? $taskKind : null,
            'participant' => $participant !== '' ? $participant : null,
            'task_prompt' => $taskPrompt !== '' ? $taskPrompt : null,
            'task_prompt_sha256' => $taskPromptSha256 !== '' ? $taskPromptSha256 : null,
            'verification_passed' => $verificationPassed,
            'elapsed_seconds' => $elapsedSeconds,
            'manual_steps' => $manualSteps,
            'provider_calls' => $providerCalls,
            'observed_at' => now()->toISOString(),
            'run_ref' => $runRef !== '' ? $runRef : null,
            'notes' => $notes !== '' ? $notes : null,
            'blocking_findings' => $blockers,
        ];

        if ($ref !== '' && ! in_array('source_ref_invalid', $blockers, true)) {
            $path = $receiptsRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $ref);
            File::ensureDirectoryExists(dirname($path));
            File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
            $payload['ref'] = $ref;
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function writeRawRunEvidence(
        string $ref,
        string $caseId,
        string $taskKind,
        string $participant,
        string $summary,
        string $verificationCommand,
        bool $verificationPassed,
        string $notes = '',
    ): array {
        $ref = trim($ref);
        $caseId = trim($caseId);
        $taskKind = trim($taskKind);
        $participant = trim($participant);
        $summary = trim($summary);
        $verificationCommand = trim($verificationCommand);
        $notes = trim($notes);

        $blockers = [];
        if (! $this->validRelativeRef($ref) || ! str_starts_with($ref, 'desktop_efficiency/raw/') || ! str_ends_with($ref, '.json')) {
            $blockers[] = 'run_ref_invalid';
        }
        if ($caseId === '') {
            $blockers[] = 'case_id_missing';
        }
        if (! in_array($taskKind, self::REQUIRED_TASK_KINDS, true)) {
            $blockers[] = 'task_kind_invalid';
        }
        if (! in_array($participant, ['atlas', ...self::REQUIRED_ENGINES], true)) {
            $blockers[] = 'participant_invalid';
        }
        if ($summary === '') {
            $blockers[] = 'raw_summary_missing';
        }
        if ($verificationCommand === '') {
            $blockers[] = 'raw_verification_command_missing';
        }
        if (! $verificationPassed) {
            $blockers[] = 'verification_passed_invalid';
        }

        $payload = [
            'schema_version' => 'atlas.dev.desktop_efficiency_raw_run.v1',
            'status' => $blockers === [] ? 'passed' : 'blocked',
            'case_id' => $caseId !== '' ? $caseId : null,
            'task_kind' => $taskKind !== '' ? $taskKind : null,
            'participant' => $participant !== '' ? $participant : null,
            'summary' => $summary !== '' ? $summary : null,
            'verification_command' => $verificationCommand !== '' ? $verificationCommand : null,
            'verification_passed' => $verificationPassed,
            'captured_at' => now()->toISOString(),
            'notes' => $notes !== '' ? $notes : null,
            'blocking_findings' => $blockers,
        ];

        if ($ref !== '' && ! in_array('run_ref_invalid', $blockers, true)) {
            $receiptsRoot = rtrim((string) config('atlas_dev.receipts_path', storage_path('atlas-dev/receipts')), DIRECTORY_SEPARATOR);
            $path = $receiptsRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $ref);
            File::ensureDirectoryExists(dirname($path));
            File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
            $payload['ref'] = $ref;
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function buildCasesFromSources(string $path): array
    {
        $receiptsRoot = rtrim((string) config('atlas_dev.receipts_path', storage_path('atlas-dev/receipts')), DIRECTORY_SEPARATOR);
        $cases = [];
        $blockers = [];

        foreach (self::REQUIRED_TASK_KINDS as $taskKind) {
            $caseId = $taskKind.'_case';
            $taskPrompt = $this->templateTaskPrompt($taskKind);
            $taskPromptHash = hash('sha256', $taskPrompt);
            $sourcePromptLocked = false;
            $case = [
                'case_id' => $caseId,
                'task_kind' => $taskKind,
                'task_prompt' => $taskPrompt,
                'task_prompt_sha256' => $taskPromptHash,
            ];

            foreach (['atlas', ...self::REQUIRED_ENGINES] as $participant) {
                $ref = 'desktop_efficiency/source/'.$caseId.'/'.$participant.'.json';
                $sourcePath = $receiptsRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $ref);
                if (! is_file($sourcePath)) {
                    $blockers[] = $caseId.':'.$participant.'_source_missing';
                    $case[$participant] = $this->blockedParticipant($ref);

                    continue;
                }

                $source = JsonFileStore::readArray($sourcePath);
                if (! is_array($source)) {
                    $blockers[] = $caseId.':'.$participant.'_source_invalid_json';
                    $case[$participant] = $this->blockedParticipant($ref);

                    continue;
                }

                $sourceTaskPrompt = AiValueNormalizer::trimmedStringOrNull($source['task_prompt'] ?? null);
                if ($sourceTaskPrompt === null) {
                    $blockers[] = $caseId.':'.$participant.'_task_prompt_missing';
                } elseif (! $sourcePromptLocked) {
                    $taskPrompt = $sourceTaskPrompt;
                    $taskPromptHash = hash('sha256', $taskPrompt);
                    $case['task_prompt'] = $taskPrompt;
                    $case['task_prompt_sha256'] = $taskPromptHash;
                    $sourcePromptLocked = true;
                } elseif ($sourceTaskPrompt !== $taskPrompt) {
                    $blockers[] = $caseId.':'.$participant.'_task_prompt_mismatch';
                }

                $sourceBlockers = $this->sourceRecordBlockers($source, $caseId, $taskKind, $participant, $taskPromptHash, $receiptsRoot, $ref);
                foreach ($sourceBlockers as $sourceBlocker) {
                    $blockers[] = $caseId.':'.$participant.'_'.$sourceBlocker;
                }

                $case[$participant] = [
                    'status' => ($source['status'] ?? null) === 'passed' ? 'passed' : 'blocked',
                    'verification_passed' => ($source['verification_passed'] ?? null) === true,
                    'elapsed_seconds' => $source['elapsed_seconds'] ?? null,
                    'manual_steps' => $source['manual_steps'] ?? null,
                    'provider_calls' => $source['provider_calls'] ?? null,
                    'evidence_refs' => [$ref],
                ];
            }

            $cases[] = $case;
        }

        $payload = [
            'schema_version' => self::CASES_SCHEMA_VERSION,
            'measurement_mode' => 'observed_operator_runs',
            'generated_from_sources' => true,
            'cases' => $cases,
        ];

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return [
            'schema_version' => self::EVIDENCE_SCHEMA_VERSION,
            'status' => $blockers === [] ? 'passed' : 'blocked',
            'cases_path_label' => basename($path),
            'case_count' => count($cases),
            'source_count' => count(self::REQUIRED_TASK_KINDS) * 3,
            'cases_written' => true,
            'blocking_findings' => AtlasDevStringListNormalizer::uniqueTrimmedStrings($blockers),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function sourceStatus(string $casesPath = ''): array
    {
        $receiptsRoot = rtrim((string) config('atlas_dev.receipts_path', storage_path('atlas-dev/receipts')), DIRECTORY_SEPARATOR);
        $casePrompts = $this->casePromptsFromPath($casesPath);
        $slots = [];
        $passed = 0;
        $missing = 0;
        $blocked = 0;

        foreach (self::REQUIRED_TASK_KINDS as $taskKind) {
            $caseId = $taskKind.'_case';
            $taskPrompt = $casePrompts[$caseId] ?? $this->templateTaskPrompt($taskKind);
            $taskPromptHash = hash('sha256', $taskPrompt);

            foreach (['atlas', ...self::REQUIRED_ENGINES] as $participant) {
                $ref = 'desktop_efficiency/source/'.$caseId.'/'.$participant.'.json';
                $path = $receiptsRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $ref);
                $slot = [
                    'case_id' => $caseId,
                    'task_kind' => $taskKind,
                    'participant' => $participant,
                    'ref' => $ref,
                    'task_prompt_sha256' => $taskPromptHash,
                    'status' => 'missing',
                    'blocking_findings' => ['source_missing'],
                    'write_command' => $this->sourceWriteCommand($caseId, $taskKind, $participant, $ref, $taskPrompt, $taskPromptHash),
                ];

                if (! is_file($path)) {
                    $missing++;
                    $slots[] = $slot;

                    continue;
                }

                $source = JsonFileStore::readArray($path);
                if (! is_array($source)) {
                    $slot['status'] = 'blocked';
                    $slot['blocking_findings'] = ['source_invalid_json'];
                    $blocked++;
                    $slots[] = $slot;

                    continue;
                }

                $sourceBlockers = $this->sourceRecordBlockers($source, $caseId, $taskKind, $participant, $taskPromptHash, $receiptsRoot, $ref);
                $slot['status'] = $sourceBlockers === [] ? 'passed' : 'blocked';
                $slot['blocking_findings'] = $sourceBlockers;
                $slot['observed'] = [
                    'elapsed_seconds' => $source['elapsed_seconds'] ?? null,
                    'manual_steps' => $source['manual_steps'] ?? null,
                    'provider_calls' => $source['provider_calls'] ?? null,
                    'verification_passed' => $source['verification_passed'] ?? null,
                ];

                if ($sourceBlockers === []) {
                    $passed++;
                } else {
                    $blocked++;
                }

                $slots[] = $slot;
            }
        }

        $remaining = $missing + $blocked;
        $nextSlot = null;
        foreach ($slots as $slot) {
            if (($slot['status'] ?? null) !== 'passed') {
                $nextSlot = $slot;

                break;
            }
        }

        return [
            'schema_version' => self::EVIDENCE_SCHEMA_VERSION,
            'status' => $remaining === 0 ? 'passed' : 'blocked',
            'source_collection_status' => [
                'total_slots' => count(self::REQUIRED_TASK_KINDS) * 3,
                'passed_slots' => $passed,
                'blocked_slots' => $blocked,
                'missing_slots' => $missing,
                'remaining_slots' => $remaining,
            ],
            'required_task_kinds' => self::REQUIRED_TASK_KINDS,
            'required_participants' => ['atlas', ...self::REQUIRED_ENGINES],
            'next_required_source' => $nextSlot,
            'slots' => $slots,
            'blocking_findings' => $remaining === 0 ? [] : ['source_evidence_collection_incomplete'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function sourceCommands(string $casesPath = ''): array
    {
        $status = $this->sourceStatus($casesPath);
        $pending = [];

        foreach ((array) ($status['slots'] ?? []) as $slot) {
            if (! is_array($slot) || ($slot['status'] ?? null) === 'passed') {
                continue;
            }

            $pending[] = [
                'case_id' => $slot['case_id'] ?? null,
                'task_kind' => $slot['task_kind'] ?? null,
                'participant' => $slot['participant'] ?? null,
                'ref' => $slot['ref'] ?? null,
                'run_ref' => 'desktop_efficiency/raw/'.($slot['case_id'] ?? 'unknown_case').'/'.($slot['participant'] ?? 'unknown').'.json',
                'blocking_findings' => $slot['blocking_findings'] ?? [],
                'raw_run_command' => $this->rawRunWriteCommand(
                    (string) ($slot['case_id'] ?? ''),
                    (string) ($slot['task_kind'] ?? ''),
                    (string) ($slot['participant'] ?? ''),
                    'desktop_efficiency/raw/'.($slot['case_id'] ?? 'unknown_case').'/'.($slot['participant'] ?? 'unknown').'.json',
                ),
                'command' => $slot['write_command'] ?? null,
            ];
        }

        return [
            'schema_version' => self::EVIDENCE_SCHEMA_VERSION,
            'status' => $pending === [] ? 'passed' : 'blocked',
            'source_collection_status' => $status['source_collection_status'] ?? null,
            'pending_command_count' => count($pending),
            'pending_commands' => $pending,
            'blocking_findings' => $pending === [] ? [] : ['source_evidence_collection_incomplete'],
        ];
    }

    /**
     * @param  array<string,mixed>  $template
     * @return array{refs:list<string>,written_refs:list<string>,refreshed_refs:list<string>,preserved_refs:list<string>}
     */
    private function writeSourceTemplates(array $template): array
    {
        $receiptsRoot = rtrim((string) config('atlas_dev.receipts_path', storage_path('atlas-dev/receipts')), DIRECTORY_SEPARATOR);
        $refs = [];
        $writtenRefs = [];
        $refreshedRefs = [];
        $preservedRefs = [];

        foreach ((array) ($template['cases'] ?? []) as $case) {
            if (! is_array($case)) {
                continue;
            }

            $caseId = AiValueNormalizer::trimmedStringOrNull($case['case_id'] ?? null);
            $taskKind = AiValueNormalizer::trimmedStringOrNull($case['task_kind'] ?? null);
            $taskPrompt = AiValueNormalizer::trimmedStringOrNull($case['task_prompt'] ?? null);
            if ($caseId === null || $taskKind === null) {
                continue;
            }
            $taskPromptHash = $taskPrompt !== null ? hash('sha256', $taskPrompt) : null;

            foreach (['atlas', 'claude_code', 'codex'] as $participant) {
                $participantPayload = (array) ($case[$participant] ?? []);
                foreach ($this->evidenceRefs($participantPayload['evidence_refs'] ?? []) as $ref) {
                    $path = $receiptsRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $ref);
                    $sourceTemplate = $this->sourceTemplatePayload($caseId, $taskKind, $participant, $taskPrompt, $taskPromptHash);
                    if (is_file($path)) {
                        $refs[] = $ref;
                        $existing = JsonFileStore::readArray($path);
                        if (is_array($existing) && $this->refreshableSourceTemplate($existing)) {
                            File::put($path, json_encode($sourceTemplate, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
                            $refreshedRefs[] = $ref;

                            continue;
                        }

                        $preservedRefs[] = $ref;

                        continue;
                    }

                    File::ensureDirectoryExists(dirname($path));
                    File::put($path, json_encode($sourceTemplate, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
                    $refs[] = $ref;
                    $writtenRefs[] = $ref;
                }
            }
        }

        return [
            'refs' => AtlasDevStringListNormalizer::uniqueTrimmedStrings($refs),
            'written_refs' => AtlasDevStringListNormalizer::uniqueTrimmedStrings($writtenRefs),
            'refreshed_refs' => AtlasDevStringListNormalizer::uniqueTrimmedStrings($refreshedRefs),
            'preserved_refs' => AtlasDevStringListNormalizer::uniqueTrimmedStrings($preservedRefs),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sourceTemplatePayload(string $caseId, string $taskKind, string $participant, ?string $taskPrompt, ?string $taskPromptHash): array
    {
        return [
            'schema_version' => 'atlas.dev.desktop_efficiency_source.v1',
            'case_id' => $caseId,
            'task_kind' => $taskKind,
            'participant' => $participant,
            'task_prompt' => $taskPrompt,
            'task_prompt_sha256' => $taskPromptHash,
            'status' => 'blocked',
            'verification_passed' => false,
            'observed_at' => null,
            'run_ref' => null,
            'notes' => 'Replace status/verification_passed and attach observed run details before computing 10x evidence.',
        ];
    }

    /**
     * @param  array<string,mixed>  $source
     */
    private function refreshableSourceTemplate(array $source): bool
    {
        return ($source['schema_version'] ?? null) === 'atlas.dev.desktop_efficiency_source.v1'
            && ($source['status'] ?? null) === 'blocked'
            && ($source['verification_passed'] ?? null) === false
            && ($source['observed_at'] ?? null) === null
            && ! isset($source['elapsed_seconds'])
            && ! isset($source['manual_steps'])
            && ! isset($source['provider_calls']);
    }

    /**
     * @return array<string,mixed>
     */
    private function blockedParticipant(string $ref): array
    {
        return [
            'status' => 'blocked',
            'verification_passed' => false,
            'elapsed_seconds' => null,
            'manual_steps' => null,
            'provider_calls' => null,
            'evidence_refs' => [$ref],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $cases
     * @return list<array<string,string>>
     */
    private function sourceWriteCommands(array $cases): array
    {
        $commands = [];
        foreach ($cases as $case) {
            $caseId = (string) ($case['case_id'] ?? '');
            $taskKind = (string) ($case['task_kind'] ?? '');
            $taskPrompt = (string) ($case['task_prompt'] ?? '');
            $taskPromptSha256 = (string) ($case['task_prompt_sha256'] ?? '');

            foreach (['atlas', ...self::REQUIRED_ENGINES] as $participant) {
                $ref = $this->evidenceRefs(data_get($case, $participant.'.evidence_refs', []))[0] ?? '';
                if ($caseId === '' || $taskKind === '' || $taskPrompt === '' || $taskPromptSha256 === '' || $ref === '') {
                    continue;
                }

                $commands[] = [
                    'case_id' => $caseId,
                    'participant' => $participant,
                    'ref' => $ref,
                    'command' => $this->sourceWriteCommand($caseId, $taskKind, $participant, $ref, $taskPrompt, $taskPromptSha256),
                ];
            }
        }

        return $commands;
    }

    private function sourceWriteCommand(string $caseId, string $taskKind, string $participant, string $ref, string $taskPrompt, string $taskPromptSha256): string
    {
        return 'php artisan atlas:dev:desktop:efficiency-evidence'
            .' --write-source='.$ref
            .' --case-id='.$caseId
            .' --task-kind='.$taskKind
            .' --participant='.$participant
            .' --task-prompt='.escapeshellarg($taskPrompt)
            .' --task-prompt-sha256='.$taskPromptSha256
            .' --elapsed-seconds=<REAL_SECONDS>'
            .' --manual-steps=<REAL_STEPS>'
            .' --provider-calls=<REAL_PROVIDER_CALLS>'
            .' --run-ref=desktop_efficiency/raw/'.$caseId.'/'.$participant.'.json'
            .' --verification-passed'
            .' --json --strict';
    }

    private function rawRunWriteCommand(string $caseId, string $taskKind, string $participant, string $runRef): string
    {
        return 'php artisan atlas:dev:desktop:efficiency-evidence'
            .' --write-run-ref='.$runRef
            .' --case-id='.$caseId
            .' --task-kind='.$taskKind
            .' --participant='.$participant
            .' --raw-summary='.escapeshellarg('<OBSERVED_RUN_SUMMARY>')
            .' --raw-verification-command='.escapeshellarg('<VERIFICATION_COMMAND_OR_REVIEW_CHECK>')
            .' --verification-passed'
            .' --json --strict';
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{valid:bool,passed:bool,effort_seconds:float,evidence_refs:list<string>,missing_evidence_refs:list<string>,invalid_evidence_refs:list<string>}
     */
    private function participantMetrics(array $payload, string $receiptsRoot, string $caseId, string $taskKind, string $participant, ?string $taskPromptSha256): array
    {
        $elapsed = $this->nonNegativeFloat($payload['elapsed_seconds'] ?? null);
        $manualSteps = $this->nonNegativeFloat($payload['manual_steps'] ?? null);
        $providerCalls = $this->nonNegativeFloat($payload['provider_calls'] ?? null);
        $evidenceRefs = $this->evidenceRefs($payload['evidence_refs'] ?? []);
        $missingEvidenceRefs = [];
        $invalidEvidenceRefs = [];
        foreach ($evidenceRefs as $ref) {
            $path = $receiptsRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $ref);
            if (! is_file($path)) {
                $missingEvidenceRefs[] = $ref;

                continue;
            }

            if (! $this->sourceEvidenceMatches($path, $receiptsRoot, $ref, $caseId, $taskKind, $participant, $taskPromptSha256, $payload)) {
                $invalidEvidenceRefs[] = $ref;
            }
        }
        $passed = ($payload['status'] ?? null) === 'passed'
            && ($payload['verification_passed'] ?? null) === true;

        return [
            'valid' => $elapsed !== null
                && $manualSteps !== null
                && $providerCalls !== null
                && $evidenceRefs !== []
                && $missingEvidenceRefs === []
                && $invalidEvidenceRefs === [],
            'passed' => $passed,
            'effort_seconds' => (float) ($elapsed ?? 0.0)
                + (float) ($manualSteps ?? 0.0) * self::MANUAL_STEP_SECONDS
                + (float) ($providerCalls ?? 0.0) * self::PROVIDER_CALL_SECONDS,
            'evidence_refs' => $evidenceRefs,
            'missing_evidence_refs' => array_values($missingEvidenceRefs),
            'invalid_evidence_refs' => array_values($invalidEvidenceRefs),
        ];
    }

    /**
     * @param  array<string,mixed>  $participantPayload
     */
    private function sourceEvidenceMatches(string $path, string $receiptsRoot, string $sourceRef, string $caseId, string $taskKind, string $participant, ?string $taskPromptSha256, array $participantPayload): bool
    {
        $decoded = JsonFileStore::readArray($path);
        if (! is_array($decoded)) {
            return false;
        }
        $runRef = AiValueNormalizer::trimmedStringOrNull($decoded['run_ref'] ?? null);

        return ($decoded['schema_version'] ?? null) === 'atlas.dev.desktop_efficiency_source.v1'
            && ($decoded['case_id'] ?? null) === $caseId
            && ($decoded['task_kind'] ?? null) === $taskKind
            && ($decoded['participant'] ?? null) === $participant
            && is_string($taskPromptSha256)
            && is_string($decoded['task_prompt'] ?? null)
            && hash('sha256', (string) $decoded['task_prompt']) === $taskPromptSha256
            && ($decoded['task_prompt_sha256'] ?? null) === $taskPromptSha256
            && ($decoded['status'] ?? null) === 'passed'
            && ($decoded['verification_passed'] ?? null) === true
            && $runRef !== null
            && $this->validRelativeRef($runRef)
            && $runRef !== $sourceRef
            && $this->rawRunRefMatches($receiptsRoot, $runRef, $caseId, $taskKind, $participant)
            && $this->validObservedAt($decoded['observed_at'] ?? null)
            && $this->sameNumericMetric($decoded, $participantPayload, 'elapsed_seconds')
            && $this->sameNumericMetric($decoded, $participantPayload, 'manual_steps')
            && $this->sameNumericMetric($decoded, $participantPayload, 'provider_calls');
    }

    /**
     * @param  array<string,mixed>  $source
     * @return list<string>
     */
    private function sourceRecordBlockers(array $source, string $caseId, string $taskKind, string $participant, string $taskPromptSha256, string $receiptsRoot, string $sourceRef): array
    {
        $blockers = [];
        $expectations = [
            'schema_version' => 'atlas.dev.desktop_efficiency_source.v1',
            'case_id' => $caseId,
            'task_kind' => $taskKind,
            'participant' => $participant,
            'task_prompt_sha256' => $taskPromptSha256,
            'status' => 'passed',
            'verification_passed' => true,
        ];

        foreach ($expectations as $key => $expected) {
            if (($source[$key] ?? null) !== $expected) {
                $blockers[] = $key.'_invalid';
            }
        }

        foreach (['elapsed_seconds', 'manual_steps', 'provider_calls'] as $metric) {
            if ($this->nonNegativeFloat($source[$metric] ?? null) === null) {
                $blockers[] = $metric.'_invalid';
            }
        }
        $taskPrompt = AiValueNormalizer::trimmedStringOrNull($source['task_prompt'] ?? null);
        if ($taskPrompt === null) {
            $blockers[] = 'task_prompt_missing';
        } elseif (hash('sha256', $taskPrompt) !== $taskPromptSha256) {
            $blockers[] = 'task_prompt_sha256_mismatch';
        }
        if (! $this->validObservedAt($source['observed_at'] ?? null)) {
            $blockers[] = 'observed_at_invalid';
        }
        $runRef = AiValueNormalizer::trimmedStringOrNull($source['run_ref'] ?? null);
        if ($runRef === null) {
            $blockers[] = 'run_ref_missing';
        } elseif (! $this->validRelativeRef($runRef)) {
            $blockers[] = 'run_ref_invalid';
        } elseif ($runRef === $sourceRef) {
            $blockers[] = 'run_ref_self_reference';
        } elseif (! is_file($receiptsRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $runRef))) {
            $blockers[] = 'run_ref_not_found';
        } elseif (! $this->rawRunRefMatches($receiptsRoot, $runRef, $caseId, $taskKind, $participant)) {
            $blockers[] = 'run_ref_invalid_payload';
        }

        return AtlasDevStringListNormalizer::uniqueTrimmedStrings($blockers);
    }

    private function rawRunRefMatches(string $receiptsRoot, string $runRef, string $caseId, string $taskKind, string $participant): bool
    {
        $path = $receiptsRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $runRef);
        if (! is_file($path)) {
            return false;
        }

        $decoded = JsonFileStore::readArray($path);
        if (! is_array($decoded)) {
            return false;
        }

        return ($decoded['schema_version'] ?? null) === 'atlas.dev.desktop_efficiency_raw_run.v1'
            && ($decoded['status'] ?? null) === 'passed'
            && ($decoded['case_id'] ?? null) === $caseId
            && ($decoded['task_kind'] ?? null) === $taskKind
            && ($decoded['participant'] ?? null) === $participant
            && AiValueNormalizer::trimmedStringOrNull($decoded['summary'] ?? null) !== null
            && AiValueNormalizer::trimmedStringOrNull($decoded['verification_command'] ?? null) !== null
            && ($decoded['verification_passed'] ?? null) === true
            && $this->validObservedAt($decoded['captured_at'] ?? null);
    }

    private function validObservedAt(mixed $value): bool
    {
        if (! is_string($value) || trim($value) === '') {
            return false;
        }

        return strtotime($value) !== false;
    }

    /**
     * @param  array<string,mixed>  $source
     * @param  array<string,mixed>  $participantPayload
     */
    private function sameNumericMetric(array $source, array $participantPayload, string $key): bool
    {
        $sourceValue = $this->nonNegativeFloat($source[$key] ?? null);
        $payloadValue = $this->nonNegativeFloat($participantPayload[$key] ?? null);

        return $sourceValue !== null
            && $payloadValue !== null
            && abs($sourceValue - $payloadValue) < 0.0001;
    }

    private function validRelativeRef(string $ref): bool
    {
        return $ref !== ''
            && ! str_starts_with($ref, '/')
            && ! str_contains($ref, '://')
            && ! str_contains($ref, '..');
    }

    private function nonNegativeFloat(mixed $value): ?float
    {
        if (is_string($value) && is_numeric($value)) {
            $value = (float) $value;
        }

        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        $float = (float) $value;

        return $float >= 0.0 ? $float : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function templateCase(string $taskKind): array
    {
        $caseId = $taskKind.'_case';

        return [
            'case_id' => $caseId,
            'task_kind' => $taskKind,
            'task_prompt' => $taskPrompt = $this->templateTaskPrompt($taskKind),
            'task_prompt_sha256' => hash('sha256', $taskPrompt),
            'atlas' => $this->templateParticipant($caseId, 'atlas'),
            'claude_code' => $this->templateParticipant($caseId, 'claude_code'),
            'codex' => $this->templateParticipant($caseId, 'codex'),
        ];
    }

    private function templateTaskPrompt(string $taskKind): string
    {
        return match ($taskKind) {
            'patch' => 'Apply a narrow code patch in the workspace and verify the changed behavior with the focused test named in the task.',
            'repair' => 'Diagnose one failing test or runtime error in the workspace, patch the root cause, and rerun the focused verification command.',
            'review' => 'Review the provided workspace diff, identify concrete defects or risks, and return only actionable findings with file references.',
            'frontend' => 'Implement a scoped frontend UI change in the workspace, preserve existing design conventions, and run the declared frontend verification.',
            'question' => 'Answer a repo-bound workspace question using only confirmed code references and no provider-side patch.',
            default => 'Execute the observed workspace-dev task exactly as written for all participants.',
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function templateParticipant(string $caseId, string $participant): array
    {
        return [
            'status' => 'blocked',
            'verification_passed' => false,
            'elapsed_seconds' => 0,
            'manual_steps' => 0,
            'provider_calls' => 0,
            'evidence_refs' => [
                'desktop_efficiency/source/'.$caseId.'/'.$participant.'.json',
            ],
        ];
    }

    /**
     * @return array<string,string>
     */
    private function casePromptsFromPath(string $casesPath): array
    {
        if ($casesPath === '' || ! is_file($casesPath)) {
            return [];
        }

        $decoded = JsonFileStore::readArray($casesPath);
        if (! is_array($decoded)) {
            return [];
        }

        $prompts = [];
        foreach ((array) ($decoded['cases'] ?? []) as $case) {
            if (! is_array($case)) {
                continue;
            }

            $caseId = AiValueNormalizer::trimmedStringOrNull($case['case_id'] ?? null);
            $taskPrompt = AiValueNormalizer::trimmedStringOrNull($case['task_prompt'] ?? null);
            if ($caseId === null || $taskPrompt === null) {
                continue;
            }

            $prompts[$caseId] = $taskPrompt;
        }

        return $prompts;
    }

    /**
     * @return list<string>
     */
    private function evidenceRefs(mixed $value): array
    {
        $refs = [];
        foreach ((array) $value as $ref) {
            if (! is_string($ref)) {
                continue;
            }

            $ref = trim($ref);
            if ($ref === '' || str_starts_with($ref, '/') || str_contains($ref, '://') || str_contains($ref, '..')) {
                continue;
            }

            $refs[] = $ref;
        }

        return AtlasDevStringListNormalizer::uniqueTrimmedStrings($refs);
    }
}

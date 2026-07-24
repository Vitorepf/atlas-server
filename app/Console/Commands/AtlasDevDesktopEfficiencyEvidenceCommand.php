<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\AtlasDev\Runtime\AtlasDevDesktopEfficiencyEvidenceService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasDevDesktopEfficiencyEvidenceCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:dev:desktop:efficiency-evidence
        {--input= : Path to atlas.dev.desktop_efficiency_cases.v1 JSON}
        {--write-template= : Write an operator-fillable cases template to this path}
        {--cases= : Optional atlas.dev.desktop_efficiency_cases.v1 JSON used to derive --write-source task prompt hash}
        {--build-cases-from-sources= : Build atlas.dev.desktop_efficiency_cases.v1 JSON from source evidence files at this path}
        {--source-status : Report the 15 source evidence slots still required for the comparative 10x battery}
        {--source-commands : Emit only pending source evidence write commands for collectors}
        {--write-run-ref= : Write one raw observed run evidence file at this relative ref}
        {--write-source= : Write one atlas.dev.desktop_efficiency_source.v1 evidence file at this relative ref}
        {--case-id= : Case id for --write-source}
        {--task-kind= : Task kind for --write-source or --write-run-ref}
        {--participant= : Participant for --write-source or --write-run-ref: atlas, claude_code, or codex}
        {--task-prompt= : Exact observed task prompt for --write-source; defaults to the matching cases.json prompt}
        {--task-prompt-sha256= : SHA-256 of the exact task_prompt from cases.json for --write-source}
        {--elapsed-seconds= : Observed elapsed seconds for --write-source}
        {--manual-steps= : Observed manual operator steps for --write-source}
        {--provider-calls= : Observed provider calls for --write-source}
        {--verification-passed : Mark --write-source verification as passed}
        {--run-ref= : Required relative raw run/evidence ref for --write-source}
        {--raw-summary= : Required observed raw run summary for --write-run-ref}
        {--raw-verification-command= : Required verification command or review check used for --write-run-ref}
        {--notes= : Optional operator notes for --write-source}
        {--persist : Persist the computed evidence to desktop_efficiency/latest.json}
        {--json : Emit canonical JSON}
        {--strict : Return non-zero when the evidence does not prove the 10x requirement}';

    protected $description = 'Compute Atlas Dev Desktop comparative efficiency evidence from operator-supplied cases.';

    public function handle(AtlasDevDesktopEfficiencyEvidenceService $service): int
    {
        $templatePath = (string) ($this->option('write-template') ?: '');
        if ($templatePath !== '') {
            $payload = $service->writeTemplate($templatePath);

            return $this->finish($payload);
        }

        $buildCasesPath = (string) ($this->option('build-cases-from-sources') ?: '');
        if ($buildCasesPath !== '') {
            $payload = $service->buildCasesFromSources($buildCasesPath);

            return $this->finish($payload);
        }

        if ((bool) $this->option('source-status')) {
            $payload = $service->sourceStatus((string) ($this->option('cases') ?: ''));

            return $this->finish($payload);
        }

        if ((bool) $this->option('source-commands')) {
            $payload = $service->sourceCommands((string) ($this->option('cases') ?: ''));

            return $this->finish($payload);
        }

        $runRef = (string) ($this->option('write-run-ref') ?: '');
        if ($runRef !== '') {
            $payload = $service->writeRawRunEvidence(
                ref: $runRef,
                caseId: (string) ($this->option('case-id') ?: ''),
                taskKind: (string) ($this->option('task-kind') ?: ''),
                participant: (string) ($this->option('participant') ?: ''),
                summary: (string) ($this->option('raw-summary') ?: ''),
                verificationCommand: (string) ($this->option('raw-verification-command') ?: ''),
                verificationPassed: (bool) $this->option('verification-passed'),
                notes: (string) ($this->option('notes') ?: ''),
            );

            return $this->finish($payload);
        }

        $sourceRef = (string) ($this->option('write-source') ?: '');
        if ($sourceRef !== '') {
            $caseId = (string) ($this->option('case-id') ?: '');
            $taskPrompt = (string) ($this->option('task-prompt') ?: '');
            if ($taskPrompt === '') {
                $taskPrompt = $this->deriveTaskPrompt($caseId);
            }
            $taskPromptSha256 = (string) ($this->option('task-prompt-sha256') ?: '');
            if ($taskPromptSha256 === '') {
                $taskPromptSha256 = $taskPrompt !== ''
                    ? hash('sha256', $taskPrompt)
                    : $this->deriveTaskPromptSha256($caseId);
            }

            $payload = $service->writeSourceEvidence(
                ref: $sourceRef,
                caseId: $caseId,
                taskKind: (string) ($this->option('task-kind') ?: ''),
                participant: (string) ($this->option('participant') ?: ''),
                taskPrompt: $taskPrompt,
                taskPromptSha256: $taskPromptSha256,
                elapsedSeconds: $this->floatOption('elapsed-seconds'),
                manualSteps: $this->floatOption('manual-steps'),
                providerCalls: $this->floatOption('provider-calls'),
                verificationPassed: (bool) $this->option('verification-passed'),
                runRef: (string) ($this->option('run-ref') ?: ''),
                notes: (string) ($this->option('notes') ?: ''),
            );

            return $this->finish($payload);
        }

        $inputPath = (string) ($this->option('input') ?: '');
        if ($inputPath === '' || ! is_file($inputPath)) {
            $payload = [
                'schema_version' => AtlasDevDesktopEfficiencyEvidenceService::EVIDENCE_SCHEMA_VERSION,
                'status' => 'blocked',
                'measured_multiplier' => 0.0,
                'compared_against' => ['claude_code', 'codex'],
                'case_count' => 0,
                'blocking_findings' => ['input_file_missing'],
            ];

            return $this->finish($payload);
        }

        $decoded = json_decode((string) file_get_contents($inputPath), true);
        $payload = $service->build(
            is_array($decoded) ? $decoded : [],
            basename($inputPath),
        );

        if ((bool) $this->option('persist')) {
            $payload['persistence'] = $service->persist($payload);
        }

        return $this->finish($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function finish(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);
        } else {
            $this->components->twoColumnDetail('Atlas Dev Desktop efficiency evidence', (string) ($payload['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Measured multiplier', (string) ($payload['measured_multiplier'] ?? '0'));
            if (($payload['blocking_findings'] ?? []) !== []) {
                $this->warn('Findings: '.implode(', ', (array) $payload['blocking_findings']));
            }
        }

        return (bool) $this->option('strict') && ($payload['status'] ?? null) !== 'passed'
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function floatOption(string $name): ?float
    {
        $value = $this->option($name);
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function deriveTaskPromptSha256(string $caseId): string
    {
        $taskPrompt = $this->deriveTaskPrompt($caseId);

        return $taskPrompt !== '' ? hash('sha256', $taskPrompt) : '';
    }

    private function deriveTaskPrompt(string $caseId): string
    {
        $path = (string) ($this->option('cases') ?: '');
        if ($path === '') {
            $path = rtrim((string) config('atlas_dev.receipts_path', storage_path('atlas-dev/receipts')), DIRECTORY_SEPARATOR)
                .DIRECTORY_SEPARATOR.'desktop_efficiency'.DIRECTORY_SEPARATOR.'cases.json';
        }

        if ($caseId === '' || ! is_file($path)) {
            return '';
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        foreach ((array) ($decoded['cases'] ?? []) as $case) {
            if (! is_array($case) || ($case['case_id'] ?? null) !== $caseId || ! is_string($case['task_prompt'] ?? null)) {
                continue;
            }

            return $case['task_prompt'];
        }

        return '';
    }
}

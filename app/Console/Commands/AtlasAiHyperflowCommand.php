<?php

namespace App\Console\Commands;

use App\Services\Ai\Router\AtlasAiHyperflowCertificationService;
use App\Services\Ai\Router\AtlasAiHyperflowRivalsBatteryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AtlasAiHyperflowCommand extends Command
{
    protected $signature = 'atlas:ai:hyperflow
        {action=certify : certify, rivals, prepare, run, evidence-template, evidence-candidates, evidence-runbook, evidence-preflight, export-evidence or import-evidence}
        {--prepare : Prepare the Hyperflow rivals battery before running the selected action}
        {--triggered-by=cli : Operator or system label stored on persisted battery runs}
        {--forge-run-id= : Single Forge/Rivals run id to export into a Hyperflow external evidence pack}
        {--forge-run-ids= : Comma-separated Forge/Rivals run ids to export into a Hyperflow external evidence pack}
        {--provider= : Filter evidence-candidates by provider, e.g. claude_code or codex_cli}
        {--limit=80 : Maximum evidence-candidates to inspect}
        {--only-ready : Only list export-ready evidence-candidates}
        {--output-path= : Where to write an exported Hyperflow external evidence pack}
        {--evidence-pack= : Path to an approved external rivals evidence pack}
        {--approved-by=operator : Operator label for imported external evidence}
        {--operator-approved : Confirm operator approval for evidence import}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run Atlas Hyperflow backend certification gates without calling external providers.';

    public function handle(
        AtlasAiHyperflowCertificationService $certification,
        AtlasAiHyperflowRivalsBatteryService $battery,
    ): int {
        $action = strtolower(trim((string) $this->argument('action')));
        $prepared = (bool) $this->option('prepare') || $action === 'prepare'
            ? $battery->prepare()
            : null;

        $payload = match ($action) {
            'prepare' => [
                'schema_version' => 'atlas.ai.hyperflow_command.v1',
                'action' => 'prepare',
                'prepared' => $prepared,
                'status' => ($prepared['status'] ?? null) === 'blocked' ? 'blocked' : 'prepared',
                'writes' => (bool) ($prepared['writes'] ?? false),
            ],
            'run', 'run-battery', 'execute' => [
                'schema_version' => 'atlas.ai.hyperflow_command.v1',
                'action' => 'run',
                'prepared' => $prepared,
                'rivals_battery_run' => $battery->runAndPersist([
                    'triggered_by' => (string) $this->option('triggered-by'),
                ]),
                'writes' => true,
            ],
            'import-evidence', 'import-external-evidence' => [
                'schema_version' => 'atlas.ai.hyperflow_command.v1',
                'action' => 'import-evidence',
                'prepared' => $prepared,
                'external_evidence_import' => $battery->importExternalEvidencePack([
                    'evidence_pack_path' => (string) $this->option('evidence-pack'),
                    'approved_by' => (string) $this->option('approved-by'),
                    'operator_approved' => (bool) $this->option('operator-approved'),
                ]),
                'writes' => true,
            ],
            'export-evidence', 'export-external-evidence' => [
                'schema_version' => 'atlas.ai.hyperflow_command.v1',
                'action' => 'export-evidence',
                'prepared' => $prepared,
                'external_evidence_export' => $battery->exportExternalEvidencePack([
                    'forge_run_id' => (string) $this->option('forge-run-id'),
                    'forge_run_ids' => (string) $this->option('forge-run-ids'),
                    'output_path' => (string) $this->option('output-path'),
                    'approved_by' => (string) $this->option('approved-by'),
                    'operator_approved' => (bool) $this->option('operator-approved'),
                ]),
                'writes' => true,
            ],
            'evidence-preflight', 'external-evidence-preflight', 'preflight' => [
                'schema_version' => 'atlas.ai.hyperflow_command.v1',
                'action' => 'evidence-preflight',
                'prepared' => $prepared,
                'external_evidence_preflight' => $battery->externalEvidencePreflight([
                    'forge_run_id' => (string) $this->option('forge-run-id'),
                    'forge_run_ids' => (string) $this->option('forge-run-ids'),
                ]),
                'writes' => (bool) ($prepared['writes'] ?? false),
            ],
            'evidence-candidates', 'external-evidence-candidates', 'candidates' => [
                'schema_version' => 'atlas.ai.hyperflow_command.v1',
                'action' => 'evidence-candidates',
                'prepared' => $prepared,
                'external_evidence_candidates' => $battery->externalEvidenceCandidates([
                    'provider' => (string) $this->option('provider'),
                    'limit' => (int) $this->option('limit'),
                    'only_ready' => (bool) $this->option('only-ready'),
                ]),
                'writes' => (bool) ($prepared['writes'] ?? false),
            ],
            'evidence-runbook', 'external-evidence-runbook', 'runbook' => [
                'schema_version' => 'atlas.ai.hyperflow_command.v1',
                'action' => 'evidence-runbook',
                'prepared' => $prepared,
                'external_evidence_runbook' => $battery->externalEvidenceRunbook([
                    'limit' => (int) $this->option('limit'),
                    'approved_by' => (string) $this->option('approved-by'),
                ]),
                'writes' => (bool) ($prepared['writes'] ?? false),
            ],
            'evidence-template', 'external-evidence-template', 'template' => $this->evidenceTemplatePayload($battery, $prepared),
            'rivals', 'rivals-battery', 'battery', 'status' => [
                'schema_version' => 'atlas.ai.hyperflow_command.v1',
                'action' => 'rivals',
                'prepared' => $prepared,
                'rivals_battery' => $battery->status(),
                'writes' => (bool) ($prepared['writes'] ?? false),
            ],
            'certify', 'certification' => [
                'schema_version' => 'atlas.ai.hyperflow_command.v1',
                'action' => 'certify',
                'prepared' => $prepared,
                'certification' => $certification->certify(),
                'writes' => (bool) ($prepared['writes'] ?? false),
            ],
            default => [
                'schema_version' => 'atlas.ai.hyperflow_command.v1',
                'action' => $action,
                'status' => 'failed',
                'error' => 'unsupported_action',
                'supported_actions' => ['certify', 'rivals', 'prepare', 'run', 'evidence-template', 'evidence-candidates', 'evidence-runbook', 'evidence-preflight', 'export-evidence', 'import-evidence'],
                'writes' => false,
            ],
        };

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $this->exitCodeFor($action, $payload);
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Hyperflow</>', $action);
        if ($action === 'prepare') {
            $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Writes', ($payload['writes'] ?? false) ? 'yes' : 'no');
        } elseif (in_array($action, ['run', 'run-battery', 'execute'], true)) {
            $this->components->twoColumnDetail('Run', (string) data_get($payload, 'rivals_battery_run.status', 'unknown'));
            $this->components->twoColumnDetail('Ready', data_get($payload, 'rivals_battery_run.ready') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Next', (string) data_get($payload, 'rivals_battery_run.next_action', 'unknown'));
        } elseif (in_array($action, ['import-evidence', 'import-external-evidence'], true)) {
            $this->components->twoColumnDetail('Import', (string) data_get($payload, 'external_evidence_import.status', 'unknown'));
            $this->components->twoColumnDetail('Writes', data_get($payload, 'external_evidence_import.writes') ? 'yes' : 'no');
        } elseif (in_array($action, ['export-evidence', 'export-external-evidence'], true)) {
            $this->components->twoColumnDetail('Export', (string) data_get($payload, 'external_evidence_export.status', 'unknown'));
            $this->components->twoColumnDetail('Pack', (string) data_get($payload, 'external_evidence_export.evidence_pack.path', 'unknown'));
            $this->components->twoColumnDetail('Ready', data_get($payload, 'external_evidence_export.ready') ? 'yes' : 'no');
        } elseif (in_array($action, ['evidence-template', 'external-evidence-template', 'template'], true)) {
            $this->components->twoColumnDetail('Template', (string) data_get($payload, 'external_evidence_template.status', 'unknown'));
            $this->components->twoColumnDetail('Import API', (string) data_get($payload, 'external_evidence_template.import.api', 'unknown'));
            if (data_get($payload, 'template_written.path')) {
                $this->components->twoColumnDetail('Written', (string) data_get($payload, 'template_written.path'));
            }
        } elseif (in_array($action, ['evidence-preflight', 'external-evidence-preflight', 'preflight'], true)) {
            $this->components->twoColumnDetail('Preflight', (string) data_get($payload, 'external_evidence_preflight.status', 'unknown'));
            $this->components->twoColumnDetail('Certification ready', data_get($payload, 'external_evidence_preflight.certification_ready') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Export ready', data_get($payload, 'external_evidence_preflight.candidate_export_ready') ? 'yes' : 'no');
        } elseif (in_array($action, ['evidence-candidates', 'external-evidence-candidates', 'candidates'], true)) {
            $this->components->twoColumnDetail('Candidates', (string) data_get($payload, 'external_evidence_candidates.status', 'unknown'));
            $this->components->twoColumnDetail('Count', (string) data_get($payload, 'external_evidence_candidates.candidate_count', 0));
            $this->components->twoColumnDetail('Next', (string) data_get($payload, 'external_evidence_candidates.next_action', 'unknown'));
        } elseif (in_array($action, ['evidence-runbook', 'external-evidence-runbook', 'runbook'], true)) {
            $this->components->twoColumnDetail('Runbook', (string) data_get($payload, 'external_evidence_runbook.status', 'unknown'));
            $this->components->twoColumnDetail('Next', (string) data_get($payload, 'external_evidence_runbook.next_action', 'unknown'));
        } elseif (in_array($action, ['rivals', 'rivals-battery', 'battery', 'status'], true)) {
            $this->components->twoColumnDetail('Battery', (string) data_get($payload, 'rivals_battery.status', 'unknown'));
            $this->components->twoColumnDetail('Ready', data_get($payload, 'rivals_battery.ready') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Next', (string) data_get($payload, 'rivals_battery.next_action', 'unknown'));
        } elseif (in_array($action, ['certify', 'certification'], true)) {
            $this->components->twoColumnDetail('Certification', (string) data_get($payload, 'certification.status', 'unknown'));
            $this->components->twoColumnDetail('Failed checks', (string) data_get($payload, 'certification.summary.failed', 0));
            foreach ((array) data_get($payload, 'certification.remaining_blockers', []) as $blocker) {
                $this->warn((string) $blocker);
            }
        } else {
            $this->error('Unsupported action: '.$action);
        }

        return $this->exitCodeFor($action, $payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function exitCodeFor(string $action, array $payload): int
    {
        return match ($action) {
            'prepare' => ($payload['status'] ?? null) === 'prepared' ? self::SUCCESS : self::FAILURE,
            'run', 'run-battery', 'execute' => data_get($payload, 'rivals_battery_run.ready') === true ? self::SUCCESS : self::FAILURE,
            'import-evidence', 'import-external-evidence' => data_get($payload, 'external_evidence_import.writes') === true ? self::SUCCESS : self::FAILURE,
            'export-evidence', 'export-external-evidence' => data_get($payload, 'external_evidence_export.ready') === true ? self::SUCCESS : self::FAILURE,
            'evidence-template', 'external-evidence-template', 'template' => data_get($payload, 'external_evidence_template.status') === 'ready' ? self::SUCCESS : self::FAILURE,
            'evidence-preflight', 'external-evidence-preflight', 'preflight' => data_get($payload, 'external_evidence_preflight.status') === 'ready' ? self::SUCCESS : self::FAILURE,
            'evidence-candidates', 'external-evidence-candidates', 'candidates' => data_get($payload, 'external_evidence_candidates.candidate_count', 0) > 0 ? self::SUCCESS : self::FAILURE,
            'evidence-runbook', 'external-evidence-runbook', 'runbook' => data_get($payload, 'external_evidence_runbook.schema_version') === 'atlas.ai.hyperflow_external_evidence_runbook.v1' ? self::SUCCESS : self::FAILURE,
            'rivals', 'rivals-battery', 'battery', 'status' => data_get($payload, 'rivals_battery.ready') === true ? self::SUCCESS : self::FAILURE,
            'certify', 'certification' => data_get($payload, 'certification.status') === 'passed' ? self::SUCCESS : self::FAILURE,
            default => self::FAILURE,
        };
    }

    /**
     * @param  array<string,mixed>|null  $prepared
     * @return array<string,mixed>
     */
    private function evidenceTemplatePayload(AtlasAiHyperflowRivalsBatteryService $battery, ?array $prepared): array
    {
        $template = $battery->externalEvidencePackTemplate();
        $outputPath = trim((string) $this->option('output-path'));
        $templateWritten = null;

        if ($outputPath !== '') {
            $content = json_encode(data_get($template, 'template', []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
            File::ensureDirectoryExists(dirname($outputPath));
            File::put($outputPath, $content);
            $templateWritten = [
                'schema_version' => 'atlas.ai.hyperflow_external_evidence_pack_template_write.v1',
                'path' => $outputPath,
                'sha256' => hash('sha256', $content),
                'bytes' => strlen($content),
                'certification_ready' => false,
                'next_action' => 'fill_real_external_provider_evidence_then_import',
            ];
        }

        return [
            'schema_version' => 'atlas.ai.hyperflow_command.v1',
            'action' => 'evidence-template',
            'prepared' => $prepared,
            'external_evidence_template' => $template,
            'template_written' => $templateWritten,
            'writes' => (bool) ($prepared['writes'] ?? false) || $templateWritten !== null,
        ];
    }
}

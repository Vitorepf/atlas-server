<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * L4-14: final M capture for the Fable Lists 4-5-6 campaign.
 *
 * This service does not create a new memory system. It proves that the campaign
 * leaves behind canonical docs, frozen tests, provider-safe projections, KB/code
 * index receipts, and a cold-session context-pack receipt that another session
 * can verify without relying on the current chat.
 */
final class AtlasFableFinalCaptureService
{
    public const SCHEMA_VERSION = 'atlas.fable.l4_14.final_capture.v1';

    public const RECEIPT_SCHEMA_VERSION = 'atlas.fable.l4_14.ritual_receipt.v1';

    public const OPERATOR_PROOF_REQUEST_SCHEMA_VERSION = 'atlas.fable.l4_14.operator_proof_request.v1';

    public const DEFAULT_CAPTURE_PATH = 'app/atlas/evidence/fable-l4-14-final-capture.json';

    public const DEFAULT_OPERATOR_PROOF_REQUEST_PATH = 'app/atlas/evidence/fable-l4-14-operator-proof-request.json';

    public const DEFAULT_RECEIPT_DIR = 'app/atlas/evidence/fable-l4-14';

    private const OPERATOR_PROOF_TEMPLATE_FILES = [
        'L4-9' => 'l4-9-operator-comparison-template.json',
        'L4-10' => 'l4-10-real-obra-receipt-template.json',
    ];

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function capture(array $options = []): array
    {
        $workspace = $this->stringOrNull($options['workspace'] ?? null) ?? base_path();
        $finalReportPath = $this->stringOrNull($options['final_report_path'] ?? null)
            ?? storage_path(AtlasFableFinalReportService::DEFAULT_REPORT_PATH);
        $packetPath = $this->stringOrNull($options['packet_path'] ?? null)
            ?? storage_path(AtlasFableFinalReportService::DEFAULT_PACKET_PATH);
        $operatorEvidenceIngest = $this->operatorEvidenceIngest($options, $finalReportPath, $packetPath);

        $ritualReceipts = (bool) ($options['run_ritual'] ?? false)
            ? $this->runRitual($workspace, $options)
            : $this->loadRitualReceipts($options);

        $finalReport = $this->finalReportCheck($finalReportPath, $packetPath, $options);
        $campaignArtifacts = $this->fileChecks('campaign_artifacts', $this->campaignArtifactFiles());
        $frozenTests = $this->fileChecks('frozen_tests', $this->frozenTestFiles());
        $commands = $this->commandChecks($this->requiredCommands());
        $ritual = $this->ritualChecks($ritualReceipts);
        $coldSession = $this->coldSessionRecoveryCheck($finalReport, $campaignArtifacts, $commands, $ritual);
        $operatorGatedExternalProofs = (array) ($finalReport['operator_gated_external_proofs'] ?? [
            'status' => 'unknown',
            'pending' => ['final_report_external_proof_gate_missing'],
        ]);
        $operatorProofRequest = $this->operatorProofRequest($operatorGatedExternalProofs);
        $operatorProofRequestPath = (bool) ($options['write_operator_proof_request'] ?? false)
            ? ($this->stringOrNull($options['operator_proof_request_path'] ?? null)
                ?? storage_path(self::DEFAULT_OPERATOR_PROOF_REQUEST_PATH))
            : null;
        $operatorProofTemplatePaths = $this->operatorProofTemplatePaths(
            $operatorProofRequestPath ?? storage_path(self::DEFAULT_OPERATOR_PROOF_REQUEST_PATH),
        );
        $operatorProofTemplatePaths = array_intersect_key(
            $operatorProofTemplatePaths,
            array_filter(
                (array) ($operatorProofRequest['items'] ?? []),
                static fn (mixed $item): bool => is_array($item) && is_array($item['template_payload'] ?? null),
            ),
        );
        $operatorProofRequest['artifact'] = [
            'write_requested' => $operatorProofRequestPath !== null,
            'path' => $operatorProofRequestPath ?? storage_path(self::DEFAULT_OPERATOR_PROOF_REQUEST_PATH),
            'template_paths' => $operatorProofTemplatePaths,
        ];

        $blockers = [
            ...$this->blockersFromCheck('final_report', $finalReport),
            ...$this->blockersFromCheck('campaign_artifacts', $campaignArtifacts),
            ...$this->blockersFromCheck('frozen_tests', $frozenTests),
            ...$this->blockersFromCheck('commands', $commands),
            ...$this->blockersFromRitual($ritual),
            ...$this->blockersFromCheck('cold_session_recovery', $coldSession),
        ];

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $this->captureStatus($blockers, $operatorGatedExternalProofs),
            'generated_at' => Carbon::now()->toIso8601String(),
            'scope' => [
                'campaign' => 'fable-listas-4-5-6',
                'item' => 'L4-14',
                'claim' => 'final M capture is recorded in canonical docs, tests, KB/code indexes, provider projections, and cold-session receipts',
            ],
            'final_report' => $finalReport,
            'operator_evidence_ingest' => $operatorEvidenceIngest,
            'campaign_artifacts' => $campaignArtifacts,
            'frozen_tests' => $frozenTests,
            'commands' => $commands,
            'ritual' => $ritual,
            'cold_session_recovery' => $coldSession,
            'operator_gated_external_proofs' => $operatorGatedExternalProofs,
            'operator_proof_request' => $operatorProofRequest,
            'stale_memory_corrections' => [
                'status' => 'captured_for_operator_audit',
                'minimum_campaign_findings' => 2,
                'policy' => 'stale memories are fixed through canonical docs plus provider-safe projection regeneration, never by trusting a chat transcript',
            ],
            'blockers' => $blockers,
            'claim_policy' => [
                'provider_dispatches_now' => false,
                'raw_sensitive_context_included' => false,
                'synthetic_completion_claim_allowed' => false,
                'recorded_artifact_capture_claim_allowed' => $blockers === [],
                'operator_gated_external_proof_claim_allowed' => ($operatorGatedExternalProofs['status'] ?? null) === 'ready',
                'operator_proof_request_claim_allowed' => ($operatorProofRequest['status'] ?? null) === 'not_required',
                'completion_claim_allowed' => $blockers === [] && ($operatorGatedExternalProofs['status'] ?? null) === 'ready',
                'list_4_completion_claim_allowed' => $blockers === [] && ($operatorGatedExternalProofs['status'] ?? null) === 'ready',
                'honesty_note' => $this->honestyNote($operatorGatedExternalProofs),
            ],
        ];

        if ($operatorProofRequestPath !== null) {
            $payload['written_operator_proof_template_paths'] = $this->writeOperatorProofTemplateFiles(
                $operatorProofRequest,
                $operatorProofTemplatePaths,
            );
            $this->writeJson($operatorProofRequestPath, $operatorProofRequest);
            $payload['written_operator_proof_request_path'] = $operatorProofRequestPath;
        }

        if ((bool) ($options['write_capture'] ?? false)) {
            $capturePath = $this->stringOrNull($options['capture_path'] ?? null)
                ?? storage_path(self::DEFAULT_CAPTURE_PATH);
            $this->writeJson($capturePath, $payload);
            $payload['written_capture_path'] = $capturePath;
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function operatorEvidenceIngest(array $options, string $finalReportPath, string $packetPath): array
    {
        $devBeatEvidencePath = $this->stringOrNull($options['dev_beat_evidence_path'] ?? null);
        $forgeEvidencePath = $this->stringOrNull($options['forge_evidence_path'] ?? null);
        $requested = $devBeatEvidencePath !== null || $forgeEvidencePath !== null;

        if (! $requested) {
            return [
                'status' => 'not_requested',
                'requested' => false,
                'provider_dispatches_now' => false,
            ];
        }

        $report = app(AtlasFableFinalReportService::class)->report([
            'baseline_path' => $this->stringOrNull($options['baseline_path'] ?? null),
            'series_path' => $this->stringOrNull($options['series_path'] ?? null),
            'date' => $this->stringOrNull($options['date'] ?? null),
            'hours' => max(1, min(168, (int) ($options['hours'] ?? 24))),
            'dev_beat_evidence_path' => $devBeatEvidencePath,
            'forge_evidence_path' => $forgeEvidencePath,
            'write_report' => true,
            'report_path' => $finalReportPath,
            'write_packet' => true,
            'packet_path' => $packetPath,
        ]);

        return [
            'status' => 'refreshed_final_report',
            'requested' => true,
            'dev_beat_evidence_path' => $devBeatEvidencePath,
            'forge_evidence_path' => $forgeEvidencePath,
            'refreshed_final_report_path' => $finalReportPath,
            'refreshed_packet_path' => $packetPath,
            'final_report_status' => (string) ($report['status'] ?? 'unknown'),
            'l4_9_status' => (string) data_get($report, 'source_reports.dev_beat_test.status', 'unknown'),
            'l4_9_external_comparison_claim_allowed' => (bool) data_get(
                $report,
                'source_reports.dev_beat_test.claim_policy.external_comparison_claim_allowed',
                false,
            ),
            'l4_9_external_superiority_claim_allowed' => (bool) data_get(
                $report,
                'source_reports.dev_beat_test.claim_policy.external_superiority_claim_allowed',
                false,
            ),
            'l4_10_status' => (string) data_get($report, 'source_reports.forge_l4_10_proof.status', 'unknown'),
            'l4_10_certified' => (bool) data_get($report, 'source_reports.forge_l4_10_proof.certified', false),
            'claim_policy' => [
                'provider_dispatches_now' => false,
                'synthetic_completion_claim_allowed' => false,
                'report_refresh_is_claim_only_after_downstream_gates_pass' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,array<string,mixed>>
     */
    private function runRitual(string $workspace, array $options): array
    {
        $task = $this->stringOrNull($options['context_task'] ?? null)
            ?? 'Fable Lists 4-5-6 cold session recovery after L4-14 final capture';

        $commands = [
            'knowledge_sync' => [
                'command' => 'atlas:engineering:knowledge',
                'arguments' => [
                    'action' => 'sync',
                    '--prune' => true,
                    '--json' => true,
                ],
            ],
            'code_index' => [
                'command' => 'atlas:engineering:knowledge',
                'arguments' => [
                    'action' => 'index-code',
                    '--workspace' => $workspace,
                    '--prune' => true,
                    '--summary-only' => true,
                    '--json' => true,
                ],
            ],
            'projection_write' => [
                'command' => 'atlas:memory:projection',
                'arguments' => [
                    'action' => 'write',
                    '--target' => 'all',
                    '--workspace' => $workspace,
                    '--yes' => true,
                    '--json' => true,
                ],
            ],
            'projection_status' => [
                'command' => 'atlas:memory:projection',
                'arguments' => [
                    'action' => 'status',
                    '--target' => 'all',
                    '--workspace' => $workspace,
                    '--json' => true,
                ],
            ],
            'docs_health' => [
                'command' => 'atlas:engineering:knowledge',
                'arguments' => [
                    'action' => 'docs-health',
                    '--json' => true,
                ],
            ],
            'context_pack' => [
                'command' => 'atlas:context-pack',
                'arguments' => [
                    'task' => $task,
                    '--workspace' => $workspace,
                    '--changed-file' => [
                        'docs/fable-lista-4-14-itens.md',
                        'docs/fable-lista-5-14-itens.md',
                        'docs/fable-lista-6-14-itens.md',
                        'app/Services/Ai/Programming/AtlasFableFinalCaptureService.php',
                    ],
                    '--json' => true,
                ],
            ],
        ];

        $receipts = [];
        foreach ($commands as $key => $spec) {
            $path = $this->receiptPathForKey($key, $options);
            $receipts[$key] = $this->runCommandReceipt(
                $key,
                (string) $spec['command'],
                (array) $spec['arguments'],
                $path,
            );
        }

        return $receipts;
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,array<string,mixed>>
     */
    private function loadRitualReceipts(array $options): array
    {
        $receipts = [];
        foreach ($this->receiptKeys() as $key) {
            $path = $this->receiptPathForKey($key, $options);
            $receipts[$key] = $this->loadReceipt($key, $path);
        }

        return $receipts;
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function runCommandReceipt(string $key, string $command, array $arguments, string $path): array
    {
        $out = new BufferedOutput;
        $exit = null;
        $raw = '';
        $error = null;

        try {
            $exit = Artisan::call($command, $arguments, $out);
            $raw = trim($out->fetch());
        } catch (Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 500);
        }

        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $receipt = [
            'schema_version' => self::RECEIPT_SCHEMA_VERSION,
            'key' => $key,
            'command' => $this->commandLine($command, $arguments),
            'exit_code' => $exit,
            'captured_at' => Carbon::now()->toIso8601String(),
            'parsed' => is_array($decoded) ? $decoded : null,
            'raw_excerpt' => is_array($decoded) ? null : mb_substr($raw, 0, 1200),
            'error' => $error,
        ];

        $this->writeJson($path, $receipt);

        return $receipt + ['path' => $path];
    }

    /**
     * @return array<string,mixed>
     */
    private function loadReceipt(string $key, string $path): array
    {
        if (! is_file($path)) {
            return [
                'schema_version' => self::RECEIPT_SCHEMA_VERSION,
                'key' => $key,
                'status' => 'missing',
                'path' => $path,
            ];
        }

        $decoded = json_decode((string) @file_get_contents($path), true);
        if (! is_array($decoded)) {
            return [
                'schema_version' => self::RECEIPT_SCHEMA_VERSION,
                'key' => $key,
                'status' => 'invalid_json',
                'path' => $path,
            ];
        }

        if (($decoded['schema_version'] ?? null) === self::RECEIPT_SCHEMA_VERSION) {
            return $decoded + ['path' => $path];
        }

        return [
            'schema_version' => self::RECEIPT_SCHEMA_VERSION,
            'key' => $key,
            'command' => 'loaded external JSON receipt',
            'exit_code' => $decoded['_command_exit_code'] ?? null,
            'captured_at' => Carbon::now()->toIso8601String(),
            'parsed' => $decoded,
            'path' => $path,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function finalReportCheck(string $reportPath, string $packetPath): array
    {
        $blockers = [];
        $report = null;
        $operatorGatedExternalProofs = [
            'status' => 'unknown',
            'pending' => ['final_report_unavailable'],
        ];
        if (! is_file($reportPath)) {
            $blockers[] = 'final_report_missing';
        } else {
            $decoded = json_decode((string) @file_get_contents($reportPath), true);
            if (! is_array($decoded)) {
                $blockers[] = 'final_report_invalid_json';
            } else {
                $report = $decoded;
                if (($report['schema_version'] ?? null) !== AtlasFableFinalReportService::SCHEMA_VERSION) {
                    $blockers[] = 'final_report_schema_mismatch';
                }
                if (($report['status'] ?? null) !== 'ready_with_operator_gated_external_proofs') {
                    $blockers[] = 'final_report_not_ready';
                }
                $operatorGatedExternalProofs = $this->operatorGatedExternalProofs($report);
            }
        }

        $packet = app(AtlasFableFinalReportService::class)->verifyPacketPath($packetPath);
        if (($packet['status'] ?? null) !== 'ready') {
            $blockers[] = 'handoff_packet_not_ready';
        }

        return [
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'report_path' => $reportPath,
            'packet_path' => $packetPath,
            'report_schema' => is_array($report) ? ($report['schema_version'] ?? null) : null,
            'report_status' => is_array($report) ? ($report['status'] ?? null) : null,
            'packet_verification' => $packet,
            'operator_gated_external_proofs' => $operatorGatedExternalProofs,
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  array<int,string>  $blockers
     * @param  array<string,mixed>  $operatorGatedExternalProofs
     */
    private function captureStatus(array $blockers, array $operatorGatedExternalProofs): string
    {
        if ($blockers !== []) {
            return 'blocked';
        }

        return ($operatorGatedExternalProofs['status'] ?? null) === 'ready'
            ? 'ready'
            : 'ready_with_operator_gated_external_proofs';
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function operatorGatedExternalProofs(array $report): array
    {
        $devStatus = (string) data_get($report, 'source_reports.dev_beat_test.status', 'unknown');
        $devExternalComparisonAllowed = (bool) data_get(
            $report,
            'source_reports.dev_beat_test.claim_policy.external_comparison_claim_allowed',
            false,
        );
        $devExternalSuperiorityAllowed = (bool) data_get(
            $report,
            'source_reports.dev_beat_test.claim_policy.external_superiority_claim_allowed',
            false,
        );

        $forgeStatus = (string) data_get($report, 'source_reports.forge_l4_10_proof.status', 'unknown');
        $forgeCertified = (bool) data_get($report, 'source_reports.forge_l4_10_proof.certified', false);

        $pending = [];
        if ($devStatus !== 'atlas_dev_beats_baseline' || ! $devExternalComparisonAllowed || ! $devExternalSuperiorityAllowed) {
            $pending[] = 'L4-9:atlas_dev_external_benchmark_receipt_missing_or_not_winning';
        }
        if ($forgeStatus !== 'certified' || ! $forgeCertified) {
            $pending[] = 'L4-10:real_multi_node_obra_receipt_missing_or_rejected';
        }

        return [
            'schema_version' => 'atlas.fable.l4_14.operator_gated_external_proofs.v1',
            'status' => $pending === [] ? 'ready' : 'pending_operator_evidence',
            'pending_count' => count($pending),
            'pending' => $pending,
            'l4_9' => [
                'status' => $devStatus,
                'external_comparison_claim_allowed' => $devExternalComparisonAllowed,
                'external_superiority_claim_allowed' => $devExternalSuperiorityAllowed,
            ],
            'l4_10' => [
                'status' => $forgeStatus,
                'certified' => $forgeCertified,
            ],
            'claim_policy' => [
                'synthetic_completion_claim_allowed' => false,
                'list_4_completion_claim_allowed' => $pending === [],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $operatorGatedExternalProofs
     * @return array<string,mixed>
     */
    private function operatorProofRequest(array $operatorGatedExternalProofs): array
    {
        $status = (string) ($operatorGatedExternalProofs['status'] ?? 'unknown');
        $pending = array_values((array) ($operatorGatedExternalProofs['pending'] ?? []));
        $l49Status = (string) data_get($operatorGatedExternalProofs, 'l4_9.status', 'unknown');
        $l410Status = (string) data_get($operatorGatedExternalProofs, 'l4_10.status', 'unknown');
        $l410Certified = (bool) data_get($operatorGatedExternalProofs, 'l4_10.certified', false);
        $l49InternalMeasurementAllowed = in_array($l49Status, ['external_claim_blocked', 'atlas_dev_beats_baseline'], true);

        return [
            'schema_version' => self::OPERATOR_PROOF_REQUEST_SCHEMA_VERSION,
            'status' => $status === 'ready' ? 'not_required' : 'pending_operator_evidence',
            'source_gate_status' => $status,
            'pending_count' => count($pending),
            'pending' => $pending,
            'purpose' => 'Make the remaining operator-gated evidence contract explicit without dispatching providers or allowing a synthetic Lista 4 completion claim.',
            'items' => [
                'L4-9' => [
                    'title' => 'Atlas Dev beat-test external baseline',
                    'current_status' => $l49Status,
                    'internal_evidence' => [
                        'status' => $l49InternalMeasurementAllowed ? 'recorded' : 'not_loaded_or_missing',
                        'evidence_path' => 'storage/app/atlas/evidence/fable-l4-9/atlas-dev-three-medium-tasks.json',
                        'report_path' => 'storage/app/atlas/evidence/fable-l4-9/atlas-dev-three-medium-tasks-report.json',
                        'known_result' => $l49InternalMeasurementAllowed ? [
                            'atlas_dev_passed_count' => 3,
                            'atlas_dev_missing_count' => 0,
                            'comparable_external_count' => 0,
                        ] : null,
                    ],
                    'operator_action_required' => 'Provide comparable external developer-agent receipts for the same bug, feature, and refactor classes, then score them with atlas:dev:beat-test.',
                    'required_external_baseline' => [
                        'schema_version' => 'atlas.fable.l4_9.external_baseline_evidence.v1',
                        'minimum_case_count' => 3,
                        'required_task_types' => ['bug', 'feature', 'refactor'],
                        'accepted_external_runners' => ['claude_code', 'cursor', 'operator_approved_equivalent'],
                        'required_fields_per_case' => [
                            'task_type',
                            'task_summary',
                            'runner',
                            'model',
                            'workspace_or_repo_ref',
                            'changed_files',
                            'validation_commands with command + exit_code=0',
                            'duration_ms',
                            'tests_passed',
                            'scope_guard_status',
                            'verification_status',
                            'evidence_refs',
                        ],
                    ],
                    'template_payload' => $this->l49OperatorComparisonTemplate(),
                    'validation' => [
                        'command' => '/opt/homebrew/bin/php artisan atlas:dev:beat-test --evidence=<operator-comparison-evidence.json> --write-report --report-path=storage/app/atlas/evidence/fable-l4-9/operator-comparison-report.json --json',
                        'pass_condition' => 'status=atlas_dev_beats_baseline with external_comparison_claim_allowed=true and external_superiority_claim_allowed=true',
                        'current_external_comparison_claim_allowed' => (bool) data_get($operatorGatedExternalProofs, 'l4_9.external_comparison_claim_allowed', false),
                        'current_external_superiority_claim_allowed' => (bool) data_get($operatorGatedExternalProofs, 'l4_9.external_superiority_claim_allowed', false),
                    ],
                    'claim_policy' => [
                        'provider_dispatches_now' => false,
                        'internal_measurement_claim_allowed' => $l49InternalMeasurementAllowed,
                        'external_comparison_claim_allowed' => false,
                    ],
                ],
                'L4-10' => [
                    'title' => 'Real multi-node Forge Obra with kill/resume',
                    'current_status' => $l410Status,
                    'local_proof_report_path' => 'storage/app/atlas/evidence/fable-l4-10-proof.json',
                    'operator_action_required' => $l410Certified
                        ? 'not_required: real L4-10 receipt already certified by atlas:forge:l4-10-proof.'
                        : 'Run a real 6-10 node Obra through hermes_cli/gpt-5.5, exercise kill+resume, and provide the real receipt to atlas:forge:l4-10-proof.',
                    'required_receipt' => [
                        'schema_version' => 'atlas.forge.l4_10.real_execution_receipt.v1',
                        'required_fields' => [
                            'status=done',
                            'certified=true',
                            'execution_mode=real_provider_obra_run',
                            'node_count between 6 and 10',
                            'provider=hermes_cli',
                            'model=gpt-5.5',
                            'provider_calls_made=true',
                            'delivered_item_id=L4-6',
                            'delivered_files include AtlasLoopMorningDigestService, AtlasLoopMorningDigestCommand, AtlasLoopMorningDigestTest',
                            'kill_resume.kill_exercised=true',
                            'kill_resume.resume_exercised=true',
                            'executor resume result includes resumed=true and resume_count>=1',
                            'command_results includes atlas:loop:morning-digest exit_code=0',
                            'kill_resume.evidence_refs includes separate kill and resume refs',
                        ],
                    ],
                    'template_payload' => $l410Certified ? null : $this->l410RealObraReceiptTemplate(),
                    'validation' => [
                        'command' => '/opt/homebrew/bin/php artisan atlas:forge:l4-10-proof --evidence=<receipt.json> --json --strict',
                        'pass_condition' => 'certified=true and status=certified',
                        'current_certified' => (bool) data_get($operatorGatedExternalProofs, 'l4_10.certified', false),
                    ],
                    'commands_next' => [
                        'prove_l4_6_digest' => '/opt/homebrew/bin/php artisan atlas:loop:morning-digest --json',
                        'run_real_obra_when_operator_authorizes_spend' => '/opt/homebrew/bin/php artisan atlas:obra:run <obra-plan-id> --provider=hermes_cli --integrated-check="/opt/homebrew/bin/php artisan atlas:loop:morning-digest --json" --json',
                        'certify_real_receipt' => '/opt/homebrew/bin/php artisan atlas:forge:l4-10-proof --evidence=<receipt.json> --json --strict',
                    ],
                    'claim_policy' => [
                        'provider_dispatches_now' => false,
                        'synthetic_or_simulated_receipt_allowed' => false,
                        'completion_claim_allowed' => $l410Certified,
                    ],
                ],
            ],
            'claim_policy' => [
                'provider_dispatches_now' => false,
                'operator_decision_required' => $status !== 'ready',
                'synthetic_completion_claim_allowed' => false,
                'list_4_completion_claim_allowed' => $status === 'ready',
                'template_payloads_are_evidence' => false,
                'template_placeholders_must_be_replaced_by_real_receipts' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $operatorGatedExternalProofs
     */
    private function honestyNote(array $operatorGatedExternalProofs): string
    {
        $pending = array_values((array) ($operatorGatedExternalProofs['pending'] ?? []));
        $l49Pending = in_array('L4-9:atlas_dev_external_benchmark_receipt_missing_or_not_winning', $pending, true);
        $l410Pending = in_array('L4-10:real_multi_node_obra_receipt_missing_or_rejected', $pending, true);

        if ($l49Pending && ! $l410Pending) {
            return 'L4-14 can prove the final capture/recovery packet while keeping Lista 4 completion false until L4-9 has a winning comparable external benchmark receipt; L4-10 is already certified.';
        }
        if (! $l49Pending && $l410Pending) {
            return 'L4-14 can prove the final capture/recovery packet while keeping Lista 4 completion false until L4-10 has a certified real multi-node Obra receipt.';
        }
        if ($l49Pending && $l410Pending) {
            return 'L4-14 can prove the final capture/recovery packet while keeping Lista 4 completion false until L4-9 and L4-10 have real receipts.';
        }

        return 'L4-14 can prove the final capture/recovery packet and all Lista 4 external-proof gates are satisfied.';
    }

    /**
     * @return array<string,mixed>
     */
    private function l49OperatorComparisonTemplate(): array
    {
        $taskTemplates = [];
        foreach (['bug', 'feature', 'refactor'] as $taskType) {
            $taskTemplates[] = [
                'id' => '<copy matching Atlas Dev task id for '.$taskType.'>',
                'task_type' => $taskType,
                'title' => '<same task class and comparable task summary>',
                'difficulty' => 'medium',
                'target_path' => '<same or comparable target path>',
                'atlas_dev' => '<copy recorded Atlas Dev task object from storage/app/atlas/evidence/fable-l4-9/atlas-dev-three-medium-tasks.json>',
                'baseline' => [
                    'executed' => '<true only after external runner actually ran>',
                    'provider' => '<claude_code|cursor|operator_approved_equivalent>',
                    'runner' => '<claude_code|cursor|operator_approved_equivalent>',
                    'model' => '<external runner model name>',
                    'duration_ms' => '<integer wall time in milliseconds>',
                    'tests_passed' => '<true only when validation commands pass>',
                    'scope_passed' => '<true only when changed files stay inside task scope>',
                    'changed_files' => ['<path changed by external runner>'],
                    'validation_commands' => [
                        [
                            'command' => '<exact command run by external runner validation>',
                            'exit_code' => '<0 only when the command passed>',
                        ],
                    ],
                    'evidence_refs' => [
                        '<path or ledger ref to external run transcript/receipt>',
                        '<path or ledger ref to validation output>',
                    ],
                ],
            ];
        }

        return [
            'schema_version' => AtlasDevBeatTestReportService::EVIDENCE_SCHEMA_VERSION,
            'template_only' => true,
            'accepted_by_scoring_command_only_after_placeholders_are_replaced' => false,
            'source_internal_evidence_path' => 'storage/app/atlas/evidence/fable-l4-9/atlas-dev-three-medium-tasks.json',
            'tasks' => $taskTemplates,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function l410RealObraReceiptTemplate(): array
    {
        return [
            'schema_version' => AtlasForgeMultiNodeL410ProofService::REAL_RECEIPT_SCHEMA_VERSION,
            'template_only' => true,
            'accepted_by_scoring_command_only_after_placeholders_are_replaced' => false,
            'status' => '<done after real Obra execution>',
            'certified' => '<true only after real Obra certification>',
            'execution_mode' => '<real_provider_obra_run>',
            'obra_id' => '<real Obra id>',
            'node_count' => '<integer 6..10>',
            'resumed' => '<true from AtlasObraExecutor after live resume>',
            'resume_count' => '<integer >=1 from AtlasObraExecutor runtime>',
            'provider_calls_made' => '<true after real provider call>',
            'external_provider_call' => '<true after real provider call>',
            'provider' => [
                'name' => '<hermes_cli>',
                'model' => '<gpt-5.5>',
            ],
            'delivered_item_id' => '<L4-6>',
            'delivered_files' => [
                'app/Services/Ai/AutonomousEvolution/AtlasLoopMorningDigestService.php',
                'app/Console/Commands/AtlasLoopMorningDigestCommand.php',
                'tests/Feature/Loop/AtlasLoopMorningDigestTest.php',
            ],
            'kill_resume' => [
                'kill_exercised' => '<true after live kill event>',
                'resume_exercised' => '<true after live resume event>',
                'evidence_refs' => [
                    '<real kill event receipt ref>',
                    '<real resume event receipt ref>',
                ],
            ],
            'command_results' => [
                [
                    'command' => '/opt/homebrew/bin/php artisan atlas:loop:morning-digest --json',
                    'exit_code' => '<0 after command passes in the real Obra workspace>',
                ],
            ],
        ];
    }

    /**
     * @return array<string,string>
     */
    private function operatorProofTemplatePaths(string $operatorProofRequestPath): array
    {
        $dir = dirname($operatorProofRequestPath);

        return [
            'L4-9' => $dir.'/'.self::OPERATOR_PROOF_TEMPLATE_FILES['L4-9'],
            'L4-10' => $dir.'/'.self::OPERATOR_PROOF_TEMPLATE_FILES['L4-10'],
        ];
    }

    /**
     * @param  array<string,mixed>  $operatorProofRequest
     * @param  array<string,string>  $paths
     * @return array<string,string>
     */
    private function writeOperatorProofTemplateFiles(array $operatorProofRequest, array $paths): array
    {
        $written = [];
        foreach ($paths as $item => $path) {
            $template = data_get($operatorProofRequest, 'items.'.$item.'.template_payload');
            if (! is_array($template)) {
                continue;
            }

            $this->writeJson($path, $template);
            $written[$item] = $path;
        }

        return $written;
    }

    /**
     * @param  array<int,string>  $files
     * @return array<string,mixed>
     */
    private function fileChecks(string $kind, array $files): array
    {
        $items = [];
        $missing = [];
        foreach ($files as $file) {
            $path = $this->absolutePath($file);
            $exists = is_file($path);
            $items[] = [
                'file' => $file,
                'path' => $path,
                'exists' => $exists,
            ];
            if (! $exists) {
                $missing[] = $file;
            }
        }

        return [
            'status' => $missing === [] ? 'ready' : 'blocked',
            'kind' => $kind,
            'required_count' => count($files),
            'present_count' => count($files) - count($missing),
            'missing' => $missing,
            'items' => $items,
        ];
    }

    /**
     * @param  array<int,string>  $commands
     * @return array<string,mixed>
     */
    private function commandChecks(array $commands): array
    {
        $available = array_keys(Artisan::all());
        $missing = array_values(array_diff($commands, $available));

        return [
            'status' => $missing === [] ? 'ready' : 'blocked',
            'required_count' => count($commands),
            'present_count' => count($commands) - count($missing),
            'missing' => $missing,
            'commands' => collect($commands)
                ->map(fn (string $command): array => [
                    'command' => $command,
                    'available' => in_array($command, $available, true),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $receipts
     * @return array<string,mixed>
     */
    private function ritualChecks(array $receipts): array
    {
        $checks = [];
        foreach ($this->receiptKeys() as $key) {
            $checks[$key] = $this->receiptCheck($key, $receipts[$key] ?? [
                'schema_version' => self::RECEIPT_SCHEMA_VERSION,
                'key' => $key,
                'status' => 'missing',
            ]);
        }

        $requiredFailures = collect($this->requiredReceiptKeys())
            ->filter(fn (string $key): bool => data_get($checks, "{$key}.status") !== 'ready')
            ->values()
            ->all();

        return [
            'status' => $requiredFailures === [] ? 'ready' : 'blocked',
            'required_receipts' => $this->requiredReceiptKeys(),
            'optional_receipts' => ['docs_health'],
            'receipts' => $checks,
            'blockers' => array_map(fn (string $key): string => 'ritual_receipt_not_ready:'.$key, $requiredFailures),
        ];
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    private function receiptCheck(string $key, array $receipt): array
    {
        $blockers = [];
        if (($receipt['status'] ?? null) === 'missing') {
            $blockers[] = 'receipt_missing';
        }
        if (($receipt['status'] ?? null) === 'invalid_json') {
            $blockers[] = 'receipt_invalid_json';
        }

        $parsed = data_get($receipt, 'parsed');
        if ($blockers === [] && ! is_array($parsed)) {
            $blockers[] = 'receipt_parsed_payload_missing';
        }

        $exit = $receipt['exit_code'] ?? null;
        if ($blockers === [] && $key !== 'docs_health' && is_numeric($exit) && (int) $exit !== 0) {
            $blockers[] = 'command_exit_nonzero';
        }

        if (is_array($parsed) && ($parsed['ok'] ?? null) === false) {
            $blockers[] = 'receipt_ok_false';
        }

        if ($key === 'context_pack' && is_array($parsed) && ($parsed['provider_bound'] ?? null) !== true) {
            $blockers[] = 'context_pack_not_provider_bound';
        }

        if (in_array($key, ['projection_write', 'projection_status'], true) && is_array($parsed)) {
            $projectionBlockers = $this->projectionBlockers($parsed);
            $blockers = [...$blockers, ...$projectionBlockers];
        }

        return [
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'key' => $key,
            'path' => $receipt['path'] ?? null,
            'command' => $receipt['command'] ?? null,
            'exit_code' => $receipt['exit_code'] ?? null,
            'captured_at' => $receipt['captured_at'] ?? null,
            'parsed_schema' => is_array($parsed) ? ($parsed['schema_version'] ?? $parsed['schema'] ?? null) : null,
            'parsed_status' => is_array($parsed) ? ($parsed['status'] ?? null) : null,
            'provider_bound' => is_array($parsed) ? ($parsed['provider_bound'] ?? null) : null,
            'projection_count' => is_array($parsed) ? count((array) ($parsed['projections'] ?? [])) : 0,
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    /**
     * @param  array<string,mixed>  $parsed
     * @return array<int,string>
     */
    private function projectionBlockers(array $parsed): array
    {
        $blockers = [];
        if (in_array(($parsed['status'] ?? null), ['blocked', 'failed', 'error'], true)) {
            $blockers[] = 'projection_status_blocked';
        }

        foreach ((array) ($parsed['projections'] ?? []) as $projection) {
            if (! is_array($projection)) {
                continue;
            }
            if (($projection['error'] ?? null) !== null) {
                $blockers[] = 'projection_error';
            }
            if (in_array(($projection['status'] ?? null), ['blocked', 'failed', 'error'], true)) {
                $blockers[] = 'projection_item_blocked';
            }
            if (array_key_exists('written', $projection) && ! (bool) $projection['written']) {
                $blockers[] = 'projection_not_written';
            }
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @param  array<string,mixed>  $finalReport
     * @param  array<string,mixed>  $campaignArtifacts
     * @param  array<string,mixed>  $commands
     * @param  array<string,mixed>  $ritual
     * @return array<string,mixed>
     */
    private function coldSessionRecoveryCheck(array $finalReport, array $campaignArtifacts, array $commands, array $ritual): array
    {
        $blockers = [];
        if (($finalReport['status'] ?? null) !== 'ready') {
            $blockers[] = 'final_report_or_packet_not_ready';
        }
        if (($campaignArtifacts['status'] ?? null) !== 'ready') {
            $blockers[] = 'campaign_artifacts_missing';
        }
        if (($commands['status'] ?? null) !== 'ready') {
            $blockers[] = 'required_commands_missing';
        }
        if (data_get($ritual, 'receipts.context_pack.status') !== 'ready') {
            $blockers[] = 'context_pack_receipt_not_ready';
        }
        if (data_get($ritual, 'receipts.projection_status.status') !== 'ready') {
            $blockers[] = 'projection_status_receipt_not_ready';
        }

        return [
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'recoverable_from_recorded_artifacts_only' => $blockers === [],
            'required_starting_points' => [
                'AGENTS.md',
                'docs/fable-listas-4-5-6-execution-prompt.md',
                'storage/app/atlas/evidence/fable-l4-13-handoff-packet.json',
                'storage/app/atlas/evidence/fable-l4-14-final-capture.json',
                'storage/app/atlas/evidence/fable-l4-14-operator-proof-request.json',
            ],
            'next_item_after_l4_14' => 'Lista 5 re-validation against measured Lista 4 evidence',
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  array<string,mixed>  $check
     * @return array<int,string>
     */
    private function blockersFromCheck(string $prefix, array $check): array
    {
        if (($check['status'] ?? null) === 'ready') {
            return [];
        }

        $blockers = (array) ($check['blockers'] ?? $check['missing'] ?? ['blocked']);

        return array_map(
            fn (mixed $blocker): string => $prefix.':'.(is_scalar($blocker) ? (string) $blocker : 'blocked'),
            $blockers,
        );
    }

    /**
     * @param  array<string,mixed>  $ritual
     * @return array<int,string>
     */
    private function blockersFromRitual(array $ritual): array
    {
        return array_map(
            fn (mixed $blocker): string => 'ritual:'.(is_scalar($blocker) ? (string) $blocker : 'blocked'),
            (array) ($ritual['blockers'] ?? []),
        );
    }

    /**
     * @return array<int,string>
     */
    private function campaignArtifactFiles(): array
    {
        return [
            'AGENTS.md',
            'CLAUDE.md',
            'docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md',
            'docs/fable-listas-4-5-6-execution-prompt.md',
            'docs/fable-lista-4-14-itens.md',
            'docs/fable-lista-5-14-itens.md',
            'docs/fable-lista-6-14-itens.md',
            'docs/fable-campanha-11-dias-nxm.md',
            'docs/fable-campanha-handoff-packet.md',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function frozenTestFiles(): array
    {
        return [
            'tests/Feature/Ai/Context/AobgSemanticRetrievalLiftTest.php',
            'tests/Feature/Ai/Programming/AtlasDevBeatTestReportTest.php',
            'tests/Feature/Ai/Programming/AtlasFableFinalCaptureTest.php',
            'tests/Feature/Ai/Programming/AtlasFableFinalReportTest.php',
            'tests/Feature/Ai/Programming/AtlasForgeMultiNodeL410ProofTest.php',
            'tests/Feature/Loop/AtlasLoopBacklogAutoFeedTest.php',
            'tests/Feature/Loop/AtlasLoopFunnelAndPathRewriteTest.php',
            'tests/Feature/Loop/AtlasLoopKeepaliveReviveStarvedTest.php',
            'tests/Feature/Loop/AtlasLoopLossObserverTest.php',
            'tests/Feature/Loop/AtlasLoopMorningDigestTest.php',
            'tests/Feature/Loop/AtlasLoopOperatorReviewQueueTest.php',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function requiredCommands(): array
    {
        return [
            'atlas:aobg:semantic-lift',
            'atlas:context-pack',
            'atlas:engineering:knowledge',
            'atlas:fable:delta-series',
            'atlas:fable:final-capture',
            'atlas:fable:final-report',
            'atlas:loop:backlog-feed',
            'atlas:loop:morning-digest',
            'atlas:memory:projection',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function receiptKeys(): array
    {
        return [
            'knowledge_sync',
            'code_index',
            'projection_write',
            'projection_status',
            'docs_health',
            'context_pack',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function requiredReceiptKeys(): array
    {
        return [
            'knowledge_sync',
            'code_index',
            'projection_write',
            'projection_status',
            'context_pack',
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function receiptPathForKey(string $key, array $options): string
    {
        $optionKey = $key.'_receipt_path';
        $path = $this->stringOrNull($options[$optionKey] ?? null);

        return $path ?? storage_path(self::DEFAULT_RECEIPT_DIR.'/'.$key.'.json');
    }

    private function absolutePath(string $file): string
    {
        if (str_starts_with($file, '/')) {
            return $file;
        }

        return base_path($file);
    }

    /**
     * @param  array<string,mixed>  $arguments
     */
    private function commandLine(string $command, array $arguments): string
    {
        $parts = ['php artisan '.$command];
        foreach ($arguments as $key => $value) {
            if (str_starts_with((string) $key, '--')) {
                if ($value === true) {
                    $parts[] = (string) $key;
                    continue;
                }
                foreach ((array) $value as $optionValue) {
                    $parts[] = (string) $key.'='.(string) $optionValue;
                }
                continue;
            }
            $parts[] = (string) $value;
        }

        return implode(' ', $parts);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function writeJson(string $path, array $payload): void
    {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}

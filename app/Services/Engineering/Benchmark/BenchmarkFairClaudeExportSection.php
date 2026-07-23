<?php

namespace App\Services\Engineering\Benchmark;

use App\Models\AiTraceMetricSummary;
use App\Models\AtlasEngineeringBenchmarkCase;
use App\Models\AtlasEngineeringBenchmarkResult;
use App\Models\AtlasEngineeringBenchmarkRun;
use App\Models\AtlasEngineeringBenchmarkSuite;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasTask;
use App\Services\Ai\FairClaudePolicy;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;
use App\Services\Engineering\EngineeringHarnessRunnerService;
use App\Services\Engineering\EngineeringClaudeCodeBaselineRunnerService;
use App\Services\Engineering\EngineeringWorkspaceService;
use App\Services\Engineering\EngineeringReleaseGateAlertService;
use App\Services\Engineering\EngineeringBenchmarkInput;
use App\Services\Engineering\EngineeringStringListNormalizer;

class BenchmarkFairClaudeExportSection
{
    public function __construct(
        private readonly BenchmarkPrimitives $primitives,
    ) {}

    public function writeFairClaudeExportBundle(array $payload, string $directory): array
    {
        $directory = rtrim($directory, DIRECTORY_SEPARATOR);
        if ($directory === '') {
            throw new InvalidArgumentException('Export directory cannot be empty.');
        }

        File::ensureDirectoryExists($directory);

        $contents = [
            'report.json' => $this->primitives->prettyJson(Arr::except($payload, ['claim_markdown', 'export_bundle', 'written_export_bundle'])),
            'evidence.json' => $this->primitives->prettyJson($payload['evidence_packet'] ?? []),
            'claim.md' => (string) ($payload['claim_markdown'] ?? ''),
            'case-comparisons.json' => $this->primitives->prettyJson($payload['case_comparisons'] ?? []),
            'history-timeline.json' => $this->primitives->prettyJson($payload['history_timeline'] ?? []),
        ];
        $files = [];
        foreach ($contents as $filename => $content) {
            $path = $directory.DIRECTORY_SEPARATOR.$filename;
            File::put($path, $content);
            $files[$filename] = [
                'path' => $path,
                'bytes' => strlen($content),
                'sha256' => hash('sha256', $content),
            ];
        }

        $manifest = [
            'schema_version' => 1,
            'kind' => 'fair_claude_written_export_bundle',
            'written_at' => now()->toJSON(),
            'directory' => $directory,
            'evidence_hash' => data_get($payload, 'evidence_packet.evidence_hash'),
            'bundle_hash' => data_get($payload, 'export_bundle.bundle_hash'),
            'files' => $files,
        ];
        $manifestJson = $this->primitives->prettyJson($manifest);
        $manifestPath = $directory.DIRECTORY_SEPARATOR.'manifest.json';
        File::put($manifestPath, $manifestJson);
        $manifest['files']['manifest.json'] = [
            'path' => $manifestPath,
            'bytes' => strlen($manifestJson),
            'sha256' => hash('sha256', $manifestJson),
        ];

        return $manifest;
    }

    public function verifyFairClaudeExportBundle(string $directory): array
    {
        $directory = rtrim($directory, DIRECTORY_SEPARATOR);
        $checkedAt = now()->toJSON();

        $basePayload = [
            'schema_version' => 1,
            'kind' => 'fair_claude_export_bundle_verification',
            'checked_at' => $checkedAt,
            'directory' => $directory,
            'status' => 'failed',
            'verified' => false,
            'file_count' => 0,
            'passed_count' => 0,
            'failed_count' => 0,
            'missing_count' => 0,
            'blocking_reasons' => [],
            'files' => [],
        ];

        if ($directory === '') {
            return array_merge($basePayload, [
                'blocking_reasons' => ['export_directory_empty'],
            ]);
        }

        $manifestPath = $directory.DIRECTORY_SEPARATOR.'manifest.json';
        if (! File::exists($manifestPath)) {
            return array_merge($basePayload, [
                'blocking_reasons' => ['manifest_missing'],
                'manifest_path' => $manifestPath,
            ]);
        }

        $manifestJson = File::get($manifestPath);
        $manifest = json_decode($manifestJson, true);
        if (! is_array($manifest)) {
            return array_merge($basePayload, [
                'blocking_reasons' => ['manifest_invalid_json'],
                'manifest_path' => $manifestPath,
                'manifest_sha256' => hash('sha256', $manifestJson),
            ]);
        }

        $manifestFiles = $manifest['files'] ?? [];
        if (! is_array($manifestFiles) || $manifestFiles === []) {
            return array_merge($basePayload, [
                'blocking_reasons' => ['manifest_files_missing'],
                'manifest_path' => $manifestPath,
                'manifest_sha256' => hash('sha256', $manifestJson),
                'manifest' => Arr::only($manifest, ['schema_version', 'kind', 'written_at', 'evidence_hash', 'bundle_hash']),
            ]);
        }

        $files = [];
        $passedCount = 0;
        $failedCount = 0;
        $missingCount = 0;
        $requiredFiles = [
            'report.json',
            'evidence.json',
            'claim.md',
            'case-comparisons.json',
            'history-timeline.json',
            'manifest.json',
        ];

        foreach ($manifestFiles as $filename => $fileMetadata) {
            if (! is_string($filename) || trim($filename) === '') {
                $failedCount++;
                $files[(string) $filename] = [
                    'status' => 'failed',
                    'exists' => false,
                    'hash_matches' => false,
                    'blocking_reason' => 'invalid_manifest_filename',
                ];

                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$filename;
            $expectedHash = is_array($fileMetadata) && is_string($fileMetadata['sha256'] ?? null)
                ? (string) $fileMetadata['sha256']
                : null;
            $expectedBytes = is_array($fileMetadata) && is_numeric($fileMetadata['bytes'] ?? null)
                ? (int) $fileMetadata['bytes']
                : null;
            $exists = File::exists($path);
            $actualHash = $exists ? hash_file('sha256', $path) : null;
            $actualBytes = $exists ? File::size($path) : null;
            $hashMatches = $exists && $expectedHash !== null && hash_equals($expectedHash, (string) $actualHash);
            $bytesMatch = $exists && ($expectedBytes === null || $expectedBytes === $actualBytes);
            $status = $hashMatches && $bytesMatch ? 'passed' : ($exists ? 'failed' : 'missing');

            if ($status === 'passed') {
                $passedCount++;
            } elseif ($status === 'missing') {
                $missingCount++;
            } else {
                $failedCount++;
            }

            $files[$filename] = [
                'status' => $status,
                'path' => $path,
                'exists' => $exists,
                'expected_sha256' => $expectedHash,
                'actual_sha256' => $actualHash,
                'hash_matches' => $hashMatches,
                'expected_bytes' => $expectedBytes,
                'actual_bytes' => $actualBytes,
                'bytes_match' => $bytesMatch,
            ];
        }

        foreach ($requiredFiles as $requiredFile) {
            if (array_key_exists($requiredFile, $files)) {
                continue;
            }
            if ($requiredFile === 'manifest.json' && File::exists($manifestPath)) {
                $files['manifest.json'] = [
                    'status' => 'passed',
                    'path' => $manifestPath,
                    'exists' => true,
                    'expected_sha256' => hash('sha256', $manifestJson),
                    'actual_sha256' => hash('sha256', $manifestJson),
                    'hash_matches' => true,
                    'expected_bytes' => strlen($manifestJson),
                    'actual_bytes' => strlen($manifestJson),
                    'bytes_match' => true,
                    'required' => true,
                ];
                $passedCount++;

                continue;
            }

            $failedCount++;
            $files[$requiredFile] = [
                'status' => 'failed',
                'path' => $directory.DIRECTORY_SEPARATOR.$requiredFile,
                'exists' => File::exists($directory.DIRECTORY_SEPARATOR.$requiredFile),
                'hash_matches' => false,
                'bytes_match' => false,
                'required' => true,
                'blocking_reason' => 'manifest_required_file_missing',
            ];
        }

        if (! array_key_exists('manifest.json', $files)) {
            $files['manifest.json'] = [
                'status' => 'passed',
                'path' => $manifestPath,
                'exists' => true,
                'expected_sha256' => hash('sha256', $manifestJson),
                'actual_sha256' => hash('sha256', $manifestJson),
                'hash_matches' => true,
                'expected_bytes' => strlen($manifestJson),
                'actual_bytes' => strlen($manifestJson),
                'bytes_match' => true,
            ];
            $passedCount++;
        }

        $blockingReasons = [];
        if (collect($files)->contains(fn (array $file): bool => ($file['blocking_reason'] ?? null) === 'manifest_required_file_missing')) {
            $blockingReasons[] = 'manifest_required_file_missing';
        }
        if ($missingCount > 0) {
            $blockingReasons[] = 'export_file_missing';
        }
        if ($failedCount > 0) {
            $blockingReasons[] = 'export_file_hash_mismatch';
        }

        $evidencePath = $directory.DIRECTORY_SEPARATOR.'evidence.json';
        $evidenceHashMatches = null;
        $semanticChecks = [
            'status' => 'skipped',
            'blocking_reasons' => [],
        ];
        if (File::exists($evidencePath)) {
            $evidence = json_decode(File::get($evidencePath), true);
            if (is_array($evidence)) {
                $expectedEvidenceHash = is_string($manifest['evidence_hash'] ?? null)
                    ? (string) $manifest['evidence_hash']
                    : null;
                $actualEvidenceHash = is_string($evidence['evidence_hash'] ?? null)
                    ? (string) $evidence['evidence_hash']
                    : null;
                $computedEvidenceHash = hash('sha256', $this->primitives->canonicalJsonForHash(Arr::except($evidence, ['generated_at', 'evidence_hash'])));
                $evidenceHashMatches = $expectedEvidenceHash !== null
                    && $actualEvidenceHash !== null
                    && hash_equals($expectedEvidenceHash, $actualEvidenceHash)
                    && hash_equals($actualEvidenceHash, $computedEvidenceHash);
                if (! $evidenceHashMatches) {
                    $failedCount++;
                    $blockingReasons[] = 'evidence_hash_mismatch';
                    $files['evidence.json']['status'] = 'failed';
                    $files['evidence.json']['evidence_hash_matches'] = false;
                    $files['evidence.json']['expected_evidence_hash'] = $expectedEvidenceHash;
                    $files['evidence.json']['actual_evidence_hash'] = $actualEvidenceHash;
                    $files['evidence.json']['computed_evidence_hash'] = $computedEvidenceHash;
                } else {
                    $files['evidence.json']['evidence_hash_matches'] = true;
                    $files['evidence.json']['computed_evidence_hash'] = $computedEvidenceHash;
                }

                $semanticChecks = $this->fairClaudeExportSemanticChecks($evidence, $directory);
                if (($semanticChecks['status'] ?? null) !== 'passed') {
                    $failedCount++;
                    $blockingReasons = array_merge($blockingReasons, (array) ($semanticChecks['blocking_reasons'] ?? []));
                    $files['evidence.json']['status'] = 'failed';
                    $files['evidence.json']['semantic_checks'] = $semanticChecks;
                } else {
                    $files['evidence.json']['semantic_checks'] = $semanticChecks;
                }
            } else {
                $failedCount++;
                $blockingReasons[] = 'evidence_invalid_json';
                $files['evidence.json']['status'] = 'failed';
                $files['evidence.json']['semantic_checks'] = [
                    'status' => 'failed',
                    'blocking_reasons' => ['evidence_invalid_json'],
                ];
            }
        }

        $verified = $passedCount > 0 && $failedCount === 0 && $missingCount === 0;

        return array_merge($basePayload, [
            'status' => $verified ? 'passed' : 'failed',
            'verified' => $verified,
            'manifest_path' => $manifestPath,
            'manifest_sha256' => hash('sha256', $manifestJson),
            'manifest' => Arr::only($manifest, ['schema_version', 'kind', 'written_at', 'evidence_hash', 'bundle_hash']),
            'required_files' => $requiredFiles,
            'evidence_hash_matches' => $evidenceHashMatches,
            'semantic_checks' => $semanticChecks,
            'file_count' => count($files),
            'passed_count' => $passedCount,
            'failed_count' => $failedCount,
            'missing_count' => $missingCount,
            'blocking_reasons' => $this->primitives->uniqueReasonStrings($blockingReasons),
            'files' => $files,
        ]);
    }

    public function fairClaudeExportSemanticChecks(array $evidence, string $directory): array
    {
        $blocking = [];
        $integrity = $this->primitives->arrayValue($evidence['result_integrity'] ?? []);
        $claim = $this->primitives->arrayValue($evidence['claim'] ?? []);
        $scorecard = $this->primitives->arrayValue($evidence['scorecard'] ?? []);
        $uiContract = $this->primitives->arrayValue($integrity['ui_contract'] ?? []);
        $experimentValidity = $this->primitives->arrayValue($integrity['experiment_validity'] ?? []);

        if ($integrity === []) {
            $blocking[] = 'result_integrity_missing';
        }
        if (($integrity['schema_version'] ?? null) !== 'atlas.fair_claude.result_integrity.v1') {
            $blocking[] = 'result_integrity_schema_invalid';
        }
        if (($experimentValidity['schema_version'] ?? null) !== 'atlas.fair_claude.experiment_validity.v1') {
            $blocking[] = 'experiment_validity_schema_invalid';
        }
        if (data_get($experimentValidity, 'external_variable_policy.non_evaluated_variables_cannot_decide_winner') !== true) {
            $blocking[] = 'experiment_validity_external_variable_policy_missing';
        }
        if (data_get($experimentValidity, 'external_variable_policy.non_evaluated_variables_can_only_block_comparability') !== true) {
            $blocking[] = 'experiment_validity_confounder_policy_missing';
        }
        if (data_get($experimentValidity, 'ab_test_validity_model.no_provider_specific_case_filtering') !== true) {
            $blocking[] = 'experiment_validity_case_filtering_policy_missing';
        }

        $claimWinner = $claim['winner'] ?? null;
        $claimWinnerAdmitted = (bool) ($integrity['claim_winner_admitted'] ?? $claim['claim_winner_admitted'] ?? false);
        $mustNotRenderWinner = (bool) ($uiContract['must_not_render_winner'] ?? false);
        if (! $claimWinnerAdmitted && $claimWinner !== null) {
            $blocking[] = 'claim_winner_present_without_admission';
        }
        if ($mustNotRenderWinner && $claimWinner !== null) {
            $blocking[] = 'ui_contract_winner_violation';
        }
        if (($integrity['winner_for_claim'] ?? null) !== $claimWinner) {
            $blocking[] = 'claim_winner_mismatch_with_result_integrity';
        }
        if (($integrity['score_admitted'] ?? null) === false && (int) ($scorecard['comparable_count'] ?? 0) > 0 && $claimWinner !== null) {
            $blocking[] = 'score_not_admitted_but_winner_present';
        }

        $claimPath = $directory.DIRECTORY_SEPARATOR.'claim.md';
        $claimMarkdownContainsIntegrity = File::exists($claimPath)
            && str_contains(File::get($claimPath), '## Result Integrity');
        if (! $claimMarkdownContainsIntegrity) {
            $blocking[] = 'claim_markdown_result_integrity_missing';
        }
        $claimMarkdownContainsExperimentValidity = File::exists($claimPath)
            && str_contains(File::get($claimPath), '## Experiment Validity');
        if (! $claimMarkdownContainsExperimentValidity) {
            $blocking[] = 'claim_markdown_experiment_validity_missing';
        }

        return [
            'status' => $blocking === [] ? 'passed' : 'failed',
            'blocking_reasons' => $this->primitives->uniqueReasonStrings($blocking),
            'result_integrity_status' => $integrity['status'] ?? null,
            'experiment_validity_status' => $experimentValidity['status'] ?? null,
            'claim_winner' => $claimWinner,
            'claim_winner_admitted' => $claimWinnerAdmitted,
            'must_not_render_winner' => $mustNotRenderWinner,
            'claim_markdown_contains_result_integrity' => $claimMarkdownContainsIntegrity,
            'claim_markdown_contains_experiment_validity' => $claimMarkdownContainsExperimentValidity,
        ];
    }

    public function fairClaudeExportBundle(array $payload): array
    {
        $reportJson = $this->primitives->prettyJson(Arr::except($payload, ['claim_markdown', 'export_bundle']));
        $evidenceJson = $this->primitives->prettyJson($payload['evidence_packet'] ?? []);
        $caseComparisonsJson = $this->primitives->prettyJson($payload['case_comparisons'] ?? []);
        $historyTimelineJson = $this->primitives->prettyJson($payload['history_timeline'] ?? []);
        $claimMarkdown = (string) ($payload['claim_markdown'] ?? '');
        $files = [
            'report.json' => [
                'kind' => 'fair_claude_report_json',
                'content_type' => 'application/json',
                'bytes' => strlen($reportJson),
                'sha256' => hash('sha256', $reportJson),
            ],
            'evidence.json' => [
                'kind' => 'fair_claude_evidence_packet_json',
                'content_type' => 'application/json',
                'bytes' => strlen($evidenceJson),
                'sha256' => hash('sha256', $evidenceJson),
            ],
            'claim.md' => [
                'kind' => 'fair_claude_claim_markdown',
                'content_type' => 'text/markdown',
                'bytes' => strlen($claimMarkdown),
                'sha256' => hash('sha256', $claimMarkdown),
            ],
            'case-comparisons.json' => [
                'kind' => 'fair_claude_case_comparisons_json',
                'content_type' => 'application/json',
                'bytes' => strlen($caseComparisonsJson),
                'sha256' => hash('sha256', $caseComparisonsJson),
            ],
            'history-timeline.json' => [
                'kind' => 'fair_claude_history_timeline_json',
                'content_type' => 'application/json',
                'bytes' => strlen($historyTimelineJson),
                'sha256' => hash('sha256', $historyTimelineJson),
            ],
        ];

        return [
            'schema_version' => 1,
            'kind' => 'fair_claude_export_bundle',
            'recommended_directory' => 'atlas-rivals-'.((string) data_get($payload, 'suite.slug', 'suite')).'-'.now()->format('Ymd-His'),
            'verification_command' => 'atlas rivals verify --output-dir=<export-directory> --json',
            'evidence_hash' => data_get($payload, 'evidence_packet.evidence_hash'),
            'files' => $files,
            'bundle_hash' => hash('sha256', $this->primitives->canonicalJsonForHash($files)),
        ];
    }
}

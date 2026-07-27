<?php

namespace App\Services\Ai\Kernel\Architecture\Scanner;

use App\Support\PeeledSource;
use Illuminate\Support\Facades\File;

class EngineeringAudit
{
    public function __construct(private ScanPrimitivesSupport $primitives) {}

    /**
     * @return array<string,callable(): array<int,string>>
     */
    public function checks(): array
    {
        return [
            'ap89_engineering_harness_runner_input_contract' => fn (): array => $this->scanEngineeringHarnessRunnerInputContract(),
            'ap90_engineering_harnessability_input_contract' => fn (): array => $this->scanEngineeringHarnessabilityInputContract(),
            'ap91_engineering_docker_harness_input_contract' => fn (): array => $this->scanEngineeringDockerHarnessInputContract(),
            'ap92_engineering_test_matrix_input_contract' => fn (): array => $this->scanEngineeringTestMatrixInputContract(),
            'ap93_engineering_claude_code_baseline_input_contract' => fn (): array => $this->scanEngineeringClaudeCodeBaselineInputContract(),
            'ap94_engineering_benchmark_input_contract' => fn (): array => $this->scanEngineeringBenchmarkInputContract(),
            'ap95_engineering_context_intelligence_input_contract' => fn (): array => $this->scanEngineeringContextIntelligenceInputContract(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function scanEngineeringHarnessRunnerInputContract(): array
    {
        $inputPath = app_path('Services/Engineering/EngineeringHarnessRunnerInput.php');
        $runnerPath = app_path('Services/Engineering/EngineeringHarnessRunnerService.php');
        $testPath = base_path('tests/Unit/EngineeringHarnessRunnerInputTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = PeeledSource::read($inputPath);
        $runner = PeeledSource::read($runnerPath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'final class EngineeringHarnessRunnerInput',
            'public const DEFAULT_MAX_ATTEMPTS = 1',
            'public const MAX_MAX_ATTEMPTS = 10',
            'public function maxAttempts(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringHarnessRunnerInput.php: engineering harness runner input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            '?EngineeringHarnessRunnerInput $input = null',
            '$this->runnerInput()->maxAttempts($options[\'max_attempts\'] ?? null)',
            '$this->runnerInput()->maxAttempts($maxAttempts)',
            '$this->runnerInput()->maxAttempts($requested[\'max_attempts\'] ?? null)',
            'private function runnerInput(): EngineeringHarnessRunnerInput',
        ] as $token) {
            if (! str_contains($runner, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringHarnessRunnerService.php: engineering harness runner must use shared input contract [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_engineering_harness_runner_attempt_limits',
            'EngineeringHarnessRunnerInput::DEFAULT_MAX_ATTEMPTS',
            'EngineeringHarnessRunnerInput::MAX_MAX_ATTEMPTS',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/EngineeringHarnessRunnerInputTest.php: engineering harness runner input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'engineering harness runner input contract',
            'AP-89',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe engineering harness runner input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanEngineeringHarnessabilityInputContract(): array
    {
        $inputPath = app_path('Services/Engineering/EngineeringHarnessabilityInput.php');
        $servicePath = app_path('Services/Engineering/EngineeringHarnessabilityService.php');
        $testPath = base_path('tests/Unit/EngineeringHarnessabilityInputTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = PeeledSource::read($inputPath);
        $service = PeeledSource::read($servicePath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'final class EngineeringHarnessabilityInput',
            'public const DEFAULT_CALIBRATION_LIMIT = 300',
            'public const MAX_CALIBRATION_LIMIT = 1000',
            'public function calibrationLimit(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringHarnessabilityInput.php: engineering harnessability input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            '?EngineeringHarnessabilityInput $input = null',
            '$this->harnessabilityInput()->calibrationLimit($options[\'limit\'] ?? null)',
            'private function harnessabilityInput(): EngineeringHarnessabilityInput',
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringHarnessabilityService.php: engineering harnessability must use shared input contract [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_engineering_harnessability_calibration_limit',
            'EngineeringHarnessabilityInput::DEFAULT_CALIBRATION_LIMIT',
            'EngineeringHarnessabilityInput::MAX_CALIBRATION_LIMIT',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/EngineeringHarnessabilityInputTest.php: engineering harnessability input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'engineering harnessability input contract',
            'AP-90',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe engineering harnessability input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanEngineeringDockerHarnessInputContract(): array
    {
        $inputPath = app_path('Services/Engineering/EngineeringDockerHarnessInput.php');
        $servicePath = app_path('Services/Engineering/EngineeringDockerHarnessService.php');
        $testPath = base_path('tests/Unit/EngineeringDockerHarnessInputTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = PeeledSource::read($inputPath);
        $service = PeeledSource::read($servicePath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'final class EngineeringDockerHarnessInput',
            'public const DEFAULT_HEALTHCHECK_TIMEOUT_SECONDS = 45',
            'public const MAX_HEALTHCHECK_TIMEOUT_SECONDS = 600',
            'public const DEFAULT_ARTIFACT_MAX_FILES = 100',
            'public const MAX_ARTIFACT_MAX_FILES = 1000',
            'public const DEFAULT_ARTIFACT_MAX_BYTES = 10_485_760',
            'public const MAX_ARTIFACT_MAX_BYTES = 524_288_000',
            'public const DEFAULT_CACHE_RETENTION_DAYS = 14',
            'public const MAX_CACHE_RETENTION_DAYS = 365',
            'public const DEFAULT_ARTIFACT_RETENTION_DAYS = 30',
            'public const MAX_ARTIFACT_RETENTION_DAYS = 365',
            'public function healthcheckTimeoutSeconds(',
            'public function artifactMaxFiles(',
            'public function artifactMaxBytes(',
            'public function cacheRetentionDays(',
            'public function artifactRetentionDays(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringDockerHarnessInput.php: engineering docker harness input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            '?EngineeringDockerHarnessInput $input = null',
            '$this->dockerInput()->healthcheckTimeoutSeconds($options[\'docker_healthcheck_timeout\'] ?? null)',
            '$this->dockerInput()->artifactMaxFiles($options[\'docker_artifact_max_files\'] ?? null)',
            '$this->dockerInput()->artifactMaxBytes($options[\'docker_artifact_max_bytes\'] ?? null)',
            '$this->dockerInput()->cacheRetentionDays($options[\'cache_retention_days\'] ?? null)',
            '$this->dockerInput()->artifactRetentionDays($options[\'artifact_retention_days\'] ?? null)',
            'private function dockerInput(): EngineeringDockerHarnessInput',
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringDockerHarnessService.php: engineering docker harness must use shared input contract [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_engineering_docker_harness_limits',
            'EngineeringDockerHarnessInput::MAX_HEALTHCHECK_TIMEOUT_SECONDS',
            'EngineeringDockerHarnessInput::MAX_ARTIFACT_MAX_FILES',
            'EngineeringDockerHarnessInput::MAX_ARTIFACT_MAX_BYTES',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/EngineeringDockerHarnessInputTest.php: engineering docker harness input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'engineering docker harness input contract',
            'AP-91',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe engineering docker harness input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanEngineeringTestMatrixInputContract(): array
    {
        $inputPath = app_path('Services/Engineering/EngineeringTestMatrixInput.php');
        $servicePath = app_path('Services/Engineering/EngineeringTestMatrixService.php');
        $testPath = base_path('tests/Unit/EngineeringTestMatrixInputTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = PeeledSource::read($inputPath);
        $service = PeeledSource::read($servicePath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'final class EngineeringTestMatrixInput',
            'public const DEFAULT_QUALITY_SCAN_TIMEOUT_SECONDS = 300',
            'public const MAX_QUALITY_SCAN_TIMEOUT_SECONDS = 3600',
            'public const DEFAULT_VISUAL_SMOKE_TIMEOUT_SECONDS = 45',
            'public const MAX_VISUAL_SMOKE_TIMEOUT_SECONDS = 1800',
            'public const DEFAULT_VISUAL_ARTIFACT_MAX_FILES = 200',
            'public const MAX_VISUAL_ARTIFACT_MAX_FILES = 2000',
            'public const DEFAULT_VISUAL_ARTIFACT_MAX_BYTES = 52_428_800',
            'public const MAX_VISUAL_ARTIFACT_MAX_BYTES = 1_073_741_824',
            'public const DEFAULT_QUALITY_ARTIFACT_MAX_FILES = 100',
            'public const MAX_QUALITY_ARTIFACT_MAX_FILES = 1000',
            'public const DEFAULT_QUALITY_ARTIFACT_MAX_BYTES = 10_485_760',
            'public const MAX_QUALITY_ARTIFACT_MAX_BYTES = 524_288_000',
            'public function qualityScanTimeoutSeconds(',
            'public function visualSmokeTimeoutSeconds(',
            'public function visualArtifactMaxFiles(',
            'public function visualArtifactMaxBytes(',
            'public function qualityArtifactMaxFiles(',
            'public function qualityArtifactMaxBytes(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringTestMatrixInput.php: engineering test matrix input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            '?EngineeringTestMatrixInput $input = null',
            '$this->matrixInput()->qualityScanTimeoutSeconds()',
            '$this->matrixInput()->visualSmokeTimeoutSeconds()',
            '$this->matrixInput()->visualArtifactMaxFiles()',
            '$this->matrixInput()->visualArtifactMaxBytes()',
            '$this->matrixInput()->qualityArtifactMaxFiles()',
            '$this->matrixInput()->qualityArtifactMaxBytes()',
            'private function matrixInput(): EngineeringTestMatrixInput',
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringTestMatrixService.php: engineering test matrix must use shared input contract [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_engineering_test_matrix_limits',
            'EngineeringTestMatrixInput::MAX_QUALITY_SCAN_TIMEOUT_SECONDS',
            'EngineeringTestMatrixInput::MAX_VISUAL_ARTIFACT_MAX_FILES',
            'EngineeringTestMatrixInput::MAX_QUALITY_ARTIFACT_MAX_BYTES',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/EngineeringTestMatrixInputTest.php: engineering test matrix input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'engineering test matrix input contract',
            'AP-92',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe engineering test matrix input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanEngineeringClaudeCodeBaselineInputContract(): array
    {
        $inputPath = app_path('Services/Engineering/EngineeringClaudeCodeBaselineInput.php');
        $servicePath = app_path('Services/Engineering/EngineeringClaudeCodeBaselineRunnerService.php');
        $testPath = base_path('tests/Unit/EngineeringClaudeCodeBaselineInputTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = PeeledSource::read($inputPath);
        $service = PeeledSource::read($servicePath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'final class EngineeringClaudeCodeBaselineInput',
            'public const DEFAULT_TIMEOUT_SECONDS = 900',
            'public const MAX_TIMEOUT_SECONDS = 3600',
            'public const DEFAULT_VALIDATION_TIMEOUT_SECONDS = 300',
            'public const MAX_VALIDATION_TIMEOUT_SECONDS = 1800',
            'public function runTimeoutSeconds(',
            'public function validationTimeoutSeconds(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringClaudeCodeBaselineInput.php: engineering Claude Code baseline input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            '?EngineeringClaudeCodeBaselineInput $input = null',
            '$this->baselineInput()->runTimeoutSeconds($runnerOptions)',
            '$this->baselineInput()->validationTimeoutSeconds($runnerOptions)',
            'private function baselineInput(): EngineeringClaudeCodeBaselineInput',
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringClaudeCodeBaselineRunnerService.php: Claude Code baseline runner must use shared input contract [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_claude_code_baseline_timeouts',
            'EngineeringClaudeCodeBaselineInput::MAX_TIMEOUT_SECONDS',
            'EngineeringClaudeCodeBaselineInput::MAX_VALIDATION_TIMEOUT_SECONDS',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/EngineeringClaudeCodeBaselineInputTest.php: Claude Code baseline input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'engineering Claude Code baseline input contract',
            'AP-93',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe engineering Claude Code baseline input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanEngineeringBenchmarkInputContract(): array
    {
        $inputPath = app_path('Services/Engineering/EngineeringBenchmarkInput.php');
        $servicePath = app_path('Services/Engineering/EngineeringBenchmarkService.php');
        $testPath = base_path('tests/Unit/EngineeringBenchmarkInputTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = PeeledSource::read($inputPath);
        $service = PeeledSource::read($servicePath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'final class EngineeringBenchmarkInput',
            'public const DEFAULT_PROMOTE_RECENT_RUNS_LIMIT = 10',
            'public const MAX_PROMOTE_RECENT_RUNS_LIMIT = 100',
            'public const DEFAULT_TREND_LIMIT = 50',
            'public const MAX_TREND_LIMIT = 200',
            'public const DEFAULT_FAIR_CLAUDE_REPORT_LIMIT = 20',
            'public const MAX_FAIR_CLAUDE_REPORT_LIMIT = 200',
            'public const DEFAULT_CALIBRATE_SUITE_LIMIT = 200',
            'public const MAX_CALIBRATE_SUITE_LIMIT = 500',
            'public const MAX_FAIR_CLAUDE_COMPARISONS = 200',
            'public function promoteRecentRunsLimit(',
            'public function trendLimit(',
            'public function fairClaudeReportLimit(',
            'public function calibrateSuiteLimit(',
            'public function fairClaudeComparisonTakeLimit(',
            'public function fairClaudeScanLimit(',
            'public function fairClaudeBatchSize(',
            'public function minSourceScore(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringBenchmarkInput.php: engineering benchmark input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            '?EngineeringBenchmarkInput $input = null',
            '$this->benchmarkInput()->promoteRecentRunsLimit($options[\'limit\'] ?? null)',
            '$this->benchmarkInput()->minSourceScore($options[\'min_source_score\'] ?? null)',
            '$this->benchmarkInput()->trendLimit($options[\'limit\'] ?? null)',
            '$this->benchmarkInput()->fairClaudeReportLimit($options[\'limit\'] ?? null)',
            '$this->benchmarkInput()->fairClaudeScanLimit($limit)',
            '$this->benchmarkInput()->fairClaudeBatchSize($scanLimit)',
            '$this->benchmarkInput()->calibrateSuiteLimit($options[\'limit\'] ?? null)',
            '$this->benchmarkInput()->fairClaudeComparisonTakeLimit($limit)',
            'private function benchmarkInput(): EngineeringBenchmarkInput',
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringBenchmarkService.php: engineering benchmark must use shared input contract [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_engineering_benchmark_limits',
            'EngineeringBenchmarkInput::MAX_PROMOTE_RECENT_RUNS_LIMIT',
            'EngineeringBenchmarkInput::MAX_FAIR_CLAUDE_REPORT_LIMIT',
            'EngineeringBenchmarkInput::MAX_FAIR_CLAUDE_COMPARISONS',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/EngineeringBenchmarkInputTest.php: engineering benchmark input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'engineering benchmark input contract',
            'AP-94',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe engineering benchmark input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanEngineeringContextIntelligenceInputContract(): array
    {
        $inputPath = app_path('Services/Engineering/EngineeringContextIntelligenceInput.php');
        $knowledgePath = app_path('Services/Engineering/EngineeringKnowledgeBaseService.php');
        $codePath = app_path('Services/Engineering/EngineeringCodeIntelligenceService.php');
        $artifactPath = app_path('Services/Engineering/EngineeringRunArtifactService.php');
        $commandPath = app_path('Console/Commands/AtlasEngineeringKnowledgeCommand.php');
        $testPath = base_path('tests/Unit/EngineeringContextIntelligenceInputTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = PeeledSource::read($inputPath);
        $knowledge = PeeledSource::read($knowledgePath);
        $code = PeeledSource::read($codePath);
        $artifact = PeeledSource::read($artifactPath);
        $command = PeeledSource::read($commandPath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'final class EngineeringContextIntelligenceInput',
            'public const DEFAULT_KNOWLEDGE_LIMIT = 50',
            'public const MAX_KNOWLEDGE_LIMIT = 200',
            'public const DEFAULT_CODE_LIMIT = 50',
            'public const MAX_CODE_LIMIT = 500',
            'public const DEFAULT_EVIDENCE_HISTORY_LIMIT = 100',
            'public const MAX_EVIDENCE_HISTORY_LIMIT = 200',
            'public function knowledgeLimit(',
            'public function codeLimit(',
            'public function evidenceHistoryLimit(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringContextIntelligenceInput.php: engineering context intelligence input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            '?EngineeringContextIntelligenceInput $input = null',
            '$this->contextInput()->knowledgeLimit($limit)',
            'private function contextInput(): EngineeringContextIntelligenceInput',
        ] as $token) {
            if (! str_contains($knowledge, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringKnowledgeBaseService.php: knowledge base must use shared context intelligence input [{$token}]";
            }
        }

        foreach ([
            '?EngineeringContextIntelligenceInput $input = null',
            '$this->contextInput()->codeLimit($options[\'limit\'] ?? null)',
            '$this->contextInput()->codeLimit($limit)',
            'private function contextInput(): EngineeringContextIntelligenceInput',
        ] as $token) {
            if (! str_contains($code, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringCodeIntelligenceService.php: code intelligence must use shared context intelligence input [{$token}]";
            }
        }

        foreach ([
            '?EngineeringContextIntelligenceInput $input = null',
            '$this->contextInput()->evidenceHistoryLimit($limit)',
            '$this->contextInput()->evidenceHistoryLimit(200)',
            '$this->contextInput()->evidenceHistoryLimit()',
            'private function contextInput(): EngineeringContextIntelligenceInput',
        ] as $token) {
            if (! str_contains($artifact, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringRunArtifactService.php: run artifacts must use shared context intelligence input [{$token}]";
            }
        }

        foreach ([
            'EngineeringContextIntelligenceInput $input',
            '$this->contextInput()->knowledgeLimit($this->option(\'limit\'))',
            '$this->contextInput()->codeLimit($this->option(\'limit\'))',
            'private function contextInput(): EngineeringContextIntelligenceInput',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasEngineeringKnowledgeCommand.php: engineering knowledge command must use shared context intelligence input [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_engineering_context_intelligence_limits',
            'EngineeringContextIntelligenceInput::MAX_KNOWLEDGE_LIMIT',
            'EngineeringContextIntelligenceInput::MAX_CODE_LIMIT',
            'EngineeringContextIntelligenceInput::MAX_EVIDENCE_HISTORY_LIMIT',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/EngineeringContextIntelligenceInputTest.php: engineering context intelligence input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'engineering context intelligence input contract',
            'AP-95',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe engineering context intelligence input contract [{$token}]";
            }
        }

        return $violations;
    }
}

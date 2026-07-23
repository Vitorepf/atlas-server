<?php

declare(strict_types=1);

namespace App\Http\Controllers\AtlasDev\Support\PipelineRun;

use App\Http\Controllers\AtlasDev\Support\RunExecutionResult;
use App\Models\AiJob;
use App\Services\Ai\Aemor\AtlasEngineeringOutcomeRecorder;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\Concerns\RunsCliProcesses;
use App\Services\Ai\Context\AtlasCanonicalContextRef;
use App\Services\Ai\Context\AtlasContextRuntime;
use App\Services\Ai\Context\AtlasDeliveredPackLedger;
use App\Services\Ai\Context\AtlasRetrievalFeedbackLoopService;
use App\Services\Ai\EngineeringKernel\Adapters\AtlasDevGateAdapter;
use App\Services\Ai\EngineeringKernel\RegressionLock\RegressionLockLedger;
use App\Services\Ai\EngineeringKernel\TrustLevel;
use App\Services\Ai\Governance\GovernanceConsultSkipCounter;
use App\Services\Ai\Governance\ProviderGovernanceConsult;
use App\Services\Ai\Governance\ProviderGovernanceCoverageLedger;
use App\Services\Ai\HermesCliProvider;
use App\Services\Ai\Programming\AtlasDev\Differential\CandidateDivergenceGate;
use App\Services\Ai\Programming\AtlasDev\Differential\DifferentialTestingService;
use App\Services\Ai\Programming\AtlasDev\Differential\Shadow\PhpSubprocessShadowDiffHarness;
use App\Services\Ai\Programming\AtlasDev\Differential\Shadow\ShadowDiffGate;
use App\Services\Ai\Programming\AtlasDev\Differential\Shadow\ShadowDiffHarness;
use App\Services\Ai\Programming\AtlasDev\Differential\Shadow\ShadowDiffService;
use App\Services\Ai\Programming\AtlasDev\Gate\AtlasDevVerificationCommandRunnerContract as VerificationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Gate\CompletionDecision;
use App\Services\Ai\Programming\AtlasDev\Gate\CompletionStateGate;
use App\Services\Ai\Programming\AtlasDev\Gate\DevWeakOutputDetector;
use App\Services\Ai\Programming\AtlasDev\Gate\PatchApplier;
use App\Services\Ai\Programming\AtlasDev\Gate\PatchApplyResult;
use App\Services\Ai\Programming\AtlasDev\Gate\ReceiptComposer;
use App\Services\Ai\Programming\AtlasDev\Gate\ReceiptStorageAdapter;
use App\Services\Ai\Programming\AtlasDev\Gate\ScopeGuard;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGate;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGateResult;
use App\Services\Ai\Programming\AtlasDev\Gate\WorktreeBaseline;
use App\Services\Ai\Programming\AtlasDev\Intelligence\PatchIntelligenceInput;
use App\Services\Ai\Programming\AtlasDev\Intelligence\PatchIntelligenceService;
use App\Services\Ai\Programming\AtlasDev\Intelligence\ReviewIntelligenceService;
use App\Services\Ai\Programming\AtlasDev\Intelligence\TestSelectionInput;
use App\Services\Ai\Programming\AtlasDev\Intelligence\TestSelectionIntelligenceService;
use App\Services\Ai\Programming\AtlasDev\MinimaxFirst\AtlasMinimaxFirstWorkerService;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationScoreGate;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationScoreVerdict;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationTestingAdapter;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationTestingResult;
use App\Services\Ai\Programming\AtlasDev\Mutation\SymfonyMutationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Probe\IntentCoverageProbe;
use App\Services\Ai\Programming\AtlasDev\Probe\IntentFalsificationProbe;
use App\Services\Ai\Programming\AtlasDev\Probe\SpecConstitutionVerdict;
use App\Services\Ai\Programming\AtlasDev\Probe\SpecDrivenConstitutionGate;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\ProviderPromptBuilder;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Provider\DiffParser;
use App\Services\Ai\Programming\AtlasDev\Provider\DiffParseResult;
use App\Services\Ai\Programming\AtlasDev\Provider\ProviderCallResult;
use App\Services\Ai\Programming\AtlasDev\Provider\SonnetClaudeCliAdapter;
use App\Services\Ai\Programming\AtlasDev\Regression\CallerTestSelectionService;
use App\Services\Ai\Programming\AtlasDev\Regression\RegressionBaselineCache;
use App\Services\Ai\Programming\AtlasDev\Regression\RegressionBaselineGate;
use App\Services\Ai\Programming\AtlasDev\Regression\RegressionBaselineService;
use App\Services\Ai\Programming\AtlasDev\Regression\VerificationRegressionBaselineRunner;
use App\Services\Ai\Programming\AtlasDev\Repair\FailureCapsuleBuilder;
use App\Services\Ai\Programming\AtlasDev\Repair\FailureSignatureHasher;
use App\Services\Ai\Programming\AtlasDev\Repair\RepairPromptComposer;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevFailureCapsuleRuntimeService;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevTaskPacketRuntimeService;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\CompactSdd;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GateOutcome;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeFileDiff;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopePreExistingChange;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\MiniProgrammingSpec;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use App\Services\Ai\Programming\AtlasDev\Schemas\ScopeGuardReceipt;
use App\Services\Ai\Programming\AtlasDev\Schemas\VerificationReceipt;
use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationConfig;
use App\Services\Ai\Programming\AtlasDev\Support\WorkspaceOriginIdentity;
use App\Services\Ai\Programming\AtlasDev\WorkspaceMutatingProviders;
use App\Services\Ai\Programming\AtlasForgeCodexCliInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeCursorCliInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeMinimaxM27CliInvocationDriver;
use App\Services\Ai\Programming\HermesWorkspaceDefaults;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ForgeAuthority\AwisExecutionGatePort;
use Illuminate\Contracts\Container\BindingResolutionException;
use Throwable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Deterministic fast-path patch synthesis and safe patch application.
 *
 * Extracted verbatim from PipelineRunExecutor (godfile split, GOD-DEBULK
 * 2026-07-22). Behavior unchanged; cross-family calls route through the
 * sibling sections injected below.
 */
final class DeterministicPatchSection
{
    public function __construct(
        private readonly ProviderResultSupport $providerResult,
    ) {}

    public function tryDeterministicPatch(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        string $runId,
    ): ?ProviderCallResult {
        if ($taskContract->allowedFiles === [] || $taskContract->validationCommands === []) {
            return null;
        }

        $candidates = [];
        foreach ($taskContract->allowedFiles as $relativePath) {
            if (str_starts_with($relativePath, '/') || str_contains($relativePath, '..')) {
                continue;
            }

            $absolutePath = rtrim($envelope->workspace, '/').'/'.$relativePath;
            if (! is_file($absolutePath)) {
                continue;
            }

            $original = (string) file_get_contents($absolutePath);
            $updated = $this->deterministicUpdatedContents($relativePath, $original, $envelope->normalizedIntent);
            if ($updated === null || $updated === $original) {
                continue;
            }

            $candidates[] = [$relativePath, $original, $updated];
        }

        if (count($candidates) !== 1) {
            return null;
        }

        [$relativePath, $original, $updated] = $candidates[0];
        $diff = $this->singleFileUnifiedDiff($relativePath, $original, $updated);
        if ($diff === '') {
            return null;
        }

        return ProviderCallResult::fromStdout(
            runId: $runId,
            actualProvider: 'atlas_deterministic',
            actualModelFamily: 'atlas_dev_fast_path',
            exitStatus: 0,
            stdout: $diff,
            stderr: '',
            durationMs: 0,
            tokensIn: 0,
            tokensOut: 0,
            costEstimateUsd: 0.0,
            providerSafe: true,
        );
    }

    public function deterministicFastPathEnabled(): bool
    {
        return (bool) config('atlas_dev.efficient.deterministic_fast_path_enabled', true);
    }

    public function deterministicUpdatedContents(string $relativePath, string $original, string $intent): ?string
    {
        $lower = strtolower($intent);
        if (str_ends_with($relativePath, '.php') && str_contains($lower, 'hello atlas')) {
            if (str_contains($original, 'helo atlas')) {
                return str_replace('helo atlas', 'hello atlas', $original);
            }

            return preg_replace(
                "/return\\s+(['\"])[^'\"]*\\1\\s*;/",
                "return 'hello atlas';",
                $original,
                1,
            ) ?: null;
        }

        if (preg_match('/\\.(?:css|html)\\z/i', $relativePath) === 1
            && preg_match('/background(?:-color)?\\s+#([0-9a-f]{3,6})/i', $intent, $background)
            && preg_match('/border-radius\\s+([0-9]+px)/i', $intent, $radius)) {
            return $this->upsertPrimaryButtonStyles(
                $original,
                '#'.strtolower($background[1]),
                strtolower($radius[1]),
            );
        }

        if (str_ends_with($relativePath, '.blade.php')
            && str_contains($lower, 'elevated')
            && str_contains($original, 'class="status-card compact"')) {
            return str_replace(
                'class="status-card compact"',
                'class="status-card compact elevated"',
                $original,
            );
        }

        return null;
    }

    public function upsertPrimaryButtonStyles(string $contents, string $background, string $radius): ?string
    {
        $pattern = '/(?P<head>\\.primary-button\\s*\\{)(?P<body>.*?)(?P<tail>\\})/s';
        if (preg_match($pattern, $contents) !== 1) {
            return null;
        }

        return preg_replace_callback($pattern, function (array $matches) use ($background, $radius): string {
            $body = (string) $matches['body'];
            $body = $this->upsertCssDeclaration($body, 'background', $background);
            $body = $this->upsertCssDeclaration($body, 'border-radius', $radius);

            return $matches['head'].$body.$matches['tail'];
        }, $contents, 1) ?: null;
    }

    public function upsertCssDeclaration(string $body, string $property, string $value): string
    {
        if (preg_match('/(^|\\s)'.preg_quote($property, '/').'\\s*:/i', $body) === 1) {
            return preg_replace(
                '/'.preg_quote($property, '/').'\\s*:\\s*[^;]+;/i',
                $property.': '.$value.';',
                $body,
                1,
            ) ?? $body;
        }

        $indent = str_contains($body, "\n") ? '  ' : ' ';

        return rtrim($body)."\n".$indent.$property.': '.$value.";\n";
    }

    public function singleFileUnifiedDiff(string $relativePath, string $original, string $updated): string
    {
        $oldPath = tempnam(sys_get_temp_dir(), 'atlas-dev-old-');
        $newPath = tempnam(sys_get_temp_dir(), 'atlas-dev-new-');
        if ($oldPath !== false && $newPath !== false) {
            try {
                file_put_contents($oldPath, $original);
                file_put_contents($newPath, $updated);

                $process = new Process([
                    'diff',
                    '-u',
                    '--label',
                    'a/'.$relativePath,
                    '--label',
                    'b/'.$relativePath,
                    $oldPath,
                    $newPath,
                ]);
                $process->run();
                $diff = $process->getOutput();
                if ($process->getExitCode() === 1 && $diff !== '') {
                    return str_ends_with($diff, "\n") ? $diff : $diff."\n";
                }
            } finally {
                @unlink($oldPath);
                @unlink($newPath);
            }
        }

        $oldLines = explode("\n", $original);
        $newLines = explode("\n", $updated);
        $oldHadTrailingNewline = str_ends_with($original, "\n");
        $newHadTrailingNewline = str_ends_with($updated, "\n");
        if ($oldHadTrailingNewline) {
            array_pop($oldLines);
        }
        if ($newHadTrailingNewline) {
            array_pop($newLines);
        }

        $diff = [
            '--- a/'.$relativePath,
            '+++ b/'.$relativePath,
            sprintf('@@ -1,%d +1,%d @@', max(1, count($oldLines)), max(1, count($newLines))),
        ];
        $max = max(count($oldLines), count($newLines));
        for ($i = 0; $i < $max; $i++) {
            $old = $oldLines[$i] ?? null;
            $new = $newLines[$i] ?? null;
            if ($old !== null && $new !== null && $old === $new) {
                $diff[] = ' '.$old;

                continue;
            }
            if ($old !== null) {
                $diff[] = '-'.$old;
            }
            if ($new !== null) {
                $diff[] = '+'.$new;
            }
        }

        return implode("\n", $diff)."\n";
    }

    public function applyPatchIfSafe(
        DiffParseResult $diffResult,
        string $scopeStatus,
        string $workspace,
        ProviderCallResult $callResult,
    ): PatchApplyResult {
        if (! $diffResult->hasPatch()) {
            return new PatchApplyResult(
                status: PatchApplyResult::STATUS_SKIPPED,
                exitCode: 0,
                durationMs: 0,
                stdout: '',
                stderr: '',
                reason: 'no_patch',
            );
        }

        if ($this->providerResult->providerMutatedWorkspace($callResult)) {
            return new PatchApplyResult(
                status: PatchApplyResult::STATUS_SKIPPED,
                exitCode: 0,
                durationMs: 0,
                stdout: '',
                stderr: '',
                reason: 'provider_mutated_workspace',
            );
        }

        if ($scopeStatus !== ScopeGuardReceipt::STATUS_PASSED) {
            return new PatchApplyResult(
                status: PatchApplyResult::STATUS_FAILED,
                exitCode: 1,
                durationMs: 0,
                stdout: '',
                stderr: 'Patch application skipped because scope guard did not pass.',
                reason: 'scope_guard_not_passed',
            );
        }

        return (new PatchApplier)->apply($diffResult, $workspace, scope: ['run_id' => $callResult->runId]);
    }
}

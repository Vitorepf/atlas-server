<?php

declare(strict_types=1);

namespace App\Http\Controllers\AtlasDev\Support\PipelineRun;

use App\Services\Ai\Programming\AtlasDev\Gate\PatchApplier;
use App\Services\Ai\Programming\AtlasDev\Gate\PatchApplyResult;
use App\Services\Ai\Programming\AtlasDev\Provider\DiffParseResult;
use App\Services\Ai\Programming\AtlasDev\Provider\ProviderCallResult;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\ScopeGuardReceipt;
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

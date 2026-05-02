<?php

namespace App\Services\Engineering;

use App\Models\AtlasEngineeringPatchArtifact;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasEngineeringRunAttempt;
use App\Support\AtlasSecurity;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class EngineeringPatchArtifactService
{
    /**
     * @param  array<string,mixed>  $metadata
     */
    public function capture(
        AtlasEngineeringRun $run,
        ?AtlasEngineeringRunAttempt $attempt,
        string $workspace,
        array $metadata = [],
    ): ?AtlasEngineeringPatchArtifact {
        if (! Schema::hasTable('atlas_engineering_patch_artifacts')) {
            return null;
        }

        $workspace = realpath($workspace) ?: $workspace;
        $status = $this->process(['git', 'status', '--short'], $workspace);
        $diff = $this->process(['git', 'diff'], $workspace, 20);
        $changedFiles = $this->changedFiles($workspace, $status, $diff);
        $createdFiles = $this->statusFiles($status, ['A', '??']);
        $deletedFiles = $this->statusFiles($status, ['D']);
        $riskFlags = $this->riskFlags($changedFiles, $diff);
        $baseRef = $this->process(['git', 'rev-parse', '--short', 'HEAD'], $workspace) ?: null;
        $headRef = $this->process(['git', 'branch', '--show-current'], $workspace) ?: null;
        $redactedDiff = $diff !== '' ? rtrim(AtlasSecurity::redactString($diff))."\n" : '';
        $diffHash = $redactedDiff !== '' ? hash('sha256', $redactedDiff) : null;
        $diffPath = $this->persistDiff($run, $attempt, $redactedDiff);

        $artifact = AtlasEngineeringPatchArtifact::query()->create([
            'engineering_run_id' => $run->id,
            'attempt_id' => $attempt?->id,
            'base_ref' => $baseRef,
            'head_ref' => $headRef,
            'diff_hash' => $diffHash,
            'diff_excerpt' => $redactedDiff !== '' ? Str::limit($redactedDiff, 12000, "\n...[diff truncated by Atlas]") : null,
            'diff_path' => $diffPath,
            'changed_files_json' => $changedFiles,
            'created_files_json' => $createdFiles,
            'deleted_files_json' => $deletedFiles,
            'risk_flags_json' => $riskFlags,
            'metadata' => array_merge($metadata, [
                'status_excerpt' => Str::limit(AtlasSecurity::redactString($status), 4000, "\n...[status truncated by Atlas]"),
                'dirty_count' => count($changedFiles),
            ]),
        ]);

        if ($attempt) {
            $attempt->forceFill([
                'patch_hash' => $diffHash,
                'changed_files_json' => $changedFiles,
                'diff_stat_json' => [
                    'dirty_count' => count($changedFiles),
                    'created_count' => count($createdFiles),
                    'deleted_count' => count($deletedFiles),
                    'risk_flags' => $riskFlags,
                ],
            ])->save();
        }

        return $artifact;
    }

    private function persistDiff(AtlasEngineeringRun $run, ?AtlasEngineeringRunAttempt $attempt, string $redactedDiff): ?string
    {
        if ($redactedDiff === '') {
            return null;
        }

        $attemptNumber = $attempt?->attempt_number ?: 0;
        $directory = storage_path('app/engineering-runs/'.$run->id);
        $path = $directory.'/attempt-'.$attemptNumber.'.patch';
        File::ensureDirectoryExists($directory);
        File::put($path, $redactedDiff);

        return $path;
    }

    /**
     * @return array<int,string>
     */
    private function changedFiles(string $workspace, string $status, string $diff): array
    {
        $fromStatus = collect(explode("\n", $status))
            ->map(fn (string $line): ?string => $this->pathFromStatusLine($line))
            ->filter()
            ->values();
        $fromDiff = collect(explode("\n", $this->process(['git', 'diff', '--name-only'], $workspace)))
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->values();

        if ($fromStatus->isEmpty() && $diff === '') {
            return [];
        }

        return $fromStatus
            ->concat($fromDiff)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int,string>  $codes
     * @return array<int,string>
     */
    private function statusFiles(string $status, array $codes): array
    {
        return collect(explode("\n", $status))
            ->map(fn (string $line): array => $this->statusEntry($line))
            ->filter(function (array $entry) use ($codes): bool {
                $code = (string) $entry['code'];

                return collect($codes)->contains(fn (string $candidate): bool => str_contains($code, $candidate));
            })
            ->pluck('file')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{code:string,file:string}
     */
    private function statusEntry(string $line): array
    {
        $line = rtrim($line);
        if (preg_match('/^(.{1,2})\s+(.+)$/', $line, $matches) === 1) {
            return [
                'code' => trim((string) $matches[1]),
                'file' => trim((string) $matches[2]),
            ];
        }

        $line = trim($line);
        if (preg_match('/^([A-Z?]{1,2})\s+(.+)$/', $line, $matches) === 1) {
            return [
                'code' => trim((string) $matches[1]),
                'file' => trim((string) $matches[2]),
            ];
        }

        return ['code' => '', 'file' => ''];
    }

    private function pathFromStatusLine(string $line): ?string
    {
        $entry = $this->statusEntry($line);

        return $entry['file'] !== '' ? $entry['file'] : null;
    }

    /**
     * @param  array<int,string>  $changedFiles
     * @return array<int,string>
     */
    private function riskFlags(array $changedFiles, string $diff): array
    {
        $flags = [];

        if (collect($changedFiles)->contains(fn (string $file): bool => str_contains($file, '.env'))) {
            $flags[] = 'env_file_changed';
        }

        if (preg_match('/(api[_-]?key|secret|password|token)\s*[=:]/i', $diff) === 1) {
            $flags[] = 'possible_secret_in_diff';
        }

        if (collect($changedFiles)->contains(fn (string $file): bool => str_starts_with($file, 'database/migrations/'))) {
            $flags[] = 'migration_changed';
        }

        if (count($changedFiles) > 25) {
            $flags[] = 'large_diff_surface';
        }

        return array_values(array_unique($flags));
    }

    /**
     * @param  array<int,string>  $command
     */
    private function process(array $command, string $workspace, int $timeout = 10): string
    {
        try {
            $process = new Process($command, $workspace, AtlasSecurity::processEnv(profile: 'tool'));
            $process->setTimeout($timeout);
            $process->run();
        } catch (\Throwable) {
            return '';
        }

        return trim(AtlasSecurity::redactString($process->getOutput()));
    }
}

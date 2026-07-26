<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Pipeline;

use App\Services\Ai\Programming\AtlasDev\Schemas\CodeDiscoveryManifest;
use App\Services\Ai\Programming\AtlasDev\Schemas\MiniProgrammingSpec;
use Throwable;

/**
 * Gives Atlas Dev real workcell decomposition so a model always receives a small, provable unit
 * instead of one giant undifferentiated diff. Pure, deterministic: given the composed
 * {@see MiniProgrammingSpec} payload and the
 * {@see CodeDiscoveryManifest} payload, it clusters
 * `allowed_files` by directory and splits `acceptance_criteria` along those clusters.
 *
 * COMMON CASE (zero overhead): a spec whose allowed_files live in ONE directory cluster yields
 * exactly ONE workcell mirroring the spec's full scope — no artificial splitting for a small
 * change.
 *
 * MULTI-CONCERN CASE: allowed_files spanning more than one directory split into one workcell per
 * cluster. Every workcell's allowed_files is disjoint from every other's (conflict-free by
 * construction — the same invariant the Task Fabric's claim system relies on). An acceptance
 * criterion is assigned to a workcell when its `verification` text names one of that workcell's
 * files; unmatched criteria stay on the workcell holding the largest file cluster (the "primary"
 * slice) so no criterion is silently dropped. A workcell whose files are ALL test paths depends
 * on every non-test workcell (it exercises their implementation).
 *
 * Deterministic, no I/O, no provider call. Any internal error falls back to the single-workcell
 * shape over the spec's raw allowed_files — this decomposer can only ever get in the way if it
 * throws, so it never does from the caller's perspective.
 */
final class DevWorkcellDecomposer
{
    public const SCHEMA = 'atlas.dev.pipeline.workcell_decomposition.v1';

    /**
     * @param  array<string,mixed>  $spec
     * @param  array<string,mixed>  $manifest
     * @return array{schema:string, workcells:list<array<string,mixed>>}
     */
    public function decompose(array $spec, array $manifest): array
    {
        try {
            return $this->decomposeInternal($spec, $manifest);
        } catch (Throwable) {
            return $this->singleWorkcellFallback($spec);
        }
    }

    /**
     * @param  array<string,mixed>  $spec
     * @param  array<string,mixed>  $manifest
     * @return array{schema:string, workcells:list<array<string,mixed>>}
     */
    private function decomposeInternal(array $spec, array $manifest): array
    {
        $allowedFiles = array_values(array_unique(array_filter(array_map('strval', (array) ($spec['allowed_files'] ?? [])))));
        $acceptanceCriteria = array_values((array) ($spec['acceptance_criteria'] ?? []));
        $verificationCommands = array_values(array_map('strval', (array) ($spec['verification_plan']['commands'] ?? [])));
        $goal = (string) ($spec['goal'] ?? '');

        if ($allowedFiles === []) {
            return $this->singleWorkcellFallback($spec);
        }

        $clusters = $this->clusterByDirectory($allowedFiles);

        if (count($clusters) <= 1) {
            return [
                'schema' => self::SCHEMA,
                'workcells' => [$this->buildWorkcell(
                    'wc-1',
                    $goal,
                    $allowedFiles,
                    $acceptanceCriteria,
                    $verificationCommands,
                    [],
                )],
            ];
        }

        // Deterministic order: directory name ascending.
        ksort($clusters, SORT_STRING);
        $clusterKeys = array_keys($clusters);

        $primaryCluster = $clusterKeys[0];
        $largestCount = count($clusters[$primaryCluster]);
        foreach ($clusters as $dir => $files) {
            if (count($files) > $largestCount) {
                $primaryCluster = $dir;
                $largestCount = count($files);
            }
        }

        $workcells = [];
        $workcellIdByCluster = [];
        $index = 0;
        foreach ($clusterKeys as $dir) {
            $index++;
            $workcellIdByCluster[$dir] = "wc-{$index}";
        }

        $assignedCriteria = array_fill_keys($clusterKeys, []);
        foreach ($acceptanceCriteria as $criterion) {
            $verificationText = is_array($criterion) ? (string) ($criterion['verification'] ?? '') : '';
            $matchedCluster = null;
            foreach ($clusters as $dir => $files) {
                foreach ($files as $file) {
                    if ($verificationText !== '' && str_contains($verificationText, $file)) {
                        $matchedCluster = $dir;

                        break 2;
                    }
                }
            }
            $assignedCriteria[$matchedCluster ?? $primaryCluster][] = $criterion;
        }

        foreach ($clusterKeys as $dir) {
            $files = $clusters[$dir];
            $isTestCluster = $this->allTestPaths($files);
            $dependsOn = [];
            if ($isTestCluster) {
                foreach ($clusterKeys as $otherDir) {
                    if ($otherDir !== $dir && ! $this->allTestPaths($clusters[$otherDir])) {
                        $dependsOn[] = $workcellIdByCluster[$otherDir];
                    }
                }
                sort($dependsOn);
            }

            $sliceCommands = $this->commandsForCluster($verificationCommands, $files);

            $workcells[] = $this->buildWorkcell(
                $workcellIdByCluster[$dir],
                $goal !== '' ? "{$goal} — cluster: {$dir}" : "cluster: {$dir}",
                $files,
                $assignedCriteria[$dir],
                $sliceCommands,
                $dependsOn,
            );
        }

        return [
            'schema' => self::SCHEMA,
            'workcells' => $workcells,
        ];
    }

    /**
     * @param  list<string>  $files
     * @return array<string,list<string>> directory => sorted files, deterministic
     */
    private function clusterByDirectory(array $files): array
    {
        $clusters = [];
        foreach ($files as $file) {
            $dir = str_contains($file, '/') ? substr($file, 0, (int) strrpos($file, '/')) : '.';
            $clusters[$dir][] = $file;
        }
        foreach ($clusters as $dir => $list) {
            sort($clusters[$dir]);
        }

        return $clusters;
    }

    /** @param  list<string>  $files */
    private function allTestPaths(array $files): bool
    {
        foreach ($files as $file) {
            if (! (str_starts_with($file, 'tests/') || str_contains($file, '/tests/') || str_ends_with($file, 'Test.php'))) {
                return false;
            }
        }

        return $files !== [];
    }

    /**
     * @param  list<string>  $commands
     * @param  list<string>  $files
     * @return list<string>
     */
    private function commandsForCluster(array $commands, array $files): array
    {
        $matched = [];
        foreach ($commands as $command) {
            foreach ($files as $file) {
                if (str_contains($command, $file) || str_contains($command, basename($file, '.php'))) {
                    $matched[] = $command;

                    break;
                }
            }
        }

        if ($matched !== []) {
            return array_values(array_unique($matched));
        }

        return $commands !== [] ? [$commands[0]] : [];
    }

    /**
     * @param  list<string>  $allowedFiles
     * @param  list<mixed>  $acceptanceCriteria
     * @param  list<string>  $verificationCommands
     * @param  list<string>  $dependsOn
     * @return array<string,mixed>
     */
    private function buildWorkcell(
        string $workcellId,
        string $objectiveSlice,
        array $allowedFiles,
        array $acceptanceCriteria,
        array $verificationCommands,
        array $dependsOn,
    ): array {
        return [
            'workcell_id' => $workcellId,
            'objective_slice' => $objectiveSlice,
            'allowed_files' => array_values($allowedFiles),
            'acceptance_criteria' => array_values($acceptanceCriteria),
            'risk_level' => $this->allTestPaths($allowedFiles) ? 'low' : 'medium',
            'verification_command' => $verificationCommands[0] ?? '',
            'depends_on' => array_values($dependsOn),
        ];
    }

    /**
     * @param  array<string,mixed>  $spec
     * @return array{schema:string, workcells:list<array<string,mixed>>}
     */
    private function singleWorkcellFallback(array $spec): array
    {
        $allowedFiles = [];
        try {
            $allowedFiles = array_values(array_filter(array_map('strval', (array) ($spec['allowed_files'] ?? []))));
        } catch (Throwable) {
            $allowedFiles = [];
        }

        $goal = '';
        try {
            $goal = (string) ($spec['goal'] ?? '');
        } catch (Throwable) {
            $goal = '';
        }

        $acceptanceCriteria = [];
        try {
            $acceptanceCriteria = array_values((array) ($spec['acceptance_criteria'] ?? []));
        } catch (Throwable) {
            $acceptanceCriteria = [];
        }

        return [
            'schema' => self::SCHEMA,
            'workcells' => [[
                'workcell_id' => 'wc-1',
                'objective_slice' => $goal,
                'allowed_files' => $allowedFiles,
                'acceptance_criteria' => $acceptanceCriteria,
                'risk_level' => 'medium',
                'verification_command' => '',
                'depends_on' => [],
            ]],
        ];
    }
}

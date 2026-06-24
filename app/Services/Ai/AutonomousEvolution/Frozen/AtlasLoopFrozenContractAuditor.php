<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Frozen;

final class AtlasLoopFrozenContractAuditor
{
    public const SCHEMA_VERSION = 'atlas.ai.loop_frozen_contract_auditor.v1';

    public const VERDICT_ALLOW = 'ALLOW';

    public const VERDICT_BLOCK = 'BLOCK';

    /**
     * @param  list<string>  $changedPaths
     * @param  list<string>  $rerunGreenAssertedPaths
     * @return array{
     *     schema_version:string,
     *     verdict:'ALLOW'|'BLOCK',
     *     offending_pairs:list<array{fqcn:string,frozen_test_path:string}>,
     *     checked_pairs:list<array{fqcn:string,frozen_test_path:string,covered_by:string}>
     * }
     */
    public function audit(
        array $changedPaths,
        AtlasLoopFrozenContractRegistry $registry,
        array $rerunGreenAssertedPaths = [],
    ): array {
        $changed = $this->normalizedSet($changedPaths);
        $rerunGreen = $this->normalizedSet($rerunGreenAssertedPaths);
        $offending = [];
        $checked = [];

        foreach (array_keys($changed) as $path) {
            $fqcn = $this->fqcnFromLoopPath($path);
            if ($fqcn === null) {
                continue;
            }

            $contract = $registry->get($fqcn);
            if ($contract === null) {
                continue;
            }

            $testPath = $this->normalizePath((string) $contract['test_path']);
            $coveredBy = $this->coveredBy($testPath, $changed, $rerunGreen);
            if ($coveredBy === null) {
                $offending[] = [
                    'fqcn' => $fqcn,
                    'frozen_test_path' => $testPath,
                ];
                continue;
            }

            $checked[] = [
                'fqcn' => $fqcn,
                'frozen_test_path' => $testPath,
                'covered_by' => $coveredBy,
            ];
        }

        usort($offending, static fn (array $a, array $b): int => $a['fqcn'] <=> $b['fqcn']);
        usort($checked, static fn (array $a, array $b): int => $a['fqcn'] <=> $b['fqcn']);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'verdict' => $offending === [] ? self::VERDICT_ALLOW : self::VERDICT_BLOCK,
            'offending_pairs' => $offending,
            'checked_pairs' => $checked,
        ];
    }

    /**
     * @param  list<string>  $paths
     * @return array<string,true>
     */
    private function normalizedSet(array $paths): array
    {
        $set = [];
        foreach ($paths as $path) {
            $path = $this->normalizePath($path);
            if ($path !== '') {
                $set[$path] = true;
            }
        }
        ksort($set);

        return $set;
    }

    private function normalizePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        $path = preg_replace('#/+#', '/', $path) ?? '';

        return ltrim($path, './');
    }

    private function fqcnFromLoopPath(string $path): ?string
    {
        $prefix = 'app/Services/Ai/AutonomousEvolution/';
        if (! str_starts_with($path, $prefix) || ! str_ends_with($path, '.php')) {
            return null;
        }

        $relative = substr($path, strlen($prefix), -4);
        if ($relative === '') {
            return null;
        }

        return 'App\\Services\\Ai\\AutonomousEvolution\\'.str_replace('/', '\\', $relative);
    }

    /**
     * @param  array<string,true>  $changed
     * @param  array<string,true>  $rerunGreen
     */
    private function coveredBy(string $testPath, array $changed, array $rerunGreen): ?string
    {
        if (isset($changed[$testPath])) {
            return 'diff_touched';
        }
        if (isset($rerunGreen[$testPath])) {
            return 'rerun_green_asserted';
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasImplementationTruthService;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasCapabilityTestExecutionService;
use App\Services\Ai\Cognition\AtlasCognitionEvidenceResolver;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

class AtlasCognitionRemintTouchedCommand extends Command
{
    protected $signature = 'atlas:cognition:remint-touched
        {--paths= : Comma-separated touched paths}
        {--from-commit= : Resolve touched paths via git diff --name-only <sha>}
        {--limit=10 : Maximum owner capabilities to remint}
        {--json : Emit canonical JSON}';

    protected $description = 'Re-mint ACOS pipeline receipts for owner docs affected by touched paths.';

    public function handle(
        AtlasCognitionEvidenceResolver $resolver,
        AtlasImplementationTruthService $truth,
        AtlasCapabilityTestExecutionService $execution,
    ): int {
        $limit = max(1, min(100, (int) $this->option('limit')));
        $paths = $this->paths();
        $ownerMap = $resolver->ownerCapabilityIdsForPaths($paths);
        $ownerIds = $this->dedupe(array_merge(...array_values($ownerMap ?: [[]])));

        $capabilities = [];
        foreach ($truth->capabilityTestRefs() as $capability) {
            $capabilities[(string) ($capability['capability_id'] ?? '')] = $capability;
        }
        foreach ($truth->docsWithEvidence() as $doc) {
            $id = (string) ($doc['id'] ?? '');
            if ($id !== '' && ! isset($capabilities[$id])) {
                $capabilities[$id] = [
                    'capability_id' => $id,
                    'owner_doc' => (string) ($doc['path'] ?? ''),
                    'evidence_refs' => (array) ($doc['evidence_refs'] ?? []),
                    'test_refs' => [],
                ];
            }
        }

        $processed = 0;
        $green = 0;
        $minted = [];
        foreach ($ownerIds as $capabilityId) {
            if ($processed >= $limit) {
                break;
            }
            $cap = $capabilities[$capabilityId] ?? null;
            if (! is_array($cap)) {
                continue;
            }

            $testRef = $this->firstResolvedTestRef($cap);
            if ($testRef === null) {
                continue;
            }

            $hashes = $truth->freshnessHashes((array) ($cap['evidence_refs'] ?? []), $testRef);
            $receipt = $execution->runAndRecord(
                $capabilityId,
                $testRef,
                null,
                $hashes['test_file_hash'] ?? null,
                $hashes['impl_files_hash'] ?? null,
            );
            $processed++;
            $passed = (bool) ($receipt['passed'] ?? false) && (int) ($receipt['tests_run'] ?? 0) > 0;
            $seal = null;
            if ($passed) {
                try {
                    $seal = $execution->verifyGreenMintSeal($capabilityId, $testRef, (array) ($cap['evidence_refs'] ?? []));
                    if (($seal['born_stale'] ?? false) === true) {
                        $passed = false;
                    }
                } catch (Throwable $e) {
                    $seal = ['sealed' => false, 'born_stale' => true, 'error' => mb_substr($e->getMessage(), 0, 200)];
                    $passed = false;
                }
            }
            $green += $passed ? 1 : 0;
            $minted[] = [
                'capability_id' => $capabilityId,
                'owner_doc' => (string) ($cap['owner_doc'] ?? ''),
                'test_ref' => $testRef,
                'green' => $passed,
                'tests_run' => (int) ($receipt['tests_run'] ?? 0),
                'seal' => $seal,
            ];
        }

        $result = [
            'schema_version' => 'atlas.cognition.remint_touched.v1',
            'paths' => $paths,
            'owner_map' => $ownerMap,
            'owner_capability_ids' => $ownerIds,
            'capabilities_processed' => $processed,
            'green_receipts_minted' => $green,
            'minted' => $minted,
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Touched paths', (string) count($paths));
        $this->components->twoColumnDetail('Owner capabilities', (string) count($ownerIds));
        $this->components->twoColumnDetail('Processed', (string) $processed);
        $this->components->twoColumnDetail('Green receipts', (string) $green);

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function paths(): array
    {
        $fromOption = trim((string) $this->option('from-commit'));
        if ($fromOption !== '') {
            return $this->pathsFromCommit($fromOption);
        }

        $raw = trim((string) $this->option('paths'));
        if ($raw === '') {
            return [];
        }

        return $this->dedupe(array_values(array_filter(array_map(
            fn (string $path): string => $this->normalizePath($path),
            explode(',', $raw),
        ))));
    }

    /**
     * @return list<string>
     */
    private function pathsFromCommit(string $commit): array
    {
        try {
            $process = new Process(['git', 'diff', '--name-only', $commit, '--'], base_path());
            $process->setTimeout(30);
            $process->run();
            if (! $process->isSuccessful()) {
                return [];
            }

            return $this->dedupe(array_values(array_filter(array_map(
                fn (string $path): string => $this->normalizePath($path),
                explode("\n", $process->getOutput()),
            ))));
        } catch (Throwable) {
            return [];
        }
    }

    private function firstResolvedTestRef(array $capability): ?string
    {
        foreach ((array) ($capability['test_refs'] ?? []) as $testRef) {
            if (($testRef['index_resolved'] ?? false) === true) {
                $ref = trim((string) ($testRef['ref'] ?? ''));
                if ($ref !== '') {
                    return $ref;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function dedupe(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $out[$value] = true;
            }
        }

        return array_keys($out);
    }

    private function normalizePath(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', trim($path)), '/');

        return str_contains($path, '..') ? '' : $path;
    }
}

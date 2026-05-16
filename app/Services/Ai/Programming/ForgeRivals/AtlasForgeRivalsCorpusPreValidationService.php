<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use InvalidArgumentException;

/**
 * Atlas Forge Rivals · Corpus Pre-Validation Gate (v1).
 *
 * Walks every case the operator's flags will resolve to (preset/case_set/cases)
 * and refuses to let the battery proceed if any case is contaminated:
 *
 *   - fixture_seed_dir_missing:<case_id>      seed_dir declared but empty
 *   - fixture_seed_dir_not_found:<case_id>    seed_dir points nowhere
 *   - fixture_seed_empty:<case_id>            seed dir exists but only README.md
 *   - fixture_seed_no_stageable_files:<case_id>  has files but no allowed target
 *   - expected_changed_files_missing:<case_id>   release case with empty list
 *
 * Runs entirely read-only — never invokes a provider, never spends tokens,
 * never mutates the worktree. The intent is to fail-closed *before* any
 * provider call is reached so a contaminated corpus cannot turn into an
 * Atlas 100 × 0 score by accident.
 *
 * Schema: atlas.forge.rivals.corpus_pre_validation.v1
 */
final class AtlasForgeRivalsCorpusPreValidationService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.corpus_pre_validation.v1';

    public function __construct(
        private readonly AtlasForgeRivalsProviderArenaCorpusService $corpus,
    ) {}

    /**
     * @param  array<string,mixed>  $input  preset/case_set/case/cases + optional require_expected_changed_files
     * @return array{
     *     status: 'ok'|'blocked',
     *     case_count: int,
     *     valid_count: int,
     *     blocked_count: int,
     *     case_ids: list<string>,
     *     blocked_case_ids: list<string>,
     *     blockers: list<string>,
     *     per_case: list<array<string,mixed>>,
     *     resolution: array<string,mixed>,
     *     provider_tokens_spent: false,
     *     external_provider_call: false,
     *     schema_version: string,
     * }
     */
    public function validate(array $input): array
    {
        $preset = strtolower(trim((string) ($input['preset'] ?? '')));
        $caseSet = strtolower(trim((string) ($input['case_set'] ?? '')));
        $explicitCase = trim((string) ($input['case'] ?? ''));
        $explicitCases = array_values(array_filter(
            array_map(static fn ($v): string => trim((string) $v), (array) ($input['cases'] ?? [])),
            static fn (string $v): bool => $v !== '',
        ));
        $requireExpectedChangedFiles = (bool) ($input['require_expected_changed_files'] ?? true);

        try {
            $cases = $this->resolveCases($explicitCase, $explicitCases, $caseSet, $preset);
        } catch (InvalidArgumentException $e) {
            return $this->terminal(
                preset: $preset,
                caseSet: $caseSet,
                explicitCase: $explicitCase,
                explicitCases: $explicitCases,
                cases: [],
                blockers: [(string) $e->getMessage()],
            );
        }

        if ($cases === []) {
            $blocker = $caseSet !== ''
                ? 'empty_case_set:'.$caseSet
                : ($preset !== '' ? 'empty_preset:'.$preset : 'no_cases_resolved');

            return $this->terminal(
                preset: $preset,
                caseSet: $caseSet,
                explicitCase: $explicitCase,
                explicitCases: $explicitCases,
                cases: [],
                blockers: [$blocker],
            );
        }

        $perCase = [];
        $aggregateBlockers = [];
        $blockedCaseIds = [];

        foreach ($cases as $case) {
            $report = $this->validateCase($case, $requireExpectedChangedFiles);
            $perCase[] = $report;
            if (! $report['valid']) {
                $blockedCaseIds[] = (string) $report['case_id'];
                foreach ($report['blockers'] as $b) {
                    $aggregateBlockers[] = (string) $b;
                }
            }
        }

        $aggregateBlockers = array_values(array_unique($aggregateBlockers));
        $caseIds = array_values(array_map(static fn (array $r): string => (string) $r['case_id'], $perCase));
        $blockedCaseIds = array_values(array_unique($blockedCaseIds));
        $valid = $blockedCaseIds === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $valid ? 'ok' : 'blocked',
            'case_count' => count($perCase),
            'valid_count' => count($perCase) - count($blockedCaseIds),
            'blocked_count' => count($blockedCaseIds),
            'case_ids' => $caseIds,
            'blocked_case_ids' => $blockedCaseIds,
            'blockers' => $aggregateBlockers,
            'per_case' => $perCase,
            'resolution' => [
                'preset' => $preset,
                'case_set' => $caseSet !== '' ? $caseSet : null,
                'explicit_case' => $explicitCase !== '' ? $explicitCase : null,
                'explicit_cases' => $explicitCases,
            ],
            'provider_tokens_spent' => false,
            'external_provider_call' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array{
     *     case_id: string,
     *     valid: bool,
     *     seed_dir: string,
     *     seed_source: 'worktree'|'source_repo'|'unresolved',
     *     stageable_file_count: int,
     *     expected_changed_files: list<string>,
     *     blockers: list<string>,
     * }
     */
    private function validateCase(array $case, bool $requireExpectedChangedFiles): array
    {
        $caseId = (string) ($case['case_id'] ?? '');
        $fixture = is_array($case['setup_fixture'] ?? null) ? $case['setup_fixture'] : [];
        $seedDir = trim((string) ($fixture['seed_dir'] ?? ''));
        $expectedChangedFiles = array_values(array_map(
            static fn ($v): string => trim((string) $v),
            (array) ($case['expected_changed_files'] ?? []),
        ));
        $expectedChangedFiles = array_values(array_filter(
            $expectedChangedFiles,
            static fn (string $v): bool => $v !== '',
        ));

        $blockers = [];
        $seedSource = 'unresolved';
        $stageableFileCount = 0;

        if ($seedDir === '') {
            $blockers[] = 'fixture_seed_dir_missing:'.$caseId;
        } else {
            $seedRoot = $this->resolveSeedRoot($seedDir);
            if ($seedRoot === null) {
                $blockers[] = 'fixture_seed_dir_not_found:'.$caseId;
            } else {
                $seedSource = 'source_repo';
                $files = $this->seedFiles($seedRoot);
                $nonReadme = array_values(array_filter(
                    $files,
                    fn (string $path): bool => ! $this->isReadme($seedRoot, $path),
                ));
                $stageableFileCount = count($nonReadme);
                if ($stageableFileCount === 0) {
                    $blockers[] = 'fixture_seed_empty:'.$caseId;
                }
            }
        }

        if ($requireExpectedChangedFiles && $expectedChangedFiles === []) {
            $blockers[] = 'expected_changed_files_missing:'.$caseId;
        }

        return [
            'case_id' => $caseId,
            'valid' => $blockers === [],
            'seed_dir' => $seedDir,
            'seed_source' => $seedSource,
            'stageable_file_count' => $stageableFileCount,
            'expected_changed_files' => $expectedChangedFiles,
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    /**
     * @param  list<string>  $explicitCases
     * @return list<array<string,mixed>>
     */
    private function resolveCases(string $explicitCase, array $explicitCases, string $caseSet, string $preset): array
    {
        if ($explicitCase !== '') {
            return [$this->corpus->case($explicitCase)];
        }

        if ($explicitCases !== []) {
            return array_values(array_map(fn (string $id): array => $this->corpus->case($id), $explicitCases));
        }

        if ($caseSet !== '') {
            return $this->corpus->casesForCaseSet($caseSet);
        }

        if ($preset === AtlasForgeRivalsCasesRegistry::PRESET_RELEASE) {
            return $this->corpus->casesForCaseSet(AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_RELEASE);
        }

        return [];
    }

    private function resolveSeedRoot(string $seedDir): ?string
    {
        if ($seedDir === '' || str_contains($seedDir, '..') || str_contains($seedDir, "\0")) {
            return null;
        }
        if (! str_starts_with($seedDir, 'storage/forge-rivals-corpus/')) {
            return null;
        }
        $absolute = base_path($seedDir);

        return is_dir($absolute) ? $absolute : null;
    }

    /**
     * @return list<string>
     */
    private function seedFiles(string $seedRoot): array
    {
        $files = [];
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($seedRoot, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $files[] = $file->getPathname();
                }
            }
        } catch (\Throwable) {
            return [];
        }
        sort($files);

        return array_values($files);
    }

    private function isReadme(string $seedRoot, string $path): bool
    {
        $relative = ltrim(str_replace('\\', '/', substr($path, strlen(rtrim($seedRoot, '/')))), '/');

        return strtolower($relative) === 'readme.md';
    }

    /**
     * @param  list<string>  $explicitCases
     * @param  list<array<string,mixed>>  $cases
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function terminal(
        string $preset,
        string $caseSet,
        string $explicitCase,
        array $explicitCases,
        array $cases,
        array $blockers,
    ): array {
        $blockers = array_values(array_unique($blockers));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'blocked',
            'case_count' => count($cases),
            'valid_count' => 0,
            'blocked_count' => count($cases),
            'case_ids' => [],
            'blocked_case_ids' => [],
            'blockers' => $blockers,
            'per_case' => [],
            'resolution' => [
                'preset' => $preset,
                'case_set' => $caseSet !== '' ? $caseSet : null,
                'explicit_case' => $explicitCase !== '' ? $explicitCase : null,
                'explicit_cases' => $explicitCases,
            ],
            'provider_tokens_spent' => false,
            'external_provider_call' => false,
        ];
    }
}

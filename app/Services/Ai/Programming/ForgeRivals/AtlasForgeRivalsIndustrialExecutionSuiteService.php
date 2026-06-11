<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use App\Services\Ai\Support\AiStringListNormalizer;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Atlas Forge Rivals · Industrial Execution Suite v1 readiness.
 *
 * Local-only executable-fixture readiness for industrial case sets. This
 * service may materialize deterministic local fixtures under storage/, but it
 * never calls providers, never spends tokens, and never unlocks external
 * certification.
 */
final class AtlasForgeRivalsIndustrialExecutionSuiteService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.industrial_execution_suite.v1';

    public const SUITE_ID = 'atlas-forge-rivals-industrial-execution-suite-v1';

    public const CANONICAL_DOC = 'docs/engineering-knowledge-base/atlas-forge-rivals-industrial-execution-suite-v1.md';

    public const CANONICAL_PHRASE = 'Rivals emits measured evidence; Atlas Decide decides model routing.';

    /** @var list<string> */
    private const EXECUTION_CASE_SETS = [
        AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_INDUSTRIAL_50,
        AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_INDUSTRIAL_100,
        AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_INDUSTRIAL_200,
        AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_AMBIGUOUS_BUGS,
        AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_MULTI_DAY_REFACTORS,
        AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_INCIDENT_RESPONSE,
        AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_PRODUCT_SECURITY_MIGRATIONS,
        AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_META_PROVIDER_STRESS,
        AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_EXTREME_DIFFERENTIATOR,
        AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_CEILING_360,
    ];

    public function __construct(
        private readonly AtlasForgeRivalsProviderArenaCorpusService $corpus,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function readiness(array $input = []): array
    {
        $caseSet = strtolower(trim((string) ($input['case_set'] ?? $input['preset'] ?? AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_INDUSTRIAL_50)));
        if ($caseSet === '') {
            $caseSet = AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_INDUSTRIAL_50;
        }

        if ($caseSet === AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_STATISTICAL_REPEAT) {
            return $this->statisticalRepeatReadiness();
        }

        if (! in_array($caseSet, self::EXECUTION_CASE_SETS, true)) {
            return $this->blockedReadiness($caseSet, ['not_industrial_execution_case_set:'.$caseSet]);
        }

        $ensureFixtures = (bool) ($input['ensure_fixtures'] ?? true);
        $hasCasesOverride = is_array($input['cases_override'] ?? null);
        $cases = $hasCasesOverride
            ? array_values((array) $input['cases_override'])
            : $this->corpus->casesForCaseSet($caseSet);

        if ($ensureFixtures) {
            if ($hasCasesOverride) {
                foreach ($cases as $case) {
                    if (is_array($case)) {
                        $this->materializeCaseFixture($case);
                    }
                }
            } else {
                $this->ensureFixtures($caseSet);
                $cases = $this->corpus->casesForCaseSet($caseSet);
            }
        }

        $requiredCasesOverride = is_numeric($input['required_cases_override'] ?? null)
            ? max(1, (int) $input['required_cases_override'])
            : null;

        return $this->readinessForCases($caseSet, $cases, $requiredCasesOverride);
    }

    /**
     * Materialize deterministic seed files for an executable industrial
     * corpus. Files are stored under the declared fixture_seed_path so the
     * existing RunReal staging logic remains the single execution path.
     *
     * @return array<string,mixed>
     */
    public function ensureFixtures(string $caseSet = AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_INDUSTRIAL_50): array
    {
        $caseSet = strtolower(trim($caseSet));
        if (! in_array($caseSet, self::EXECUTION_CASE_SETS, true)) {
            return [
                'status' => 'blocked',
                'case_set' => $caseSet,
                'blockers' => ['fixture_materialization_not_supported_for:'.$caseSet],
            ];
        }

        $written = [];
        foreach ($this->corpus->casesForCaseSet($caseSet) as $case) {
            $written = array_merge($written, $this->materializeCaseFixture($case));
        }

        return [
            'status' => 'ok',
            'case_set' => $caseSet,
            'materialized_files' => array_values(array_unique($written)),
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $cases
     * @return array<string,mixed>
     */
    public function readinessForCases(string $caseSet, array $cases, ?int $requiredCasesOverride = null): array
    {
        $caseSet = strtolower(trim($caseSet));
        $missingFixtures = [];
        $emptySeedCases = [];
        $missingTests = [];
        $missingExpectedChangedFiles = [];
        $oracleViolations = [];
        $evidenceViolations = [];
        $invalidCases = [];

        foreach ($cases as $case) {
            $caseId = $this->caseId($case);
            $seedDir = trim((string) ($case['fixture_seed_path'] ?? data_get($case, 'setup_fixture.seed_dir', '')));
            if ($seedDir === '' || ! $this->safeStorageFixturePath($seedDir)) {
                $missingFixtures[] = $caseId;
                $invalidCases[] = $caseId.':fixture_seed_path_missing_or_unsafe';

                continue;
            }

            $seedRoot = base_path($seedDir);
            if (! is_dir($seedRoot)) {
                $missingFixtures[] = $caseId;
                $invalidCases[] = $caseId.':fixture_seed_dir_not_found';
            } elseif ($this->nonReadmeSeedFiles($seedRoot) === []) {
                $emptySeedCases[] = $caseId;
                $invalidCases[] = $caseId.':fixture_seed_empty';
            }

            $expectedChanged = AiStringListNormalizer::trimmedCastValues($case['expected_changed_files'] ?? []);
            if ($expectedChanged === []) {
                $missingExpectedChangedFiles[] = $caseId;
                $invalidCases[] = $caseId.':expected_changed_files_missing';
            }

            $testCommand = trim((string) ($case['test_command'] ?? $case['quick_test_command'] ?? $case['full_test_command'] ?? ''));
            $testFiles = array_values(array_filter($expectedChanged, static fn (string $path): bool => str_contains($path, '/tests/') || str_ends_with($path, 'Test.php')));
            if ($testCommand === '' || $testFiles === []) {
                $missingTests[] = $caseId;
                $invalidCases[] = $caseId.':test_command_or_test_file_missing';
            } elseif (is_dir($seedRoot ?? '')) {
                foreach ($testFiles as $testFile) {
                    if (! is_file(rtrim($seedRoot, '/').'/'.$testFile)) {
                        $missingTests[] = $caseId;
                        $invalidCases[] = $caseId.':seed_test_file_missing:'.$testFile;
                        break;
                    }
                }
            }

            $oracle = $case['oracle'] ?? null;
            $hidden = $case['hidden_oracle_metadata'] ?? null;
            if (! is_array($oracle) || $oracle === [] || ! is_array($hidden) || $hidden === []) {
                $oracleViolations[] = $caseId;
                $invalidCases[] = $caseId.':oracle_metadata_missing';
            }

            $evidence = AiStringListNormalizer::trimmedCastValues($case['evidence_requirements'] ?? $case['expected_evidence'] ?? []);
            foreach (['patch_diff', 'provider_receipt', 'test_log', 'scorecard_per_case', 'workspace_hashes'] as $required) {
                if (! in_array($required, $evidence, true)) {
                    $evidenceViolations[] = $caseId.':'.$required;
                    $invalidCases[] = $caseId.':evidence_requirement_missing:'.$required;
                }
            }
        }

        $total = count($cases);
        $requiredCases = $requiredCasesOverride
            ?? AtlasForgeRivalsProviderArenaCorpusService::INDUSTRIAL_CASE_SET_MIN_VALID_CASES[$caseSet]
            ?? 50;
        $invalidCases = array_values(array_unique($invalidCases));
        $executable = max(0, $total - count(array_unique(array_map(
            static fn (string $entry): string => explode(':', $entry, 2)[0],
            $invalidCases,
        ))));
        $blockers = [];
        if (! in_array($caseSet, self::EXECUTION_CASE_SETS, true)) {
            $blockers[] = 'not_industrial_execution_case_set:'.$caseSet;
        }
        if ($total < $requiredCases) {
            $blockers[] = 'industrial_execution_requires_min_cases:'.$caseSet.':'.$total.'/'.$requiredCases;
        }
        if ($executable < $requiredCases) {
            $blockers[] = 'industrial_execution_has_non_executable_cases:'.$caseSet.':'.$executable.'/'.$requiredCases;
        }
        foreach ($invalidCases as $invalid) {
            $blockers[] = 'industrial_case_not_executable:'.$invalid;
        }

        $ok = $blockers === [];

        return [
            'status' => $ok ? 'ok' : 'blocked',
            'schema_version' => self::SCHEMA_VERSION,
            'suite_id' => self::SUITE_ID,
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'canonical_phrase' => self::CANONICAL_PHRASE,
            'case_set' => $caseSet,
            'total_cases' => $total,
            'executable_cases' => $executable,
            'required_cases' => $requiredCases,
            'execution_case_sets' => self::EXECUTION_CASE_SETS,
            'missing_fixtures' => array_values(array_unique($missingFixtures)),
            'empty_seed_cases' => array_values(array_unique($emptySeedCases)),
            'missing_tests' => array_values(array_unique($missingTests)),
            'missing_expected_changed_files' => array_values(array_unique($missingExpectedChangedFiles)),
            'oracle_metadata_status' => [
                'status' => $oracleViolations === [] ? 'ok' : 'blocked',
                'violations' => array_values(array_unique($oracleViolations)),
            ],
            'evidence_requirements_status' => [
                'status' => $evidenceViolations === [] ? 'ok' : 'blocked',
                'violations' => array_values(array_unique($evidenceViolations)),
            ],
            'claim_status' => $this->claimStatus($ok, false),
            'local_fake_execution_ready' => $ok,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'external_rivals_certification_status' => 'blocked_requires_human_approval',
            'external_rivals_certification_unlocked' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'separated_from_external_rivals_certification' => true,
            'blockers' => array_values(array_unique($blockers)),
            'next_command' => $ok
                ? 'php artisan atlas:forge:rivals run-battery --preset='.$caseSet.' --mode=local_fake --json'
                : 'fix industrial execution fixture blockers, then re-run php artisan atlas:forge:rivals industrial-execution --case-set='.$caseSet.' --json',
            'note' => 'Industrial execution readiness is local-only. No provider invoked, no tokens spent, and no external claim unlocked.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function statisticalRepeatReadiness(): array
    {
        $cases = $this->corpus->casesForCaseSet(AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_STATISTICAL_REPEAT);

        return [
            'status' => 'blocked',
            'schema_version' => self::SCHEMA_VERSION,
            'suite_id' => self::SUITE_ID,
            'case_set' => AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_STATISTICAL_REPEAT,
            'total_cases' => count($cases),
            'executable_cases' => 0,
            'statistical_repeat' => [
                'required_repetitions_per_group' => 3,
                'completed_repetitions_per_group' => 0,
                'confidence_ready' => false,
                'confidence_status' => 'blocked_until_real_repetitions_complete',
                'blockers' => ['statistical_repetitions_missing'],
            ],
            'claim_status' => $this->claimStatus(false, true),
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'external_rivals_certification_status' => 'blocked_requires_human_approval',
            'external_rivals_certification_unlocked' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'separated_from_external_rivals_certification' => true,
            'blockers' => ['statistical_repetitions_missing'],
            'next_command' => 'complete real repeated executions before requesting statistical confidence',
            'note' => 'statistical-repeat readiness exists, but strong confidence stays blocked until real repetitions are complete.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function blockedReadiness(string $caseSet, array $blockers): array
    {
        return [
            'status' => 'blocked',
            'schema_version' => self::SCHEMA_VERSION,
            'suite_id' => self::SUITE_ID,
            'case_set' => $caseSet,
            'total_cases' => 0,
            'executable_cases' => 0,
            'missing_fixtures' => [],
            'empty_seed_cases' => [],
            'missing_tests' => [],
            'missing_expected_changed_files' => [],
            'oracle_metadata_status' => ['status' => 'blocked', 'violations' => []],
            'evidence_requirements_status' => ['status' => 'blocked', 'violations' => []],
            'claim_status' => $this->claimStatus(false, false),
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'external_rivals_certification_status' => 'blocked_requires_human_approval',
            'external_rivals_certification_unlocked' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'separated_from_external_rivals_certification' => true,
            'blockers' => array_values(array_unique($blockers)),
            'next_command' => 'use an executable industrial case set: '.implode('|', self::EXECUTION_CASE_SETS),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function claimStatus(bool $localFakeReady, bool $requiresStatisticalRepeat): array
    {
        return [
            'ready_for_local_fake_harness_proof' => $localFakeReady,
            'ready_for_strong_benchmark_claim' => false,
            'claim_level' => 'claim_blocked',
            'external_claim_allowed' => false,
            'external_rivals_certification_status' => 'blocked_requires_human_approval',
            'requires_real_execution' => true,
            'requires_evidence_pack_complete' => true,
            'requires_replay_green' => true,
            'requires_scorecard_per_case' => true,
            'requires_adjudicator_green' => true,
            'requires_matrix_report_green' => true,
            'requires_statistical_repeat' => $requiresStatisticalRepeat,
            'confidence_status' => $requiresStatisticalRepeat
                ? 'blocked_until_real_repetitions_complete'
                : 'blocked_until_real_execution_evidence_replay_matrix_complete',
            'local_fake_is_not_real_claim' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     * @return list<string>
     */
    private function materializeCaseFixture(array $case): array
    {
        $caseId = $this->caseId($case);
        $seedDir = trim((string) ($case['fixture_seed_path'] ?? ''));
        if ($caseId === '' || $seedDir === '' || ! $this->safeStorageFixturePath($seedDir)) {
            return [];
        }

        $seedRoot = base_path($seedDir);
        $expectedChanged = AiStringListNormalizer::trimmedCastValues($case['expected_changed_files'] ?? []);
        $sourceFile = $expectedChanged[0] ?? 'storage/forge-rivals-industrial/'.$caseId.'/src/'.$caseId.'.php';
        $testFile = $expectedChanged[1] ?? 'storage/forge-rivals-industrial/'.$caseId.'/tests/'.$caseId.'Test.php';
        $docFile = 'storage/forge-rivals-industrial/'.$caseId.'/docs/'.$caseId.'-runbook.md';
        $manifestFile = 'storage/forge-rivals-industrial/'.$caseId.'/fixture.json';

        $files = [
            $sourceFile => $this->sourceFixtureContents($case),
            $testFile => $this->testFixtureContents($case, $sourceFile, $manifestFile),
            $docFile => $this->docFixtureContents($case),
            $manifestFile => $this->jsonEncode([
                'schema_version' => self::SCHEMA_VERSION,
                'suite_id' => self::SUITE_ID,
                'case_id' => $caseId,
                'case_set' => (string) ($case['industrial_case_set'] ?? $case['case_set'] ?? AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_INDUSTRIAL_50),
                'category' => $case['category'] ?? null,
                'task_type' => $case['task_type'] ?? null,
                'difficulty_level' => $case['difficulty_level'] ?? null,
                'acceptance_criteria' => $case['acceptance_criteria'] ?? [],
                'evidence_requirements' => $case['evidence_requirements'] ?? [],
                'invalid_if' => $case['invalid_if'] ?? [],
                'expected_changed_files' => $expectedChanged,
                'oracle_hash' => data_get($case, 'hidden_oracle_metadata.oracle_hash'),
                'external_provider_call' => false,
                'provider_tokens_spent' => false,
            ]),
        ];

        $written = [];
        foreach ($files as $relative => $contents) {
            if (! $this->safeStorageFixturePath($relative)) {
                continue;
            }
            $path = $seedRoot.'/'.$relative;
            $dir = dirname($path);
            if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
                continue;
            }
            if (@file_put_contents($path, $contents) !== false) {
                $written[] = $path;
            }
        }

        return $written;
    }

    /**
     * @param  array<string,mixed>  $case
     */
    private function sourceFixtureContents(array $case): string
    {
        $caseId = $this->caseId($case);
        $class = $this->className($caseId).'Fixture';

        return <<<PHP
<?php

declare(strict_types=1);

final class {$class}
{
    public const CASE_ID = '{$caseId}';
    public const TASK_TYPE = '{$case['task_type']}';
    public const DIFFICULTY_LEVEL = '{$case['difficulty_level']}';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => '{$this->escapePhpString((string) ($case['objective'] ?? ''))}',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}
PHP;
    }

    /**
     * @param  array<string,mixed>  $case
     */
    private function testFixtureContents(array $case, string $sourceFile, string $manifestFile): string
    {
        $caseId = $this->caseId($case);

        return <<<PHP
<?php

declare(strict_types=1);

\$source = __DIR__.'/../src/{$caseId}.php';
\$manifest = dirname(__DIR__).'/fixture.json';

if (! is_file(\$source)) {
    fwrite(STDERR, "missing source fixture\\n");
    exit(1);
}

if (! is_file(\$manifest)) {
    fwrite(STDERR, "missing fixture manifest\\n");
    exit(1);
}

\$sourceText = (string) file_get_contents(\$source);
\$manifestPayload = json_decode((string) file_get_contents(\$manifest), true);

if (! str_contains(\$sourceText, '{$caseId}')) {
    fwrite(STDERR, "source fixture case id mismatch\\n");
    exit(1);
}

if (! is_array(\$manifestPayload) || (\$manifestPayload['case_id'] ?? null) !== '{$caseId}') {
    fwrite(STDERR, "manifest case id mismatch\\n");
    exit(1);
}

echo "industrial fixture {$caseId} ok\\n";
PHP;
    }

    /**
     * @param  array<string,mixed>  $case
     */
    private function docFixtureContents(array $case): string
    {
        $caseId = $this->caseId($case);
        $criteria = implode("\n", array_map(static fn (string $line): string => '- '.$line, AiStringListNormalizer::trimmedCastValues($case['acceptance_criteria'] ?? [])));
        $invalid = implode("\n", array_map(static fn (string $line): string => '- '.$line, AiStringListNormalizer::trimmedCastValues($case['invalid_if'] ?? [])));

        $contents = <<<MD
# {$caseId}

Industrial execution fixture for local_fake harness validation.

## Objective
{$case['objective']}

## Acceptance Criteria
{$criteria}

## Invalid If
{$invalid}

No provider is called by this fixture.
MD;

        return $contents.PHP_EOL;
    }

    private function className(string $caseId): string
    {
        $parts = preg_split('/[^A-Za-z0-9]+/', $caseId) ?: [];
        $name = implode('', array_map(static fn (string $part): string => ucfirst($part), array_filter($parts)));

        return $name !== '' ? $name : 'IndustrialCase';
    }

    private function caseId(array $case): string
    {
        return trim((string) ($case['case_id'] ?? $case['id'] ?? 'unknown_case'));
    }

    private function safeStorageFixturePath(string $path): bool
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, "\0")) {
            return false;
        }

        return str_starts_with($path, 'storage/forge-rivals-corpus/')
            || str_starts_with($path, 'storage/forge-rivals-industrial/');
    }

    /**
     * @return list<string>
     */
    private function nonReadmeSeedFiles(string $seedRoot): array
    {
        $files = [];
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($seedRoot, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                    continue;
                }
                $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen(rtrim($seedRoot, '/')))), '/');
                if (strtolower($relative) === 'readme.md') {
                    continue;
                }
                $files[] = $file->getPathname();
            }
        } catch (\Throwable) {
            return [];
        }

        sort($files);

        return array_values($files);
    }

    private function escapePhpString(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function jsonEncode(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }
}

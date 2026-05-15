<?php

declare(strict_types=1);

namespace App\Services\Ai\Kernel\Architecture;

use App\Console\Commands\AtlasForgeRivalsCommand;
use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsCorpusCasesActionService;
use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsCorpusFixtureRunnerService;
use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsCorpusPlannerService;
use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Atlas Forge Rivals · Provider Arena Corpus Certification (v1).
 *
 * Audit-level read-model that proves the canonical 12-case corpus, the
 * fixture runner, the planner and the `cases` action are wired end-to-end
 * and honour every safety rule of the Provider Arena. It NEVER unlocks
 * `external_rivals_certification`.
 *
 * 15 invariants:
 *   1.  corpus_has_min_twelve_cases
 *   2.  every_case_has_required_fields
 *   3.  every_case_has_quick_test_command
 *   4.  every_case_has_allowed_files_scope
 *   5.  every_case_has_invalid_if
 *   6.  case_sets_cover_all_six_presets
 *   7.  quick_preset_has_three_to_four_cases
 *   8.  release_preset_includes_all_cases
 *   9.  frontend_preset_only_frontend
 *   10. backend_preset_only_backend
 *   11. bugfix_preset_only_bugfix
 *   12. architecture_preset_includes_architecture_refactor_docs
 *   13. fixture_runner_uses_workspace_hygiene
 *   14. no_case_calls_external_provider
 *   15. no_case_unlocks_external_rivals
 *
 * Schema: atlas.forge_rivals_provider_arena_corpus_certification.v1
 * Doc:    docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-corpus-v1.md
 */
class AtlasForgeRivalsProviderArenaCorpusCertification
{
    public const SCHEMA_VERSION = 'atlas.forge_rivals_provider_arena_corpus_certification.v1';

    public const CERTIFICATION_KEY = 'atlas_forge_rivals_provider_arena_corpus_certification';

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_MISSING_ARTIFACTS = 'missing_artifacts';

    /** @var list<string> */
    public const REQUIRED_INVARIANTS = [
        'corpus_has_min_twelve_cases',
        'every_case_has_required_fields',
        'every_case_has_quick_test_command',
        'every_case_has_allowed_files_scope',
        'every_case_has_invalid_if',
        'case_sets_cover_all_six_presets',
        'quick_preset_has_three_to_four_cases',
        'release_preset_includes_all_cases',
        'frontend_preset_only_frontend',
        'backend_preset_only_backend',
        'bugfix_preset_only_bugfix',
        'architecture_preset_includes_architecture_refactor_docs',
        'fixture_runner_uses_workspace_hygiene',
        'no_case_calls_external_provider',
        'no_case_unlocks_external_rivals',
    ];

    public function __construct(
        private readonly ?AtlasForgeRivalsProviderArenaCorpusService $corpus = null,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function evaluate(array $options = []): array
    {
        $repoRoot = $this->resolveRepoRoot($options);
        $corpus = $this->corpus ?? new AtlasForgeRivalsProviderArenaCorpusService;

        $invariants = $this->invariants($corpus, $repoRoot);
        $artifacts = $this->artifacts($repoRoot);
        $missing = $this->collectMissingArtifacts($artifacts);

        $hasBlocked = false;
        foreach ($invariants as $row) {
            if (! (bool) ($row['ok'] ?? false)) {
                $hasBlocked = true;
                break;
            }
        }

        $status = match (true) {
            $missing !== [] => self::STATUS_MISSING_ARTIFACTS,
            $hasBlocked => self::STATUS_BLOCKED,
            default => self::STATUS_AVAILABLE,
        };

        $blockers = [];
        foreach ($invariants as $name => $row) {
            if (! (bool) ($row['ok'] ?? false)) {
                $blockers[] = (string) $name.'_blocked';
            }
        }
        foreach ($missing as $entry) {
            $blockers[] = $entry;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'certification_key' => self::CERTIFICATION_KEY,
            'status' => $status,
            'ok' => $status === self::STATUS_AVAILABLE,
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'evidence_command' => 'php artisan atlas:forge:rivals cases --json --strict',
            'invariants' => $invariants,
            'invariants_all_true' => $status === self::STATUS_AVAILABLE,
            'invariants_summary' => array_map(static fn (array $r): bool => (bool) ($r['ok'] ?? false), $invariants),
            'artifacts' => $artifacts,
            'missing_artifacts' => $missing,
            'blockers' => array_values(array_unique($blockers)),
            'corpus_count' => count($corpus->cases()),
            'case_sets' => AtlasForgeRivalsProviderArenaCorpusService::CASE_SETS,
            'task_categories' => AtlasForgeRivalsProviderArenaCorpusService::TASK_CATEGORIES,
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'separated_from' => 'external_rivals_certification',
            'note' => 'Provider Arena Corpus v1 NEVER unblocks external_rivals_certification by itself.',
            'related_docs' => [
                'docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-corpus-v1.md',
                'docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md',
            ],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function invariants(AtlasForgeRivalsProviderArenaCorpusService $corpus, string $repoRoot): array
    {
        $out = [];
        foreach (self::REQUIRED_INVARIANTS as $name) {
            $out[$name] = $this->evaluateInvariant($name, $corpus, $repoRoot);
        }

        return $out;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function artifacts(string $repoRoot): array
    {
        return [
            'corpus_service' => [
                'class' => AtlasForgeRivalsProviderArenaCorpusService::class,
                'present' => class_exists(AtlasForgeRivalsProviderArenaCorpusService::class),
            ],
            'fixture_runner' => [
                'class' => AtlasForgeRivalsCorpusFixtureRunnerService::class,
                'present' => class_exists(AtlasForgeRivalsCorpusFixtureRunnerService::class),
            ],
            'planner' => [
                'class' => AtlasForgeRivalsCorpusPlannerService::class,
                'present' => class_exists(AtlasForgeRivalsCorpusPlannerService::class),
            ],
            'cases_action' => [
                'class' => AtlasForgeRivalsCorpusCasesActionService::class,
                'present' => class_exists(AtlasForgeRivalsCorpusCasesActionService::class),
            ],
            'canonical_command' => [
                'class' => AtlasForgeRivalsCommand::class,
                'present' => class_exists(AtlasForgeRivalsCommand::class),
            ],
            'canonical_doc' => [
                'path' => 'docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-corpus-v1.md',
                'present' => is_file($repoRoot.'/docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-corpus-v1.md'),
            ],
            'seed_root' => [
                'path' => 'storage/forge-rivals-corpus',
                'present' => is_dir($repoRoot.'/storage/forge-rivals-corpus'),
            ],
        ];
    }

    /**
     * @return array{ok:bool,status:string,description:string,check:string,evidence:list<string>}
     */
    private function evaluateInvariant(
        string $name,
        AtlasForgeRivalsProviderArenaCorpusService $corpus,
        string $repoRoot,
    ): array {
        $cases = $corpus->cases();
        $fixtureSrc = $this->readFile($repoRoot.'/app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsCorpusFixtureRunnerService.php');

        switch ($name) {
            case 'corpus_has_min_twelve_cases':
                return [
                    'ok' => count($cases) >= 12,
                    'status' => 'slice_corpus_v1',
                    'description' => 'Corpus declara pelo menos 12 casos canon.',
                    'check' => 'count(corpus->cases()) >= 12',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php',
                    ],
                ];

            case 'every_case_has_required_fields':
                $allOk = true;
                foreach ($cases as $c) {
                    if ($corpus->validateManifest($c) !== []) {
                        $allOk = false;
                        break;
                    }
                }

                return [
                    'ok' => $allOk,
                    'status' => 'slice_corpus_v1',
                    'description' => 'Cada caso passa no validador de 16 campos canônicos.',
                    'check' => 'validateManifest(case) === [] para todos os casos',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php',
                    ],
                ];

            case 'every_case_has_quick_test_command':
                $allOk = true;
                foreach ($cases as $c) {
                    if (trim((string) ($c['quick_test_command'] ?? '')) === '') {
                        $allOk = false;
                        break;
                    }
                }

                return [
                    'ok' => $allOk,
                    'status' => 'slice_corpus_v1',
                    'description' => 'Cada caso declara quick_test_command não vazio.',
                    'check' => 'trim(case.quick_test_command) !== "" para todos os casos',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php',
                    ],
                ];

            case 'every_case_has_allowed_files_scope':
                $allOk = true;
                foreach ($cases as $c) {
                    $scope = $c['allowed_files_scope'] ?? null;
                    if (! is_array($scope) || $scope === []) {
                        $allOk = false;
                        break;
                    }
                }

                return [
                    'ok' => $allOk,
                    'status' => 'slice_corpus_v1',
                    'description' => 'Cada caso declara allowed_files_scope não vazio.',
                    'check' => 'count(case.allowed_files_scope) > 0 para todos os casos',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php',
                    ],
                ];

            case 'every_case_has_invalid_if':
                $allOk = true;
                foreach ($cases as $c) {
                    $iv = $c['invalid_if'] ?? null;
                    if (! is_array($iv) || $iv === []) {
                        $allOk = false;
                        break;
                    }
                }

                return [
                    'ok' => $allOk,
                    'status' => 'slice_corpus_v1',
                    'description' => 'Cada caso declara invalid_if (condições de invalidação) não vazio.',
                    'check' => 'count(case.invalid_if) > 0 para todos os casos',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php',
                    ],
                ];

            case 'case_sets_cover_all_six_presets':
                $expected = ['quick', 'release', 'frontend', 'backend', 'bugfix', 'architecture'];
                $actual = AtlasForgeRivalsProviderArenaCorpusService::CASE_SETS;
                $missing = array_diff($expected, $actual);

                return [
                    'ok' => $missing === [],
                    'status' => 'slice_corpus_v1',
                    'description' => 'CASE_SETS expõe exatamente os 6 presets canon.',
                    'check' => 'sort(CASE_SETS) === [architecture, backend, bugfix, frontend, quick, release]',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php',
                    ],
                ];

            case 'quick_preset_has_three_to_four_cases':
                $quickCount = count($corpus->casesForCaseSet('quick'));

                return [
                    'ok' => $quickCount >= 3 && $quickCount <= 4,
                    'status' => 'slice_corpus_v1',
                    'description' => 'Preset quick contém 3 ou 4 casos curtos.',
                    'check' => '3 <= count(quick) <= 4',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php',
                    ],
                ];

            case 'release_preset_includes_all_cases':
                $release = count($corpus->casesForCaseSet('release'));

                return [
                    'ok' => $release === count($cases) && $release >= 12,
                    'status' => 'slice_corpus_v1',
                    'description' => 'Preset release contém todos os casos do corpus.',
                    'check' => 'count(release) === count(corpus->cases())',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php',
                    ],
                ];

            case 'frontend_preset_only_frontend':
                $frontend = $corpus->casesForCaseSet('frontend');
                $ok = $frontend !== [];
                foreach ($frontend as $c) {
                    if (($c['task_category'] ?? '') !== 'frontend') {
                        $ok = false;
                        break;
                    }
                }

                return [
                    'ok' => $ok,
                    'status' => 'slice_corpus_v1',
                    'description' => 'Preset frontend só inclui casos com task_category=frontend.',
                    'check' => 'todos os elementos têm task_category=frontend',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php',
                    ],
                ];

            case 'backend_preset_only_backend':
                $backend = $corpus->casesForCaseSet('backend');
                $ok = $backend !== [];
                foreach ($backend as $c) {
                    if (($c['task_category'] ?? '') !== 'backend') {
                        $ok = false;
                        break;
                    }
                }

                return [
                    'ok' => $ok,
                    'status' => 'slice_corpus_v1',
                    'description' => 'Preset backend só inclui casos com task_category=backend.',
                    'check' => 'todos os elementos têm task_category=backend',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php',
                    ],
                ];

            case 'bugfix_preset_only_bugfix':
                $bugfix = $corpus->casesForCaseSet('bugfix');
                $ok = $bugfix !== [];
                foreach ($bugfix as $c) {
                    if (($c['task_category'] ?? '') !== 'bugfix') {
                        $ok = false;
                        break;
                    }
                }

                return [
                    'ok' => $ok,
                    'status' => 'slice_corpus_v1',
                    'description' => 'Preset bugfix só inclui casos com task_category=bugfix.',
                    'check' => 'todos os elementos têm task_category=bugfix',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php',
                    ],
                ];

            case 'architecture_preset_includes_architecture_refactor_docs':
                $arch = $corpus->casesForCaseSet('architecture');
                $cats = [];
                foreach ($arch as $c) {
                    $cats[(string) ($c['task_category'] ?? '')] = true;
                }
                $expected = ['architecture', 'refactor', 'docs'];
                $hasAll = true;
                foreach ($expected as $e) {
                    if (! isset($cats[$e])) {
                        $hasAll = false;
                        break;
                    }
                }
                $onlyExpected = true;
                foreach (array_keys($cats) as $c) {
                    if (! in_array($c, $expected, true)) {
                        $onlyExpected = false;
                        break;
                    }
                }

                return [
                    'ok' => $hasAll && $onlyExpected,
                    'status' => 'slice_corpus_v1',
                    'description' => 'Preset architecture inclui architecture, refactor e docs (e só esses).',
                    'check' => 'task_categories union == {architecture, refactor, docs}',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php',
                    ],
                ];

            case 'fixture_runner_uses_workspace_hygiene':
                return [
                    'ok' => str_contains($fixtureSrc, 'WorkspaceHygieneService')
                        && str_contains($fixtureSrc, 'trackedPythonBytecode')
                        && str_contains($fixtureSrc, 'blocked_tracked_python_bytecode'),
                    'status' => 'slice_corpus_v1',
                    'description' => 'Fixture runner delega à canonical WorkspaceHygieneService e refusa preparar workspace quando .pyc/__pycache__ aparece tracked.',
                    'check' => 'AtlasForgeRivalsCorpusFixtureRunnerService chama WorkspaceHygieneService::trackedPythonBytecode e emite blocker',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsCorpusFixtureRunnerService.php',
                        'app/Services/Ai/Programming/WorkspaceHygieneService.php',
                    ],
                ];

            case 'no_case_calls_external_provider':
                $bad = [];
                foreach ($cases as $c) {
                    foreach (['quick_test_command', 'full_test_command'] as $field) {
                        $cmd = strtolower((string) ($c[$field] ?? ''));
                        foreach (['claude ', 'codex ', 'gemini ', 'curl ', 'wget ', 'http://', 'https://'] as $needle) {
                            if (str_contains($cmd, $needle)) {
                                $bad[] = (string) $c['case_id'].':'.$field;
                            }
                        }
                    }
                }

                return [
                    'ok' => $bad === [],
                    'status' => 'slice_corpus_v1',
                    'description' => 'Nenhum case declara comando que dispare provider externo ou rede.',
                    'check' => 'quick_test_command e full_test_command sem claude/codex/gemini/curl/wget/http',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php',
                    ],
                ];

            case 'no_case_unlocks_external_rivals':
                $bad = [];
                foreach ($cases as $c) {
                    $blob = strtolower(json_encode($c, JSON_UNESCAPED_SLASHES) ?: '');
                    if (str_contains($blob, 'unlocks_external_rivals_certification')
                        || str_contains($blob, 'external_rivals_unlock')
                    ) {
                        $bad[] = (string) $c['case_id'];
                    }
                }

                return [
                    'ok' => $bad === [],
                    'status' => 'slice_corpus_v1',
                    'description' => 'Nenhum case faz referência a desbloquear external_rivals_certification.',
                    'check' => 'manifests não contêm unlocks_external_rivals_certification',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php',
                    ],
                ];
        }

        return [
            'ok' => false,
            'status' => 'unknown_corpus_invariant',
            'description' => 'Unknown corpus invariant: '.$name,
            'check' => '',
            'evidence' => [],
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $artifacts
     * @return list<string>
     */
    private function collectMissingArtifacts(array $artifacts): array
    {
        $missing = [];
        foreach ($artifacts as $key => $artifact) {
            if (! (bool) ($artifact['present'] ?? false)) {
                $missing[] = (string) $key.'_missing';
            }
        }

        return $missing;
    }

    private function readFile(string $path): string
    {
        if (! is_file($path)) {
            return '';
        }

        return (string) file_get_contents($path);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function resolveRepoRoot(array $options): string
    {
        $explicit = $options['workspace'] ?? null;
        if (is_string($explicit) && trim($explicit) !== '' && is_dir(trim($explicit))) {
            return rtrim(trim($explicit), '/');
        }

        if (function_exists('base_path')) {
            return rtrim(base_path(), '/');
        }

        return rtrim(dirname(__DIR__, 4), '/');
    }
}

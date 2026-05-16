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
 * Atlas Forge Rivals · Provider Arena Corpus Release v1 Certification.
 *
 * Audit-level read-model que prova que o Corpus Release v1 está canon:
 * 12 casos reais, 8 categorias canon cobertas, schema de 22 campos
 * validado, case sets resolvíveis, fixture seeds presentes, evidências e
 * replay declarados, sem chamada de provider, sem destravar
 * `external_rivals_certification`.
 *
 * 20 invariants (1:1 com a spec do operador):
 *
 *   1. release_has_exactly_twelve_cases
 *   2. all_case_ids_unique
 *   3. every_case_has_valid_primary_category
 *   4. every_case_has_objective_business_rule_acceptance
 *   5. every_case_has_allowed_and_forbidden_files_scope
 *   6. every_case_has_quick_and_full_test_command
 *   7. every_case_quality_weights_sum_to_one
 *   8. every_case_invalid_if_has_hard_gates
 *   9. every_case_fixture_seed_path_exists_on_disk
 *  10. no_case_admits_synthetic_score
 *  11. no_case_unlocks_external_rivals_certification
 *  12. release_covers_all_eight_canonical_categories
 *  13. frontend_case_set_returns_only_frontend_cases
 *  14. backend_case_set_returns_only_backend_cases
 *  15. architecture_case_set_returns_only_architecture_or_refactor
 *  16. quick_case_set_has_exactly_three_cases_and_no_global_claim
 *  17. individual_case_id_resolves_to_one_manifest
 *  18. aggregate_manifest_is_deterministic_and_hashable
 *  19. no_case_command_invokes_external_provider
 *  20. no_case_scope_leaks_to_voice_or_cartografia
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
        'release_matrix_has_exactly_forty_cases',
        'release_matrix_fills_every_cell_8x5',
        'all_case_ids_unique',
        'every_case_has_valid_primary_category',
        'every_case_has_objective_business_rule_acceptance',
        'every_case_has_allowed_and_forbidden_files_scope',
        'every_case_has_quick_and_full_test_command',
        'every_case_quality_weights_sum_to_one',
        'every_case_invalid_if_has_hard_gates',
        'every_case_fixture_seed_path_exists_on_disk',
        'no_case_admits_synthetic_score',
        'no_case_unlocks_external_rivals_certification',
        'release_covers_all_eight_canonical_categories',
        'frontend_case_set_returns_only_frontend_cases',
        'backend_case_set_returns_only_backend_cases',
        'architecture_case_set_returns_only_architecture_or_refactor',
        'quick_case_set_has_exactly_three_cases_and_no_global_claim',
        'individual_case_id_resolves_to_one_manifest',
        'aggregate_manifest_is_deterministic_and_hashable',
        'no_case_command_invokes_external_provider',
        'no_case_scope_leaks_to_voice_or_cartografia',
        'every_case_has_canonical_difficulty_block_l1_to_l5',
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
            'release_version' => AtlasForgeRivalsProviderArenaCorpusService::RELEASE_VERSION,
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'evidence_command' => 'php artisan atlas:forge:rivals cases --case-set=release --json --strict',
            'invariants' => $invariants,
            'invariants_all_true' => $status === self::STATUS_AVAILABLE,
            'invariants_summary' => array_map(static fn (array $r): bool => (bool) ($r['ok'] ?? false), $invariants),
            'invariants_count' => count(self::REQUIRED_INVARIANTS),
            'artifacts' => $artifacts,
            'missing_artifacts' => $missing,
            'blockers' => array_values(array_unique($blockers)),
            'corpus_count' => count($corpus->cases()),
            'corpus_content_hash' => $corpus->contentHash(),
            'case_sets' => AtlasForgeRivalsProviderArenaCorpusService::CASE_SETS,
            'task_categories' => AtlasForgeRivalsProviderArenaCorpusService::TASK_CATEGORIES,
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'separated_from' => 'external_rivals_certification',
            'note' => 'Provider Arena Corpus Release v1 NEVER unblocks external_rivals_certification by itself.',
            'related_docs' => [
                'docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-corpus-v1.md',
                'docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md',
                'docs/engineering-knowledge-base/atlas-forge-rivals-benchmark-strategy-v1.md',
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
            case 'release_matrix_has_exactly_forty_cases':
                return [
                    'ok' => count($cases) === 40,
                    'status' => 'release_matrix_v1',
                    'description' => 'Release Matrix v1 declara exatamente 40 casos (8 categorias × 5 níveis).',
                    'check' => 'count(corpus->cases()) === 40',
                    'evidence' => ['app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php'],
                ];

            case 'release_matrix_fills_every_cell_8x5':
                $matrix = [];
                foreach ($cases as $c) {
                    $cat = (string) ($c['category'] ?? '');
                    $lvl = (string) ($c['difficulty_level'] ?? '');
                    $matrix[$cat][$lvl] = ($matrix[$cat][$lvl] ?? 0) + 1;
                }
                $ok = true;
                $missing = [];
                foreach (AtlasForgeRivalsProviderArenaCorpusService::TASK_CATEGORIES as $cat) {
                    foreach (\App\Services\Ai\Programming\ForgeRivals\Schema\AtlasForgeRivalsSchemaContractService::DIFFICULTY_LEVELS as $lvl) {
                        $count = $matrix[$cat][$lvl] ?? 0;
                        if ($count !== 1) {
                            $ok = false;
                            $missing[] = $cat.'/'.$lvl.'='.$count;
                        }
                    }
                }

                return [
                    'ok' => $ok,
                    'status' => 'release_matrix_v1',
                    'description' => 'Cada célula da matriz 8 categorias × 5 níveis tem exatamente 1 caso.',
                    'check' => 'forall(cat,lvl): count(case where category=cat and difficulty_level=lvl) == 1',
                    'evidence' => $ok
                        ? ['app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php']
                        : array_map(static fn (string $m): string => 'matrix_cell_violation:'.$m, $missing),
                ];

            case 'all_case_ids_unique':
                $ids = $corpus->caseIds();

                return [
                    'ok' => count($ids) === count(array_unique($ids)),
                    'status' => 'release_v1',
                    'description' => 'Cada case_id é único no corpus.',
                    'check' => 'count(case_ids) === count(unique(case_ids))',
                    'evidence' => ['app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php'],
                ];

            case 'every_case_has_valid_primary_category':
                $ok = true;
                foreach ($cases as $c) {
                    if (! in_array((string) ($c['category'] ?? ''), AtlasForgeRivalsProviderArenaCorpusService::TASK_CATEGORIES, true)) {
                        $ok = false;
                        break;
                    }
                }

                return [
                    'ok' => $ok,
                    'status' => 'release_v1',
                    'description' => 'Cada caso declara uma primary category dentro das 8 canônicas.',
                    'check' => 'case.category ∈ TASK_CATEGORIES',
                    'evidence' => ['app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php'],
                ];

            case 'every_case_has_objective_business_rule_acceptance':
                $ok = true;
                foreach ($cases as $c) {
                    if (trim((string) ($c['objective'] ?? '')) === '') {
                        $ok = false;
                        break;
                    }
                    if (trim((string) ($c['business_rule'] ?? '')) === '') {
                        $ok = false;
                        break;
                    }
                    if (! is_array($c['acceptance_criteria'] ?? null) || $c['acceptance_criteria'] === []) {
                        $ok = false;
                        break;
                    }
                }

                return [
                    'ok' => $ok,
                    'status' => 'release_v1',
                    'description' => 'Cada caso preenche objective, business_rule e acceptance_criteria.',
                    'check' => 'todos os 3 campos populados em todos os casos',
                    'evidence' => ['app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php'],
                ];

            case 'every_case_has_allowed_and_forbidden_files_scope':
                $ok = true;
                foreach ($cases as $c) {
                    $allowed = $c['allowed_files_scope'] ?? null;
                    $forbidden = $c['forbidden_files_scope'] ?? null;
                    if (! is_array($allowed) || $allowed === []) {
                        $ok = false;
                        break;
                    }
                    if (! is_array($forbidden) || $forbidden === []) {
                        $ok = false;
                        break;
                    }
                }

                return [
                    'ok' => $ok,
                    'status' => 'release_v1',
                    'description' => 'Cada caso declara allowed_files_scope e forbidden_files_scope não vazios.',
                    'check' => 'count(allowed) > 0 && count(forbidden) > 0',
                    'evidence' => ['app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php'],
                ];

            case 'every_case_has_quick_and_full_test_command':
                $ok = true;
                foreach ($cases as $c) {
                    if (trim((string) ($c['quick_test_command'] ?? '')) === '') {
                        $ok = false;
                        break;
                    }
                    if (trim((string) ($c['full_test_command'] ?? '')) === '') {
                        $ok = false;
                        break;
                    }
                }

                return [
                    'ok' => $ok,
                    'status' => 'release_v1',
                    'description' => 'Cada caso declara quick_test_command e full_test_command.',
                    'check' => 'ambos os campos populados em todos os casos',
                    'evidence' => ['app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php'],
                ];

            case 'every_case_quality_weights_sum_to_one':
                $ok = true;
                foreach ($cases as $c) {
                    $weights = $c['quality_weights'] ?? null;
                    if (! is_array($weights) || ! isset($weights['weights']) || ! is_array($weights['weights'])) {
                        $ok = false;
                        break;
                    }
                    $sum = 0.0;
                    foreach ($weights['weights'] as $w) {
                        $sum += (float) $w;
                    }
                    if (abs($sum - 1.0) > 0.01) {
                        $ok = false;
                        break;
                    }
                }

                return [
                    'ok' => $ok,
                    'status' => 'release_v1',
                    'description' => 'quality_weights.weights somam 1.0 em todos os casos (tolerância 0.01).',
                    'check' => 'sum(weights) ≈ 1.0',
                    'evidence' => ['app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php'],
                ];

            case 'every_case_invalid_if_has_hard_gates':
                $ok = true;
                foreach ($cases as $c) {
                    $iv = $c['invalid_if'] ?? null;
                    if (! is_array($iv) || $iv === []) {
                        $ok = false;
                        break;
                    }
                    foreach (AtlasForgeRivalsProviderArenaCorpusService::REQUIRED_INVALID_IF_HARD_GATES as $gate) {
                        if (! in_array($gate, $iv, true)) {
                            $ok = false;
                            break 2;
                        }
                    }
                }

                return [
                    'ok' => $ok,
                    'status' => 'release_v1',
                    'description' => 'invalid_if contém os hard gates canon (synthetic_score, touched_forbidden_files, external_rivals_unlock).',
                    'check' => 'all hard gates ⊆ case.invalid_if',
                    'evidence' => ['app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php'],
                ];

            case 'every_case_fixture_seed_path_exists_on_disk':
                $ok = true;
                $missing = [];
                foreach ($cases as $c) {
                    $path = (string) ($c['fixture_seed_path'] ?? '');
                    if ($path === '') {
                        $ok = false;
                        $missing[] = (string) ($c['case_id'] ?? '').':empty';

                        continue;
                    }
                    $abs = rtrim($repoRoot, '/').'/'.ltrim($path, '/');
                    if (! is_dir($abs)) {
                        $ok = false;
                        $missing[] = (string) ($c['case_id'] ?? '').':'.$path;
                    }
                }

                return [
                    'ok' => $ok,
                    'status' => 'release_v1',
                    'description' => 'fixture_seed_path de cada caso é um diretório existente no repo.',
                    'check' => 'is_dir(repoRoot/fixture_seed_path) === true',
                    'evidence' => $missing === []
                        ? ['storage/forge-rivals-corpus']
                        : array_map(static fn (string $m): string => 'storage/forge-rivals-corpus/missing:'.$m, $missing),
                ];

            case 'no_case_admits_synthetic_score':
                $ok = true;
                foreach ($cases as $c) {
                    $blob = strtolower(json_encode($c, JSON_UNESCAPED_SLASHES) ?: '');
                    if (str_contains($blob, '"synthetic_score":true') || str_contains($blob, 'synthetic_score_admitted_is_ok')) {
                        $ok = false;
                        break;
                    }
                    $iv = (array) ($c['invalid_if'] ?? []);
                    if (! in_array('synthetic_score_admitted', $iv, true)) {
                        $ok = false;
                        break;
                    }
                }

                return [
                    'ok' => $ok,
                    'status' => 'release_v1',
                    'description' => 'Nenhum caso admite synthetic_score; todos listam synthetic_score_admitted em invalid_if.',
                    'check' => 'synthetic_score_admitted ∈ case.invalid_if',
                    'evidence' => ['app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php'],
                ];

            case 'no_case_unlocks_external_rivals_certification':
                $ok = true;
                foreach ($cases as $c) {
                    $blob = strtolower(json_encode($c, JSON_UNESCAPED_SLASHES) ?: '');
                    if (str_contains($blob, 'unlocks_external_rivals_certification')
                        || str_contains($blob, '"external_rivals_unlock":true')
                    ) {
                        $ok = false;
                        break;
                    }
                    $iv = (array) ($c['invalid_if'] ?? []);
                    if (! in_array('external_rivals_unlock_attempted', $iv, true)) {
                        $ok = false;
                        break;
                    }
                    if ((string) ($c['claim_level'] ?? '') !== AtlasForgeRivalsProviderArenaCorpusService::CLAIM_LEVEL_CASE_RESULT_ONLY) {
                        $ok = false;
                        break;
                    }
                }

                return [
                    'ok' => $ok,
                    'status' => 'release_v1',
                    'description' => 'Nenhum caso destrava external_rivals_certification; claim_level sempre case_result_only.',
                    'check' => 'claim_level === "case_result_only" && external_rivals_unlock_attempted ∈ invalid_if',
                    'evidence' => ['app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php'],
                ];

            case 'release_covers_all_eight_canonical_categories':
                $coverage = [];
                foreach ($cases as $c) {
                    $coverage[(string) ($c['category'] ?? '')] = true;
                }
                $allCovered = true;
                foreach (AtlasForgeRivalsProviderArenaCorpusService::TASK_CATEGORIES as $cat) {
                    if (! isset($coverage[$cat])) {
                        $allCovered = false;
                        break;
                    }
                }

                return [
                    'ok' => $allCovered,
                    'status' => 'release_v1',
                    'description' => 'Os 12 casos cobrem todas as 8 categorias canon como primary category.',
                    'check' => 'forall(cat ∈ TASK_CATEGORIES): ∃ case with case.category == cat',
                    'evidence' => ['app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php'],
                ];

            case 'frontend_case_set_returns_only_frontend_cases':
                $set = $corpus->casesForCaseSet('frontend');
                $ok = $set !== [];
                foreach ($set as $c) {
                    if ((string) ($c['category'] ?? '') !== 'frontend_ui') {
                        $ok = false;
                        break;
                    }
                }

                return [
                    'ok' => $ok,
                    'status' => 'release_v1',
                    'description' => 'Case set frontend só inclui casos com category=frontend_ui.',
                    'check' => 'forall c ∈ casesForCaseSet(frontend): c.category == frontend_ui',
                    'evidence' => ['app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php'],
                ];

            case 'backend_case_set_returns_only_backend_cases':
                $set = $corpus->casesForCaseSet('backend');
                $ok = $set !== [];
                foreach ($set as $c) {
                    if (! in_array(
                        (string) ($c['category'] ?? ''),
                        ['backend_logic', 'integration_performance'],
                        true,
                    )) {
                        $ok = false;
                        break;
                    }
                }

                return [
                    'ok' => $ok,
                    'status' => 'release_matrix_v1',
                    'description' => 'Case set backend só inclui casos com category=backend_logic OU integration_performance.',
                    'check' => 'forall c ∈ casesForCaseSet(backend): c.category ∈ {backend_logic, integration_performance}',
                    'evidence' => ['app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php'],
                ];

            case 'architecture_case_set_returns_only_architecture_or_refactor':
                $set = $corpus->casesForCaseSet('architecture');
                $ok = $set !== [];
                foreach ($set as $c) {
                    if (! in_array((string) ($c['category'] ?? ''), ['architecture', 'refactor'], true)) {
                        $ok = false;
                        break;
                    }
                }

                return [
                    'ok' => $ok,
                    'status' => 'release_v1',
                    'description' => 'Case set architecture inclui apenas categorias architecture e refactor.',
                    'check' => 'forall c ∈ casesForCaseSet(architecture): c.category ∈ {architecture, refactor}',
                    'evidence' => ['app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php'],
                ];

            case 'quick_case_set_has_exactly_three_cases_and_no_global_claim':
                $quick = $corpus->casesForCaseSet('quick');
                $ok = count($quick) === 3;
                foreach ($quick as $c) {
                    if ((string) ($c['claim_level'] ?? '') !== AtlasForgeRivalsProviderArenaCorpusService::CLAIM_LEVEL_CASE_RESULT_ONLY) {
                        $ok = false;
                        break;
                    }
                }

                return [
                    'ok' => $ok,
                    'status' => 'release_v1',
                    'description' => 'Case set quick tem exatamente 3 casos e nenhum declara claim global.',
                    'check' => 'count(quick) == 3 && forall c: c.claim_level == case_result_only',
                    'evidence' => ['app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php'],
                ];

            case 'individual_case_id_resolves_to_one_manifest':
                $ok = true;
                foreach ($corpus->caseIds() as $caseId) {
                    try {
                        $resolved = $corpus->case($caseId);
                        if ((string) ($resolved['case_id'] ?? '') !== $caseId) {
                            $ok = false;
                            break;
                        }
                    } catch (\InvalidArgumentException) {
                        $ok = false;
                        break;
                    }
                }

                return [
                    'ok' => $ok,
                    'status' => 'release_v1',
                    'description' => 'corpus->case($id) resolve cada case_id para exatamente um manifest.',
                    'check' => 'forall id ∈ caseIds(): corpus->case(id).case_id == id',
                    'evidence' => ['app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php'],
                ];

            case 'aggregate_manifest_is_deterministic_and_hashable':
                $hashA = $corpus->contentHash();
                $hashB = $corpus->contentHash();

                return [
                    'ok' => $hashA === $hashB && preg_match('/^[a-f0-9]{64}$/', $hashA) === 1,
                    'status' => 'release_v1',
                    'description' => 'Aggregate manifest hash é determinístico (mesmo hash em duas chamadas seguidas).',
                    'check' => 'corpus->contentHash() == corpus->contentHash() (sha256, 64 hex chars)',
                    'evidence' => ['app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php'],
                ];

            case 'no_case_command_invokes_external_provider':
                $ok = true;
                foreach ($cases as $c) {
                    foreach (['quick_test_command', 'full_test_command'] as $field) {
                        $cmd = strtolower((string) ($c[$field] ?? ''));
                        foreach (['claude ', 'codex ', 'gemini ', 'curl ', 'wget ', 'http://', 'https://'] as $needle) {
                            if (str_contains($cmd, $needle)) {
                                $ok = false;
                                break 3;
                            }
                        }
                    }
                }

                return [
                    'ok' => $ok,
                    'status' => 'release_v1',
                    'description' => 'Nenhum quick/full_test_command dispara provider externo.',
                    'check' => 'commands sem claude/codex/gemini/curl/wget/http(s)',
                    'evidence' => ['app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php'],
                ];

            case 'every_case_has_canonical_difficulty_block_l1_to_l5':
                $contract = new \App\Services\Ai\Programming\ForgeRivals\Schema\AtlasForgeRivalsSchemaContractService;
                $allOk = true;
                $coverage = [];
                $firstBadCase = '';
                $firstBadReason = '';
                foreach ($cases as $c) {
                    $diffViolations = $contract->validateDifficultyBlock($c, 'cert.difficulty_block', flat: true);
                    if ($diffViolations !== []) {
                        $allOk = false;
                        $firstBadCase = (string) ($c['case_id'] ?? 'unknown');
                        $firstBadReason = $diffViolations[0];
                        break;
                    }
                    $coverage[(string) $c['difficulty_level']] = true;
                }
                // Release v1 deve usar L1, L2, L3, L4 e L5 todos.
                foreach (\App\Services\Ai\Programming\ForgeRivals\Schema\AtlasForgeRivalsSchemaContractService::DIFFICULTY_LEVELS as $level) {
                    if (! isset($coverage[$level])) {
                        $allOk = false;
                        $firstBadCase = 'corpus-wide';
                        $firstBadReason = 'no_case_uses_difficulty_level:'.$level;
                        break;
                    }
                }

                return [
                    'ok' => $allOk,
                    'status' => 'release_v1',
                    'description' => 'Cada caso declara o bloco canon de dificuldade L1..L5 (difficulty_level, difficulty_score, difficulty_reason, planning_weight, execution_weight, ambiguity_level, risk_level) e o release cobre todos os 5 níveis.',
                    'check' => 'SchemaContract.validateDifficultyBlock ok ∀ case && {L1..L5} ⊆ release.difficulty_level',
                    'evidence' => $allOk
                        ? ['app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php']
                        : ['failed_case:'.$firstBadCase.':'.$firstBadReason],
                ];

            case 'no_case_scope_leaks_to_voice_or_cartografia':
                $ok = true;
                foreach ($cases as $c) {
                    foreach ((array) ($c['allowed_files_scope'] ?? []) as $glob) {
                        $g = (string) $glob;
                        if (str_starts_with($g, 'atlas-desktop/src/voice/')
                            || str_contains($g, '/voice/')
                            || str_starts_with($g, 'atlas-cartografia/')
                            || str_contains($g, '/cartografia/')
                        ) {
                            $ok = false;
                            break 2;
                        }
                    }
                }
                // Cert também precisa do hygiene-check em FixtureRunner — duplicado de defesa em profundidade.
                if ($ok) {
                    $ok = str_contains($fixtureSrc, 'WorkspaceHygieneService');
                }

                return [
                    'ok' => $ok,
                    'status' => 'release_v1',
                    'description' => 'Nenhum allowed_files_scope toca Voice ou Cartografia; fixture runner continua sob WorkspaceHygieneService.',
                    'check' => 'forall glob ∈ allowed_files_scope: ¬matches(voice|cartografia)',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php',
                        'app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsCorpusFixtureRunnerService.php',
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

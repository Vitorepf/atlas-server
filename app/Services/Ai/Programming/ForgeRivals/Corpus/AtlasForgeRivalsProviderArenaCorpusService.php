<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals\Corpus;

use InvalidArgumentException;

/**
 * Atlas Forge Rivals · Provider Arena Corpus (v1).
 *
 * Canonical, file-based corpus of programming cases that the Provider Arena
 * uses to compare arms (Atlas Forge vs Claude Code vs Codex CLI vs Gemini
 * CLI) across nine task categories. The corpus is declarative and never
 * dispatches a provider — execution happens through the existing battery
 * pipeline; this service only owns the schema and the case manifests.
 *
 * Twelve cases (≥12, by spec):
 *   - 2 frontend
 *   - 2 backend
 *   - 2 bugfix
 *   - 2 tests
 *   - 1 refactor
 *   - 1 architecture
 *   - 1 docs
 *   - 1 security (+performance dimension)
 *
 * Each case carries the sixteen canonical fields the v1 schema requires
 * (case_id, task_category, role_focus, objective, business_rule,
 * acceptance_criteria, allowed_files_scope, forbidden_files_scope,
 * setup_fixture, expected_signal, quick_test_command, full_test_command,
 * quality_gates, timeout_policy, expected_evidence, invalid_if). Quality
 * gate weights are advisory in v1 — the adjudicator keeps its existing
 * WEIGHTS constant; a future slice may consume them.
 *
 * Six case sets:
 *   - quick:        3 short cases (smoke pass / one per major lane)
 *   - release:      every case (full corpus)
 *   - frontend:     only frontend task_category
 *   - backend:      only backend task_category
 *   - bugfix:       only bugfix task_category
 *   - architecture: architecture + refactor + docs
 *
 * Safety rules (never relaxed):
 *   - No case dispatches a provider in its declared commands.
 *   - No case touches Voice or Cartografia paths.
 *   - No case unlocks `external_rivals_certification`.
 *   - Every case is fully replayable from its seed_dir.
 *
 * Schema: atlas.forge.rivals.provider_arena_corpus.v1
 */
final class AtlasForgeRivalsProviderArenaCorpusService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.provider_arena_corpus.v1';

    public const CASE_SET_QUICK = 'quick';

    public const CASE_SET_RELEASE = 'release';

    public const CASE_SET_FRONTEND = 'frontend';

    public const CASE_SET_BACKEND = 'backend';

    public const CASE_SET_BUGFIX = 'bugfix';

    public const CASE_SET_ARCHITECTURE = 'architecture';

    /** @var list<string> */
    public const CASE_SETS = [
        self::CASE_SET_QUICK,
        self::CASE_SET_RELEASE,
        self::CASE_SET_FRONTEND,
        self::CASE_SET_BACKEND,
        self::CASE_SET_BUGFIX,
        self::CASE_SET_ARCHITECTURE,
    ];

    /** @var list<string> */
    public const TASK_CATEGORIES = [
        'frontend',
        'backend',
        'bugfix',
        'tests',
        'refactor',
        'architecture',
        'docs',
        'performance',
        'security',
    ];

    /** @var list<string> Canonical fields every case manifest must declare. */
    public const REQUIRED_FIELDS = [
        'case_id',
        'task_category',
        'role_focus',
        'objective',
        'business_rule',
        'acceptance_criteria',
        'allowed_files_scope',
        'forbidden_files_scope',
        'setup_fixture',
        'expected_signal',
        'quick_test_command',
        'full_test_command',
        'quality_gates',
        'timeout_policy',
        'expected_evidence',
        'invalid_if',
    ];

    /**
     * @return list<array<string,mixed>>
     */
    public function cases(): array
    {
        return $this->corpus();
    }

    /**
     * @return list<string>
     */
    public function caseIds(): array
    {
        return array_values(array_map(
            static fn (array $case): string => (string) $case['case_id'],
            $this->corpus(),
        ));
    }

    /**
     * @return array<string,mixed>
     */
    public function case(string $caseId): array
    {
        $caseId = trim($caseId);
        foreach ($this->corpus() as $case) {
            if ((string) $case['case_id'] === $caseId) {
                return $case;
            }
        }

        throw new InvalidArgumentException("unknown_case_id:{$caseId}");
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function casesForCaseSet(string $caseSet): array
    {
        $key = strtolower(trim($caseSet));
        if (! in_array($key, self::CASE_SETS, true)) {
            throw new InvalidArgumentException("unknown_case_set:{$caseSet}");
        }

        $all = $this->corpus();

        return match ($key) {
            self::CASE_SET_RELEASE => $all,
            self::CASE_SET_QUICK => array_values(array_filter(
                $all,
                static fn (array $c): bool => in_array((string) $c['case_id'], self::QUICK_CASE_IDS, true)
            )),
            self::CASE_SET_FRONTEND => $this->filterByCategory($all, ['frontend']),
            self::CASE_SET_BACKEND => $this->filterByCategory($all, ['backend']),
            self::CASE_SET_BUGFIX => $this->filterByCategory($all, ['bugfix']),
            self::CASE_SET_ARCHITECTURE => $this->filterByCategory($all, ['architecture', 'refactor', 'docs']),
        };
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function casesForTaskCategory(string $taskCategory): array
    {
        $key = strtolower(trim($taskCategory));
        if (! in_array($key, self::TASK_CATEGORIES, true)) {
            throw new InvalidArgumentException("unknown_task_category:{$taskCategory}");
        }

        return $this->filterByCategory($this->corpus(), [$key]);
    }

    /**
     * @param  array<string,mixed>  $case
     * @return list<string>
     */
    public function validateManifest(array $case): array
    {
        $invalid = [];
        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $case)) {
                $invalid[] = "missing_field:{$field}";
            }
        }

        if (isset($case['task_category']) && ! in_array($case['task_category'], self::TASK_CATEGORIES, true)) {
            $invalid[] = 'task_category_not_in_canon:'.(string) $case['task_category'];
        }

        foreach (['acceptance_criteria', 'allowed_files_scope', 'forbidden_files_scope', 'expected_evidence', 'invalid_if'] as $listField) {
            if (isset($case[$listField]) && ! is_array($case[$listField])) {
                $invalid[] = "field_must_be_array:{$listField}";
            }
            if (isset($case[$listField]) && is_array($case[$listField]) && $case[$listField] === []) {
                $invalid[] = "field_must_be_non_empty:{$listField}";
            }
        }

        foreach (['objective', 'business_rule', 'expected_signal', 'quick_test_command', 'full_test_command', 'role_focus'] as $stringField) {
            if (isset($case[$stringField]) && (! is_string($case[$stringField]) || trim((string) $case[$stringField]) === '')) {
                $invalid[] = "field_must_be_non_empty_string:{$stringField}";
            }
        }

        if (isset($case['quality_gates'])) {
            $gates = $case['quality_gates'];
            if (! is_array($gates) || ! isset($gates['dimensions'], $gates['weights'])) {
                $invalid[] = 'quality_gates_malformed';
            } elseif (! is_array($gates['dimensions']) || ! is_array($gates['weights']) || $gates['dimensions'] === []) {
                $invalid[] = 'quality_gates_dimensions_missing';
            } else {
                $sum = 0.0;
                foreach ($gates['weights'] as $w) {
                    $sum += (float) $w;
                }
                if (abs($sum - 1.0) > 0.01) {
                    $invalid[] = 'quality_gates_weights_do_not_sum_to_one';
                }
            }
        }

        if (isset($case['setup_fixture'])) {
            $fixture = $case['setup_fixture'];
            if (! is_array($fixture) || ! isset($fixture['seed_dir']) || trim((string) $fixture['seed_dir']) === '') {
                $invalid[] = 'setup_fixture_seed_dir_missing';
            }
        }

        if (isset($case['timeout_policy'])) {
            $tp = $case['timeout_policy'];
            $required = ['wall_clock_seconds_max', 'per_stage_seconds_max', 'hard_kill_after_seconds'];
            if (! is_array($tp)) {
                $invalid[] = 'timeout_policy_must_be_array';
            } else {
                foreach ($required as $key) {
                    if (! isset($tp[$key]) || ! is_int($tp[$key]) || $tp[$key] <= 0) {
                        $invalid[] = "timeout_policy_missing_or_invalid:{$key}";
                    }
                }
            }
        }

        if (isset($case['allowed_files_scope']) && is_array($case['allowed_files_scope'])) {
            foreach ($case['allowed_files_scope'] as $glob) {
                $g = (string) $glob;
                if (str_starts_with($g, 'atlas-desktop/src/voice/')
                    || str_contains($g, 'voice/')
                    || str_starts_with($g, 'atlas-cartografia/')
                    || str_contains($g, 'cartografia/')
                ) {
                    $invalid[] = 'allowed_scope_touches_voice_or_cartografia';
                    break;
                }
            }
        }

        return $invalid;
    }

    /**
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        $cases = $this->corpus();
        $byCategory = [];
        foreach (self::TASK_CATEGORIES as $cat) {
            $byCategory[$cat] = count($this->filterByCategory($cases, [$cat]));
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'count' => count($cases),
            'case_ids' => $this->caseIds(),
            'case_sets' => self::CASE_SETS,
            'task_categories' => self::TASK_CATEGORIES,
            'by_task_category' => $byCategory,
            'quick_case_ids' => self::QUICK_CASE_IDS,
            'no_external_provider_call_admitted' => true,
            'separated_from_external_rivals_certification' => true,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $cases
     * @param  list<string>  $allowed
     * @return list<array<string,mixed>>
     */
    private function filterByCategory(array $cases, array $allowed): array
    {
        return array_values(array_filter(
            $cases,
            static fn (array $c): bool => in_array((string) ($c['task_category'] ?? ''), $allowed, true),
        ));
    }

    /** @var list<string> Quick preset = 3 short representative cases. */
    public const QUICK_CASE_IDS = [
        'arena-frontend-button-loading-state',
        'arena-backend-pagination-cursor',
        'arena-bugfix-off-by-one-paginator',
    ];

    /**
     * @return list<array<string,mixed>>
     */
    private function corpus(): array
    {
        return [
            // 1 — frontend / ui_correctness
            [
                'case_id' => 'arena-frontend-button-loading-state',
                'task_category' => 'frontend',
                'role_focus' => 'ui_correctness',
                'objective' => 'Adicionar estado loading no botão Submit do formulário de captura sem regredir a interação existente.',
                'business_rule' => 'Operador clica duas vezes durante latência alta e gera captura duplicada; estado loading evita double-submit já visto em prod.',
                'acceptance_criteria' => [
                    'botão exibe spinner enquanto request pending',
                    'botão fica disabled durante submit',
                    'estado restaurado em sucesso e em erro',
                    'nenhum visual change em outros componentes',
                ],
                'allowed_files_scope' => [
                    'atlas-desktop/src/components/inbox/CaptureForm.tsx',
                    'atlas-desktop/src/components/ui/Button.tsx',
                    'atlas-desktop/src/components/inbox/__tests__/CaptureForm.test.tsx',
                ],
                'forbidden_files_scope' => [
                    'atlas-desktop/src/voice/**',
                    'atlas-server/app/**',
                    'atlas-cartografia/**',
                ],
                'setup_fixture' => [
                    'seed_dir' => 'storage/forge-rivals-corpus/arena-frontend-button-loading-state/seed',
                    'base_files' => ['CaptureForm.tsx', 'Button.tsx', '__tests__/CaptureForm.test.tsx'],
                ],
                'expected_signal' => 'vitest passa em CaptureForm.test.tsx com o caso shows_loading_state_during_submit verde',
                'quick_test_command' => 'vitest run components/inbox/__tests__/CaptureForm.test.tsx --reporter=basic',
                'full_test_command' => 'vitest run --reporter=basic',
                'quality_gates' => [
                    'dimensions' => ['ui_correctness', 'accessibility', 'visual_polish', 'maintainability'],
                    'weights' => [
                        'ui_correctness' => 0.40,
                        'accessibility' => 0.25,
                        'visual_polish' => 0.20,
                        'maintainability' => 0.15,
                    ],
                ],
                'timeout_policy' => [
                    'wall_clock_seconds_max' => 300,
                    'per_stage_seconds_max' => 90,
                    'hard_kill_after_seconds' => 420,
                ],
                'expected_evidence' => [
                    'patch_diff',
                    'vitest_output',
                    'before_after_dom_snapshot',
                ],
                'invalid_if' => [
                    'touched_forbidden_files',
                    'tests_skipped_silently',
                    'synthetic_score_admitted',
                    'fixture_drift_unrecorded',
                ],
            ],

            // 2 — frontend / accessibility
            [
                'case_id' => 'arena-frontend-list-empty-state',
                'task_category' => 'frontend',
                'role_focus' => 'accessibility',
                'objective' => 'Renderizar estado vazio acessível na lista de capturas quando filtro não retorna resultado.',
                'business_rule' => 'Lista vazia hoje aparece como hairline sem texto — leitor de tela não anuncia, operador acha que UI travou.',
                'acceptance_criteria' => [
                    'region com role=status e aria-live=polite anuncia o vazio',
                    'texto cita filtro ativo',
                    'CTA reset filtro presente e focável',
                    'não quebra lista populada',
                ],
                'allowed_files_scope' => [
                    'atlas-desktop/src/components/inbox/CaptureList.tsx',
                    'atlas-desktop/src/components/inbox/CaptureListEmpty.tsx',
                    'atlas-desktop/src/components/inbox/__tests__/CaptureList.test.tsx',
                ],
                'forbidden_files_scope' => [
                    'atlas-desktop/src/voice/**',
                    'atlas-server/app/**',
                    'atlas-cartografia/**',
                ],
                'setup_fixture' => [
                    'seed_dir' => 'storage/forge-rivals-corpus/arena-frontend-list-empty-state/seed',
                    'base_files' => ['CaptureList.tsx', '__tests__/CaptureList.test.tsx'],
                ],
                'expected_signal' => 'vitest a11y test exercita anúncio do leitor de tela e passa',
                'quick_test_command' => 'vitest run components/inbox/__tests__/CaptureList.test.tsx --reporter=basic',
                'full_test_command' => 'vitest run --reporter=basic',
                'quality_gates' => [
                    'dimensions' => ['accessibility', 'ui_correctness', 'maintainability'],
                    'weights' => [
                        'accessibility' => 0.50,
                        'ui_correctness' => 0.30,
                        'maintainability' => 0.20,
                    ],
                ],
                'timeout_policy' => [
                    'wall_clock_seconds_max' => 300,
                    'per_stage_seconds_max' => 90,
                    'hard_kill_after_seconds' => 420,
                ],
                'expected_evidence' => [
                    'patch_diff',
                    'vitest_output',
                    'a11y_report',
                ],
                'invalid_if' => [
                    'touched_forbidden_files',
                    'aria_attributes_missing',
                    'synthetic_score_admitted',
                ],
            ],

            // 3 — backend / api_correctness
            [
                'case_id' => 'arena-backend-pagination-cursor',
                'task_category' => 'backend',
                'role_focus' => 'api_correctness',
                'objective' => 'Substituir paginação offset-based por cursor opaco no endpoint de listing de capturas.',
                'business_rule' => 'Mobile usa infinite scroll; offset gera linhas duplicadas quando captura nova entra durante scroll — sintoma já reportado três vezes.',
                'acceptance_criteria' => [
                    'cursor opaco derivado de (created_at, id)',
                    'sem duplicates entre páginas em insert concorrente simulado',
                    'limit respeitado',
                    'compat com clientes legados via header opt-in X-Pagination-Mode',
                ],
                'allowed_files_scope' => [
                    'atlas-server/app/Http/Controllers/Api/CapturesController.php',
                    'atlas-server/app/Services/Captures/PaginationCursor.php',
                    'atlas-server/tests/Feature/Api/CapturesPaginationTest.php',
                ],
                'forbidden_files_scope' => [
                    'atlas-server/app/Services/Ai/**',
                    'atlas-server/database/migrations/**',
                    'atlas-desktop/src/voice/**',
                ],
                'setup_fixture' => [
                    'seed_dir' => 'storage/forge-rivals-corpus/arena-backend-pagination-cursor/seed',
                    'base_files' => ['CapturesController.php', 'PaginationCursor.php', 'CapturesPaginationTest.php'],
                ],
                'expected_signal' => 'CapturesPaginationTest verde com cenário de 50 capturas em 3 páginas sem overlap',
                'quick_test_command' => "php artisan test --filter='CapturesPaginationTest'",
                'full_test_command' => "php artisan test --filter='Captures'",
                'quality_gates' => [
                    'dimensions' => ['api_correctness', 'reliability', 'maintainability'],
                    'weights' => [
                        'api_correctness' => 0.50,
                        'reliability' => 0.30,
                        'maintainability' => 0.20,
                    ],
                ],
                'timeout_policy' => [
                    'wall_clock_seconds_max' => 480,
                    'per_stage_seconds_max' => 120,
                    'hard_kill_after_seconds' => 600,
                ],
                'expected_evidence' => [
                    'patch_diff',
                    'phpunit_output',
                    'request_response_capture',
                ],
                'invalid_if' => [
                    'touched_forbidden_files',
                    'migration_required_for_quick_test',
                    'synthetic_score_admitted',
                ],
            ],

            // 4 — backend / reliability
            [
                'case_id' => 'arena-backend-rate-limit-window',
                'task_category' => 'backend',
                'role_focus' => 'reliability',
                'objective' => 'Implementar rate limiting sliding-window por usuário no endpoint /api/captures.',
                'business_rule' => 'Cliente buggy enviou 4000 requests/min e derrubou a fila; precisamos throttling honesto antes do worker.',
                'acceptance_criteria' => [
                    'limite 60 req/min por user_id',
                    'janela deslizante (não fixed window)',
                    'resposta 429 com Retry-After',
                    'limite headers X-RateLimit-* presentes',
                ],
                'allowed_files_scope' => [
                    'atlas-server/app/Http/Middleware/CapturesRateLimit.php',
                    'atlas-server/app/Services/RateLimit/SlidingWindow.php',
                    'atlas-server/tests/Feature/RateLimit/CapturesRateLimitTest.php',
                ],
                'forbidden_files_scope' => [
                    'atlas-server/config/auth.php',
                    'atlas-server/app/Services/Ai/**',
                    'atlas-desktop/src/voice/**',
                ],
                'setup_fixture' => [
                    'seed_dir' => 'storage/forge-rivals-corpus/arena-backend-rate-limit-window/seed',
                    'base_files' => ['CapturesRateLimit.php', 'SlidingWindow.php', 'CapturesRateLimitTest.php'],
                ],
                'expected_signal' => 'rate-limit test verde com burst de 70 simulado em redis fake',
                'quick_test_command' => "php artisan test --filter='CapturesRateLimitTest'",
                'full_test_command' => "php artisan test --filter='RateLimit|Captures'",
                'quality_gates' => [
                    'dimensions' => ['reliability', 'api_correctness', 'observability'],
                    'weights' => [
                        'reliability' => 0.50,
                        'api_correctness' => 0.30,
                        'observability' => 0.20,
                    ],
                ],
                'timeout_policy' => [
                    'wall_clock_seconds_max' => 480,
                    'per_stage_seconds_max' => 120,
                    'hard_kill_after_seconds' => 600,
                ],
                'expected_evidence' => [
                    'patch_diff',
                    'phpunit_output',
                    'rate_limit_simulation_log',
                ],
                'invalid_if' => [
                    'touched_forbidden_files',
                    'fixed_window_admitted_as_sliding',
                    'synthetic_score_admitted',
                ],
            ],

            // 5 — bugfix / minimal_diff
            [
                'case_id' => 'arena-bugfix-off-by-one-paginator',
                'task_category' => 'bugfix',
                'role_focus' => 'minimal_diff',
                'objective' => 'Corrigir off-by-one no calculador de página total que descarta o último registro quando count é múltiplo exato do limit.',
                'business_rule' => 'Operador via "Page 5 de 4" no rodapé. Bug intermitente reportado por suporte.',
                'acceptance_criteria' => [
                    'total_pages = ceil(count / limit) sem exceção',
                    'count=0 ⇒ total_pages=0',
                    'count=20 limit=5 ⇒ total_pages=4',
                    'regression test cobre os 3 cenários',
                ],
                'allowed_files_scope' => [
                    'atlas-server/app/Services/Pagination/PageCalculator.php',
                    'atlas-server/tests/Unit/Pagination/PageCalculatorTest.php',
                ],
                'forbidden_files_scope' => [
                    'atlas-server/app/Http/**',
                    'atlas-server/database/**',
                    'atlas-desktop/src/voice/**',
                ],
                'setup_fixture' => [
                    'seed_dir' => 'storage/forge-rivals-corpus/arena-bugfix-off-by-one-paginator/seed',
                    'base_files' => ['PageCalculator.php', 'PageCalculatorTest.php'],
                ],
                'expected_signal' => 'PageCalculatorTest todos os 3 cenários verdes',
                'quick_test_command' => "php artisan test --filter='PageCalculatorTest'",
                'full_test_command' => "php artisan test --filter='Pagination'",
                'quality_gates' => [
                    'dimensions' => ['minimal_diff', 'regression_prevention', 'test_pass'],
                    'weights' => [
                        'minimal_diff' => 0.45,
                        'regression_prevention' => 0.35,
                        'test_pass' => 0.20,
                    ],
                ],
                'timeout_policy' => [
                    'wall_clock_seconds_max' => 240,
                    'per_stage_seconds_max' => 60,
                    'hard_kill_after_seconds' => 360,
                ],
                'expected_evidence' => [
                    'patch_diff',
                    'phpunit_output',
                    'regression_test_listing',
                ],
                'invalid_if' => [
                    'touched_forbidden_files',
                    'patch_exceeds_twenty_lines',
                    'synthetic_score_admitted',
                ],
            ],

            // 6 — bugfix / regression_prevention
            [
                'case_id' => 'arena-bugfix-null-pointer-in-formatter',
                'task_category' => 'bugfix',
                'role_focus' => 'regression_prevention',
                'objective' => 'Eliminar TypeError quando formatter de timestamp recebe null vindo de campo opcional.',
                'business_rule' => 'Crash em produção quando captura legacy sem updated_at é renderizada no painel admin.',
                'acceptance_criteria' => [
                    'formatter retorna string vazia em null',
                    'lança ValueError em string mal-formada (preservado)',
                    'comportamento atual de DateTime válido preservado',
                    'regression test cobre os 3 cenários',
                ],
                'allowed_files_scope' => [
                    'atlas-server/app/Support/Formatter/TimestampFormatter.php',
                    'atlas-server/tests/Unit/Support/TimestampFormatterTest.php',
                ],
                'forbidden_files_scope' => [
                    'atlas-server/app/Http/**',
                    'atlas-server/database/**',
                    'atlas-desktop/src/voice/**',
                ],
                'setup_fixture' => [
                    'seed_dir' => 'storage/forge-rivals-corpus/arena-bugfix-null-pointer-in-formatter/seed',
                    'base_files' => ['TimestampFormatter.php', 'TimestampFormatterTest.php'],
                ],
                'expected_signal' => 'TimestampFormatterTest todos os 3 cenários verdes',
                'quick_test_command' => "php artisan test --filter='TimestampFormatterTest'",
                'full_test_command' => "php artisan test --filter='Formatter|Support'",
                'quality_gates' => [
                    'dimensions' => ['regression_prevention', 'minimal_diff', 'test_pass'],
                    'weights' => [
                        'regression_prevention' => 0.50,
                        'minimal_diff' => 0.30,
                        'test_pass' => 0.20,
                    ],
                ],
                'timeout_policy' => [
                    'wall_clock_seconds_max' => 240,
                    'per_stage_seconds_max' => 60,
                    'hard_kill_after_seconds' => 360,
                ],
                'expected_evidence' => [
                    'patch_diff',
                    'phpunit_output',
                    'crash_repro_log',
                ],
                'invalid_if' => [
                    'touched_forbidden_files',
                    'silently_swallowed_other_exceptions',
                    'synthetic_score_admitted',
                ],
            ],

            // 7 — tests / coverage_quality
            [
                'case_id' => 'arena-tests-missing-edge-case',
                'task_category' => 'tests',
                'role_focus' => 'coverage_quality',
                'objective' => 'Adicionar casos de borda faltantes ao test do parser de captura sem tocar produção.',
                'business_rule' => 'Parser nunca quebrou em prod mas cobertura é 41%; PR de refator no parser não tem rede para apoiar mudanças seguras.',
                'acceptance_criteria' => [
                    'cobertura ≥ 80% no parser',
                    'cobertos: input vazio, whitespace puro, emoji só, json inválido',
                    'sem mock pesado nem dado fake — entradas reais',
                    'nenhum byte tocado em código de produção',
                ],
                'allowed_files_scope' => [
                    'atlas-server/tests/Unit/Captures/CaptureParserTest.php',
                ],
                'forbidden_files_scope' => [
                    'atlas-server/app/**',
                    'atlas-desktop/**',
                ],
                'setup_fixture' => [
                    'seed_dir' => 'storage/forge-rivals-corpus/arena-tests-missing-edge-case/seed',
                    'base_files' => ['CaptureParserTest.php'],
                ],
                'expected_signal' => 'pcov reporta ≥80% de cobertura linha no parser',
                'quick_test_command' => "php artisan test --filter='CaptureParserTest'",
                'full_test_command' => "php artisan test --filter='Captures' --coverage",
                'quality_gates' => [
                    'dimensions' => ['coverage_quality', 'determinism', 'maintainability'],
                    'weights' => [
                        'coverage_quality' => 0.50,
                        'determinism' => 0.30,
                        'maintainability' => 0.20,
                    ],
                ],
                'timeout_policy' => [
                    'wall_clock_seconds_max' => 360,
                    'per_stage_seconds_max' => 90,
                    'hard_kill_after_seconds' => 480,
                ],
                'expected_evidence' => [
                    'patch_diff',
                    'phpunit_output',
                    'coverage_report',
                ],
                'invalid_if' => [
                    'production_code_modified',
                    'tests_use_random_inputs_without_seed',
                    'synthetic_score_admitted',
                ],
            ],

            // 8 — tests / determinism
            [
                'case_id' => 'arena-tests-flaky-time-dependent',
                'task_category' => 'tests',
                'role_focus' => 'determinism',
                'objective' => 'Eliminar flakiness em test que depende de microtime() — substituir por clock injetável.',
                'business_rule' => 'Test passa local mas falha 5% das vezes em CI por race com sleep(1); todo PR sofre.',
                'acceptance_criteria' => [
                    'test não usa sleep nem microtime real',
                    'clock injetável no SUT',
                    '20 execuções consecutivas todas verdes',
                    'produção continua usando microtime real',
                ],
                'allowed_files_scope' => [
                    'atlas-server/app/Support/Clock/ClockInterface.php',
                    'atlas-server/app/Support/Clock/SystemClock.php',
                    'atlas-server/tests/Unit/Support/ClockBasedServiceTest.php',
                ],
                'forbidden_files_scope' => [
                    'atlas-server/app/Http/**',
                    'atlas-server/database/**',
                    'atlas-desktop/**',
                ],
                'setup_fixture' => [
                    'seed_dir' => 'storage/forge-rivals-corpus/arena-tests-flaky-time-dependent/seed',
                    'base_files' => ['ClockInterface.php', 'SystemClock.php', 'ClockBasedServiceTest.php'],
                ],
                'expected_signal' => '20 reruns sem flake; nenhum sleep no test',
                'quick_test_command' => "php artisan test --filter='ClockBasedServiceTest' --repeat=20",
                'full_test_command' => "php artisan test --filter='Clock'",
                'quality_gates' => [
                    'dimensions' => ['determinism', 'maintainability', 'test_pass'],
                    'weights' => [
                        'determinism' => 0.55,
                        'maintainability' => 0.25,
                        'test_pass' => 0.20,
                    ],
                ],
                'timeout_policy' => [
                    'wall_clock_seconds_max' => 420,
                    'per_stage_seconds_max' => 120,
                    'hard_kill_after_seconds' => 540,
                ],
                'expected_evidence' => [
                    'patch_diff',
                    'phpunit_output',
                    'repeat_run_log',
                ],
                'invalid_if' => [
                    'sleep_or_microtime_remaining_in_test',
                    'production_uses_test_double',
                    'synthetic_score_admitted',
                ],
            ],

            // 9 — refactor / cohesion
            [
                'case_id' => 'arena-refactor-extract-pure-function',
                'task_category' => 'refactor',
                'role_focus' => 'cohesion',
                'objective' => 'Extrair lógica de cálculo de prioridade de Captura para função pura testável, sem mudar comportamento.',
                'business_rule' => 'Hoje a prioridade está embutida no Controller; impossível testar isolado. Refator deve ser invisível pro consumidor.',
                'acceptance_criteria' => [
                    'função pura extraída em CapturePriorityCalculator',
                    'Controller passa a delegar',
                    'tests do Controller continuam verdes sem alteração',
                    'novo test unit cobre 4 cenários de prioridade',
                ],
                'allowed_files_scope' => [
                    'atlas-server/app/Http/Controllers/Api/CapturesController.php',
                    'atlas-server/app/Services/Captures/CapturePriorityCalculator.php',
                    'atlas-server/tests/Unit/Captures/CapturePriorityCalculatorTest.php',
                ],
                'forbidden_files_scope' => [
                    'atlas-server/database/**',
                    'atlas-server/app/Services/Ai/**',
                    'atlas-desktop/src/voice/**',
                ],
                'setup_fixture' => [
                    'seed_dir' => 'storage/forge-rivals-corpus/arena-refactor-extract-pure-function/seed',
                    'base_files' => ['CapturesController.php', 'CapturePriorityCalculatorTest.php'],
                ],
                'expected_signal' => 'Controller tests inalterados + Calculator test novo verde',
                'quick_test_command' => "php artisan test --filter='CapturePriorityCalculatorTest'",
                'full_test_command' => "php artisan test --filter='Captures'",
                'quality_gates' => [
                    'dimensions' => ['cohesion', 'behavior_preservation', 'maintainability'],
                    'weights' => [
                        'cohesion' => 0.45,
                        'behavior_preservation' => 0.35,
                        'maintainability' => 0.20,
                    ],
                ],
                'timeout_policy' => [
                    'wall_clock_seconds_max' => 480,
                    'per_stage_seconds_max' => 120,
                    'hard_kill_after_seconds' => 600,
                ],
                'expected_evidence' => [
                    'patch_diff',
                    'phpunit_output',
                    'before_after_complexity_report',
                ],
                'invalid_if' => [
                    'touched_forbidden_files',
                    'behavior_change_introduced',
                    'public_api_broken',
                    'synthetic_score_admitted',
                ],
            ],

            // 10 — architecture / boundary_integrity
            [
                'case_id' => 'arena-architecture-module-boundary-leak',
                'task_category' => 'architecture',
                'role_focus' => 'boundary_integrity',
                'objective' => 'Eliminar leak de modelo Eloquent que vaza do módulo Captures para o Inbox sem passar por DTO.',
                'business_rule' => 'Quando alguém adiciona coluna nova ao DB ela aparece automaticamente no contrato externo; precisamos boundary explícito.',
                'acceptance_criteria' => [
                    'CaptureDTO declarado e usado no contrato externo',
                    'Model Eloquent não aparece em namespace Inbox',
                    'PHPStan level 6 sem warning novo',
                    'test arquitetural valida boundary',
                ],
                'allowed_files_scope' => [
                    'atlas-server/app/Modules/Inbox/Captures/CaptureDTO.php',
                    'atlas-server/app/Modules/Inbox/InboxFacade.php',
                    'atlas-server/tests/Architecture/CapturesBoundaryTest.php',
                ],
                'forbidden_files_scope' => [
                    'atlas-server/database/migrations/**',
                    'atlas-server/app/Services/Ai/**',
                    'atlas-desktop/src/voice/**',
                ],
                'setup_fixture' => [
                    'seed_dir' => 'storage/forge-rivals-corpus/arena-architecture-module-boundary-leak/seed',
                    'base_files' => ['InboxFacade.php', 'CapturesBoundaryTest.php'],
                ],
                'expected_signal' => 'CapturesBoundaryTest passa + PHPStan sem regressão',
                'quick_test_command' => "php artisan test --filter='CapturesBoundaryTest'",
                'full_test_command' => "php artisan test --filter='Architecture'",
                'quality_gates' => [
                    'dimensions' => ['boundary_integrity', 'cohesion', 'maintainability'],
                    'weights' => [
                        'boundary_integrity' => 0.55,
                        'cohesion' => 0.25,
                        'maintainability' => 0.20,
                    ],
                ],
                'timeout_policy' => [
                    'wall_clock_seconds_max' => 600,
                    'per_stage_seconds_max' => 150,
                    'hard_kill_after_seconds' => 720,
                ],
                'expected_evidence' => [
                    'patch_diff',
                    'phpunit_output',
                    'phpstan_baseline_diff',
                    'dependency_graph_excerpt',
                ],
                'invalid_if' => [
                    'eloquent_model_remains_exposed',
                    'touched_forbidden_files',
                    'phpstan_baseline_inflated',
                    'synthetic_score_admitted',
                ],
            ],

            // 11 — docs / clarity
            [
                'case_id' => 'arena-docs-readme-quickstart',
                'task_category' => 'docs',
                'role_focus' => 'clarity',
                'objective' => 'Adicionar seção Quickstart em README do módulo Captures cobrindo install, run, primeiro fluxo end-to-end.',
                'business_rule' => 'Onboarding leva 2h porque README só tem arquitetura. Engenheiro novo precisa rodar uma captura local em 10 min.',
                'acceptance_criteria' => [
                    'seção Quickstart com 5 passos sequenciais',
                    'comandos copy-paste reais (não placeholder)',
                    'troubleshooting com 3 erros mais comuns',
                    'lint markdown limpo',
                ],
                'allowed_files_scope' => [
                    'atlas-server/app/Modules/Inbox/Captures/README.md',
                ],
                'forbidden_files_scope' => [
                    'atlas-server/app/**',
                    'atlas-server/database/**',
                    'atlas-desktop/**',
                ],
                'setup_fixture' => [
                    'seed_dir' => 'storage/forge-rivals-corpus/arena-docs-readme-quickstart/seed',
                    'base_files' => ['README.md'],
                ],
                'expected_signal' => 'markdownlint limpo; doc passa em revisão manual de operador',
                'quick_test_command' => 'markdownlint app/Modules/Inbox/Captures/README.md',
                'full_test_command' => 'markdownlint app/Modules/**/*.md',
                'quality_gates' => [
                    'dimensions' => ['clarity', 'actionability', 'maintainability'],
                    'weights' => [
                        'clarity' => 0.50,
                        'actionability' => 0.35,
                        'maintainability' => 0.15,
                    ],
                ],
                'timeout_policy' => [
                    'wall_clock_seconds_max' => 240,
                    'per_stage_seconds_max' => 60,
                    'hard_kill_after_seconds' => 360,
                ],
                'expected_evidence' => [
                    'patch_diff',
                    'markdownlint_output',
                    'doc_rendered_preview',
                ],
                'invalid_if' => [
                    'touched_forbidden_files',
                    'commands_contain_placeholders',
                    'synthetic_score_admitted',
                ],
            ],

            // 12 — security (+performance) / safety
            [
                'case_id' => 'arena-security-input-validation-traversal',
                'task_category' => 'security',
                'role_focus' => 'safety',
                'objective' => 'Adicionar validação contra path traversal no endpoint de download de evidence pack.',
                'business_rule' => 'Endpoint atual aceita filename do user em string crua; ../ na URL pode ler arbitrário do storage.',
                'acceptance_criteria' => [
                    'requests com .. ou / no filename rejeitadas com 400',
                    'allowlist de extensões: .json .md .log .zip',
                    'path final canonical sob storage/evidence',
                    'security test verifica 6 payloads de traversal',
                ],
                'allowed_files_scope' => [
                    'atlas-server/app/Http/Controllers/Evidence/EvidenceDownloadController.php',
                    'atlas-server/app/Support/Path/SafePathResolver.php',
                    'atlas-server/tests/Feature/Security/EvidenceTraversalTest.php',
                ],
                'forbidden_files_scope' => [
                    'atlas-server/config/auth.php',
                    'atlas-server/app/Services/Ai/**',
                    'atlas-desktop/src/voice/**',
                ],
                'setup_fixture' => [
                    'seed_dir' => 'storage/forge-rivals-corpus/arena-security-input-validation-traversal/seed',
                    'base_files' => ['EvidenceDownloadController.php', 'SafePathResolver.php', 'EvidenceTraversalTest.php'],
                ],
                'expected_signal' => 'EvidenceTraversalTest verde com 6 payloads bloqueados',
                'quick_test_command' => "php artisan test --filter='EvidenceTraversalTest'",
                'full_test_command' => "php artisan test --filter='Security|Evidence'",
                'quality_gates' => [
                    'dimensions' => ['safety', 'api_correctness', 'observability'],
                    'weights' => [
                        'safety' => 0.55,
                        'api_correctness' => 0.30,
                        'observability' => 0.15,
                    ],
                ],
                'timeout_policy' => [
                    'wall_clock_seconds_max' => 360,
                    'per_stage_seconds_max' => 90,
                    'hard_kill_after_seconds' => 480,
                ],
                'expected_evidence' => [
                    'patch_diff',
                    'phpunit_output',
                    'security_test_payload_list',
                ],
                'invalid_if' => [
                    'touched_forbidden_files',
                    'allowlist_replaced_by_denylist',
                    'synthetic_score_admitted',
                ],
            ],
        ];
    }
}

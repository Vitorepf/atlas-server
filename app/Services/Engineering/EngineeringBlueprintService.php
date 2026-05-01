<?php

namespace App\Services\Engineering;

use App\Models\AtlasTask;

class EngineeringBlueprintService
{
    /**
     * @param  array<string,mixed>  $contract
     * @return array<string,mixed>
     */
    public function forTask(AtlasTask $task, array $contract): array
    {
        $databaseReview = $this->requiresDatabaseReview($contract);
        $uiQa = $this->requiresUiQa($contract);
        $acceptanceMatrix = $this->acceptanceMatrix($contract);

        return [
            'schema_version' => 1,
            'blueprint_id' => $this->blueprintId($task, $contract),
            'source' => 'atlas_engineering_contract',
            'generated_at' => now()->toJSON(),
            'objective' => (string) ($contract['goal'] ?? $task->title ?? 'Executar tarefa tecnica Atlas.'),
            'phases' => $this->phases($databaseReview, $uiQa),
            'task_contract_refs' => [
                'task_id' => $task->id,
                'project_id' => $task->project_id,
                'project_step_id' => $task->project_step_id,
            ],
            'acceptance_matrix' => $acceptanceMatrix,
            'scenario_inventory' => $this->scenarioInventory($contract, $uiQa),
            'review_gates' => $this->reviewGates($databaseReview, $uiQa, $acceptanceMatrix),
            'contingency_policy' => $this->contingencyPolicy($databaseReview, $uiQa),
        ];
    }

    /**
     * @param  array<string,mixed>  $blueprint
     * @return array<int,string>
     */
    public function promptLines(array $blueprint): array
    {
        $lines = [];

        $this->appendList($lines, 'Blueprint phases', collect((array) ($blueprint['phases'] ?? []))
            ->map(fn (mixed $phase): string => is_array($phase)
                ? (string) ($phase['id'] ?? '').': '.(string) ($phase['gate'] ?? '')
                : '')
            ->filter()
            ->values()
            ->all());

        $this->appendList($lines, 'Scenario inventory', collect((array) ($blueprint['scenario_inventory'] ?? []))
            ->map(fn (mixed $scenario): string => is_array($scenario)
                ? (string) ($scenario['id'] ?? '').': '.(string) ($scenario['name'] ?? '')
                : '')
            ->filter()
            ->values()
            ->all());

        $this->appendList($lines, 'Review gates', collect((array) ($blueprint['review_gates'] ?? []))
            ->map(fn (mixed $gate): string => is_array($gate)
                ? (string) ($gate['id'] ?? '').': '.(string) ($gate['status'] ?? 'required')
                : '')
            ->filter()
            ->values()
            ->all());

        return $lines;
    }

    /**
     * @param  array<string,mixed>  $contract
     * @return array<int,array<string,mixed>>
     */
    private function acceptanceMatrix(array $contract): array
    {
        return collect($this->list($contract['acceptance_criteria'] ?? []))
            ->values()
            ->map(fn (string $criterion, int $index): array => [
                'id' => 'ac_'.($index + 1),
                'criterion' => $criterion,
                'verification_method' => $this->verificationMethod($criterion, $contract),
                'status' => 'pending',
                'evidence_required' => true,
            ])
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function phases(bool $databaseReview, bool $uiQa): array
    {
        $phases = [
            [
                'id' => 'inventory',
                'title' => 'Inventario de contexto',
                'gate' => 'arquivos, contratos e padroes relevantes identificados',
                'required_outputs' => ['files_to_touch', 'patterns_to_follow', 'risks'],
            ],
            [
                'id' => 'implementation_plan',
                'title' => 'Plano tecnico',
                'gate' => 'plano mapeado aos criterios de aceite',
                'required_outputs' => ['acceptance_mapping', 'validation_plan'],
            ],
            [
                'id' => 'scoped_implementation',
                'title' => 'Implementacao com escopo',
                'gate' => 'diff limitado ao contrato',
                'required_outputs' => ['changed_files', 'scope_notes'],
            ],
            [
                'id' => 'validation',
                'title' => 'Validacao',
                'gate' => 'comandos ou QA manual registrados',
                'required_outputs' => ['tests_or_smoke', 'coverage_gaps'],
            ],
            [
                'id' => 'deep_review',
                'title' => 'Review profundo',
                'gate' => 'risco residual e confianca declarados',
                'required_outputs' => ['review_confidence', 'residual_risks'],
            ],
        ];

        if ($uiQa) {
            $phases[] = [
                'id' => 'manual_qa',
                'title' => 'QA manual',
                'gate' => 'fluxos visuais verificados com evidencia',
                'required_outputs' => ['qa_steps', 'screenshot_or_manual_evidence'],
            ];
        }

        if ($databaseReview) {
            $phases[] = [
                'id' => 'database_review',
                'title' => 'Postgres/schema review',
                'gate' => 'migracoes, indices, constraints e queries revisados',
                'required_outputs' => ['migration_safety', 'index_plan', 'rollback_notes'],
            ];
        }

        $phases[] = [
            'id' => 'finish',
            'title' => 'Pacote de conclusao',
            'gate' => 'criterios, validacao e risco residual resumidos',
            'required_outputs' => ['acceptance_status', 'validation_status', 'decision'],
        ];

        return $phases;
    }

    /**
     * @param  array<string,mixed>  $contract
     * @return array<int,array<string,mixed>>
     */
    private function scenarioInventory(array $contract, bool $uiQa): array
    {
        $scenarios = [
            [
                'id' => 'happy_path',
                'name' => 'Fluxo principal do contrato',
                'source' => 'default',
                'verification_method' => 'smoke_or_targeted_test',
            ],
            [
                'id' => 'regression_scope',
                'name' => 'Regressao no comportamento existente proximo ao diff',
                'source' => 'default',
                'verification_method' => 'test_or_inspection',
            ],
        ];

        foreach ($this->list($contract['edge_cases'] ?? []) as $index => $edgeCase) {
            $scenarios[] = [
                'id' => 'edge_'.($index + 1),
                'name' => $edgeCase,
                'source' => 'contract.edge_cases',
                'verification_method' => $this->verificationMethod($edgeCase, $contract),
            ];
        }

        if ($uiQa) {
            $scenarios[] = [
                'id' => 'responsive_or_visual',
                'name' => 'Fluxo visual/responsivo impactado pela mudanca',
                'source' => 'ui_heuristic',
                'verification_method' => 'manual_qa_or_playwright',
            ];
        }

        return array_slice($scenarios, 0, 16);
    }

    /**
     * @param  array<string,mixed>  $contract
     * @param  array<int,array<string,mixed>>  $acceptanceMatrix
     * @return array<int,array<string,mixed>>
     */
    private function reviewGates(bool $databaseReview, bool $uiQa, array $acceptanceMatrix): array
    {
        $gates = [
            [
                'id' => 'acceptance_criteria',
                'title' => 'Acceptance criteria coverage',
                'required' => $acceptanceMatrix !== [],
                'status' => $acceptanceMatrix === [] ? 'not_applicable' : 'required',
                'minimum_confidence' => 0.78,
            ],
            [
                'id' => 'deep_code_review',
                'title' => 'Deep implementation review',
                'required' => true,
                'status' => 'required',
                'minimum_confidence' => 0.82,
            ],
            [
                'id' => 'validation_evidence',
                'title' => 'Validation evidence',
                'required' => true,
                'status' => 'required',
                'minimum_confidence' => 0.8,
            ],
        ];

        if ($uiQa) {
            $gates[] = [
                'id' => 'manual_qa',
                'title' => 'Manual QA / Playwright evidence',
                'required' => true,
                'status' => 'required',
                'minimum_confidence' => 0.8,
            ];
        }

        if ($databaseReview) {
            $gates[] = [
                'id' => 'database_review',
                'title' => 'Postgres schema/query review',
                'required' => true,
                'status' => 'required',
                'minimum_confidence' => 0.86,
                'checks' => [
                    'migration rollback path',
                    'indexes for new lookup paths',
                    'foreign keys and nullability',
                    'transaction boundaries',
                    'N+1 or slow query risk',
                ],
            ];
        }

        return $gates;
    }

    /**
     * @return array<string,mixed>
     */
    private function contingencyPolicy(bool $databaseReview, bool $uiQa): array
    {
        return [
            'stop_conditions' => array_values(array_filter([
                'provider muda arquivos fora do contrato',
                'teste falha apos tentativa de reparo',
                'criterio de aceite depende de decisao humana ausente',
                $databaseReview ? 'mudanca de schema sem plano de rollback' : null,
                $uiQa ? 'fluxo visual nao verificavel no ambiente atual' : null,
            ])),
            'fallback' => 'marcar como needs_review, registrar lacuna e preservar o menor diff util',
            'human_review_required_when' => [
                'review confidence abaixo do threshold',
                'QA manual ausente para criterio visual',
                'database_review requerido sem evidencia',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $contract
     */
    private function verificationMethod(string $text, array $contract): string
    {
        $haystack = mb_strtolower($text.' '.$this->contractText($contract));

        return match (true) {
            $this->containsAny($haystack, ['migration', 'migracao', 'postgres', 'sql', 'schema', 'index', 'query', 'eloquent']) => 'database_review',
            $this->containsAny($haystack, ['ui', 'tela', 'frontend', 'visual', 'wireframe', 'screenshot', 'mobile', 'playwright']) => 'manual_qa_or_playwright',
            $this->containsAny($haystack, ['test', 'phpunit', 'pest', 'endpoint', 'api', 'cli', 'command', 'comando']) => 'automated_or_smoke_test',
            default => 'inspection_or_targeted_test',
        };
    }

    /**
     * @param  array<string,mixed>  $contract
     */
    private function requiresDatabaseReview(array $contract): bool
    {
        return $this->containsAny($this->contractText($contract), [
            'migration',
            'migracao',
            'postgres',
            'database',
            'schema',
            'sql',
            'query',
            'eloquent',
            'model',
            'index',
            'foreign key',
            'constraint',
        ]);
    }

    /**
     * @param  array<string,mixed>  $contract
     */
    private function requiresUiQa(array $contract): bool
    {
        return $this->containsAny($this->contractText($contract), [
            'ui',
            'frontend',
            'tela',
            'visual',
            'wireframe',
            'screenshot',
            'mobile',
            'playwright',
            'viewport',
        ]);
    }

    /**
     * @param  array<string,mixed>  $contract
     */
    private function contractText(array $contract): string
    {
        $encoded = json_encode($contract, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return mb_strtolower(is_string($encoded) ? $encoded : '');
    }

    /**
     * @return array<int,string>
     */
    private function list(mixed $items): array
    {
        if (! is_array($items)) {
            $items = [$items];
        }

        return collect($items)
            ->filter(fn (mixed $item): bool => is_scalar($item) && trim((string) $item) !== '')
            ->map(fn (mixed $item): string => trim((string) $item))
            ->unique()
            ->values()
            ->all();
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $contract
     */
    private function blueprintId(AtlasTask $task, array $contract): string
    {
        $hash = hash('sha256', json_encode([
            'task_id' => $task->id,
            'goal' => $contract['goal'] ?? null,
            'acceptance_criteria' => $contract['acceptance_criteria'] ?? [],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return 'eng_'.substr($hash, 0, 16);
    }

    /**
     * @param  array<int,string>  $lines
     * @param  array<int,string>  $items
     */
    private function appendList(array &$lines, string $title, array $items): void
    {
        if ($items === []) {
            return;
        }

        $lines[] = '';
        $lines[] = '## '.$title;
        foreach (array_slice($items, 0, 12) as $item) {
            $lines[] = '- '.$item;
        }
    }
}

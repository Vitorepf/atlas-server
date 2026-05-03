<?php

namespace App\Services\Engineering;

use App\Models\AtlasEngineeringProjectBlueprint;
use App\Models\AtlasProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class EngineeringProjectBlueprintService
{
    public function __construct(
        private readonly EngineeringPhasePlannerService $phasePlanner,
        private readonly EngineeringBlueprintCoverageValidator $coverageValidator,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function prepare(AtlasProject $project, array $options = []): array
    {
        $blueprint = $this->buildBlueprint($project, $options);
        $validation = $this->coverageValidator->validate($blueprint);

        return [
            'project_id' => $project->id,
            'blueprint' => $blueprint,
            'content_hash' => $this->contentHash($blueprint),
            'validation' => $validation,
            'missing_fields' => collect($validation['errors'] ?? [])->pluck('path')->unique()->values()->all(),
            'suggested_questions' => $this->suggestedQuestions($validation),
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function create(AtlasProject $project, array $options = []): array
    {
        $prepared = $this->prepare($project, $options);

        if (! Schema::hasTable('atlas_engineering_project_blueprints')) {
            return [
                ...$prepared,
                'record' => null,
            ];
        }

        $hash = (string) $prepared['content_hash'];
        $existing = AtlasEngineeringProjectBlueprint::query()
            ->where('project_id', $project->id)
            ->where('content_hash', $hash)
            ->first();

        if ($existing) {
            return [
                ...$prepared,
                'record' => $this->payload($existing),
            ];
        }

        $record = DB::transaction(function () use ($project, $prepared, $hash, $options): AtlasEngineeringProjectBlueprint {
            $version = ((int) AtlasEngineeringProjectBlueprint::query()
                ->where('project_id', $project->id)
                ->max('version')) + 1;

            return AtlasEngineeringProjectBlueprint::query()->create([
                'project_id' => $project->id,
                'status' => 'draft',
                'version' => max(1, $version),
                'source' => 'atlas_project_blueprint',
                'created_by' => (string) ($options['created_by'] ?? 'atlas_ai'),
                'blueprint_json' => $prepared['blueprint'],
                'validation_json' => $prepared['validation'],
                'human_exception_json' => [],
                'content_hash' => $hash,
                'prepared_at' => now(),
            ]);
        });

        return [
            ...$prepared,
            'record' => $this->payload($record),
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function validateProject(AtlasProject $project, array $options = []): array
    {
        $record = $this->resolveRecord($project, $options['version'] ?? null);
        $blueprint = $record
            ? (array) $record->blueprint_json
            : (array) $this->prepare($project, $options)['blueprint'];
        $validation = $this->coverageValidator->validate($blueprint);

        if ($record) {
            $record->forceFill(['validation_json' => $validation])->save();
        }

        return [
            'project_id' => $project->id,
            'record' => $record ? $this->payload($record->refresh()) : null,
            'blueprint' => $blueprint,
            'content_hash' => $this->contentHash($blueprint),
            'validation' => $validation,
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function freeze(AtlasProject $project, array $options = []): array
    {
        $record = $this->resolveRecord($project, $options['version'] ?? null);
        if (! $record) {
            $created = $this->create($project, $options);
            $recordId = data_get($created, 'record.id');
            $record = is_string($recordId) ? AtlasEngineeringProjectBlueprint::query()->find($recordId) : null;
        }

        if (! $record) {
            throw ValidationException::withMessages(['project_id' => 'Blueprint de projeto indisponivel.']);
        }

        $blueprint = (array) $record->blueprint_json;
        $validation = $this->coverageValidator->validate($blueprint);
        $exception = $this->humanException($options, $validation);

        if (($validation['blocking'] ?? true) && $exception === []) {
            throw ValidationException::withMessages([
                'blueprint' => collect($validation['errors'] ?? [])
                    ->map(fn (array $error): string => (string) ($error['message'] ?? 'Coverage incompleto.'))
                    ->values()
                    ->all(),
            ]);
        }

        $record = DB::transaction(function () use ($project, $record, $blueprint, $validation, $exception): AtlasEngineeringProjectBlueprint {
            AtlasEngineeringProjectBlueprint::query()
                ->where('project_id', $project->id)
                ->where('status', 'frozen')
                ->whereKeyNot($record->id)
                ->update([
                    'status' => 'superseded',
                    'superseded_at' => now(),
                ]);

            $record->forceFill([
                'status' => 'frozen',
                'validation_json' => $validation,
                'human_exception_json' => $exception,
                'content_hash' => $this->contentHash($blueprint),
                'frozen_at' => now(),
            ])->save();

            return $record->refresh();
        });

        return [
            'project_id' => $project->id,
            'record' => $this->payload($record),
            'blueprint' => $blueprint,
            'content_hash' => $record->content_hash,
            'validation' => $validation,
            'human_exception' => $exception,
        ];
    }

    public function latest(AtlasProject $project, ?string $status = null): ?AtlasEngineeringProjectBlueprint
    {
        if (! Schema::hasTable('atlas_engineering_project_blueprints')) {
            return null;
        }

        return AtlasEngineeringProjectBlueprint::query()
            ->where('project_id', $project->id)
            ->when($status, fn ($query) => $query->where('status', $status))
            ->latest('version')
            ->first();
    }

    /**
     * @return array<string,mixed>
     */
    public function payload(AtlasEngineeringProjectBlueprint $record): array
    {
        $blueprint = is_array($record->blueprint_json) ? $record->blueprint_json : [];
        $currentHash = $this->contentHash($blueprint);

        return [
            'id' => $record->id,
            'project_id' => $record->project_id,
            'status' => $record->status,
            'version' => $record->version,
            'source' => $record->source,
            'created_by' => $record->created_by,
            'content_hash' => $record->content_hash,
            'matches_current_content' => hash_equals($record->content_hash, $currentHash),
            'stale' => ! hash_equals($record->content_hash, $currentHash),
            'validation' => $record->validation_json,
            'human_exception' => $record->human_exception_json,
            'prepared_at' => $record->prepared_at?->toJSON(),
            'frozen_at' => $record->frozen_at?->toJSON(),
            'superseded_at' => $record->superseded_at?->toJSON(),
            'created_at' => $record->created_at?->toJSON(),
            'updated_at' => $record->updated_at?->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $blueprint
     */
    public function contentHash(array $blueprint): string
    {
        $payload = $this->sortRecursive(collect($blueprint)
            ->except(['generated_at', 'prepared_at'])
            ->all());
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', is_string($encoded) ? $encoded : '');
    }

    private function resolveRecord(AtlasProject $project, mixed $version): ?AtlasEngineeringProjectBlueprint
    {
        if (! Schema::hasTable('atlas_engineering_project_blueprints')) {
            return null;
        }

        $query = AtlasEngineeringProjectBlueprint::query()->where('project_id', $project->id);
        if (is_numeric($version)) {
            $query->where('version', (int) $version);
        }

        return $query->latest('version')->first();
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $validation
     * @return array<string,mixed>
     */
    private function humanException(array $options, array $validation): array
    {
        $reason = trim((string) ($options['exception_reason'] ?? ''));
        if ($reason === '') {
            return [];
        }

        return [
            'reason' => $reason,
            'approved_by' => (string) ($options['approved_by'] ?? $options['created_by'] ?? 'operator'),
            'validation_status' => (string) ($validation['status'] ?? 'unknown'),
            'recorded_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function buildBlueprint(AtlasProject $project, array $options): array
    {
        $project->loadMissing(['steps', 'tasks']);
        $phasePlan = $this->phasePlanner->forProject($project);
        $scenarioIds = collect($phasePlan)->pluck('order')->map(fn (int $order): string => 'scenario_phase_'.$order)->all();

        return [
            'schema_version' => 'atlas.engineering.project_blueprint.v1',
            'blueprint_id' => 'proj_bp_'.substr(hash('sha256', $project->id.':'.($project->updated_at?->timestamp ?? '0')), 0, 16),
            'project_id' => $project->id,
            'status' => 'draft',
            'version' => null,
            'created_by' => (string) ($options['created_by'] ?? 'atlas_ai'),
            'objective' => [
                'problem' => (string) ($project->description ?: $project->goal ?: $project->title),
                'desired_outcome' => (string) ($project->desired_outcome ?: $project->goal ?: $project->title),
                'non_goals' => $this->stringList(data_get($project->metadata, 'engineering_non_goals', ['Escopo nao descrito no blueprint.'])),
                'success_metrics' => $this->stringList($project->definition_of_done ?: $project->minimum_viable_outcome ?: 'Gates obrigatorios passam.'),
            ],
            'product_context' => [
                'users' => $this->stringList(data_get($project->metadata, 'engineering_users', ['operator'])),
                'workflows' => $this->stringList($project->next_action ?: $project->goal ?: $project->title),
                'constraints' => $this->stringList(data_get($project->metadata, 'engineering_constraints', [])),
            ],
            'technical_context' => [
                'repositories' => $this->stringList(data_get($project->metadata, 'repositories', ['atlas-server', 'atlas-app'])),
                'systems' => $this->stringList(data_get($project->metadata, 'systems', ['Laravel', 'Postgres', 'Atlas app'])),
                'dependencies' => $this->stringList(data_get($project->metadata, 'dependencies', [])),
                'risk_profile' => $this->riskProfile($project),
            ],
            'inventory' => $this->inventory($project),
            'scenarios' => $this->scenarios($project, $phasePlan, $scenarioIds),
            'data_model' => [
                'entities' => data_get($project->metadata, 'engineering_data_model', []),
            ],
            'data_flow' => [
                'flows' => data_get($project->metadata, 'engineering_data_flow', []),
            ],
            'phase_plan' => $phasePlan,
            'task_generation_policy' => [
                'overwrite_human_tasks' => false,
                'generated_task_source' => 'project_blueprint',
            ],
            'qa_plan' => [
                'manual_qa_required_when' => ['ui_visual', 'manual_interaction', 'medium_or_high_visual_risk'],
            ],
            'review_plan' => [
                'deep_review_required' => true,
                'p1_confidence_threshold' => 0.8,
            ],
            'postgres_plan' => [
                'database_review_required_when' => ['migration', 'schema', 'jsonb_contract', 'query_plan', 'raw_sql'],
            ],
            'contingency_policy' => [
                'fallback' => 'bloquear freeze/run ate lacuna ou excecao humana ser registrada',
            ],
            'memory_policy' => [
                'privacy_guard' => true,
                'preserve_refs' => ['blueprint', 'contract', 'evidence', 'code_refs'],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function inventory(AtlasProject $project): array
    {
        $metadataInventory = data_get($project->metadata, 'engineering_inventory');
        if (is_array($metadataInventory) && $metadataInventory !== []) {
            return $metadataInventory;
        }

        $text = mb_strtolower($project->title.' '.$project->description.' '.$project->goal.' '.$project->desired_outcome);

        return [
            'screens' => str_contains($text, 'app') || str_contains($text, 'ui') || str_contains($text, 'tela') ? [[
                'id' => 'screen_project_engineering',
                'name' => 'Fluxo operacional de engenharia',
                'route' => '/projects',
                'states' => ['loading', 'ready', 'error', 'empty'],
                'interactions' => ['inspect_blueprint', 'record_evidence', 'run_harness'],
                'visual_requirements' => ['responsive', 'no_overlap', 'stable_toolbar'],
            ]] : [],
            'api_surfaces' => [[
                'id' => 'api_project_engineering_blueprint',
                'route' => 'GET/POST /projects/{project}/engineering/blueprint',
                'consumers' => ['atlas-app', 'CLI', 'automation'],
                'failure_modes' => ['404', '422', 'auth'],
            ]],
            'data_entities' => str_contains($text, 'migration') || str_contains($text, 'postgres') || str_contains($text, 'schema') ? [[
                'id' => 'project_schema_change',
                'owner' => 'Engineering Blueprint',
                'risks' => ['migration_risk', 'data_integrity'],
            ]] : [],
            'external_tools' => [[
                'id' => 'atlas_harness_runner',
                'purpose' => 'run, test, review and evidence capture',
                'cost' => 'free/local',
            ]],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $phasePlan
     * @param  array<int,string>  $scenarioIds
     * @return array<int,array<string,mixed>>
     */
    private function scenarios(AtlasProject $project, array $phasePlan, array $scenarioIds): array
    {
        $scenarios = [[
            'id' => 'scenario_happy_path',
            'type' => 'happy_path',
            'title' => 'Fluxo principal do projeto entregue',
            'preconditions' => ['Projeto existe', 'Blueprint foi revisado'],
            'steps' => ['Executar tasks geradas por fase', 'Registrar evidencias', 'Resolver gates'],
            'expected_result' => (string) ($project->desired_outcome ?: $project->goal ?: $project->title),
            'acceptance_refs' => ['ac_1'],
            'evidence_required' => ['validation_evidence', 'deep_code_review'],
            'risk' => $this->riskProfile($project),
        ]];

        foreach ($phasePlan as $phase) {
            $order = (int) ($phase['order'] ?? count($scenarios));
            $scenarios[] = [
                'id' => 'scenario_phase_'.$order,
                'type' => 'regression',
                'title' => 'Fase '.$order.' validada contra contrato',
                'preconditions' => ['Blueprint congelado'],
                'steps' => ['Executar task da fase', 'Rodar validacao focada'],
                'expected_result' => (string) ($phase['objective'] ?? $phase['title'] ?? 'Fase validada'),
                'acceptance_refs' => ['ac_1'],
                'evidence_required' => ['validation_evidence'],
                'risk' => $this->riskProfile($project),
            ];
        }

        if (data_get($this->inventory($project), 'screens.0')) {
            $scenarios[] = [
                'id' => 'scenario_visual_qa',
                'type' => 'visual',
                'title' => 'Fluxo visual sem regressao operacional',
                'preconditions' => ['App ou tela alvo disponivel'],
                'steps' => ['Abrir tela afetada', 'Executar interacao principal', 'Capturar evidencia visual'],
                'expected_result' => 'Sem sobreposicao, estados obrigatorios presentes e fluxo principal operavel.',
                'acceptance_refs' => ['ac_1'],
                'evidence_required' => ['manual_qa', 'screenshot'],
                'risk' => 'medium',
            ];
        }

        return collect($scenarios)
            ->filter(fn (array $scenario): bool => in_array((string) $scenario['id'], ['scenario_happy_path', 'scenario_visual_qa'], true)
                || in_array((string) $scenario['id'], $scenarioIds, true))
            ->values()
            ->all();
    }

    private function riskProfile(AtlasProject $project): string
    {
        $explicit = (string) data_get($project->metadata, 'engineering_risk_profile', '');
        if (in_array($explicit, ['low', 'medium', 'high', 'critical'], true)) {
            return $explicit;
        }

        return in_array($project->priority, ['urgent', 'high'], true) ? 'high' : 'medium';
    }

    /**
     * @return array<int,string>
     */
    private function suggestedQuestions(array $validation): array
    {
        return collect($validation['errors'] ?? [])
            ->map(fn (array $error): string => 'Completar '.$error['path'].': '.$error['message'])
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            $value = preg_split('/\r?\n|- /', (string) $value) ?: [];
        }

        return collect($value)
            ->filter(fn (mixed $item): bool => is_scalar($item) && trim((string) $item, " \t\n\r\0\x0B-") !== '')
            ->map(fn (mixed $item): string => trim((string) $item, " \t\n\r\0\x0B-"))
            ->unique()
            ->values()
            ->all();
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $mapped = array_map(fn (mixed $item): mixed => $this->sortRecursive($item), $value);

        if (! $isList) {
            ksort($mapped);
        }

        return $mapped;
    }
}

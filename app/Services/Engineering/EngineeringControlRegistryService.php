<?php

namespace App\Services\Engineering;

use App\Models\AtlasEngineeringControl;
use App\Models\AtlasEngineeringControlResult;
use App\Models\AtlasEngineeringControlRevision;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasEngineeringRunAttempt;
use App\Models\AtlasTask;
use App\Services\Ai\Runtime\WorkspaceProfiler;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Support\AtlasPhpBinary;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class EngineeringControlRegistryService
{
    private const VOLATILE_DEFINITION_KEYS = [
        'attempt_id',
        'attempt_uuid',
        'blueprint_id',
        'blueprint_hash',
        'created_at',
        'detected_at',
        'duration_ms',
        'engineering_run_id',
        'finished_at',
        'generated_at',
        'id',
        'request_id',
        'run_id',
        'run_uuid',
        'started_at',
        'task_id',
        'task_uuid',
        'timestamp',
        'trace_id',
        'trace_uuid',
        'updated_at',
    ];

    public function __construct(private readonly WorkspaceProfiler $profiler) {}

    /**
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $blueprint
     * @return array<int,array<string,mixed>>
     */
    public function applicableControls(
        AtlasTask $task,
        string $workspace,
        array $contract,
        array $blueprint,
        ?string $profile = null,
        bool $autoTest = false,
        ?string $testCommand = null,
    ): array {
        $workspace = realpath($workspace) ?: $workspace;
        $workspaceProfile = $this->profiler->profile($workspace);
        $stack = $workspaceProfile->stack;
        $scripts = $workspaceProfile->scripts;
        $profile = $profile ?: $this->defaultProfile($stack);

        $controls = [
            $this->control(
                slug: 'engineering_task_contract',
                name: 'Engineering task contract',
                direction: 'feedforward',
                executionType: 'mixed',
                category: 'behaviour',
                timing: 'prepare',
                required: true,
                failurePolicy: 'blocks_resolved',
                metadata: ['profile' => $profile, 'task_id' => $task->id],
            ),
            $this->control(
                slug: 'engineering_blueprint_snapshot',
                name: 'Frozen engineering blueprint',
                direction: 'feedforward',
                executionType: 'mixed',
                category: 'architecture_fitness',
                timing: 'prepare',
                required: true,
                failurePolicy: 'blocks_resolved',
                metadata: ['profile' => $profile, 'blueprint_id' => $blueprint['blueprint_id'] ?? null],
            ),
            $this->control(
                slug: 'git_status_snapshot',
                name: 'Git status snapshot',
                direction: 'feedback',
                executionType: 'computational',
                category: 'delivery_safety',
                timing: 'post_attempt',
                required: true,
                failurePolicy: 'blocks_resolved',
                command: 'git status --short',
                metadata: ['profile' => $profile],
            ),
            $this->control(
                slug: 'patch_artifact',
                name: 'Patch artifact',
                direction: 'feedback',
                executionType: 'computational',
                category: 'delivery_safety',
                timing: 'post_attempt',
                required: true,
                failurePolicy: 'blocks_resolved',
                command: 'git diff',
                metadata: ['profile' => $profile],
            ),
        ];

        $preferredTest = $testCommand ?: ($workspaceProfile->testCommands[0] ?? null);
        if ($autoTest && is_string($preferredTest) && trim($preferredTest) !== '') {
            $controls[] = $this->control(
                slug: 'primary_test_command',
                name: 'Primary test command',
                direction: 'feedback',
                executionType: 'computational',
                category: 'maintainability',
                timing: 'post_attempt',
                required: true,
                failurePolicy: 'blocks_resolved',
                command: $preferredTest,
                metadata: ['profile' => $profile, 'source' => $testCommand ? 'operator' : 'detected'],
            );
        }

        if (in_array('laravel', $stack, true)) {
            if (File::exists($workspace.'/vendor/bin/pint')) {
                $controls[] = $this->control(
                    slug: 'laravel_pint_check',
                    name: 'Laravel Pint check',
                    direction: 'feedback',
                    executionType: 'computational',
                    category: 'maintainability',
                    timing: 'pre_commit',
                    required: false,
                    failurePolicy: 'advisory',
                    // Forge worktrees can contain a large Laravel repository;
                    // validate only the dirty patch instead of rescanning every
                    // tracked PHP file on every attempt.
                    command: escapeshellarg(AtlasPhpBinary::path()).' -d memory_limit=1024M vendor/bin/pint --test --dirty',
                    metadata: ['profile' => $profile, 'memory_limit' => '1024M'],
                );
            }

            $controls[] = $this->control(
                slug: 'laravel_migration_pretend',
                name: 'Laravel migration pretend',
                direction: 'feedback',
                executionType: 'computational',
                category: 'delivery_safety',
                timing: 'post_attempt',
                required: $this->touchesDatabase($contract, $blueprint),
                failurePolicy: 'blocks_resolved',
                command: 'php artisan migrate --pretend',
                metadata: ['profile' => $profile],
            );
        }

        if ($this->hasGate($blueprint, 'manual_qa')) {
            $controls[] = $this->control(
                slug: 'manual_behaviour_evidence',
                name: 'Manual behaviour evidence',
                direction: 'feedback',
                executionType: 'human',
                category: 'behaviour',
                timing: 'review',
                required: true,
                failurePolicy: 'requires_human',
                metadata: [
                    'profile' => $profile,
                    'reason' => 'manual_qa_gate_required',
                    'accepted_evidence' => ['playwright', 'screenshot', 'manual_qa_note'],
                ],
            );
        }

        if ($this->hasGate($blueprint, 'database_review')) {
            $controls[] = $this->control(
                slug: 'database_review_evidence',
                name: 'Database review evidence',
                direction: 'feedback',
                executionType: 'mixed',
                category: 'delivery_safety',
                timing: 'review',
                required: true,
                failurePolicy: 'requires_human',
                metadata: [
                    'profile' => $profile,
                    'reason' => 'database_review_gate_required',
                    'accepted_evidence' => ['migration_pretend', 'rollback_notes', 'index_review'],
                ],
            );
        }

        if (in_array('node', $stack, true)) {
            foreach ($scripts as $name => $command) {
                $normalized = Str::of((string) $name)->lower()->value();
                if (in_array($normalized, ['typecheck', 'test:front', 'lint'], true)) {
                    $controls[] = $this->control(
                        slug: 'npm_run_'.str_replace([':', '-'], '_', $normalized),
                        name: 'npm run '.$name,
                        direction: 'feedback',
                        executionType: 'computational',
                        category: $normalized === 'typecheck' ? 'maintainability' : 'behaviour',
                        timing: 'post_attempt',
                        required: $autoTest && in_array($normalized, ['typecheck', 'test:front'], true),
                        failurePolicy: $autoTest ? 'blocks_resolved' : 'advisory',
                        command: ($workspaceProfile->packageManager ?: 'npm').' run '.$name,
                        metadata: ['profile' => $profile],
                    );
                }
            }
        }

        return collect($controls)
            ->unique('slug')
            ->values()
            ->all();
    }

    /**
     * @param  array<int,array<string,mixed>>  $controls
     * @return Collection<int,AtlasEngineeringControl>
     */
    public function persistControls(array $controls): Collection
    {
        if (! DatabaseTableAvailability::has('atlas_engineering_controls')) {
            return collect();
        }

        return collect($controls)
            ->filter(fn (array $control): bool => trim((string) ($control['slug'] ?? '')) !== '')
            ->map(fn (array $control): AtlasEngineeringControl => $this->persistControlDefinition($control))
            ->values();
    }

    /**
     * @param  array<string,mixed>  $control
     * @param  array<string,mixed>  $metadata
     */
    public function recordResult(
        AtlasEngineeringRun $run,
        ?AtlasEngineeringRunAttempt $attempt,
        array|AtlasEngineeringControl $control,
        string $status,
        string $summary,
        ?string $outputExcerpt = null,
        int $durationMs = 0,
        array $metadata = [],
    ): ?AtlasEngineeringControlResult {
        if (! DatabaseTableAvailability::has('atlas_engineering_control_results')) {
            return null;
        }

        $controlModel = $control instanceof AtlasEngineeringControl ? $control : $this->modelFor($control);
        $slug = $control instanceof AtlasEngineeringControl ? $control->slug : (string) ($control['slug'] ?? 'unknown_control');
        $metadata = array_merge($metadata, [
            'control_definition_hash' => $controlModel?->definition_hash,
            'control_version' => $controlModel?->version,
        ]);

        $payload = [
            'engineering_run_id' => $run->id,
            'attempt_id' => $attempt?->id,
            'control_id' => $controlModel?->id,
            'control_slug' => $slug,
            'status' => $this->normalizeStatus($status),
            'signal_summary' => $summary,
            'output_excerpt' => $outputExcerpt ? Str::limit($outputExcerpt, 4000, "\n...[truncated]") : null,
            'duration_ms' => max(0, $durationMs),
            'metadata' => $metadata,
        ];

        if (DatabaseTableAvailability::hasColumn('atlas_engineering_control_results', 'control_definition_hash')) {
            $payload['control_definition_hash'] = $controlModel?->definition_hash;
        }

        if (DatabaseTableAvailability::hasColumn('atlas_engineering_control_results', 'control_version')) {
            $payload['control_version'] = $controlModel?->version;
        }

        return AtlasEngineeringControlResult::query()->create($payload);
    }

    /**
     * @param  array<string,mixed>  $control
     */
    private function modelFor(array $control): ?AtlasEngineeringControl
    {
        if (! DatabaseTableAvailability::has('atlas_engineering_controls')) {
            return null;
        }

        $slug = (string) ($control['slug'] ?? '');
        if ($slug === '') {
            return null;
        }

        return $this->persistControlDefinition($control);
    }

    /**
     * @param  array<string,mixed>  $control
     */
    private function persistControlDefinition(array $control): AtlasEngineeringControl
    {
        $definition = $this->versionedDefinition($control);
        $hash = $this->definitionHash($definition);
        $slug = (string) $definition['slug'];
        $now = now();

        return DB::transaction(function () use ($control, $definition, $hash, $slug, $now): AtlasEngineeringControl {
            $revision = null;
            if (DatabaseTableAvailability::has('atlas_engineering_control_revisions')) {
                $revision = AtlasEngineeringControlRevision::query()
                    ->where('slug', $slug)
                    ->where('definition_hash', $hash)
                    ->first();

                if ($revision) {
                    $revision->update(['last_seen_at' => $now]);
                } else {
                    $version = ((int) AtlasEngineeringControlRevision::query()
                        ->where('slug', $slug)
                        ->max('version')) + 1;
                    $revision = AtlasEngineeringControlRevision::query()->create([
                        'slug' => $slug,
                        'version' => max(1, $version),
                        'definition_hash' => $hash,
                        'definition_json' => $definition,
                        'changed_by' => 'atlas_engineering_control_registry',
                        'first_seen_at' => $now,
                        'last_seen_at' => $now,
                        'metadata' => [
                            'source' => 'control_registry',
                        ],
                    ]);
                }
            }

            $payload = [
                'name' => (string) ($control['name'] ?? $definition['name']),
                'direction' => (string) $definition['direction'],
                'execution_type' => (string) $definition['execution_type'],
                'regulation_category' => (string) $definition['regulation_category'],
                'timing' => (string) $definition['timing'],
                'required' => (bool) $definition['required'],
                'risk_level' => (string) $definition['risk_level'],
                'applies_when_json' => $definition['applies_when'],
                'failure_policy' => (string) $definition['failure_policy'],
                'command' => $definition['command'],
                'skill_slug' => $definition['skill_slug'],
                'metadata' => $this->normalizeDefinitionValue($control['metadata'] ?? []),
            ];

            if (DatabaseTableAvailability::hasColumn('atlas_engineering_controls', 'definition_hash')) {
                $payload['definition_hash'] = $hash;
            }

            if (DatabaseTableAvailability::hasColumn('atlas_engineering_controls', 'version')) {
                $payload['version'] = $revision?->version ?? 1;
            }

            if (DatabaseTableAvailability::hasColumn('atlas_engineering_controls', 'versioned_at')) {
                $payload['versioned_at'] = $now;
            }

            $model = AtlasEngineeringControl::query()->updateOrCreate(['slug' => $slug], $payload);

            if ($revision && $revision->control_id !== $model->id) {
                $revision->update(['control_id' => $model->id]);
            }

            return $model;
        });
    }

    /**
     * @param  array<string,mixed>  $control
     * @return array<string,mixed>
     */
    private function versionedDefinition(array $control): array
    {
        $slug = trim((string) ($control['slug'] ?? 'unknown_control'));
        $name = trim((string) ($control['name'] ?? ''));

        return $this->normalizeDefinitionValue([
            'slug' => $slug === '' ? 'unknown_control' : $slug,
            'name' => $name === '' ? Str::headline(str_replace(['_', '-'], ' ', $slug)) : $name,
            'direction' => (string) ($control['direction'] ?? 'feedback'),
            'execution_type' => (string) ($control['execution_type'] ?? 'computational'),
            'regulation_category' => (string) ($control['regulation_category'] ?? 'behaviour'),
            'timing' => (string) ($control['timing'] ?? 'post_attempt'),
            'required' => (bool) ($control['required'] ?? false),
            'risk_level' => (string) ($control['risk_level'] ?? 'medium'),
            'applies_when' => $control['applies_when'] ?? [],
            'failure_policy' => (string) ($control['failure_policy'] ?? 'advisory'),
            'command' => $control['command'] ?? null,
            'skill_slug' => $control['skill_slug'] ?? null,
            'metadata' => $control['metadata'] ?? [],
        ]);
    }

    /**
     * @param  array<string,mixed>  $definition
     */
    private function definitionHash(array $definition): string
    {
        $encoded = json_encode($definition, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return hash('sha256', $encoded ?: '{}');
    }

    /**
     * @return array<mixed>|bool|float|int|string|null
     */
    private function normalizeDefinitionValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if ($value instanceof \JsonSerializable) {
            return $this->normalizeDefinitionValue($value->jsonSerialize());
        }

        if (is_object($value)) {
            if (method_exists($value, 'toArray')) {
                return $this->normalizeDefinitionValue($value->toArray());
            }

            return $this->normalizeDefinitionValue(get_object_vars($value));
        }

        if (is_resource($value)) {
            return 'resource';
        }

        if (! is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($this->normalizedDefinitionKey($key), self::VOLATILE_DEFINITION_KEYS, true)) {
                continue;
            }

            $normalized[$key] = $this->normalizeDefinitionValue($item);
        }

        if ($this->isList($normalized)) {
            return $normalized;
        }

        ksort($normalized);

        return $normalized;
    }

    private function normalizedDefinitionKey(string $key): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($key)), '_');
    }

    /**
     * @param  array<mixed>  $value
     */
    private function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    private function control(
        string $slug,
        string $name,
        string $direction,
        string $executionType,
        string $category,
        string $timing,
        bool $required,
        string $failurePolicy,
        ?string $command = null,
        string $riskLevel = 'medium',
        array $metadata = [],
    ): array {
        return [
            'slug' => $slug,
            'name' => $name,
            'direction' => $direction,
            'execution_type' => $executionType,
            'regulation_category' => $category,
            'timing' => $timing,
            'required' => $required,
            'risk_level' => $riskLevel,
            'applies_when' => [],
            'failure_policy' => $failurePolicy,
            'command' => $command,
            'metadata' => $metadata,
        ];
    }

    /**
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $blueprint
     */
    private function touchesDatabase(array $contract, array $blueprint): bool
    {
        $haystack = strtolower(json_encode([$contract, $blueprint], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

        return str_contains($haystack, 'migration')
            || str_contains($haystack, 'database')
            || str_contains($haystack, 'schema')
            || str_contains($haystack, 'postgres')
            || str_contains($haystack, 'tabela');
    }

    /**
     * @param  array<string,mixed>  $blueprint
     */
    private function hasGate(array $blueprint, string $gateId): bool
    {
        return collect((array) ($blueprint['review_gates'] ?? []))
            ->contains(fn (mixed $gate): bool => is_array($gate) && ($gate['id'] ?? null) === $gateId);
    }

    /**
     * @param  array<int,string>  $stack
     */
    private function defaultProfile(array $stack): string
    {
        if (in_array('laravel', $stack, true) && in_array('expo', $stack, true)) {
            return 'atlas_fullstack_feature';
        }

        if (in_array('laravel', $stack, true)) {
            return 'laravel_api';
        }

        if (in_array('expo', $stack, true)) {
            return 'expo_app';
        }

        return 'generic_workspace';
    }

    private function normalizeStatus(string $status): string
    {
        return in_array($status, ['passed', 'failed', 'blocked', 'skipped', 'warning'], true)
            ? $status
            : 'warning';
    }
}

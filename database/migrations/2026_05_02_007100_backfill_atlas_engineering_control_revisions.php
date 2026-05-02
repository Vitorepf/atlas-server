<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const VOLATILE_DEFINITION_KEYS = [
        'attempt_id',
        'attempt_uuid',
        'blueprint_hash',
        'blueprint_id',
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

    public function up(): void
    {
        if (! Schema::hasTable('atlas_engineering_controls')
            || ! Schema::hasTable('atlas_engineering_control_revisions')) {
            return;
        }

        $now = now();

        DB::table('atlas_engineering_controls')
            ->orderBy('slug')
            ->get()
            ->each(function (object $control) use ($now): void {
                $definition = $this->controlDefinition($control);
                $hash = $this->definitionHash($definition);
                $slug = (string) $control->slug;
                $revision = DB::table('atlas_engineering_control_revisions')
                    ->where('slug', $slug)
                    ->where('definition_hash', $hash)
                    ->first();

                if ($revision) {
                    $version = (int) $revision->version;
                    DB::table('atlas_engineering_control_revisions')
                        ->where('id', $revision->id)
                        ->update([
                            'control_id' => $control->id,
                            'last_seen_at' => $now,
                            'updated_at' => $now,
                        ]);
                } else {
                    $version = max(1, ((int) DB::table('atlas_engineering_control_revisions')
                        ->where('slug', $slug)
                        ->max('version')) + 1);
                    DB::table('atlas_engineering_control_revisions')->insert([
                        'id' => (string) Str::uuid(),
                        'control_id' => $control->id,
                        'slug' => $slug,
                        'version' => $version,
                        'definition_hash' => $hash,
                        'definition_json' => json_encode($definition, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                        'changed_by' => 'atlas_engineering_control_backfill',
                        'first_seen_at' => $now,
                        'last_seen_at' => $now,
                        'metadata' => json_encode(['source' => 'migration_backfill']),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                DB::table('atlas_engineering_controls')
                    ->where('id', $control->id)
                    ->update([
                        'definition_hash' => $hash,
                        'version' => $version,
                        'versioned_at' => $control->versioned_at ?: $now,
                        'updated_at' => $now,
                    ]);

                if (Schema::hasTable('atlas_engineering_control_results')
                    && Schema::hasColumn('atlas_engineering_control_results', 'control_definition_hash')
                    && Schema::hasColumn('atlas_engineering_control_results', 'control_version')) {
                    DB::table('atlas_engineering_control_results')
                        ->where(function ($query) use ($control, $slug): void {
                            $query->where('control_id', $control->id)
                                ->orWhere('control_slug', $slug);
                        })
                        ->whereNull('control_definition_hash')
                        ->update([
                            'control_definition_hash' => $hash,
                            'control_version' => $version,
                            'updated_at' => $now,
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Data backfill only; structural rollback is handled by the versioning migration.
    }

    private function controlDefinition(object $control): array
    {
        return $this->normalizeDefinitionValue([
            'slug' => (string) $control->slug,
            'name' => (string) $control->name,
            'direction' => (string) $control->direction,
            'execution_type' => (string) $control->execution_type,
            'regulation_category' => (string) $control->regulation_category,
            'timing' => (string) $control->timing,
            'required' => (bool) $control->required,
            'risk_level' => (string) $control->risk_level,
            'applies_when' => $this->decodeJson($control->applies_when_json ?? '[]'),
            'failure_policy' => (string) $control->failure_policy,
            'command' => $control->command,
            'skill_slug' => $control->skill_slug,
            'metadata' => $this->decodeJson($control->metadata ?? '[]'),
        ]);
    }

    private function definitionHash(array $definition): string
    {
        $encoded = json_encode($definition, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return hash('sha256', $encoded ?: '{}');
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeDefinitionValue(mixed $value): mixed
    {
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

    private function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }
};

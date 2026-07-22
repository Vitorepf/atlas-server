<?php

namespace App\Services\Engineering\CodeIntelligence;

use App\Models\AtlasEngineeringCodeModule;
use Illuminate\Support\Str;

/**
 * GOD-DEBULK FASE C — pure `module*` shaping extracted VERBATIM from
 * EngineeringCodeIntelligenceService. Path/class → module resolution and the
 * module payload/audit shapes. Stateless: no DB, no workspace state — the
 * DB-coupled orchestrators (module(), moduleDrift()) stay in the façade.
 */
class ModuleExtractor
{
    /**
     * @return array<string,mixed>
     */
    public function moduleForPath(string $path): array
    {
        $rules = [
            ['prefix' => 'docs/engineering-knowledge-base', 'slug' => 'engineering_knowledge_docs', 'name' => 'Engineering Knowledge Docs', 'layer' => 'documentation', 'tags' => ['docs', 'knowledge']],
            ['prefix' => 'routes', 'slug' => 'api_routes', 'name' => 'API Routes', 'layer' => 'api', 'tags' => ['routes', 'api']],
            ['prefix' => 'app/Services/Engineering', 'slug' => 'engineering_harness_services', 'name' => 'Engineering Harness Services', 'layer' => 'service', 'tags' => ['engineering', 'harness']],
            ['prefix' => 'app/Console/Commands/AtlasEngineering', 'slug' => 'engineering_harness_cli', 'name' => 'Engineering Harness CLI', 'layer' => 'cli', 'tags' => ['engineering', 'cli']],
            ['prefix' => 'app/Http/Controllers/Engineering', 'slug' => 'engineering_harness_api', 'name' => 'Engineering Harness API', 'layer' => 'api', 'tags' => ['engineering', 'api']],
            ['prefix' => 'app/Models/AtlasEngineering', 'slug' => 'engineering_harness_models', 'name' => 'Engineering Harness Models', 'layer' => 'model', 'tags' => ['engineering', 'database']],
            ['contains' => 'atlas_engineering', 'prefix' => 'database/migrations', 'slug' => 'engineering_harness_schema', 'name' => 'Engineering Harness Schema', 'layer' => 'database', 'tags' => ['engineering', 'migrations']],
            ['prefix' => 'tests', 'contains' => 'Engineering', 'slug' => 'engineering_harness_tests', 'name' => 'Engineering Harness Tests', 'layer' => 'test', 'tags' => ['engineering', 'tests']],
            ['prefix' => 'app/Services/Ai', 'slug' => 'atlas_ai_services', 'name' => 'Atlas AI Services', 'layer' => 'service', 'tags' => ['ai']],
            ['prefix' => 'app/Console/Commands/AtlasCli', 'slug' => 'atlas_cli', 'name' => 'Atlas CLI', 'layer' => 'cli', 'tags' => ['cli']],
            ['prefix' => 'app/Console/Commands/AtlasMemory', 'slug' => 'atlas_memory_cli', 'name' => 'Atlas Memory CLI', 'layer' => 'cli', 'tags' => ['memory']],
            ['prefix' => 'app/Models/AtlasMemory', 'slug' => 'atlas_memory_models', 'name' => 'Atlas Memory Models', 'layer' => 'model', 'tags' => ['memory']],
            ['prefix' => 'app/Models/Ai', 'slug' => 'atlas_ai_models', 'name' => 'Atlas AI Models', 'layer' => 'model', 'tags' => ['ai']],
            ['prefix' => 'app/Http/Controllers/Mobile', 'slug' => 'mobile_gateway_api', 'name' => 'Mobile Gateway API', 'layer' => 'api', 'tags' => ['mobile']],
            ['prefix' => 'database/migrations', 'slug' => 'database_schema', 'name' => 'Database Schema', 'layer' => 'database', 'tags' => ['database']],
            ['prefix' => 'tests', 'slug' => 'test_suite', 'name' => 'Test Suite', 'layer' => 'test', 'tags' => ['tests']],
            ['prefix' => 'app/Console/Commands', 'slug' => 'console_commands', 'name' => 'Console Commands', 'layer' => 'cli', 'tags' => ['cli']],
            ['prefix' => 'app/Http/Controllers', 'slug' => 'http_controllers', 'name' => 'HTTP Controllers', 'layer' => 'api', 'tags' => ['api']],
            ['prefix' => 'app/Models', 'slug' => 'eloquent_models', 'name' => 'Eloquent Models', 'layer' => 'model', 'tags' => ['models']],
            ['prefix' => 'app/Services', 'slug' => 'application_services', 'name' => 'Application Services', 'layer' => 'service', 'tags' => ['services']],
        ];

        foreach ($rules as $rule) {
            if (isset($rule['prefix']) && ! str_starts_with($path, (string) $rule['prefix'])) {
                continue;
            }
            if (isset($rule['contains']) && ! str_contains($path, (string) $rule['contains'])) {
                continue;
            }

            return [
                'slug' => $rule['slug'],
                'name' => $rule['name'],
                'layer' => $rule['layer'],
                'root_path' => $rule['prefix'] ?? dirname($path),
                'description' => null,
                'tags' => $rule['tags'] ?? [],
            ];
        }

        $root = explode('/', $path)[0] ?? 'workspace';

        return [
            'slug' => Str::slug($root.'_misc', '_'),
            'name' => Str::of($root)->replace('_', ' ')->title().' Misc',
            'layer' => 'misc',
            'root_path' => $root,
            'description' => null,
            'tags' => [$root],
        ];
    }

    public function moduleSlugForClass(string $class): ?string
    {
        $class = ltrim($class, '\\');
        $path = str_replace('\\', '/', $class).'.php';
        $path = preg_replace('/^App\//', 'app/', $path) ?? $path;
        $module = $this->moduleForPath($path);

        return $module['slug'] ?? null;
    }

    public function moduleSlugForShortName(string $symbol): ?string
    {
        return match (true) {
            str_ends_with($symbol, 'Service') => 'application_services',
            str_ends_with($symbol, 'Controller') => 'http_controllers',
            str_ends_with($symbol, 'Command') => 'console_commands',
            str_ends_with($symbol, 'Model') => 'eloquent_models',
            default => null,
        };
    }

    /**
     * @param  array<string,mixed>|null  $module
     * @return array<string,mixed>
     */
    public function moduleAuditPayload(?array $module, string $reason): array
    {
        return [
            'reason' => $reason,
            'slug' => (string) ($module['slug'] ?? ''),
            'name' => (string) ($module['name'] ?? ''),
            'layer' => (string) ($module['layer'] ?? ''),
            'root_path' => $module['root_path'] ?? null,
            'source_hash' => (string) ($module['source_hash'] ?? ''),
            'file_count' => (int) ($module['file_count'] ?? 0),
            'symbol_count' => (int) ($module['symbol_count'] ?? 0),
            'route_count' => (int) ($module['route_count'] ?? 0),
            'command_count' => (int) ($module['command_count'] ?? 0),
            'migration_count' => (int) ($module['migration_count'] ?? 0),
            'test_count' => (int) ($module['test_count'] ?? 0),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function modulePayload(AtlasEngineeringCodeModule $module): array
    {
        return [
            'id' => $module->id,
            'slug' => $module->slug,
            'name' => $module->name,
            'layer' => $module->layer,
            'root_path' => $module->root_path,
            'primary_language' => $module->primary_language,
            'status' => $module->status,
            'owner' => $module->owner,
            'description' => $module->description,
            'docs_status' => $module->docs_status,
            'file_count' => $module->file_count,
            'symbol_count' => $module->symbol_count,
            'route_count' => $module->route_count,
            'command_count' => $module->command_count,
            'migration_count' => $module->migration_count,
            'test_count' => $module->test_count,
            'source_hash' => $module->source_hash,
            'docs_hash' => $module->docs_hash,
            'tags' => $module->tags_json ?? [],
            'related_docs' => $module->related_docs_json ?? [],
            'related_tests' => $module->related_tests_json ?? [],
            'metadata' => $module->metadata ?? [],
            'indexed_at' => $module->indexed_at?->toJSON(),
            'archived_at' => $module->archived_at?->toJSON(),
            'created_at' => $module->created_at?->toJSON(),
            'updated_at' => $module->updated_at?->toJSON(),
        ];
    }
}
